<?php

namespace Skeylup\LaravelPipedrive\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Skeylup\LaravelPipedrive\Contracts\PipedriveCacheInterface;
use Skeylup\LaravelPipedrive\Models\PipedriveCustomField;

class PipedriveCustomFieldService
{
    protected PipedriveCacheInterface $cacheService;

    public function __construct(PipedriveCacheInterface $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    /**
     * Get all custom fields for a specific entity type
     * Uses cache when available for improved performance
     */
    public function getFieldsForEntity(string $entityType, bool $activeOnly = true, bool $useCache = true): Collection
    {
        // Try to get from cache first if enabled
        if ($useCache && $this->cacheService->isEnabled()) {
            $cached = $this->cacheService->getCustomFields($entityType);
            if ($cached !== null) {
                // Filter cached results based on activeOnly parameter
                if ($activeOnly) {
                    return $cached->filter(function ($field) {
                        return $field['active_flag'] ?? true;
                    })->values();
                }

                return $cached;
            }
        }

        // Fallback to database query
        $query = PipedriveCustomField::forEntity($entityType);

        if ($activeOnly) {
            $query->active();
        }

        $fields = $query->orderBy('name')->get();

        // Cache the results for future use
        if ($useCache && $this->cacheService->isEnabled()) {
            $this->cacheService->cacheCustomFields($entityType, $fields);
        }

        return $fields;
    }

    /**
     * Get only custom fields (excluding default Pipedrive fields)
     * Uses cache when available for improved performance
     */
    public function getCustomFieldsForEntity(string $entityType, bool $activeOnly = true, bool $useCache = true): Collection
    {
        // Try to get from cache and filter for custom fields only
        if ($useCache && $this->cacheService->isEnabled()) {
            $cached = $this->cacheService->getCustomFields($entityType);
            if ($cached !== null) {
                $filtered = $cached->filter(function ($field) use ($activeOnly) {
                    $isCustom = $field['pipedrive_data']['edit_flag'] ?? false;
                    $isActive = $activeOnly ? ($field['active_flag'] ?? true) : true;

                    return $isCustom && $isActive;
                });

                return $filtered->values();
            }
        }

        // Fallback to database query
        $query = PipedriveCustomField::forEntity($entityType)->customOnly();

        if ($activeOnly) {
            $query->active();
        }

        return $query->orderBy('name')->get();
    }

    /**
     * Get fields by type for a specific entity
     */
    public function getFieldsByType(string $entityType, string $fieldType, bool $activeOnly = true): Collection
    {
        $query = PipedriveCustomField::forEntity($entityType)->ofType($fieldType);

        if ($activeOnly) {
            $query->active();
        }

        return $query->orderBy('name')->get();
    }

    /**
     * Get mandatory fields for an entity
     */
    public function getMandatoryFields(string $entityType): Collection
    {
        return PipedriveCustomField::forEntity($entityType)
            ->mandatory()
            ->active()
            ->orderBy('name')
            ->get();
    }

    /**
     * Get fields visible in add dialog
     */
    public function getAddVisibleFields(string $entityType): Collection
    {
        return PipedriveCustomField::forEntity($entityType)
            ->visibleInAdd()
            ->active()
            ->orderBy('name')
            ->get();
    }

    /**
     * Get fields visible in detail view
     */
    public function getDetailVisibleFields(string $entityType): Collection
    {
        return PipedriveCustomField::forEntity($entityType)
            ->visibleInDetails()
            ->active()
            ->orderBy('name')
            ->get();
    }

    /**
     * Find a field by its Pipedrive key
     */
    public function findByKey(string $fieldKey, string $entityType): ?PipedriveCustomField
    {
        return PipedriveCustomField::where('key', $fieldKey)
            ->where('entity_type', $entityType)
            ->first();
    }

    /**
     * Find a field by its Pipedrive ID
     */
    public function findById(int $pipedriveFieldId, string $entityType): ?PipedriveCustomField
    {
        return PipedriveCustomField::where('pipedrive_id', $pipedriveFieldId)
            ->where('entity_type', $entityType)
            ->first();
    }

    /**
     * Get all option-based fields (set and enum) for an entity
     */
    public function getOptionFields(string $entityType): Collection
    {
        return PipedriveCustomField::forEntity($entityType)
            ->whereIn('field_type', [PipedriveCustomField::TYPE_SET, PipedriveCustomField::TYPE_ENUM])
            ->active()
            ->orderBy('name')
            ->get();
    }

    /**
     * Get all relation fields (user, org, people) for an entity
     */
    public function getRelationFields(string $entityType): Collection
    {
        return PipedriveCustomField::forEntity($entityType)
            ->whereIn('field_type', [
                PipedriveCustomField::TYPE_USER,
                PipedriveCustomField::TYPE_ORG,
                PipedriveCustomField::TYPE_PEOPLE,
            ])
            ->active()
            ->orderBy('name')
            ->get();
    }

    /**
     * Get field statistics for an entity
     */
    public function getFieldStatistics(string $entityType): array
    {
        $fields = PipedriveCustomField::forEntity($entityType);

        return [
            'total' => $fields->count(),
            'active' => $fields->active()->count(),
            'custom' => $fields->customOnly()->count(),
            'mandatory' => $fields->mandatory()->count(),
            'by_type' => $fields->selectRaw('field_type, COUNT(*) as count')
                ->groupBy('field_type')
                ->pluck('count', 'field_type')
                ->toArray(),
        ];
    }

    /**
     * Validate field value based on field type
     */
    public function validateFieldValue(PipedriveCustomField $field, $value): array
    {
        $errors = [];

        // Check if mandatory field has value
        if ($field->isMandatory() && empty($value)) {
            $errors[] = "Field '{$field->name}' is mandatory and cannot be empty.";
        }

        // Type-specific validation
        switch ($field->field_type) {
            case PipedriveCustomField::TYPE_VARCHAR:
            case PipedriveCustomField::TYPE_VARCHAR_AUTO:
                if (! empty($value) && strlen($value) > 255) {
                    $errors[] = "Field '{$field->name}' cannot exceed 255 characters.";
                }
                break;

            case PipedriveCustomField::TYPE_DOUBLE:
                if (! empty($value) && ! is_numeric($value)) {
                    $errors[] = "Field '{$field->name}' must be a numeric value.";
                }
                break;

            case PipedriveCustomField::TYPE_SET:
            case PipedriveCustomField::TYPE_ENUM:
                if (! empty($value) && $field->hasOptions()) {
                    $validOptions = array_column($field->getOptions(), 'id');
                    $values = is_array($value) ? $value : [$value];

                    foreach ($values as $val) {
                        if (! in_array($val, $validOptions)) {
                            $errors[] = "Invalid option '{$val}' for field '{$field->name}'.";
                        }
                    }
                }
                break;

            case PipedriveCustomField::TYPE_DATE:
                if (! empty($value) && ! strtotime($value)) {
                    $errors[] = "Field '{$field->name}' must be a valid date.";
                }
                break;
        }

        return $errors;
    }

    /**
     * Format field value for display based on field type
     */
    public function formatFieldValue(PipedriveCustomField $field, $value): string
    {
        if (empty($value)) {
            return '';
        }

        return match ($field->field_type) {
            PipedriveCustomField::TYPE_MONETARY => $this->formatMonetaryValue($value),
            PipedriveCustomField::TYPE_DATE => $this->formatDateValue($value),
            PipedriveCustomField::TYPE_DATERANGE => $this->formatDateRangeValue($value),
            PipedriveCustomField::TYPE_TIME => $this->formatTimeValue($value),
            PipedriveCustomField::TYPE_TIMERANGE => $this->formatTimeRangeValue($value),
            PipedriveCustomField::TYPE_SET, PipedriveCustomField::TYPE_ENUM => $this->formatOptionValue($field, $value),
            PipedriveCustomField::TYPE_PHONE => $this->formatPhoneValue($value),
            PipedriveCustomField::TYPE_ADDRESS => $this->formatAddressValue($value),
            default => (string) $value,
        };
    }

    protected function formatMonetaryValue($value): string
    {
        if (is_array($value) && isset($value['amount'], $value['currency'])) {
            return number_format($value['amount'], 2).' '.$value['currency'];
        }

        return (string) $value;
    }

    protected function formatDateValue($value): string
    {
        return date('Y-m-d', strtotime($value));
    }

    protected function formatDateRangeValue($value): string
    {
        if (is_array($value) && isset($value['start_date'], $value['end_date'])) {
            return $this->formatDateValue($value['start_date']).' - '.$this->formatDateValue($value['end_date']);
        }

        return (string) $value;
    }

    protected function formatTimeValue($value): string
    {
        return date('H:i', strtotime($value));
    }

    protected function formatTimeRangeValue($value): string
    {
        if (is_array($value) && isset($value['start_time'], $value['end_time'])) {
            return $this->formatTimeValue($value['start_time']).' - '.$this->formatTimeValue($value['end_time']);
        }

        return (string) $value;
    }

    protected function formatOptionValue(PipedriveCustomField $field, $value): string
    {
        if (! $field->hasOptions()) {
            return (string) $value;
        }

        $options = collect($field->getOptions())->keyBy('id');
        $values = is_array($value) ? $value : [$value];

        $labels = collect($values)->map(function ($val) use ($options) {
            return $options->get($val)['label'] ?? $val;
        });

        return $labels->implode(', ');
    }

    protected function formatPhoneValue($value): string
    {
        // Basic phone formatting - can be enhanced based on requirements
        return (string) $value;
    }

    protected function formatAddressValue($value): string
    {
        if (is_array($value)) {
            $parts = array_filter([
                $value['street_number'] ?? '',
                $value['route'] ?? '',
                $value['locality'] ?? '',
                $value['postal_code'] ?? '',
                $value['country'] ?? '',
            ]);

            return implode(', ', $parts);
        }

        return (string) $value;
    }

    /**
     * Invalidate cache for a specific entity type
     * Call this method when custom fields are updated
     */
    public function invalidateCache(string $entityType): bool
    {
        if ($this->cacheService->isEnabled()) {
            $success = $this->cacheService->invalidateEntityCache($entityType);

            if ($success) {
                Log::info("Cache invalidated for entity type: {$entityType}");
            } else {
                Log::warning("Failed to invalidate cache for entity type: {$entityType}");
            }

            return $success;
        }

        return true; // Cache not enabled, consider as success
    }

    /**
     * Refresh cache for a specific entity type
     * Fetches fresh data from database and updates cache
     */
    public function refreshCache(string $entityType): bool
    {
        if (! $this->cacheService->isEnabled()) {
            return false;
        }

        try {
            // Get fresh data from database
            $fields = PipedriveCustomField::forEntity($entityType)
                ->orderBy('name')
                ->get();

            // Update cache
            $success = $this->cacheService->cacheCustomFields($entityType, $fields);

            if ($success) {
                Log::info("Cache refreshed for entity type: {$entityType}");
            } else {
                Log::warning("Failed to refresh cache for entity type: {$entityType}");
            }

            return $success;
        } catch (\Exception $e) {
            Log::error("Error refreshing cache for {$entityType}: ".$e->getMessage());

            return false;
        }
    }

    /**
     * Get cache statistics for custom fields
     */
    public function getCacheStatistics(): array
    {
        if (! $this->cacheService->isEnabled()) {
            return ['enabled' => false];
        }

        return $this->cacheService->getStatistics();
    }
}
