<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartTransactionResource extends JsonResource
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
            'user_id' => $this->user_id,
            'total_price' => $this->total_price,
            'status' => $this->status,
            'payment_method' => $this->payment_method,
            'payment_reference_number' => $this->payment_reference_number,
            'payment_status' => $this->payment_status,
            'proof_of_payment' => $this->proof_of_payment,
            'approval_status' => $this->approval_status,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at ? $this->approved_at->format('Y-m-d H:i:s') : null,
            'rejection_reason' => $this->rejection_reason,
            'qr_code' => $this->qr_code,

            // Format timestamps with full date and time
            'created_at' => $this->created_at ? $this->created_at->format('Y-m-d H:i:s') : null,
            'updated_at' => $this->updated_at ? $this->updated_at->format('Y-m-d H:i:s') : null,
            'paid_at' => $this->paid_at ? $this->paid_at->format('Y-m-d H:i:s') : null,
            'attendance_status' => $this->attendance_status,
            'booking_amount' => $this->booking_amount,
            'pos_amount' => $this->pos_amount,

            // Include relationships
            'user' => $this->whenLoaded('user'),
            'approver' => $this->whenLoaded('approver'),
            'cart_items' => CartItemResource::collection($this->whenLoaded('cartItems')),
            'bookings' => $this->whenLoaded('bookings'),
            'pos_sales' => $this->whenLoaded('posSales'),
            'waitlist_entries' => $this->whenLoaded('waitlistEntries'),
        ];
    }
}
