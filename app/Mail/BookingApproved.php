<?php

namespace App\Mail;

use App\Models\CartTransaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookingApproved extends Mailable
{
    use Queueable, SerializesModels;

    public $transaction;
    public $user;
    public $bookingDetails;

    /**
     * Create a new message instance.
     */
    public function __construct(CartTransaction $transaction)
    {
        $this->transaction = $transaction;
        $this->user = $transaction->user;
        
        // Prepare booking details grouped by court and date
        $this->bookingDetails = $this->prepareBookingDetails();
    }

    /**
     * Prepare booking details for email
     */
    private function prepareBookingDetails()
    {
        $details = [];
        
        foreach ($this->transaction->cartItems as $item) {
            if ($item->status === 'cancelled') {
                continue;
            }

            $key = $item->court_id . '_' . $item->booking_date;
            
            if (!isset($details[$key])) {
                $details[$key] = [
                    'court' => $item->court,
                    'sport' => $item->court->sport,
                    'date' => $item->booking_date,
                    'slots' => [],
                    'total_price' => 0
                ];
            }
            
            $details[$key]['slots'][] = [
                'start_time' => $item->start_time,
                'end_time' => $item->end_time,
                'price' => $item->price
            ];
            
            $details[$key]['total_price'] += $item->price;
        }
        
        return array_values($details);
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Booking Approved - Present Your QR Code',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.booking-approved',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
