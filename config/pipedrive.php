<?php

// config for Skeylup/LaravelPipedrive
return [
    /*
    |--------------------------------------------------------------------------
    | Authentication Method
    |--------------------------------------------------------------------------
    |
    | Choose your authentication method: 'token' or 'oauth'
    |
    | - token: Use API token for authentication (simpler, for manual integrations)
    | - oauth: Use OAuth 2.0 for authentication (for public/private apps)
    |
    */
    'auth_method' => env('PIPEDRIVE_AUTH_METHOD', 'token'),

    /*
    |--------------------------------------------------------------------------
    | API Token Configuration
    |--------------------------------------------------------------------------
    |
    | Your Pipedrive API token. You can find it in your Pipedrive account
    | under Settings > Personal preferences > API
    |
    */
    'token' => env('PIPEDRIVE_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | OAuth Configuration
    |--------------------------------------------------------------------------
    |
    | OAuth 2.0 configuration for Pipedrive apps
    |
    */
    'oauth' => [
        'client_id' => env('PIPEDRIVE_CLIENT_ID'),
        'client_secret' => env('PIPEDRIVE_CLIENT_SECRET'),
        'redirect_url' => env('PIPEDRIVE_REDIRECT_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for data synchronization from Pipedrive API
    |
    */
    'sync' => [
        // Automatically sync custom fields on app boot
        'auto_sync' => env('PIPEDRIVE_AUTO_SYNC', false),

        // Enabled entities for synchronization
        // Can be configured via PIPEDRIVE_ENABLED_ENTITIES environment variable
        // Format: comma-separated list (e.g., "deals,activities,persons")
        // Leave empty or set to "all" to sync all available entities
        'enabled_entities' => array_filter(
            explode(',', env('PIPEDRIVE_ENABLED_ENTITIES', 'activities,deals,files,notes,organizations,persons,pipelines,products,stages,users')),
            fn ($entity) => ! empty(trim($entity))
        ),

        // Automatic scheduled synchronization
        'scheduler' => [
            // Enable/disable automatic scheduled sync
            'enabled' => env('PIPEDRIVE_SCHEDULER_ENABLED', false),

            // Sync frequency in hours (default: 24 hours)
            'frequency_hours' => env('PIPEDRIVE_SCHEDULER_FREQUENCY', 24),

            // Time of day to run sync (24-hour format, e.g., '02:00')
            // Leave null to run based on frequency from app start
            'time' => env('PIPEDRIVE_SCHEDULER_TIME', '02:00'),

            // Force sync (skip confirmations and overwrite existing data)
            'force' => env('PIPEDRIVE_SCHEDULER_FORCE', true),

            // Sync custom fields along with entities
            'sync_custom_fields' => env('PIPEDRIVE_SCHEDULER_SYNC_CUSTOM_FIELDS', true),

            // Record limit for scheduled sync (max 500, sorted by last modified)
            'limit' => env('PIPEDRIVE_SCHEDULER_LIMIT', 500),

            // Custom fields specific scheduler
            'custom_fields' => [
                // Enable/disable automatic custom fields sync (independent from main scheduler)
                'enabled' => env('PIPEDRIVE_CUSTOM_FIELDS_SCHEDULER_ENABLED', true),

                // Sync frequency in hours for custom fields (default: 1 hour)
                'frequency_hours' => env('PIPEDRIVE_CUSTOM_FIELDS_SCHEDULER_FREQUENCY', 1),

                // Force sync for custom fields
                'force' => env('PIPEDRIVE_CUSTOM_FIELDS_SCHEDULER_FORCE', true),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard Authorization
    |--------------------------------------------------------------------------
    |
    | Configure access control for Pipedrive management interface.
    | Similar to Laravel Telescope authorization system.
    |
    */
    'dashboard' => [
        // List of authorized email addresses
        'authorized_emails' => [
            // 'admin@example.com',
            // 'developer@example.com',
        ],

        // List of authorized user IDs
        'authorized_user_ids' => [
            // 1,
            // 2,
        ],

        // Custom authorization callback
        // You can define a custom callback in your AppServiceProvider
        // Example: 'authorization_callback' => fn($user) => $user->hasRole('admin'),
        'authorization_callback' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks Configuration
    |--------------------------------------------------------------------------
    |
    | Configure webhook handling for real-time data synchronization
    |
    */
    'webhooks' => [
        // Enable automatic data synchronization from webhooks
        'auto_sync' => env('PIPEDRIVE_WEBHOOKS_AUTO_SYNC', true),

        // Enable custom fields detection in webhooks
        'detect_custom_fields' => env('PIPEDRIVE_WEBHOOKS_DETECT_CUSTOM_FIELDS', true),

        // Webhook route configuration
        'route' => [
            'path' => env('PIPEDRIVE_WEBHOOK_PATH', 'pipedrive/webhook'),
            'name' => 'pipedrive.webhook',
            'middleware' => ['api'],
        ],

        // Security configuration
        'security' => [
            // HTTP Basic Authentication
            'basic_auth' => [
                'enabled' => env('PIPEDRIVE_WEBHOOK_BASIC_AUTH', false),
                'username' => env('PIPEDRIVE_WEBHOOK_USERNAME'),
                'password' => env('PIPEDRIVE_WEBHOOK_PASSWORD'),
            ],

            // IP Whitelist (Pipedrive IPs)
            'ip_whitelist' => [
                'enabled' => env('PIPEDRIVE_WEBHOOK_IP_WHITELIST', false),
                'ips' => [
                    // Add Pipedrive webhook IPs here
                    // Example: '185.166.142.0/24',
                ],
            ],

            // Custom signature verification
            'signature' => [
                'enabled' => env('PIPEDRIVE_WEBHOOK_SIGNATURE', false),
                'secret' => env('PIPEDRIVE_WEBHOOK_SECRET'),
                'header' => 'X-Pipedrive-Signature',
            ],
        ],

        // Logging configuration
        'logging' => [
            'enabled' => env('PIPEDRIVE_WEBHOOK_LOGGING', true),
            'channel' => env('PIPEDRIVE_WEBHOOK_LOG_CHANNEL', 'daily'),
            'level' => env('PIPEDRIVE_WEBHOOK_LOG_LEVEL', 'info'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Entity Merge Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how entity merges are handled when entities are merged in Pipedrive
    |
    */
    'merge' => [
        // Enable heuristic detection of merges based on webhook patterns
        'enable_heuristic_detection' => env('PIPEDRIVE_MERGE_HEURISTIC_DETECTION', true),

        // Time window in seconds to analyze webhook events for merge patterns
        'detection_window_seconds' => env('PIPEDRIVE_MERGE_DETECTION_WINDOW', 30),

        // AUTOMATIC MIGRATION: Enable automatic migration of pipedrive_entity_links table
        // When enabled, relationships are automatically migrated from merged entity to surviving entity
        // This ensures continuity of relationships without requiring custom code
        'auto_migrate_relations' => env('PIPEDRIVE_MERGE_AUTO_MIGRATE', true),

        // Strategy for handling relation conflicts when migrating entity links
        // Options: 'keep_both', 'keep_surviving', 'keep_merged'
        // - keep_both: Keep both relations (recommended)
        // - keep_surviving: Keep only the relation to the surviving entity
        // - keep_merged: Keep the migrated relation, remove the existing one
        'strategy' => env('PIPEDRIVE_MERGE_STRATEGY', 'keep_both'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Configure intelligent caching for Pipedrive data to improve performance.
    | Supports Redis and file-based caching with configurable TTL values.
    |
    */
    'cache' => [
        // Enable/disable caching
        'enabled' => env('PIPEDRIVE_CACHE_ENABLED', true),

        // Cache driver (redis, file, etc.)
        'driver' => env('PIPEDRIVE_CACHE_DRIVER', 'redis'),

        // Time-to-live (TTL) values in seconds for different data types
        'ttl' => [
            // Custom fields cache (1 hour default)
            'custom_fields' => env('PIPEDRIVE_CACHE_CUSTOM_FIELDS_TTL', 3600),

            // Pipelines cache (2 hours default)
            'pipelines' => env('PIPEDRIVE_CACHE_PIPELINES_TTL', 7200),

            // Stages cache (2 hours default)
            'stages' => env('PIPEDRIVE_CACHE_STAGES_TTL', 7200),

            // Users cache (30 minutes default)
            'users' => env('PIPEDRIVE_CACHE_USERS_TTL', 1800),
        ],

        // Automatically refresh cache when data is stale
        'auto_refresh' => env('PIPEDRIVE_CACHE_AUTO_REFRESH', true),

        // Cache key prefix
        'prefix' => env('PIPEDRIVE_CACHE_PREFIX', 'pipedrive'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Robustness Configuration
    |--------------------------------------------------------------------------
    |
    | Advanced robustness features including rate limiting, error handling,
    | memory management, health monitoring, and alerting
    |
    */
    'robustness' => [

        // Rate limiting configuration
        'rate_limiting' => [
            'enabled' => env('PIPEDRIVE_RATE_LIMITING_ENABLED', true),
            'daily_budget' => env('PIPEDRIVE_DAILY_TOKEN_BUDGET', 10000),
            'max_delay' => env('PIPEDRIVE_RATE_LIMIT_MAX_DELAY', 16),
            'jitter_enabled' => env('PIPEDRIVE_RATE_LIMIT_JITTER', true),
            'token_costs' => [
                'activities' => 1,
                'deals' => 1,
                'files' => 2,
                'goals' => 1,
                'notes' => 1,
                'organizations' => 1,
                'persons' => 1,
                'pipelines' => 1,
                'products' => 1,
                'stages' => 1,
                'users' => 1,
                'custom_fields' => 1,
                'webhooks' => 1,
            ],
        ],

        // Error handling configuration
        'error_handling' => [
            'max_retry_attempts' => env('PIPEDRIVE_MAX_RETRY_ATTEMPTS', 3),
            'circuit_breaker_threshold' => env('PIPEDRIVE_CIRCUIT_BREAKER_THRESHOLD', 5),
            'circuit_breaker_timeout' => env('PIPEDRIVE_CIRCUIT_BREAKER_TIMEOUT', 300),
            'request_timeout' => env('PIPEDRIVE_REQUEST_TIMEOUT', 30),
        ],

        // Memory management configuration
        'memory_management' => [
            'adaptive_pagination' => env('PIPEDRIVE_ADAPTIVE_PAGINATION', true),
            'memory_threshold_percent' => env('PIPEDRIVE_MEMORY_THRESHOLD', 80),
            'min_batch_size' => env('PIPEDRIVE_MIN_BATCH_SIZE', 10),
            'max_batch_size' => env('PIPEDRIVE_MAX_BATCH_SIZE', 500),
            'force_gc' => env('PIPEDRIVE_FORCE_GC', true),
            'alert_threshold_percent' => env('PIPEDRIVE_MEMORY_ALERT_THRESHOLD', 85),
            'critical_threshold_percent' => env('PIPEDRIVE_MEMORY_CRITICAL_THRESHOLD', 95),
        ],

        // Health monitoring configuration
        'health_monitoring' => [
            'enabled' => env('PIPEDRIVE_HEALTH_MONITORING_ENABLED', true),
            'check_interval' => env('PIPEDRIVE_HEALTH_CHECK_INTERVAL', 300),
            'health_endpoint' => env('PIPEDRIVE_HEALTH_ENDPOINT', 'currencies'),
            'timeout' => env('PIPEDRIVE_HEALTH_CHECK_TIMEOUT', 10),
            'failure_threshold' => env('PIPEDRIVE_HEALTH_FAILURE_THRESHOLD', 3),
            'degradation_threshold' => env('PIPEDRIVE_HEALTH_DEGRADATION_THRESHOLD', 1000),
            'cache_ttl' => env('PIPEDRIVE_HEALTH_CACHE_TTL', 60),
        ],

        // Performance monitoring
        'monitoring' => [
            'enabled' => env('PIPEDRIVE_MONITORING_ENABLED', true),
            'performance_logging' => env('PIPEDRIVE_PERFORMANCE_LOGGING', true),
            'failure_rate_threshold' => env('PIPEDRIVE_FAILURE_RATE_THRESHOLD', 10),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Jobs Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for background job processing
    |
    */
    'jobs' => [
        'sync_queue' => env('PIPEDRIVE_SYNC_QUEUE', 'default'),
        'webhook_queue' => env('PIPEDRIVE_WEBHOOK_QUEUE', 'default'),
        'retry_queue' => env('PIPEDRIVE_RETRY_QUEUE', 'default'),
        'timeout' => env('PIPEDRIVE_JOB_TIMEOUT', 3600),
        'max_tries' => env('PIPEDRIVE_JOB_MAX_TRIES', 3),
        'prefer_async' => env('PIPEDRIVE_PREFER_ASYNC', false),
        'batch_processing' => env('PIPEDRIVE_BATCH_PROCESSING', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Alerting Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for monitoring alerts and notifications
    |
    */
    'alerting' => [
        'enabled' => env('PIPEDRIVE_ALERTING_ENABLED', false),
        'channels' => [
            'mail' => [
                'enabled' => env('PIPEDRIVE_ALERT_MAIL_ENABLED', false),
                'to' => env('PIPEDRIVE_ALERT_MAIL_TO'),
                'from' => env('PIPEDRIVE_ALERT_MAIL_FROM'),
            ],
            'slack' => [
                'enabled' => env('PIPEDRIVE_ALERT_SLACK_ENABLED', false),
                'webhook_url' => env('PIPEDRIVE_ALERT_SLACK_WEBHOOK'),
                'channel' => env('PIPEDRIVE_ALERT_SLACK_CHANNEL', '#alerts'),
            ],
            'log' => [
                'enabled' => env('PIPEDRIVE_ALERT_LOG_ENABLED', true),
                'level' => env('PIPEDRIVE_ALERT_LOG_LEVEL', 'error'),
            ],
        ],
        'conditions' => [
            'circuit_breaker_open' => true,
            'high_failure_rate' => true,
            'memory_threshold' => true,
            'rate_limit_exhaustion' => true,
            'health_check_failure' => true,
        ],
    ],
];
