<?php

namespace Skeylup\LaravelPipedrive\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Skeylup\LaravelPipedrive\Data\SyncOptions;
use Skeylup\LaravelPipedrive\Data\SyncResult;
use Skeylup\LaravelPipedrive\Exceptions\PipedriveException;
use Skeylup\LaravelPipedrive\Services\PipedriveErrorHandler;
use Skeylup\LaravelPipedrive\Services\PipedriveHealthChecker;
use Skeylup\LaravelPipedrive\Services\PipedriveMemoryManager;
use Skeylup\LaravelPipedrive\Services\PipedriveParsingService;
use Skeylup\LaravelPipedrive\Services\PipedriveRateLimitManager;

/**
 * Main parsing job with dual execution modes
 *
 * Supports both synchronous (for commands) and asynchronous (for schedulers)
 * execution with progress tracking and batch processing
 */
class SyncPipedriveEntityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public SyncOptions $options;

    public int $tries;

    public int $timeout;

    protected PipedriveParsingService $parsingService;

    protected PipedriveRateLimitManager $rateLimitManager;

    protected PipedriveErrorHandler $errorHandler;

    protected PipedriveMemoryManager $memoryManager;

    protected PipedriveHealthChecker $healthChecker;

    public function __construct(SyncOptions $options)
    {
        $this->options = $options;
        $this->tries = $options->maxRetries;
        $this->timeout = $options->timeout;

        if ($options->queue) {
            $this->onQueue($options->queue);
        }
    }

    /**
     * Execute the job
     */
    public function handle(
        PipedriveParsingService $parsingService,
        PipedriveRateLimitManager $rateLimitManager,
        PipedriveErrorHandler $errorHandler,
        PipedriveMemoryManager $memoryManager,
        PipedriveHealthChecker $healthChecker
    ): SyncResult {
        $this->parsingService = $parsingService;
        $this->rateLimitManager = $rateLimitManager;
        $this->errorHandler = $errorHandler;
        $this->memoryManager = $memoryManager;
        $this->healthChecker = $healthChecker;

        $startTime = Carbon::now();
        $startedAt = $startTime->toISOString();

        try {
            // Validate options
            $validationErrors = $this->options->validateOptions();
            if (! empty($validationErrors)) {
                throw new \InvalidArgumentException('Invalid sync options: '.implode(', ', $validationErrors));
            }

            Log::info('Starting Pipedrive entity sync job', [
                'entity_type' => $this->options->entityType,
                'options' => $this->options->toArray(),
                'job_id' => $this->job?->getJobId(),
                'attempt' => $this->attempts(),
            ]);

            // Initialize Pipedrive client
            $clientInfo = $this->parsingService->initializePipedriveClient();

            Log::info('Pipedrive client initialized', [
                'entity_type' => $this->options->entityType,
                'user' => $clientInfo['user'],
                'company' => $clientInfo['company'],
                'auth_method' => $clientInfo['auth_method'],
            ]);

            // Fetch data from Pipedrive
            $fetchStartTime = microtime(true);
            $data = $this->parsingService->fetchEntityData(
                $this->options->entityType,
                [
                    'limit' => $this->options->limit,
                    'full_data' => $this->options->fullData,
                    'force' => $this->options->force,
                ]
            );
            $fetchTime = microtime(true) - $fetchStartTime;

            if (empty($data)) {
                $result = SyncResult::success(
                    $this->options->entityType,
                    metadata: [
                        'message' => 'No data found',
                        'fetch_time' => $fetchTime,
                    ]
                );

                Log::info('No data found for entity sync', [
                    'entity_type' => $this->options->entityType,
                    'fetch_time' => $fetchTime,
                ]);

                return $result->withTiming($startedAt);
            }

            Log::info('Data fetched from Pipedrive', [
                'entity_type' => $this->options->entityType,
                'record_count' => count($data),
                'fetch_time' => $fetchTime,
                'full_data_mode' => $this->options->fullData,
            ]);

            // Process the data
            $processStartTime = microtime(true);
            $processingResult = $this->parsingService->processEntityData(
                $this->options->entityType,
                $data,
                [
                    'force' => $this->options->force,
                    'context' => $this->options->context,
                    'verbose' => $this->options->verbose,
                ]
            );
            $processTime = microtime(true) - $processStartTime;

            $endTime = Carbon::now();
            $totalExecutionTime = $endTime->diffInSeconds($startTime, true);

            // Create result
            $result = SyncResult::fromProcessingData(
                $this->options->entityType,
                $processingResult,
                $totalExecutionTime,
                [
                    'fetch_time' => $fetchTime,
                    'process_time' => $processTime,
                    'total_records' => count($data),
                    'job_id' => $this->job?->getJobId(),
                    'attempt' => $this->attempts(),
                    'queue' => $this->options->queue,
                ]
            );

            // Add statistics
            $result = $result
                ->withMemoryStats($this->memoryManager->getMemoryStats())
                ->withRateLimitStats($this->rateLimitManager->getStatus())
                ->withHealthStats($this->healthChecker->getHealthStatus())
                ->withTiming($startedAt, $endTime->toISOString())
                ->withContext($this->options->context, $this->options->getContextWithMetadata());

            // Log success
            Log::info('Pipedrive entity sync completed successfully', $result->toLogFormat());

            // Record success for circuit breaker
            $this->errorHandler->recordSuccess('sync');

            return $result;

        } catch (PipedriveException $e) {
            return $this->handlePipedriveException($e, $startedAt);
        } catch (\Throwable $e) {
            return $this->handleGenericException($e, $startedAt);
        }
    }

    /**
     * Handle Pipedrive-specific exceptions
     */
    protected function handlePipedriveException(PipedriveException $e, string $startedAt): SyncResult
    {
        $this->errorHandler->recordFailure($e);

        $result = SyncResult::failure(
            $this->options->entityType,
            $e->getMessage(),
            $e,
            [
                'job_id' => $this->job?->getJobId(),
                'attempt' => $this->attempts(),
                'max_attempts' => $this->tries,
                'error_type' => $e->getErrorType(),
                'retryable' => $e->isRetryable(),
                'pipedrive_context' => $e->getContext(),
            ]
        );

        $result = $result
            ->withMemoryStats($this->memoryManager->getMemoryStats())
            ->withRateLimitStats($this->rateLimitManager->getStatus())
            ->withHealthStats($this->healthChecker->getHealthStatus())
            ->withTiming($startedAt)
            ->withContext($this->options->context, $this->options->getContextWithMetadata());

        Log::error('Pipedrive entity sync failed with Pipedrive exception', array_merge(
            $result->toLogFormat(),
            ['exception_info' => $e->getErrorInfo()]
        ));

        // Determine if job should be retried
        if ($e->isRetryable() && $this->errorHandler->shouldRetry($e, $this->attempts())) {
            $delay = $this->errorHandler->getRetryDelay($e, $this->attempts());

            Log::info('Retrying Pipedrive entity sync job', [
                'entity_type' => $this->options->entityType,
                'attempt' => $this->attempts(),
                'max_attempts' => $this->tries,
                'retry_delay' => $delay,
                'error_type' => $e->getErrorType(),
            ]);

            $this->release($delay);

            return $result;
        }

        // Job failed permanently
        $this->fail($e);

        return $result;
    }

    /**
     * Handle generic exceptions
     */
    protected function handleGenericException(\Throwable $e, string $startedAt): SyncResult
    {
        // Classify the exception
        $classified = $this->errorHandler->classifyException($e, [
            'operation' => 'sync_job',
            'entity_type' => $this->options->entityType,
            'job_id' => $this->job?->getJobId(),
        ]);

        return $this->handlePipedriveException($classified, $startedAt);
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Pipedrive entity sync job failed permanently', [
            'entity_type' => $this->options->entityType,
            'job_id' => $this->job?->getJobId(),
            'attempts' => $this->attempts(),
            'exception' => [
                'class' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ],
            'options' => $this->options->toArray(),
        ]);

        // Record failure for circuit breaker
        if ($exception instanceof PipedriveException) {
            $this->errorHandler->recordFailure($exception);
        }
    }

    /**
     * Execute job synchronously (for commands)
     */
    public static function executeSync(SyncOptions $options): SyncResult
    {
        $job = new self($options->withChanges(['async' => false]));

        // Resolve dependencies manually for sync execution
        $parsingService = app(PipedriveParsingService::class);
        $rateLimitManager = app(PipedriveRateLimitManager::class);
        $errorHandler = app(PipedriveErrorHandler::class);
        $memoryManager = app(PipedriveMemoryManager::class);
        $healthChecker = app(PipedriveHealthChecker::class);

        return $job->handle(
            $parsingService,
            $rateLimitManager,
            $errorHandler,
            $memoryManager,
            $healthChecker
        );
    }

    /**
     * Dispatch job asynchronously (for schedulers)
     */
    public static function dispatchAsync(SyncOptions $options): self
    {
        $job = new self($options->withChanges(['async' => true]));

        if ($options->queue) {
            return dispatch($job)->onQueue($options->queue);
        }

        return dispatch($job);
    }

    /**
     * Get job tags for monitoring
     */
    public function tags(): array
    {
        return [
            'pipedrive',
            'sync',
            $this->options->entityType,
            $this->options->context,
        ];
    }

    /**
     * Get job display name
     */
    public function displayName(): string
    {
        return "Sync Pipedrive {$this->options->entityType}";
    }

    /**
     * Get job timeout
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addSeconds($this->timeout);
    }
}
