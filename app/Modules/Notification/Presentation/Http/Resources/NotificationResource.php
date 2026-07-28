<?php

namespace App\Modules\Notification\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_code' => $this->event_code,
            'title' => $this->title,
            'summary' => $this->summary,
            'status' => $this->status,
            'occurred_at' => $this->occurred_at,
            'read_at' => $this->read_at,
            'target' => $this->target_type ? [
                'type' => $this->target_type,
                'id' => $this->target_id,
            ] : null,
        ];
    }
}
