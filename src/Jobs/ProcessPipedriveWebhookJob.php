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
use Skeylup\LaravelPipedrive\Services\PipedriveCustomFieldDetectionService;
use Skeylup\LaravelPipedrive\Services\PipedriveErrorHandler;
use Skeylup\LaravelPipedrive\Services\PipedriveParsingService;
use Skeylup\LaravelPipedrive\Traits\EmitsPipedriveEvents;

/**
 * Webhook processing job with retry mechanism and dead letter queue support
 *
 * Processes Pipedrive webhook events with robust error handling and retry logic
 */
class ProcessPipedriveWebhookJob implements ShouldQueue
{
    use Dispatchable, EmitsPipedriveEvents, InteractsWithQueue, Queueable, SerializesModels;

    public array $webhookData;

    public string $eventType;

    public string $entityType;

    public int $entityId;

    public array $metadata;

    public int $tries = 5;

    public int $timeout = 300;

    public int $maxExceptions = 3;

    public function __construct(
        array $webhookData,
        string $eventType,
        string $entityType,
        int $entityId,
        array $metadata = []
    ) {
        $this->webhookData = $webhookData;
        $this->eventType = $eventType;
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->metadata = $metadata;

        // Set queue based on configuration
        $queue = config('pipedrive.jobs.webhook_queue', 'default');
        $this->onQueue($queue);

        // Override tries and timeout from config
        $this->tries = config('pipedrive.jobs.max_tries', 5);
        $this->timeout = config('pipedrive.jobs.timeout', 300);
    }

    /**
     * Execute the webhook processing job
     */
    public function handle(
        PipedriveParsingService $parsingService,
        PipedriveErrorHandler $errorHandler,
        PipedriveCustomFieldDetectionService $customFieldDetectionService
    ): void {
        $startTime = Carbon::now();
        $startedAt = $startTime->toISOString();

        try {
            Log::info('Processing Pipedrive webhook', [
                'event_type' => $this->eventType,
                'entity_type' => $this->entityType,
                'entity_id' => $this->entityId,
                'job_id' => $this->job?->getJobId(),
                'attempt' => $this->attempts(),
                'webhook_data' => $this->webhookData,
                'metadata' => $this->metadata,
            ]);

            // Validate webhook data
            $this->validateWebhookData();

            // Process based on event type
            $result = match ($this->eventType) {
                'added' => $this->processAddedEvent($parsingService),
                'updated' => $this->processUpdatedEvent($parsingService),
                'deleted' => $this->processDeletedEvent($parsingService),
                'merged' => $this->processMergedEvent($parsingService),
                default => $this->processGenericEvent($parsingService)
            };

            // Detect custom field changes if enabled
            $this->detectCustomFieldChanges($customFieldDetectionService);

            // Emit webhook processed event
            $this->emitWebhookProcessed($result);

            Log::info('Webhook processed successfully', [
                'event_type' => $this->eventType,
                'entity_type' => $this->entityType,
                'entity_id' => $this->entityId,
                'result' => $result->getSummary(),
                'execution_time' => Carbon::now()->diffInSeconds($startTime, true),
            ]);

            // Record success for circuit breaker
            $errorHandler->recordSuccess('webhook');

        } catch (PipedriveException $e) {
            $this->handlePipedriveException($e, $errorHandler, $startedAt);
        } catch (\Throwable $e) {
            $this->handleGenericException($e, $errorHandler, $startedAt);
        }
    }

    /**
     * Validate webhook data structure
     */
    protected function validateWebhookData(): void
    {
        if (empty($this->webhookData)) {
            throw new \InvalidArgumentException('Webhook data is empty');
        }

        if (! isset($this->webhookData['current'])) {
            throw new \InvalidArgumentException('Webhook data missing current object');
        }

        if (! isset($this->webhookData['current']['id'])) {
            throw new \InvalidArgumentException('Webhook data missing entity ID');
        }

        // Validate entity ID matches
        if ((int) $this->webhookData['current']['id'] !== $this->entityId) {
            throw new \InvalidArgumentException('Entity ID mismatch in webhook data');
        }
    }

    /**
     * Process added event
     */
    protected function processAddedEvent(PipedriveParsingService $parsingService): SyncResult
    {
        $options = SyncOptions::forWebhook($this->entityType, $this->webhookData);

        // For added events, we sync the new entity
        $data = [$this->webhookData['current']];

        $processingResult = $parsingService->processEntityData(
            $this->entityType,
            $data,
            [
                'force' => true,
                'context' => 'webhook_added',
                'verbose' => false,
            ]
        );

        return SyncResult::fromProcessingData(
            $this->entityType,
            $processingResult,
            0.0,
            [
                'event_type' => $this->eventType,
                'entity_id' => $this->entityId,
                'webhook_data' => $this->webhookData,
            ]
        );
    }

