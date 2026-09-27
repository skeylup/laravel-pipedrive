<?php

namespace Skeylup\LaravelPipedrive;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Skeylup\LaravelPipedrive\Commands\ClearPipedriveCacheCommand;
use Skeylup\LaravelPipedrive\Commands\ClearPipedriveOAuthTokenCommand;
use Skeylup\LaravelPipedrive\Commands\InstallPipedriveCommand;
use Skeylup\LaravelPipedrive\Commands\LaravelPipedriveCommand;
use Skeylup\LaravelPipedrive\Commands\ManagePipedriveEntityLinksCommand;
use Skeylup\LaravelPipedrive\Commands\ManagePipedriveWebhooksCommand;
use Skeylup\LaravelPipedrive\Commands\MigratePipedriveTokenCommand;
use Skeylup\LaravelPipedrive\Commands\ScheduledSyncPipedriveCommand;
use Skeylup\LaravelPipedrive\Commands\ShowPipedriveConfigCommand;
use Skeylup\LaravelPipedrive\Commands\SyncPipedriveCustomFieldsCommand;
use Skeylup\LaravelPipedrive\Commands\SyncPipedriveEntitiesCommand;
use Skeylup\LaravelPipedrive\Commands\TestPipedriveConnectionCommand;
use Skeylup\LaravelPipedrive\Contracts\PipedriveCacheInterface;
use Skeylup\LaravelPipedrive\Contracts\PipedriveTokenStorageInterface;
use Skeylup\LaravelPipedrive\Services\PersistentTokenStorage;
use Skeylup\LaravelPipedrive\Services\PipedriveAuthService;
use Skeylup\LaravelPipedrive\Services\PipedriveCacheService;
use Skeylup\LaravelPipedrive\Services\PipedriveCustomFieldDetectionService;
use Skeylup\LaravelPipedrive\Services\PipedriveCustomFieldService;
use Skeylup\LaravelPipedrive\Services\PipedriveEntityLinkService;
use Skeylup\LaravelPipedrive\Services\PipedriveErrorHandler;
use Skeylup\LaravelPipedrive\Services\PipedriveHealthChecker;
// Robustness Services
use Skeylup\LaravelPipedrive\Services\PipedriveMemoryManager;
use Skeylup\LaravelPipedrive\Services\PipedriveParsingService;
use Skeylup\LaravelPipedrive\Services\PipedriveQueryOptimizationService;
use Skeylup\LaravelPipedrive\Services\PipedriveRateLimitManager;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelPipedriveServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-pipedrive')
            ->hasConfigFile()
            ->hasRoutes(['webhooks', 'oauth'])
            ->hasViews()
            ->hasMigrations([
                'create_pipedrive_activities_table',
                'create_pipedrive_deals_table',
                'create_pipedrive_files_table',
                'create_pipedrive_notes_table',
                'create_pipedrive_organizations_table',
                'create_pipedrive_persons_table',
                'create_pipedrive_pipelines_table',
                'create_pipedrive_products_table',
                'create_pipedrive_stages_table',
                'create_pipedrive_users_table',
                'create_pipedrive_goals_table',
                'create_pipedrive_custom_fields_table',
                'create_pipedrive_entity_links_table',
                'create_pipedrive_oauth_tokens_table',
            ])
            ->hasCommands([
                LaravelPipedriveCommand::class,
                SyncPipedriveCustomFieldsCommand::class,
                SyncPipedriveEntitiesCommand::class,
                ScheduledSyncPipedriveCommand::class,
                TestPipedriveConnectionCommand::class,
                ManagePipedriveWebhooksCommand::class,
                ManagePipedriveEntityLinksCommand::class,
                ClearPipedriveCacheCommand::class,
                ClearPipedriveOAuthTokenCommand::class,
                MigratePipedriveTokenCommand::class,
                ShowPipedriveConfigCommand::class,
                InstallPipedriveCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        // Register existing services
        $this->app->singleton(PipedriveCustomFieldService::class);
        $this->app->singleton(PipedriveCustomFieldDetectionService::class);
        $this->app->scoped(PipedriveAuthService::class);
        $this->app->singleton(PipedriveEntityLinkService::class);
        $this->app->scoped(PipedriveCacheService::class);
        $this->app->singleton(PipedriveQueryOptimizationService::class);

        // Register entity configuration service
        $this->app->singleton(\Skeylup\LaravelPipedrive\Services\PipedriveEntityConfigService::class);

        // Register robustness services
        $this->registerRobustnessServices();

        // Bind the token storage interface to the persistent implementation
        $this->app->bind(PipedriveTokenStorageInterface::class, PersistentTokenStorage::class);

        // Bind the cache interface to the default implementation
        $this->app->bind(PipedriveCacheInterface::class, PipedriveCacheService::class);
    }

    public function packageBooted(): void
    {
        // Register scheduled sync if enabled
        $this->registerScheduledSync();

        // Register Pipedrive authorization gate
        $this->registerPipedriveGate();
    }

    /**
     * Register robustness services with proper configuration
     */
    protected function registerRobustnessServices(): void
    {
        // Rate Limit Manager
        $this->app->singleton(PipedriveRateLimitManager::class, function ($app) {
            return new PipedriveRateLimitManager(
                config('pipedrive.robustness.rate_limiting', [])
            );
        });

        // Error Handler
        $this->app->singleton(PipedriveErrorHandler::class, function ($app) {
            return new PipedriveErrorHandler(
                config('pipedrive.robustness.error_handling', [])
            );
        });

        // Memory Manager
        $this->app->singleton(PipedriveMemoryManager::class, function ($app) {
            return new PipedriveMemoryManager(
                config('pipedrive.robustness.memory_management', [])
            );
        });

        // Health Checker
        $this->app->singleton(PipedriveHealthChecker::class, function ($app) {
            return new PipedriveHealthChecker(
                $app->make(PipedriveAuthService::class),
                config('pipedrive.robustness.health_monitoring', [])
            );
        });

        // Parsing Service (depends on other robustness services)
        $this->app->singleton(PipedriveParsingService::class, function ($app) {
            return new PipedriveParsingService(
                $app->make(PipedriveAuthService::class),
                $app->make(PipedriveRateLimitManager::class),
                $app->make(PipedriveErrorHandler::class),
                $app->make(PipedriveMemoryManager::class),
                $app->make(PipedriveHealthChecker::class)
            );
        });
    }

    /**
     * Register the Pipedrive authorization gate.
     *
     * This gate determines who can access Pipedrive management interface in non-local environments.
     */
    protected function registerPipedriveGate(): void
    {
        Gate::define('viewPipedrive', function ($user = null) {
            // Allow access in local environment
            if (app()->environment('local')) {
                return true;
            }

            // If no user is authenticated, deny access
            if (! $user) {
                return false;
            }

            // Check if custom gate logic is defined in config
            $authorizedEmails = config('pipedrive.dashboard.authorized_emails', []);
            $authorizedUserIds = config('pipedrive.dashboard.authorized_user_ids', []);

            // Allow access if user email is in authorized list
            if (! empty($authorizedEmails) && in_array($user->email, $authorizedEmails)) {
                return true;
            }

            // Allow access if user ID is in authorized list
            if (! empty($authorizedUserIds) && in_array($user->id, $authorizedUserIds)) {
                return true;
            }

            // Check if custom callback is defined
            $customCallback = config('pipedrive.dashboard.authorization_callback');
            if ($customCallback && is_callable($customCallback)) {
                return call_user_func($customCallback, $user);
            }

            // Default: deny access in non-local environments
            return false;
        });
    }

    protected function registerScheduledSync(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->app->booted(function () {
            $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);

            // Check if scheduler is enabled
            if (config('pipedrive.sync.scheduler.enabled', false)) {
                $frequency = config('pipedrive.sync.scheduler.frequency_hours', 24);
                $time = config('pipedrive.sync.scheduler.time');

                $scheduledCommand = $schedule->command('pipedrive:scheduled-sync');

                if ($time) {
                    // Run at specific time daily
                    $scheduledCommand->dailyAt($time);
                } else {
                    // Run based on frequency
                    if ($frequency >= 24) {
                        $scheduledCommand->daily();
                    } elseif ($frequency >= 12) {
                        $scheduledCommand->twiceDaily();
                    } elseif ($frequency >= 6) {
                        $scheduledCommand->everySixHours();
                    } elseif ($frequency >= 3) {
                        $scheduledCommand->everyThreeHours();
                    } else {
                        $scheduledCommand->hourly();
                    }
                }

                $scheduledCommand
                    ->withoutOverlapping()
                    ->runInBackground()
                    ->onFailure(function () {
                        Log::error('Pipedrive scheduled sync failed');
                    })
                    ->onSuccess(function () {
                        Log::info('Pipedrive scheduled sync completed successfully');
                    });
            }

            // Check if custom fields scheduler is enabled
            if (config('pipedrive.sync.scheduler.custom_fields.enabled', true)) {
                $customFieldsFrequency = config('pipedrive.sync.scheduler.custom_fields.frequency_hours', 1);
                $customFieldsForce = config('pipedrive.sync.scheduler.custom_fields.force', true);

                // A bare flag: Schedule::command() compiles ['--force' => true] to --force='1', which the
                // value-less --force option rejects ("does not accept a value").
                $customFieldsCommand = $schedule->command('pipedrive:sync-custom-fields', $customFieldsForce ? ['--force'] : []);

                // Set frequency based on configuration
                if ($customFieldsFrequency >= 24) {
                    $customFieldsCommand->daily();
                } elseif ($customFieldsFrequency >= 12) {
                    $customFieldsCommand->twiceDaily();
                } elseif ($customFieldsFrequency >= 6) {
                    $customFieldsCommand->everySixHours();
                } elseif ($customFieldsFrequency >= 3) {
                    $customFieldsCommand->everyThreeHours();
                } elseif ($customFieldsFrequency >= 2) {
                    $customFieldsCommand->everyTwoHours();
                } else {
                    $customFieldsCommand->hourly();
                }

                $customFieldsCommand
                    ->withoutOverlapping()
                    ->runInBackground()
                    ->onFailure(function () {
                        Log::error('Pipedrive custom fields scheduled sync failed');
                    })
                    ->onSuccess(function () {
                        Log::info('Pipedrive custom fields scheduled sync completed successfully');
                    });
            }
        });
    }
}
