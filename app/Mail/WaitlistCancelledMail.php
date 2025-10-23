<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\BookingWaitlist;
use App\Models\CompanySetting;

class WaitlistCancelledMail extends Mailable
{
    use Queueable, SerializesModels;

    public $waitlist;

    /**
     * Create a new message instance.
     */
    public function __construct(BookingWaitlist $waitlist)
    {
        $this->waitlist = $waitlist;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Waitlist Booking Cancelled - ' . $this->waitlist->court->name,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.waitlist-cancelled',
            with: [
                'waitlist' => $this->waitlist,
                'user' => $this->waitlist->user,
                'court' => $this->waitlist->court,
                'sport' => $this->waitlist->sport,
                'contactEmail' => CompanySetting::get('contact_email', ''),
                'contactMobile' => CompanySetting::get('contact_mobile', ''),
                'contactViber' => CompanySetting::get('contact_viber', ''),
            ]
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
