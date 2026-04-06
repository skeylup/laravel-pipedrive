<?php

namespace Skeylup\LaravelPipedrive\Data;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;

class PipedriveActivityData extends BasePipedriveData
{
    public function __construct(
        public int $id,
        public ?string $subject = null,
        public ?string $note = null,
        public bool $done = false,
        public ?string $type = null,

        // Date and time fields
        public ?string $due_date = null,
        public ?string $due_time = null,
        public ?string $duration = null,

        #[MapInputName('marked_as_done_time')]
        #[WithCast(DateTimeInterfaceCast::class, format: 'Y-m-d H:i:s')]
        public ?Carbon $marked_as_done_time = null,

        // User fields
        public ?int $user_id = null,
        public ?int $created_by_user_id = null,

        // Related entities
        public ?int $deal_id = null,
        public ?int $person_id = null,
        public ?int $org_id = null,
        public ?int $lead_id = null,

        // Location fields
        public ?string $location = null,

        // Additional fields
        public ?string $public_description = null,
        public bool $busy_flag = false,
        public ?array $attendees = null,
        public ?array $participants = null,

        #[MapInputName('last_notification_time')]
        #[WithCast(DateTimeInterfaceCast::class, format: 'Y-m-d H:i:s')]
        public ?Carbon $last_notification_time = null,

        // Base class properties
        #[MapInputName('add_time')]
        #[WithCast(DateTimeInterfaceCast::class, format: 'Y-m-d H:i:s')]
        public ?Carbon $pipedrive_add_time = null,

        #[MapInputName('update_time')]
        #[WithCast(DateTimeInterfaceCast::class, format: 'Y-m-d H:i:s')]
        public ?Carbon $pipedrive_update_time = null,

        public bool $active_flag = true,
    ) {}

    /**
     * Override to filter only the fields we need
     */
    public static function fromPipedriveApi(array $data): static
    {
        // Filter only the fields we actually need
        $filteredData = [
            'id' => $data['id'],
            'subject' => $data['subject'] ?? null,
            'note' => $data['note'] ?? null,
            'done' => (bool) ($data['done'] ?? false),
            'type' => $data['type'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'due_time' => $data['due_time'] ?? null,
            'duration' => $data['duration'] ?? null,
            'marked_as_done_time' => $data['marked_as_done_time'] ?? null,
            'user_id' => $data['user_id'] ?? null,
            'created_by_user_id' => $data['created_by_user_id'] ?? null,
            'deal_id' => $data['deal_id'] ?? null,
            'person_id' => $data['person_id'] ?? null,
            'org_id' => $data['org_id'] ?? null,
            'lead_id' => $data['lead_id'] ?? null,
            'location' => $data['location'] ?? null,
            'public_description' => $data['public_description'] ?? null,
            'busy_flag' => (bool) ($data['busy_flag'] ?? false),
            'attendees' => $data['attendees'] ?? null,
            'participants' => $data['participants'] ?? null,
            'last_notification_time' => $data['last_notification_time'] ?? null,
            'add_time' => $data['add_time'] ?? null,
            'update_time' => $data['update_time'] ?? null,
            'active_flag' => (bool) ($data['active_flag'] ?? true),
        ];

        // Handle invalid dates (1970-01-01 00:00:00)
        foreach (['add_time', 'update_time', 'marked_as_done_time', 'last_notification_time'] as $timeField) {
            if (isset($filteredData[$timeField])) {
                try {
                    $parsed = \Carbon\Carbon::parse($filteredData[$timeField]);
                    if ($parsed->year <= 1970) {
                        $filteredData[$timeField] = null;
                    }
                } catch (\Exception $e) {
                    $filteredData[$timeField] = null;
                }
            }
        }

        try {
            return static::from($filteredData);
        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error('Error creating PipedriveActivityData DTO', [
                'error' => $e->getMessage(),
                'data' => $filteredData,
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
