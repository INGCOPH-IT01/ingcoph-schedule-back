<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartItemResource extends JsonResource
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
            'cart_transaction_id' => $this->cart_transaction_id,
            'booking_waitlist_id' => $this->booking_waitlist_id,
            'court_id' => $this->court_id,
            'sport_id' => $this->sport_id,
            'booking_for_user_id' => $this->booking_for_user_id,
            'booking_for_user_name' => $this->booking_for_user_name,

            // Format booking_date as date only (YYYY-MM-DD) - no timezone conversion
            'booking_date' => $this->booking_date ?
                (\Carbon\Carbon::parse($this->booking_date)->format('Y-m-d')) : null,

            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'price' => $this->price,
            'number_of_players' => $this->number_of_players,
            'status' => $this->status,
            'notes' => $this->notes,
            'admin_notes' => $this->admin_notes,

            // Format timestamps as date only for display
            'created_at' => $this->created_at ? $this->created_at->format('Y-m-d') : null,
            'updated_at' => $this->updated_at ? $this->updated_at->format('Y-m-d') : null,

            // Include relationships
            'court' => $this->whenLoaded('court'),
            'sport' => $this->whenLoaded('sport'),
            'bookingForUser' => $this->whenLoaded('bookingForUser'),
            'bookings' => $this->whenLoaded('bookings'),
        ];
    }
}
