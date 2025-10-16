<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BookingCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $booking;

    /**
     * Create a new event instance.
     */
    public function __construct(Booking $booking)
    {
        // Load minimal relationships - no images to reduce payload size
        $this->booking = $booking->load(['user', 'court.sport']);
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->booking->user_id),
            new Channel('bookings'),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'booking.created';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        // Send only essential data to avoid payload size limits
        return [
            'booking' => [
                'id' => $this->booking->id,
                'transaction_id' => $this->booking->cart_transaction_id, // Include transaction ID for real-time updates
                'user_id' => $this->booking->user_id,
                'court_id' => $this->booking->court_id,
                'start_time' => $this->booking->start_time,
                'end_time' => $this->booking->end_time,
                'total_price' => $this->booking->total_price,
                'status' => $this->booking->status,
                'payment_status' => $this->booking->payment_status,
                'user' => [
                    'id' => $this->booking->user->id,
                    'name' => $this->booking->user->name,
                    'email' => $this->booking->user->email,
                ],
                'court' => [
                    'id' => $this->booking->court->id,
                    'name' => $this->booking->court->name,
                    'sport' => $this->booking->court->sport ? [
                        'id' => $this->booking->court->sport->id,
                        'name' => $this->booking->court->sport->name,
                    ] : null,
                ],
            ],
            'timestamp' => now()->toISOString(),
        ];
    }
}
