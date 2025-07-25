<?php

namespace Skeylup\LaravelPipedrive\Models;

use Illuminate\Database\Eloquent\Builder;
use Skeylup\LaravelPipedrive\Data\PipedriveDealData;

class PipedriveDeal extends BasePipedriveModel
{
    protected $table = 'pipedrive_deals';

    /**
     * Default relationships to eager load for performance optimization
     */
    protected array $defaultEagerLoad = ['user', 'stage', 'person', 'organization'];

    /**
     * Relationships to count instead of loading
     */
    protected array $defaultWithCount = ['activities', 'notes', 'files'];

    protected $fillable = [
        'pipedrive_id',
        'title',
        'value',
        'currency',
        'status',
        'stage_id',
        'person_id',
        'org_id',
        'user_id',
        'active_flag',
        'pipedrive_data',
        'pipedrive_add_time',
        'pipedrive_update_time',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'value' => 'decimal:2',
            'weighted_value' => 'decimal:2',
            'probability' => 'integer',
            'expected_close_date' => 'date',
            'close_time' => 'datetime',
            'won_time' => 'datetime',
            'lost_time' => 'datetime',
            'first_won_time' => 'datetime',
            'stage_change_time' => 'datetime',
            'next_activity_date' => 'datetime',
            'last_activity_date' => 'datetime',
            'last_incoming_mail_time' => 'datetime',
            'last_outgoing_mail_time' => 'datetime',
            'label' => 'array',
            'active' => 'boolean',
            'deleted' => 'boolean',
        ]);
    }

    /**
     * Deal status constants
     */
    public const STATUS_OPEN = 'open';

    public const STATUS_WON = 'won';

    public const STATUS_LOST = 'lost';

    public const STATUS_DELETED = 'deleted';

    public static function getPipedriveEntityName(): string
    {
        return 'deals';
    }

    protected static function getDtoClass(): string
    {
        return PipedriveDealData::class;
    }

    // Scopes
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeWon(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_WON);
    }

    public function scopeLost(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_LOST);
    }

    public function scopeByStage(Builder $query, string $stageId): Builder
    {
        return $query->where('stage_id', $stageId);
    }

    public function scopeByPipeline(Builder $query, string $pipelineId): Builder
    {
        return $query->where('pipeline_id', $pipelineId);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForPerson(Builder $query, int $personId): Builder
    {
        return $query->where('person_id', $personId);
    }

    public function scopeForOrganization(Builder $query, int $orgId): Builder
    {
        return $query->where('org_id', $orgId);
    }

    public function scopeByCurrency(Builder $query, string $currency): Builder
    {
        return $query->where('currency', $currency);
    }

    public function scopeClosingSoon(Builder $query, int $days = 7): Builder
    {
        return $query->where('expected_close_date', '<=', now()->addDays($days))
            ->where('status', self::STATUS_OPEN);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('expected_close_date', '<', today())
            ->where('status', self::STATUS_OPEN);
    }

    // Helper methods
    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isWon(): bool
    {
        return $this->status === self::STATUS_WON;
    }

    public function isLost(): bool
    {
        return $this->status === self::STATUS_LOST;
    }

    public function isClosed(): bool
    {
        return $this->isWon() || $this->isLost();
    }

    public function isOverdue(): bool
    {
        return $this->expected_close_date &&
               $this->expected_close_date->isPast() &&
               $this->isOpen();
    }

    public function isClosingSoon(int $days = 7): bool
    {
        return $this->expected_close_date &&
               $this->expected_close_date->isBefore(now()->addDays($days)) &&
               $this->isOpen();
    }

    public function getFormattedValue(): string
    {
        if (! $this->value || ! $this->currency) {
            return 'N/A';
        }

        return number_format($this->value, 2).' '.strtoupper($this->currency);
    }

    public function getFormattedWeightedValue(): string
    {
        if (! $this->weighted_value || ! $this->currency) {
            return 'N/A';
        }

        return number_format($this->weighted_value, 2).' '.strtoupper($this->currency);
    }

    public function getProbabilityPercentage(): string
    {
        return ($this->probability ?? 0).'%';
    }

    // Relations
    public function user()
    {
        return $this->belongsTo(PipedriveUser::class, 'user_id', 'pipedrive_id');
    }

    public function person()
    {
        return $this->belongsTo(PipedrivePerson::class, 'person_id', 'pipedrive_id');
    }

    public function organization()
    {
        return $this->belongsTo(PipedriveOrganization::class, 'org_id', 'pipedrive_id');
    }

    public function stage()
    {
        return $this->belongsTo(PipedriveStage::class, 'stage_id', 'pipedrive_id');
    }

    // Reverse relations
    public function activities()
    {
        return $this->hasMany(PipedriveActivity::class, 'deal_id', 'pipedrive_id');
    }

    public function notes()
    {
        return $this->hasMany(PipedriveNote::class, 'deal_id', 'pipedrive_id');
    }

    public function files()
    {
        return $this->hasMany(PipedriveFile::class, 'deal_id', 'pipedrive_id');
    }

    /**
     * Get relationships for detail views
     */
    public function getDetailRelationships(): array
    {
        return [
            'user',
            'stage.pipeline',
            'person',
            'organization',
            'activities' => function ($query) {
                $query->latest('pipedrive_add_time')->limit(10);
            },
            'notes' => function ($query) {
                $query->latest('pipedrive_add_time')->limit(5);
            },
            'files' => function ($query) {
                $query->latest('pipedrive_add_time')->limit(10);
            },
        ];
    }

    /**
     * Get searchable fields for this model
     */
    protected function getSearchableFields(): array
    {
        return ['title', 'value'];
    }
}