    /**
     * Process updated event
     */
    protected function processUpdatedEvent(PipedriveParsingService $parsingService): SyncResult
    {
        $options = SyncOptions::forWebhook($this->entityType, $this->webhookData);

        // For updated events, we sync the current state
        $data = [$this->webhookData['current']];

        $processingResult = $parsingService->processEntityData(
            $this->entityType,
            $data,
            [
                'force' => true,
                'context' => 'webhook_updated',
                'verbose' => false,
            ]
        );

        // Emit specific update event with previous data
        if (isset($this->webhookData['previous'])) {
            $this->emitWebhookUpdated(
                $this->webhookData['current'],
                $this->webhookData['previous']
            );
        }

        return SyncResult::fromProcessingData(
            $this->entityType,
            $processingResult,
            0.0,
            [
                'event_type' => $this->eventType,
                'entity_id' => $this->entityId,
                'webhook_data' => $this->webhookData,
                'changes' => $this->extractChanges(),
            ]
        );
    }

    /**
     * Process deleted event
     */
    protected function processDeletedEvent(PipedriveParsingService $parsingService): SyncResult
    {
        // For deleted events, we need to handle the deletion in our local database
        $modelClass = $this->getModelClass();

        if ($modelClass) {
            $record = $modelClass::where('pipedrive_id', $this->entityId)->first();

            if ($record) {
                // Soft delete or mark as deleted
                if (method_exists($record, 'delete')) {
                    $record->delete();

                    // Emit deleted event
                    $this->emitModelDeleted($record, $this->webhookData['previous'] ?? [], 'webhook', [
                        'entity_type' => $this->entityType,
                        'webhook_event' => $this->eventType,
                    ]);
                }
            }
        }

        return SyncResult::success(
            $this->entityType,
            0, 0, 0, 0,
            [
                'event_type' => $this->eventType,
                'entity_id' => $this->entityId,
                'action' => 'deleted',
                'webhook_data' => $this->webhookData,
            ]
        );
    }

    /**
     * Process merged event
     */
    protected function processMergedEvent(PipedriveParsingService $parsingService): SyncResult
    {
        // Handle entity merging - update the surviving entity and handle the merged one
        $survivingId = $this->webhookData['current']['id'] ?? null;
        $mergedId = $this->webhookData['previous']['id'] ?? null;

        if ($survivingId && $mergedId) {
            // Process the surviving entity
            $result = $this->processUpdatedEvent($parsingService);

            // Handle the merged entity (mark as deleted or update references)
            $this->handleMergedEntity($mergedId, $survivingId);

            // Add merge information to metadata
            $result->metadata = array_merge($result->metadata, [
                'merged_from_id' => $mergedId,
                'surviving_id' => $survivingId,
            ]);

            return $result;
        }

        return SyncResult::failure(
            $this->entityType,
            'Invalid merge event data',
            null,
            ['webhook_data' => $this->webhookData]
        );
    }

    /**
     * Process generic event
     */
    protected function processGenericEvent(PipedriveParsingService $parsingService): SyncResult
    {
        Log::warning('Processing unknown webhook event type', [
            'event_type' => $this->eventType,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
        ]);

        // Default to processing as an update
        return $this->processUpdatedEvent($parsingService);
    }

    /**
     * Handle Pipedrive exception
     */
    protected function handlePipedriveException(
        PipedriveException $e,
        PipedriveErrorHandler $errorHandler,
        string $startedAt
    ): void {
        $errorHandler->recordFailure($e);

        Log::error('Webhook processing failed with Pipedrive exception', [
            'event_type' => $this->eventType,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'job_id' => $this->job?->getJobId(),
            'attempt' => $this->attempts(),
            'error' => $e->getErrorInfo(),
        ]);

        // Determine if job should be retried
        if ($e->isRetryable() && $errorHandler->shouldRetry($e, $this->attempts())) {
            $delay = $errorHandler->getRetryDelay($e, $this->attempts());

            Log::info('Retrying webhook processing job', [
                'event_type' => $this->eventType,
                'entity_type' => $this->entityType,
                'entity_id' => $this->entityId,
                'attempt' => $this->attempts(),
                'max_attempts' => $this->tries,
                'retry_delay' => $delay,
            ]);

            $this->release($delay);

            return;
        }

        // Job failed permanently
        $this->fail($e);
    }

