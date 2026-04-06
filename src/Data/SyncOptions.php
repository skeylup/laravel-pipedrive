<?php

namespace Skeylup\LaravelPipedrive\Data;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;

/**
 * Data Transfer Object for sync configuration options
 *
 * Standardizes sync parameters across jobs and commands
 */
class SyncOptions extends Data
{
    public function __construct(
        #[Required]
        #[In(['activities', 'deals', 'files', 'goals', 'notes', 'organizations', 'persons', 'pipelines', 'products', 'stages', 'users', 'custom_fields'])]
        public string $entityType,

        #[Min(1)]
        #[Max(500)]
        public int $limit = 500,

        public bool $fullData = false,
        public bool $force = false,
        public bool $verbose = false,
        public bool $async = false,

        public ?string $queue = null,
        public int $timeout = 3600,
        public int $maxRetries = 3,

        // Memory management options
        public bool $adaptivePagination = true,
        public int $memoryThreshold = 80,
        public int $minBatchSize = 10,
        public int $maxBatchSize = 500,

        // Rate limiting options
        public bool $rateLimitingEnabled = true,
        public int $rateLimitDelay = 300, // milliseconds
        public int $maxRateLimitRetries = 5,

        // Error handling options
        public bool $circuitBreakerEnabled = true,
        public int $circuitBreakerThreshold = 5,
        public int $circuitBreakerTimeout = 300,

        // Health checking options
        public bool $healthCheckEnabled = true,
        public bool $skipHealthCheck = false,

        // Context information
        public string $context = 'sync',
        public array $metadata = [],

        // Progress tracking
        public bool $trackProgress = false,
        public ?string $progressCallback = null,

        // Event emission
        public bool $emitEvents = true,
        public array $eventContext = [],
    ) {}

    /**
     * Create options for command execution
     */
    public static function forCommand(
        string $entityType,
        int $limit = 500,
        bool $fullData = false,
        bool $force = false,
        bool $verbose = false
    ): self {
        return new self(
            entityType: $entityType,
            limit: $limit,
            fullData: $fullData,
            force: $force,
            verbose: $verbose,
            async: false,
            context: 'command',
            trackProgress: $verbose,
            emitEvents: true
        );
    }

    /**
     * Create options for scheduled execution
     * Note: fullData is ALWAYS false for scheduled operations for safety and performance
     */
    public static function forScheduler(
        string $entityType,
        bool $force = true,
        ?string $queue = null
    ): self {
        return new self(
            entityType: $entityType,
            limit: 500,
            fullData: false, // ALWAYS false for scheduled operations
            force: $force,
            verbose: false,
            async: true,
            queue: $queue ?? config('pipedrive.jobs.sync_queue', 'default'),
            context: 'scheduler',
            trackProgress: false,
            emitEvents: true
        );
    }

    /**
     * Create options for job execution
     */
    public static function forJob(
        string $entityType,
        array $options = []
    ): self {
        $defaults = [
            'limit' => 500,
            'fullData' => false,
            'force' => false,
            'verbose' => false,
            'async' => true,
            'queue' => config('pipedrive.jobs.sync_queue', 'default'),
            'context' => 'job',
            'trackProgress' => true,
            'emitEvents' => true,
        ];

        $merged = array_merge($defaults, $options, ['entityType' => $entityType]);

        return new self(...$merged);
    }

    /**
     * Create options for webhook processing
     */
    public static function forWebhook(
        string $entityType,
        array $webhookData = []
    ): self {
        return new self(
            entityType: $entityType,
            limit: 1,
            fullData: false,
            force: true,
            verbose: false,
            async: true,
            queue: config('pipedrive.jobs.webhook_queue', 'default'),
            context: 'webhook',
            metadata: ['webhook_data' => $webhookData],
            trackProgress: false,
            emitEvents: true,
            eventContext: ['source' => 'webhook']
        );
    }

    /**
     * Create options for testing
     */
    public static function forTesting(
        string $entityType,
        array $overrides = []
    ): self {
        $defaults = [
            'limit' => 10,
            'fullData' => false,
            'force' => true,
            'verbose' => true,
            'async' => false,
            'context' => 'test',
            'rateLimitingEnabled' => false,
            'circuitBreakerEnabled' => false,
            'healthCheckEnabled' => false,
            'trackProgress' => true,
            'emitEvents' => false,
        ];

        $merged = array_merge($defaults, $overrides, ['entityType' => $entityType]);

        return new self(...$merged);
    }

    /**
     * Check if this is a full data sync
     */
    public function isFullDataSync(): bool
    {
        return $this->fullData;
    }

    /**
     * Check if this is an async operation
     */
    public function isAsync(): bool
    {
        return $this->async;
    }

    /**
     * Check if verbose output is enabled
     */
    public function isVerbose(): bool
    {
        return $this->verbose;
    }

    /**
     * Check if force mode is enabled
     */
    public function isForceMode(): bool
    {
        return $this->force;
    }

    /**
     * Get queue name for async operations
     */
    public function getQueue(): string
    {
        return $this->queue ?? 'default';
    }

    /**
     * Get effective batch size based on memory settings
     */
    public function getEffectiveBatchSize(): int
    {
        if (! $this->adaptivePagination) {
            return $this->limit;
        }

        return min($this->limit, $this->maxBatchSize);
    }

    /**
     * Get timeout in seconds
     */
    public function getTimeoutSeconds(): int
    {
        return $this->timeout;
    }

    /**
     * Get context with metadata
     */
    public function getContextWithMetadata(): array
    {
        return array_merge([
            'context' => $this->context,
            'entity_type' => $this->entityType,
            'full_data' => $this->fullData,
            'force' => $this->force,
            'async' => $this->async,
        ], $this->metadata);
    }

    /**
     * Get event context
     */
    public function getEventContext(): array
    {
        return array_merge([
            'entity_type' => $this->entityType,
            'context' => $this->context,
            'full_data' => $this->fullData,
            'force' => $this->force,
        ], $this->eventContext);
    }

    /**
     * Create a copy with modified options
     */
    public function withChanges(array $changes): self
    {
        $data = $this->toArray();
        $merged = array_merge($data, $changes);

        return new self(...$merged);
    }

    /**
     * Validate options
     */
    public function validateOptions(): array
    {
        $errors = [];

        if ($this->limit < 1 || $this->limit > 500) {
            $errors[] = 'Limit must be between 1 and 500';
        }

        if ($this->memoryThreshold < 50 || $this->memoryThreshold > 95) {
            $errors[] = 'Memory threshold must be between 50 and 95 percent';
        }

        if ($this->minBatchSize < 1 || $this->minBatchSize > $this->maxBatchSize) {
            $errors[] = 'Min batch size must be at least 1 and not greater than max batch size';
        }

        if ($this->maxBatchSize < $this->minBatchSize || $this->maxBatchSize > 500) {
            $errors[] = 'Max batch size must be at least min batch size and not greater than 500';
        }

        if ($this->timeout < 60) {
            $errors[] = 'Timeout must be at least 60 seconds';
        }

        if ($this->maxRetries < 1 || $this->maxRetries > 10) {
            $errors[] = 'Max retries must be between 1 and 10';
        }

        return $errors;
    }

    /**
     * Check if options are valid
     */
    public function isValid(): bool
    {
        return empty($this->validateOptions());
    }
}
