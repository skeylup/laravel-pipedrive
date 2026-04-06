<?php

namespace Skeylup\LaravelPipedrive\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Skeylup\LaravelPipedrive\Traits\OptimizedPipedriveQueries;

abstract class BasePipedriveModel extends Model
{
    use OptimizedPipedriveQueries;

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'pipedrive_add_time' => 'datetime',
            'pipedrive_update_time' => 'datetime',
            'active_flag' => 'boolean',
            'active' => 'boolean',
            'pipedrive_data' => 'array',
        ];
    }

    /**
     * Scope to get only active records
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active_flag', true);
    }

    /**
     * Scope to get records by Pipedrive ID
     */
    public function scopeByPipedriveId(Builder $query, int $pipedriveId): Builder
    {
        return $query->where('pipedrive_id', $pipedriveId);
    }

    /**
     * Find by Pipedrive ID
     */
    public static function findByPipedriveId(int $pipedriveId): ?static
    {
        return static::where('pipedrive_id', $pipedriveId)->first();
    }

    /**
     * Create or update from Pipedrive API data using manual preparation
     */
    public static function createOrUpdateFromPipedriveData(array $data): static
    {
        // Use manual data preparation for now (DTOs are causing issues)
        $preparedData = static::prepareDataManually($data);

        return static::updateOrCreate(
            ['pipedrive_id' => $data['id']],
            $preparedData
        );
    }

    /**
     * Manually prepare data when DTO fails
     */
    protected static function prepareDataManually(array $data): array
    {
        $model = new static;
        $fillable = $model->getFillable();
        $casts = $model->getCasts();
        $preparedData = [];

        // Map basic fields
        $preparedData['pipedrive_id'] = $data['id'];

        // Store all original data in JSON field
        $preparedData['pipedrive_data'] = $data;

        // Map only essential fields that exist in fillable (excluding pipedrive_data)
        foreach ($fillable as $field) {
            if (in_array($field, ['pipedrive_id', 'pipedrive_data'])) {
                continue;
            }

            // Handle timestamp fields
            if (in_array($field, ['pipedrive_add_time', 'pipedrive_update_time'])) {
                $apiField = str_replace('pipedrive_', '', $field);
                if (isset($data[$apiField]) && ! empty($data[$apiField])) {
                    try {
                        $parsed = \Carbon\Carbon::parse($data[$apiField]);
                        $preparedData[$field] = $parsed->year > 1970 ? $parsed : null;
                    } catch (\Exception $e) {
                        $preparedData[$field] = null;
                    }
                } else {
                    $preparedData[$field] = null;
                }

                continue;
            }

            // Handle direct field mapping for essential fields only
            if (isset($data[$field])) {
                $value = $data[$field];

                // Handle empty values based on cast type
                if ($value === '' || $value === null) {
                    $castType = $casts[$field] ?? null;
                    if (in_array($castType, ['integer', 'int', 'decimal', 'float', 'double'])) {
                        $preparedData[$field] = null;
                    } elseif ($castType === 'boolean') {
                        $preparedData[$field] = false;
                    } else {
                        $preparedData[$field] = null;
                    }
                } else {
                    // Simple value assignment for essential fields
                    if (is_array($value)) {
                        // Skip array values for essential fields (they're in JSON already)
                        $preparedData[$field] = null;
                    } else {
                        $preparedData[$field] = $value;
                    }
                }
            }
        }

        return $preparedData;
    }

    /**
     * Get the DTO class for this model - to be implemented by each model
     */
    abstract protected static function getDtoClass(): string;

    /**
     * Get the Pipedrive entity name for API calls
     */
    abstract public static function getPipedriveEntityName(): string;

    /**
     * Get the Pipedrive API endpoint
     */
    public static function getPipedriveEndpoint(): string
    {
        return static::getPipedriveEntityName();
    }

    /**
     * Check if this record is active
     */
    public function isActive(): bool
    {
        if (property_exists($this, 'active')) {
            return $this->active;
        }

        return $this->active_flag ?? true;
    }

    /**
     * Get formatted Pipedrive add time
     */
    public function getFormattedAddTime(): ?string
    {
        return $this->pipedrive_add_time?->format('Y-m-d H:i:s');
    }

    /**
     * Get formatted Pipedrive update time
     */
    public function getFormattedUpdateTime(): ?string
    {
        return $this->pipedrive_update_time?->format('Y-m-d H:i:s');
    }

    /**
     * Get the age of this record in days
     */
    public function getAgeInDays(): ?int
    {
        if (! $this->pipedrive_add_time) {
            return null;
        }

        return (int) $this->pipedrive_add_time->diffInDays(now());
    }

    /**
     * Get the last update age in days
     */
    public function getLastUpdateAgeInDays(): ?int
    {
        if (! $this->pipedrive_update_time) {
            return null;
        }

        return (int) $this->pipedrive_update_time->diffInDays(now());
    }

    /**
     * Get a value from the pipedrive_data JSON field
     */
    public function getPipedriveAttribute($key, $default = null)
    {
        return $this->pipedrive_data[$key] ?? $default;
    }

    /**
     * Set a value in the pipedrive_data JSON field
     */
    public function setPipedriveAttribute($key, $value)
    {
        $data = $this->pipedrive_data ?? [];
        $data[$key] = $value;
        $this->pipedrive_data = $data;
    }
}