    /**
     * Handle generic exception
     */
    protected function handleGenericException(
        \Throwable $e,
        PipedriveErrorHandler $errorHandler,
        string $startedAt
    ): void {
        // Classify the exception
        $classified = $errorHandler->classifyException($e, [
            'operation' => 'webhook_processing',
            'event_type' => $this->eventType,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
        ]);

        $this->handlePipedriveException($classified, $errorHandler, $startedAt);
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Webhook processing job failed permanently', [
            'event_type' => $this->eventType,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'job_id' => $this->job?->getJobId(),
            'attempts' => $this->attempts(),
            'exception' => [
                'class' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ],
            'webhook_data' => $this->webhookData,
        ]);

        // Emit webhook failed event
        $this->emitWebhookFailed($exception);

        // Move to dead letter queue if configured
        $this->moveToDeadLetterQueue();
    }

    /**
     * Get model class for entity type
     */
    protected function getModelClass(): ?string
    {
        $entityModelMap = [
            'activities' => \Skeylup\LaravelPipedrive\Models\PipedriveActivity::class,
            'deals' => \Skeylup\LaravelPipedrive\Models\PipedriveDeal::class,
            'files' => \Skeylup\LaravelPipedrive\Models\PipedriveFile::class,
            'goals' => \Skeylup\LaravelPipedrive\Models\PipedriveGoal::class,
            'notes' => \Skeylup\LaravelPipedrive\Models\PipedriveNote::class,
            'organizations' => \Skeylup\LaravelPipedrive\Models\PipedriveOrganization::class,
            'persons' => \Skeylup\LaravelPipedrive\Models\PipedrivePerson::class,
            'pipelines' => \Skeylup\LaravelPipedrive\Models\PipedrivePipeline::class,
            'products' => \Skeylup\LaravelPipedrive\Models\PipedriveProduct::class,
            'stages' => \Skeylup\LaravelPipedrive\Models\PipedriveStage::class,
            'users' => \Skeylup\LaravelPipedrive\Models\PipedriveUser::class,
        ];

        return $entityModelMap[$this->entityType] ?? null;
    }

    /**
     * Extract changes from webhook data
     */
    protected function extractChanges(): array
    {
        if (! isset($this->webhookData['previous']) || ! isset($this->webhookData['current'])) {
            return [];
        }

        $previous = $this->webhookData['previous'];
        $current = $this->webhookData['current'];
        $changes = [];

        foreach ($current as $key => $value) {
            if (! isset($previous[$key]) || $previous[$key] !== $value) {
                $changes[$key] = [
                    'old' => $previous[$key] ?? null,
                    'new' => $value,
                ];
            }
        }

        return $changes;
    }

    /**
     * Handle merged entity
     */
    protected function handleMergedEntity(int $mergedId, int $survivingId): void
    {
        $modelClass = $this->getModelClass();

        if ($modelClass) {
            // Get the surviving entity
            $survivingRecord = $modelClass::where('pipedrive_id', $survivingId)->first();

            // Get the merged entity (to be deleted)
            $mergedRecord = $modelClass::where('pipedrive_id', $mergedId)->first();

            // Initialize migration results
            $migrationResults = [
                'migrated' => 0,
                'skipped' => 0,
                'conflicts' => 0,
                'errors' => 0,
                'auto_migration_enabled' => false,
            ];

            // Check if automatic migration is enabled
            if (config('pipedrive.merge.auto_migrate_relations', true)) {
                // Migrate entity relations in the pivot table automatically
                $migrationStrategy = config('pipedrive.merge.strategy', 'keep_both');
                $migrationResults = \Skeylup\LaravelPipedrive\Models\PipedriveEntityLink::migrateEntityRelations(
                    $this->entityType,
                    $mergedId,
                    $survivingId,
                    $migrationStrategy
                );
                $migrationResults['auto_migration_enabled'] = true;

                Log::info('Automatic relation migration completed', [
                    'entity_type' => $this->entityType,
                    'merged_id' => $mergedId,
                    'surviving_id' => $survivingId,
                    'migration_results' => $migrationResults,
                ]);
            } else {
                Log::info('Automatic relation migration disabled', [
                    'entity_type' => $this->entityType,
                    'merged_id' => $mergedId,
                    'surviving_id' => $survivingId,
                    'note' => 'Set PIPEDRIVE_MERGE_AUTO_MIGRATE=true to enable automatic migration',
                ]);
            }

            // If the merged record exists, delete it
            if ($mergedRecord) {
                // Delete the merged record
                $mergedRecord->delete();
            }

            // Emit the merged event (always emitted, regardless of auto-migration setting)
            $this->emitEntityMerged(
                $this->entityType,
                $mergedId,
                $survivingId,
                $survivingRecord,
                $this->webhookData['previous'] ?? [],
                'webhook',
                [
                    'webhook_action' => 'merged',
                    'change_source' => $this->metadata['change_source'] ?? null,
                    'user_id' => $this->metadata['user_id'] ?? null,
                    'company_id' => $this->metadata['company_id'] ?? null,
                    'migration_results' => $migrationResults,
                    'auto_migration_enabled' => $migrationResults['auto_migration_enabled'],
                ],
                $migrationResults['migrated'] ?? 0
            );

            Log::info('Handled merged entity', [
                'entity_type' => $this->entityType,
                'merged_id' => $mergedId,
                'surviving_id' => $survivingId,
                'migration_results' => $migrationResults,
            ]);
        }
    }

    /**
     * Move job to dead letter queue
     */
    protected function moveToDeadLetterQueue(): void
    {
        $deadLetterQueue = config('pipedrive.jobs.retry_queue', 'default');

        // Create a new job for the dead letter queue with the original data
        $deadLetterJob = new static(
            $this->webhookData,
            $this->eventType,
            $this->entityType,
            $this->entityId,
            array_merge($this->metadata, ['dead_letter' => true])
        );

        dispatch($deadLetterJob)->onQueue($deadLetterQueue);

        Log::info('Moved webhook job to dead letter queue', [
            'event_type' => $this->eventType,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'dead_letter_queue' => $deadLetterQueue,
        ]);
    }

    /**
     * Emit webhook processed event
     */
    protected function emitWebhookProcessed(SyncResult $result): void
    {
        $this->emitWebhookReceived($this->webhookData, $this->eventType, [
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'processing_result' => $result->getSummary(),
        ]);
    }

    /**
     * Emit webhook updated event
     */
    protected function emitWebhookUpdated(array $current, array $previous): void
    {
        // This would emit a specific webhook updated event
        // Implementation depends on your event system
    }

    /**
     * Emit webhook failed event
     */
    protected function emitWebhookFailed(\Throwable $exception): void
    {
        // This would emit a webhook failed event
        // Implementation depends on your event system
    }

    /**
     * Get job tags for monitoring
     */
    public function tags(): array
    {
        return [
            'pipedrive',
            'webhook',
            $this->entityType,
            $this->eventType,
        ];
    }

    /**
     * Detect custom field changes and trigger sync if needed
     */
    protected function detectCustomFieldChanges(PipedriveCustomFieldDetectionService $detectionService): void
    {
        // Skip if custom field detection is disabled
        if (! $detectionService->isEnabled()) {
            return;
        }

        // Skip for unsupported entity types
        $entityType = $detectionService->getEntityTypeFromWebhookObject($this->entityType);
        if (! $entityType) {
            return;
        }

        // Skip for delete events (no current data to analyze)
        if ($this->eventType === 'deleted') {
            return;
        }

        try {
            $currentData = $this->webhookData['current'] ?? [];
            $previousData = $this->webhookData['previous'] ?? null;

            if (empty($currentData)) {
                return;
            }

            $detectionResult = $detectionService->detectAndSyncCustomFields(
                $entityType,
                $currentData,
                $previousData,
                $this->eventType
            );

            if ($detectionResult['detected_changes']) {
                Log::info('Custom field changes detected in webhook', [
                    'entity_type' => $entityType,
                    'webhook_entity_type' => $this->entityType,
                    'event_type' => $this->eventType,
                    'entity_id' => $this->entityId,
                    'detection_result' => $detectionResult,
                ]);
            }

        } catch (\Exception $e) {
            // Log error but don't fail the webhook processing
            Log::error('Error during custom field detection in webhook', [
                'entity_type' => $entityType,
                'webhook_entity_type' => $this->entityType,
                'event_type' => $this->eventType,
                'entity_id' => $this->entityId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get job display name
     */
    public function displayName(): string
    {
        return "Process Pipedrive Webhook: {$this->eventType} {$this->entityType} #{$this->entityId}";
    }
}
