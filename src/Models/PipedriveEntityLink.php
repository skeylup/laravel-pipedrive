<?php

namespace Skeylup\LaravelPipedrive\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PipedriveEntityLink extends Model
{
    protected $table = 'pipedrive_entity_links';

    protected $fillable = [
        'linkable_type',
        'linkable_id',
        'pipedrive_entity_type',
        'pipedrive_entity_id',
        'pipedrive_model_type',
        'pipedrive_model_id',
        'metadata',
        'is_primary',
        'is_active',
        'last_synced_at',
        'sync_status',
        'sync_error',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * Get the owning linkable model (your Laravel model)
     */
    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the associated Pipedrive model (if exists locally)
     */
    public function pipedriveModel(): MorphTo
    {
        return $this->morphTo('pipedrive_model', 'pipedrive_model_type', 'pipedrive_model_id');
    }

    /**
     * Scopes
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePrimary(Builder $query): Builder
    {
        return $query->where('is_primary', true);
    }

    public function scopeForEntity(Builder $query, string $entityType, int $entityId): Builder
    {
        return $query->where('pipedrive_entity_type', $entityType)
            ->where('pipedrive_entity_id', $entityId);
    }

    public function scopeForModel(Builder $query, Model $model): Builder
    {
        return $query->where('linkable_type', get_class($model))
            ->where('linkable_id', $model->getKey());
    }

    public function scopeSynced(Builder $query): Builder
    {
        return $query->where('sync_status', 'synced');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('sync_status', 'pending');
    }

    public function scopeWithErrors(Builder $query): Builder
    {
        return $query->where('sync_status', 'error');
    }

    /**
     * Helper methods
     */
    public function isPrimary(): bool
    {
        return $this->is_primary;
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }

    public function isSynced(): bool
    {
        return $this->sync_status === 'synced';
    }

    public function hasSyncError(): bool
    {
        return $this->sync_status === 'error';
    }

    public function markAsSynced(): self
    {
        $this->update([
            'sync_status' => 'synced',
            'last_synced_at' => now(),
            'sync_error' => null,
        ]);

        return $this;
    }

    public function markAsError(string $error): self
    {
        $this->update([
            'sync_status' => 'error',
            'sync_error' => $error,
        ]);

        return $this;
    }

    public function markAsPending(): self
    {
        $this->update([
            'sync_status' => 'pending',
            'sync_error' => null,
        ]);

        return $this;
    }

    /**
     * Get the Pipedrive model class for this entity type
     */
    public function getPipedriveModelClass(): ?string
    {
        $entityMap = [
            'deals' => PipedriveDeal::class,
            'persons' => PipedrivePerson::class,
            'organizations' => PipedriveOrganization::class,
            'activities' => PipedriveActivity::class,
            'products' => PipedriveProduct::class,
            'files' => PipedriveFile::class,
            'notes' => PipedriveNote::class,
            'users' => PipedriveUser::class,
            'pipelines' => PipedrivePipeline::class,
            'stages' => PipedriveStage::class,
            'goals' => PipedriveGoal::class,
        ];

        return $entityMap[$this->pipedrive_entity_type] ?? null;
    }

    /**
     * Get the local Pipedrive model instance
     */
    public function getLocalPipedriveModel(): ?Model
    {
        $modelClass = $this->getPipedriveModelClass();

        if (! $modelClass) {
            return null;
        }

        return $modelClass::where('pipedrive_id', $this->pipedrive_entity_id)->first();
    }

    /**
     * Sync the local Pipedrive model reference
     */
    public function syncLocalPipedriveModel(): bool
    {
        $localModel = $this->getLocalPipedriveModel();

        if ($localModel) {
            $this->update([
                'pipedrive_model_type' => get_class($localModel),
                'pipedrive_model_id' => $localModel->getKey(),
            ]);

            return true;
        }

        return false;
    }

    /**
     * Get metadata value
     */
    public function getMetadata(string $key, $default = null)
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Set metadata value
     */
    public function setMetadata(string $key, $value): self
    {
        $metadata = $this->metadata ?? [];
        $metadata[$key] = $value;
        $this->metadata = $metadata;
        $this->save();

        return $this;
    }

    /**
     * Get the age of this link in days
     */
    public function getAgeInDays(): int
    {
        return (int) $this->created_at->diffInDays(now());
    }

    /**
     * Get the last sync age in days
     */
    public function getLastSyncAgeInDays(): ?int
    {
        if (! $this->last_synced_at) {
            return null;
        }

        return (int) $this->last_synced_at->diffInDays(now());
    }

    /**
     * Migrate entity relations from one Pipedrive entity to another
     * Used when entities are merged in Pipedrive
     *
     * @param  string  $entityType  The entity type (deals, persons, organizations, etc.)
     * @param  int  $mergedId  The ID of the entity being merged (source)
     * @param  int  $survivingId  The ID of the entity that survives (target)
     * @param  string  $strategy  Strategy for handling conflicts: 'keep_both', 'keep_surviving', 'keep_merged'
     * @return array Migration results with counts
     */
    public static function migrateEntityRelations(
        string $entityType,
        int $mergedId,
        int $survivingId,
        string $strategy = 'keep_both'
    ): array {
        // Find all relations pointing to the merged entity
        $relations = self::where('pipedrive_entity_type', $entityType)
            ->where('pipedrive_entity_id', $mergedId)
            ->get();

        if ($relations->isEmpty()) {
            return [
                'migrated' => 0,
                'skipped' => 0,
                'conflicts' => 0,
                'errors' => 0,
            ];
        }

        $results = [
            'migrated' => 0,
            'skipped' => 0,
            'conflicts' => 0,
            'errors' => 0,
            'details' => [],
        ];

        foreach ($relations as $relation) {
            try {
                // Check if there's already a relation to the surviving entity
                $existingRelation = self::where('pipedrive_entity_type', $entityType)
                    ->where('pipedrive_entity_id', $survivingId)
                    ->where('linkable_type', $relation->linkable_type)
                    ->where('linkable_id', $relation->linkable_id)
                    ->first();

                if ($existingRelation) {
                    // Handle conflict based on strategy
                    $results['conflicts']++;

                    switch ($strategy) {
                        case 'keep_both':
                            // Keep both relations (do nothing with existing, update merged)
                            $relation->update([
                                'pipedrive_entity_id' => $survivingId,
                                'is_primary' => false, // Never make the migrated one primary if conflict
                                'metadata' => array_merge($relation->metadata ?? [], [
                                    'migrated_from_id' => $mergedId,
                                    'migrated_at' => now()->toIso8601String(),
                                ]),
                            ]);
                            $results['migrated']++;
                            break;

                        case 'keep_surviving':
                            // Keep only the relation to the surviving entity
                            $relation->delete();
                            $results['skipped']++;
                            break;

                        case 'keep_merged':
                            // Keep the merged relation, update its entity ID
                            $relation->update([
                                'pipedrive_entity_id' => $survivingId,
                                'metadata' => array_merge($relation->metadata ?? [], [
                                    'migrated_from_id' => $mergedId,
                                    'migrated_at' => now()->toIso8601String(),
                                ]),
                            ]);
                            // Delete the existing relation to the surviving entity
                            $existingRelation->delete();
                            $results['migrated']++;
                            break;

                        default:
                            // Unknown strategy, skip
                            $results['skipped']++;
                    }

                    $results['details'][] = [
                        'action' => 'conflict_'.$strategy,
                        'linkable_type' => $relation->linkable_type,
                        'linkable_id' => $relation->linkable_id,
                    ];
                } else {
                    // No conflict, simply update the relation
                    $relation->update([
                        'pipedrive_entity_id' => $survivingId,
                        'metadata' => array_merge($relation->metadata ?? [], [
                            'migrated_from_id' => $mergedId,
                            'migrated_at' => now()->toIso8601String(),
                        ]),
                    ]);

                    // Try to sync the local Pipedrive model reference
                    $relation->syncLocalPipedriveModel();

                    $results['migrated']++;
                    $results['details'][] = [
                        'action' => 'migrated',
                        'linkable_type' => $relation->linkable_type,
                        'linkable_id' => $relation->linkable_id,
                    ];
                }
            } catch (\Exception $e) {
                $results['errors']++;
                $results['details'][] = [
                    'action' => 'error',
                    'linkable_type' => $relation->linkable_type,
                    'linkable_id' => $relation->linkable_id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }
}
