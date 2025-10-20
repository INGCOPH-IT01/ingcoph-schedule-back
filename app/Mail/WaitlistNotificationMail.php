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

class WaitlistNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public $waitlist;
    public $notificationType; // 'available' or 'rejected'

    /**
     * Create a new message instance.
     */
    public function __construct(BookingWaitlist $waitlist, string $notificationType = 'available')
    {
        $this->waitlist = $waitlist;
        $this->notificationType = $notificationType;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subject = $this->notificationType === 'rejected'
            ? 'Booking Slot Now Available - ' . $this->waitlist->court->name
            : 'Your Waitlisted Slot is Now Available - ' . $this->waitlist->court->name;

        return new Envelope(
            subject: $subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.waitlist-notification',
            with: [
                'waitlist' => $this->waitlist,
                'user' => $this->waitlist->user,
                'court' => $this->waitlist->court,
                'sport' => $this->waitlist->sport,
                'notificationType' => $this->notificationType,
                'expiresAt' => $this->waitlist->expires_at,
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
