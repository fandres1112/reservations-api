<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

class ReservationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'is_premium' => $this->user->is_premium,
            ],
            'service' => [
                'id' => $this->service->id,
                'name' => $this->service->name,
                'duration_minutes' => $this->service->duration_minutes,
                'price' => (float) $this->service->price,
                'non_refundable' => $this->service->non_refundable,
            ],
            'professional' => [
                'id' => $this->professional->id,
                'name' => $this->professional->name,
            ],
            'start_time' => Carbon::parse($this->start_time)->setTimezone('America/Bogota')->format('Y-m-d H:i:s'),
            'end_time' => Carbon::parse($this->end_time)->setTimezone('America/Bogota')->format('Y-m-d H:i:s'),
            'status' => $this->status,
            'cancelled_at' => $this->cancelled_at ? Carbon::parse($this->cancelled_at)->setTimezone('America/Bogota')->format('Y-m-d H:i:s') : null,
            'cancelled_by' => $this->cancelled_by,
            'refund_amount' => $this->refund_amount !== null ? (float) $this->refund_amount : null,
            'created_at' => Carbon::parse($this->created_at)->setTimezone('America/Bogota')->format('Y-m-d H:i:s'),
            'updated_at' => Carbon::parse($this->updated_at)->setTimezone('America/Bogota')->format('Y-m-d H:i:s'),
        ];
    }
}
