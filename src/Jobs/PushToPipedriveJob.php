<?php

namespace Skeylup\LaravelPipedrive\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Skeylup\LaravelPipedrive\Exceptions\PipedriveException;
use Skeylup\LaravelPipedrive\Models\PipedriveCustomField;
use Skeylup\LaravelPipedrive\Services\PipedriveAuthService;
use Skeylup\LaravelPipedrive\Services\PipedriveErrorHandler;
use Skeylup\LaravelPipedrive\Services\PipedriveMemoryManager;
use Skeylup\LaravelPipedrive\Services\PipedriveRateLimitManager;
use Skeylup\LaravelPipedrive\Traits\EmitsPipedriveEvents;

class PushToPipedriveJob implements ShouldQueue
{
    use Dispatchable, EmitsPipedriveEvents, InteractsWithQueue, Queueable, SerializesModels;

    public Model $model;

    public array $modifications;

    public array $customFields;

    public string $entityType;

    public int $pipedriveId;

    public int $maxRetries;

    public int $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(
        Model $model,
        array $modifications,
        array $customFields,
        string $entityType,
        int $pipedriveId,
        ?string $queue = null,
        int $maxRetries = 3
    ) {
        $this->model = $model;
        $this->modifications = $modifications;
        $this->customFields = $customFields;
        $this->entityType = $entityType;
        $this->pipedriveId = $pipedriveId;
        $this->maxRetries = $maxRetries;

        if ($queue) {
            $this->onQueue($queue);
        }

        // Set retry attempts
        $this->tries = $maxRetries;
    }

    /**
     * Execute the job with enhanced robustness features.
     */
    public function handle(
        PipedriveRateLimitManager $rateLimitManager,
        PipedriveErrorHandler $errorHandler,
        PipedriveMemoryManager $memoryManager
    ): array {
        $startTime = Carbon::now();
        $startedAt = $startTime->toISOString();

        try {
            Log::info('Starting Pipedrive push job', [
                'model' => get_class($this->model),
                'model_id' => $this->model->getKey(),
                'entity_type' => $this->entityType,
                'pipedrive_id' => $this->pipedriveId,
                'modifications' => array_keys($this->modifications),
                'custom_fields' => array_keys($this->customFields),
                'attempt' => $this->attempts(),
                'job_id' => $this->job?->getJobId(),
            ]);

            // Monitor memory usage
            $memoryManager->monitorMemoryUsage("push_{$this->entityType}");

            // Check rate limits before making API call
            if (! $rateLimitManager->canMakeRequest($this->entityType)) {
                throw $rateLimitManager->handleRateLimitResponse([], $this->entityType);
            }

            // Get Pipedrive client
            $authService = app(PipedriveAuthService::class);
            $pipedrive = $authService->getPipedriveInstance();

            // Prepare data for Pipedrive API
            $updateData = $this->prepareDataForPipedrive();

            // Call Pipedrive API to update the entity with retry logic
            $response = $this->callPipedriveUpdateWithRetry(
                $pipedrive,
                $updateData,
                $rateLimitManager,
                $errorHandler
            );

            if (! $response || ! isset($response['success']) || ! $response['success']) {
                throw new \Exception('Pipedrive API update failed: '.($response['error'] ?? 'Unknown error'));
            }

            // Consume rate limit tokens on success
            $rateLimitManager->consumeTokens($this->entityType);

            // Update local database with the modifications
            $this->updateLocalEntity();

            // Emit success event
            $this->emitModelUpdated(
                $this->model,
                array_merge($this->modifications, $this->customFields),
                $this->extractModelChanges($this->model),
                'job',
                [
                    'job_class' => self::class,
                    'attempt' => $this->attempts(),
                    'queue' => $this->queue,
                    'job_id' => $this->job?->getJobId(),
                ]
            );

            $endTime = Carbon::now();
            $executionTime = $endTime->diffInSeconds($startTime, true);

            $result = [
                'success' => true,
                'pipedrive_id' => $this->pipedriveId,
                'entity_type' => $this->entityType,
                'updated_fields' => array_merge(array_keys($this->modifications), array_keys($this->customFields)),
                'response' => $response,
                'processed_via' => 'job',
                'attempt' => $this->attempts(),
                'execution_time' => $executionTime,
                'memory_stats' => $memoryManager->getMemoryStats(),
                'rate_limit_stats' => $rateLimitManager->getStatus(),
                'started_at' => $startedAt,
                'completed_at' => $endTime->toISOString(),
            ];

            Log::info('Successfully pushed modifications to Pipedrive via job', $result);

            // Record success for circuit breaker
            $errorHandler->recordSuccess('push');

            return $result;

        } catch (PipedriveException $e) {
            return $this->handlePipedriveException($e, $errorHandler, $startedAt);
        } catch (\Throwable $e) {
            return $this->handleGenericException($e, $errorHandler, $startedAt);
        }
    }

