<?php

namespace Skeylup\LaravelPipedrive\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Skeylup\LaravelPipedrive\Jobs\SyncPipedriveCustomFieldsJob;

class PipedriveCustomFieldDetectionService
{
    protected PipedriveCustomFieldService $customFieldService;

    public function __construct(PipedriveCustomFieldService $customFieldService)
    {
        $this->customFieldService = $customFieldService;
    }

    /**
     * Detect if custom fields have changed in webhook data and trigger sync if needed
     */
    public function detectAndSyncCustomFields(
        string $entityType,
        array $currentData,
        ?array $previousData = null,
        string $eventType = 'updated'
    ): array {
        $result = [
            'detected_changes' => false,
            'sync_triggered' => false,
            'new_fields' => [],
            'changed_fields' => [],
            'reason' => null,
        ];

        try {
            // Extract custom fields from current data
            $currentCustomFields = $this->extractCustomFields($currentData);

            // For added events, check if we have any custom fields we don't know about
            if ($eventType === 'added') {
                $result = $this->detectNewCustomFields($entityType, $currentCustomFields);
            }
            // For updated events, compare current vs previous
            elseif ($eventType === 'updated' && $previousData) {
                $previousCustomFields = $this->extractCustomFields($previousData);
                $result = $this->detectCustomFieldChanges(
                    $entityType,
                    $currentCustomFields,
                    $previousCustomFields
                );
            }

            // Trigger sync if changes detected
            if ($result['detected_changes']) {
                $result['sync_triggered'] = $this->triggerCustomFieldSync($entityType);
            }

        } catch (\Exception $e) {
            Log::error('Error detecting custom field changes', [
                'entity_type' => $entityType,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $result['reason'] = 'Error during detection: '.$e->getMessage();
        }

        return $result;
    }

    /**
     * Extract custom fields from entity data
     */
    protected function extractCustomFields(array $entityData): array
    {
        $customFields = [];

        foreach ($entityData as $key => $value) {
            // Pipedrive custom fields have 40-character hash keys
            if (strlen($key) === 40 && ctype_alnum($key)) {
                $customFields[$key] = $value;
            }
        }

        return $customFields;
    }

    /**
     * Detect new custom fields for added entities
     */
    protected function detectNewCustomFields(string $entityType, array $currentCustomFields): array
    {
        $result = [
            'detected_changes' => false,
            'sync_triggered' => false,
            'new_fields' => [],
            'changed_fields' => [],
            'reason' => null,
        ];

        if (empty($currentCustomFields)) {
            $result['reason'] = 'No custom fields in entity data';

            return $result;
        }

        // Get known custom fields for this entity type
        $knownFields = $this->customFieldService->getFieldsForEntity($entityType, false, true);
        $knownFieldKeys = $knownFields->pluck('key')->toArray();

        // Check for unknown custom field keys
        $unknownFields = [];
        foreach (array_keys($currentCustomFields) as $fieldKey) {
            if (! in_array($fieldKey, $knownFieldKeys)) {
                $unknownFields[] = $fieldKey;
            }
        }

        if (! empty($unknownFields)) {
            $result['detected_changes'] = true;
            $result['new_fields'] = $unknownFields;
            $result['reason'] = 'New custom fields detected: '.implode(', ', $unknownFields);

            Log::info('New custom fields detected in webhook', [
                'entity_type' => $entityType,
                'new_fields' => $unknownFields,
                'total_custom_fields' => count($currentCustomFields),
            ]);
        } else {
            $result['reason'] = 'All custom fields are known';
        }

        return $result;
    }

    /**
     * Detect changes in custom fields between current and previous data
     */
    protected function detectCustomFieldChanges(
        string $entityType,
        array $currentCustomFields,
        array $previousCustomFields
    ): array {
        $result = [
            'detected_changes' => false,
            'sync_triggered' => false,
            'new_fields' => [],
            'changed_fields' => [],
            'reason' => null,
        ];

        // Check for new custom fields (keys that exist in current but not in previous)
        $newFields = array_diff_key($currentCustomFields, $previousCustomFields);

        // Check for changed custom field values
        $changedFields = [];
        foreach ($currentCustomFields as $key => $currentValue) {
            if (isset($previousCustomFields[$key]) && $previousCustomFields[$key] !== $currentValue) {
                $changedFields[$key] = [
                    'previous' => $previousCustomFields[$key],
                    'current' => $currentValue,
                ];
            }
        }

        if (! empty($newFields)) {
            $result['detected_changes'] = true;
            $result['new_fields'] = array_keys($newFields);

            // Check if these are truly unknown fields
            $knownFields = $this->customFieldService->getFieldsForEntity($entityType, false, true);
            $knownFieldKeys = $knownFields->pluck('key')->toArray();

            $unknownNewFields = array_diff(array_keys($newFields), $knownFieldKeys);

            if (! empty($unknownNewFields)) {
                $result['reason'] = 'New unknown custom fields detected: '.implode(', ', $unknownNewFields);

                Log::info('New custom fields detected in webhook update', [
                    'entity_type' => $entityType,
                    'new_fields' => $unknownNewFields,
                    'changed_fields' => array_keys($changedFields),
                ]);
            } else {
                $result['detected_changes'] = false;
                $result['reason'] = 'New fields are already known';
            }
        }

        if (! empty($changedFields)) {
            $result['changed_fields'] = array_keys($changedFields);

            if (! $result['detected_changes']) {
                $result['reason'] = 'Custom field values changed but no new fields detected';
            }
        }

        if (! $result['detected_changes'] && empty($changedFields)) {
            $result['reason'] = 'No custom field changes detected';
        }

        return $result;
    }

    /**
     * Trigger custom field synchronization
     */
    protected function triggerCustomFieldSync(string $entityType): bool
    {
        try {
            // Check if we should use jobs or direct command execution
            $useJobs = config('pipedrive.sync.use_jobs', true);

            if ($useJobs) {
                // Dispatch job for async execution
                SyncPipedriveCustomFieldsJob::dispatch($entityType)
                    ->onQueue(config('pipedrive.jobs.sync_queue', 'default'));

                Log::info('Custom fields sync job dispatched', [
                    'entity_type' => $entityType,
                    'trigger' => 'webhook_detection',
                ]);
            } else {
                // Execute command directly
                $exitCode = Artisan::call('pipedrive:sync-custom-fields', [
                    '--entity' => $entityType,
                    '--force' => true,
                ]);

                if ($exitCode === 0) {
                    Log::info('Custom fields sync command executed successfully', [
                        'entity_type' => $entityType,
                        'trigger' => 'webhook_detection',
                    ]);
                } else {
                    Log::error('Custom fields sync command failed', [
                        'entity_type' => $entityType,
                        'exit_code' => $exitCode,
                        'trigger' => 'webhook_detection',
                    ]);

                    return false;
                }
            }

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to trigger custom fields sync', [
                'entity_type' => $entityType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Check if custom field detection is enabled
     */
    public function isEnabled(): bool
    {
        return config('pipedrive.webhooks.detect_custom_fields', true);
    }

    /**
     * Get entity type from webhook object type
     */
    public function getEntityTypeFromWebhookObject(string $webhookObject): ?string
    {
        $mapping = [
            'deal' => 'deal',
            'person' => 'person',
            'organization' => 'organization',
            'product' => 'product',
            'activity' => 'activity',
        ];

        return $mapping[$webhookObject] ?? null;
    }
}