    /**
     * Handle Pipedrive-specific exceptions
     */
    protected function handlePipedriveException(
        PipedriveException $e,
        PipedriveErrorHandler $errorHandler,
        string $startedAt
    ): array {
        $errorHandler->recordFailure($e);

        $result = [
            'success' => false,
            'pipedrive_id' => $this->pipedriveId,
            'entity_type' => $this->entityType,
            'error' => $e->getMessage(),
            'error_type' => $e->getErrorType(),
            'retryable' => $e->isRetryable(),
            'attempt' => $this->attempts(),
            'max_attempts' => $this->tries,
            'job_id' => $this->job?->getJobId(),
            'started_at' => $startedAt,
            'failed_at' => Carbon::now()->toISOString(),
        ];

        Log::error('Pipedrive push job failed with Pipedrive exception', array_merge($result, [
            'exception_info' => $e->getErrorInfo(),
            'model' => get_class($this->model),
            'model_id' => $this->model->getKey(),
            'modifications' => $this->modifications,
            'custom_fields' => $this->customFields,
        ]));

        // Determine if job should be retried
        if ($e->isRetryable() && $errorHandler->shouldRetry($e, $this->attempts())) {
            $delay = $errorHandler->getRetryDelay($e, $this->attempts());

            Log::info('Retrying Pipedrive push job', [
                'entity_type' => $this->entityType,
                'pipedrive_id' => $this->pipedriveId,
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
    protected function handleGenericException(
        \Throwable $e,
        PipedriveErrorHandler $errorHandler,
        string $startedAt
    ): array {
        // Classify the exception
        $classified = $errorHandler->classifyException($e, [
            'operation' => 'push_job',
            'entity_type' => $this->entityType,
            'pipedrive_id' => $this->pipedriveId,
            'model' => get_class($this->model),
            'model_id' => $this->model->getKey(),
        ]);

        return $this->handlePipedriveException($classified, $errorHandler, $startedAt);
    }

    /**
     * Call Pipedrive API with retry logic
     */
    protected function callPipedriveUpdateWithRetry(
        $pipedrive,
        array $updateData,
        PipedriveRateLimitManager $rateLimitManager,
        PipedriveErrorHandler $errorHandler,
        int $maxRetries = 3
    ) {
        $attempt = 0;

        while ($attempt < $maxRetries) {
            $attempt++;

            try {
                // Apply rate limiting delay if needed
                if ($attempt > 1) {
                    $rateLimitManager->waitForRateLimit($attempt);
                }

                // Make the API call
                $response = $this->callPipedriveUpdate($pipedrive, $updateData);

                return $response;

            } catch (PipedriveException $e) {
                if (! $e->isRetryable() || $attempt >= $maxRetries) {
                    throw $e;
                }

                $delay = $errorHandler->getRetryDelay($e, $attempt);
                Log::warning('API call failed in push job, retrying', [
                    'entity_type' => $this->entityType,
                    'pipedrive_id' => $this->pipedriveId,
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                    'error' => $e->getMessage(),
                    'retry_delay' => $delay,
                ]);

                sleep($delay);
            }
        }

        throw new \Exception("Max retry attempts ({$maxRetries}) exceeded for push operation");
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Pipedrive push job failed permanently', [
            'model' => get_class($this->model),
            'model_id' => $this->model->getKey(),
            'entity_type' => $this->entityType,
            'pipedrive_id' => $this->pipedriveId,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
            'modifications' => $this->modifications,
            'custom_fields' => $this->customFields,
            'job_id' => $this->job?->getJobId(),
        ]);

        // Record failure for circuit breaker
        if ($exception instanceof PipedriveException) {
            $errorHandler = app(PipedriveErrorHandler::class);
            $errorHandler->recordFailure($exception);
        }

        // Emit failed event
        $this->emitPushFailed($exception);
    }

    /**
     * Emit push failed event
     */
    protected function emitPushFailed(\Throwable $exception): void
    {
        // This would emit a push failed event
        // Implementation depends on your event system
        Log::error('Push to Pipedrive failed permanently', [
            'model' => get_class($this->model),
            'model_id' => $this->model->getKey(),
            'entity_type' => $this->entityType,
            'pipedrive_id' => $this->pipedriveId,
            'exception' => [
                'class' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ],
        ]);
    }

    /**
     * Prepare data for Pipedrive API call
     */
    protected function prepareDataForPipedrive(): array
    {
        $updateData = [];

        // Add basic field modifications
        foreach ($this->modifications as $field => $value) {
            $updateData[$field] = $value;
        }

        // Add custom fields with proper key mapping
        if (! empty($this->customFields)) {
            foreach ($this->customFields as $fieldName => $value) {
                // Try to find the field by name first
                $field = PipedriveCustomField::where('entity_type', $this->entityType)
                    ->where('name', $fieldName)
                    ->active()
                    ->first();

                if (! $field) {
                    // Try to find by key if name doesn't work
                    $field = PipedriveCustomField::where('entity_type', $this->entityType)
                        ->where('key', $fieldName)
                        ->active()
                        ->first();
                }

                if ($field) {
                    // Use the Pipedrive key for the API call
                    $updateData[$field->key] = $this->formatCustomFieldValue($field, $value);
                } else {
                    Log::warning("Custom field not found for entity type {$this->entityType}", [
                        'field_name' => $fieldName,
                        'job_id' => $this->job->getJobId() ?? 'unknown',
                    ]);
                }
            }
        }

        return $updateData;
    }

    /**
     * Call Pipedrive API to update entity
     */
    protected function callPipedriveUpdate($pipedrive, array $updateData): ?array
    {
        try {
            $response = match ($this->entityType) {
                'deals' => $pipedrive->deals->update($this->pipedriveId, $updateData),
                'persons' => $pipedrive->persons->update($this->pipedriveId, $updateData),
                'organizations' => $pipedrive->organizations->update($this->pipedriveId, $updateData),
                'activities' => $pipedrive->activities->update($this->pipedriveId, $updateData),
                'products' => $pipedrive->products->update($this->pipedriveId, $updateData),
                'notes' => $pipedrive->notes->update($this->pipedriveId, $updateData),
                default => throw new \Exception("Unsupported entity type for update: {$this->entityType}")
            };

            // The devio/pipedrive SDK returns a Devio\Pipedrive\Http\Response
            // whose internal state lives in protected properties, so a naive
            // json_encode() yields an empty array. Use the public getters
            // instead so the calling code can rely on `success`/`data`.
            if ($response instanceof \Devio\Pipedrive\Http\Response) {
                $content = $response->getContent();
                $contentArray = $content ? json_decode(json_encode($content), true) : [];

                return [
                    'success' => $response->isSuccess(),
                    'status_code' => $response->getStatusCode(),
                    'data' => $contentArray['data'] ?? null,
                    'error' => $contentArray['error'] ?? null,
                    'error_info' => $contentArray['error_info'] ?? null,
                    'raw' => $contentArray,
                ];
            }

            if (is_object($response)) {
                $response = json_decode(json_encode($response), true);
            }

            return $response;

        } catch (\Throwable $e) {
            $detail = $e->getMessage();

            // Surface the actual Pipedrive API error body when the SDK throws
            // a Guzzle BadResponseException, otherwise we just see "Unknown error".
            if (method_exists($e, 'getResponse') && $e->getResponse() !== null) {
                $body = (string) $e->getResponse()->getBody();
                if ($body !== '') {
                    $detail .= ' | response: '.$body;
                }
            }

            Log::error("Pipedrive API update failed for {$this->entityType} {$this->pipedriveId} in job", [
                'error' => $detail,
                'update_data' => $updateData,
                'job_id' => $this->job?->getJobId() ?? 'unknown',
            ]);

            return [
                'success' => false,
                'error' => $detail,
            ];
        }
    }

    /**
     * Update local entity with modifications
     */
    protected function updateLocalEntity(): void
    {
        // Refresh the model to get latest data
        $this->model->refresh();

        // Update basic fields
        foreach ($this->modifications as $field => $value) {
            if (in_array($field, $this->model->getFillable())) {
                $this->model->$field = $value;
            }
        }

        // Update custom fields in pipedrive_data
        if (! empty($this->customFields)) {
            $pipedriveData = $this->model->pipedrive_data ?? [];

            foreach ($this->customFields as $fieldName => $value) {
                // Store both by name and by key for easier access
                $pipedriveData[$fieldName] = $value;

                // Also try to store by Pipedrive key if we can find it
                $field = PipedriveCustomField::where('entity_type', $this->entityType)
                    ->where('name', $fieldName)
                    ->active()
                    ->first();

                if ($field) {
                    $pipedriveData[$field->key] = $value;
                }
            }

            $this->model->pipedrive_data = $pipedriveData;
        }

        // Update the update timestamp
        if (method_exists($this->model, 'setPipedriveUpdateTime')) {
            $this->model->setPipedriveUpdateTime(now());
        } elseif (in_array('pipedrive_update_time', $this->model->getFillable())) {
            $this->model->pipedrive_update_time = now();
        }

        $this->model->save();
    }

    /**
     * Format custom field value for Pipedrive API
     */
    protected function formatCustomFieldValue(PipedriveCustomField $field, $value)
    {
        return match ($field->field_type) {
            'date' => $value instanceof \Carbon\Carbon ? $value->format('Y-m-d') : $value,
            'datetime' => $value instanceof \Carbon\Carbon ? $value->format('Y-m-d H:i:s') : $value,
            'monetary' => is_numeric($value) ? (float) $value : $value,
            'int' => is_numeric($value) ? (int) $value : $value,
            'double' => is_numeric($value) ? (float) $value : $value,
            'varchar', 'text' => (string) $value,
            'enum', 'set' => $value, // These should be option IDs
            default => $value,
        };
    }

    /**
     * Extract changes from Laravel model dirty attributes
     */
    protected function extractModelChanges(Model $model): array
    {
        $changes = [];
        $dirty = $model->getDirty();
        $original = $model->getOriginal();

        foreach ($dirty as $field => $newValue) {
            $changes[$field] = [
                'old' => $original[$field] ?? null,
                'new' => $newValue,
            ];
        }

        return $changes;
    }

    /**
     * Mark the job as failed and handle cleanup
     */
    protected function markAsFailed(\Exception $exception): void
    {
        // You could update a status field on the model here
        // Or create a failed job record for tracking

        Log::error('Pipedrive push job marked as permanently failed', [
            'model' => get_class($this->model),
            'model_id' => $this->model->getKey(),
            'exception' => $exception->getMessage(),
        ]);
    }

    /**
     * Get job tags for monitoring
     */
    public function tags(): array
    {
        return [
            'pipedrive',
            'push',
            $this->entityType,
            get_class($this->model),
        ];
    }

    /**
     * Get job display name
     */
    public function displayName(): string
    {
        return "Push to Pipedrive: {$this->entityType} #{$this->pipedriveId}";
    }

    /**
     * Get job timeout
     */
    public function retryUntil(): \DateTimeInterface
    {
        $timeout = config('pipedrive.jobs.timeout', 3600);

        return now()->toDateTimeImmutable()->modify('+'.$timeout.' seconds');
    }

    /**
     * Get unique job ID for deduplication
     */
    public function uniqueId(): string
    {
        return "push_pipedrive_{$this->entityType}_{$this->pipedriveId}_{$this->model->getKey()}";
    }
}
