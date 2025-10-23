<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Waitlist Booking Cancelled</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f4f4f4;
        }
        .container {
            background-color: #ffffff;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            padding-bottom: 20px;
            border-bottom: 3px solid #ef4444;
        }
        .header h1 {
            color: #ef4444;
            margin: 0;
            font-size: 24px;
        }
        .alert-box {
            background-color: #fef2f2;
            border-left: 4px solid #ef4444;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .alert-box p {
            margin: 0;
            color: #991b1b;
        }
        .booking-details {
            background-color: #f9fafb;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .booking-details h2 {
            margin-top: 0;
            color: #374151;
            font-size: 18px;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-label {
            font-weight: 600;
            color: #6b7280;
        }
        .detail-value {
            color: #111827;
        }
        .info-box {
            background-color: #eff6ff;
            border-left: 4px solid #3b82f6;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            color: #6b7280;
            font-size: 14px;
        }
        .contact-info {
            margin-top: 20px;
            text-align: center;
        }
        .contact-info p {
            margin: 5px 0;
            color: #6b7280;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>❌ Waitlist Booking Cancelled</h1>
        </div>

        <div class="alert-box">
            <p><strong>Your waitlisted booking has been cancelled.</strong></p>
            <p>The time slot you were waiting for has been approved for another user.</p>
            <p style="margin-top: 10px;"><strong>Any booking you made for this slot will be automatically rejected.</strong></p>
        </div>

        <p>Dear {{ $user->name }},</p>

        <p>We regret to inform you that your waitlisted booking request has been cancelled because the time slot has been approved and confirmed for another user.</p>

        <p><strong>Important:</strong> If you received a notification to upload payment for this time slot and created a booking, that booking will be automatically rejected. You do not need to upload any payment.</p>

        <div class="booking-details">
            <h2>📋 Cancelled Waitlist Details</h2>
            <div class="detail-row">
                <span class="detail-label">Court:</span>
                <span class="detail-value">{{ $court->name }}</span>
            </div>
            @if($sport)
            <div class="detail-row">
                <span class="detail-label">Sport:</span>
                <span class="detail-value">{{ $sport->name }}</span>
            </div>
            @endif
            <div class="detail-row">
                <span class="detail-label">Date:</span>
                <span class="detail-value">{{ \Carbon\Carbon::parse($waitlist->start_time)->format('F d, Y') }}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Time:</span>
                <span class="detail-value">
                    {{ \Carbon\Carbon::parse($waitlist->start_time)->format('g:i A') }} -
                    {{ \Carbon\Carbon::parse($waitlist->end_time)->format('g:i A') }}
                </span>
            </div>
            @if($waitlist->price)
            <div class="detail-row">
                <span class="detail-label">Price:</span>
                <span class="detail-value">₱{{ number_format($waitlist->price, 2) }}</span>
            </div>
            @endif
        </div>

        <div class="info-box">
            <p><strong>What does this mean?</strong></p>
            <p>The slot you were waiting for is no longer available. You may browse other available time slots and make a new booking if you wish.</p>
        </div>

        <p>We apologize for any inconvenience this may have caused. We encourage you to check our booking system for other available time slots that may suit your schedule.</p>

        @if($contactEmail || $contactMobile || $contactViber)
        <div class="contact-info">
            <p><strong>Need assistance?</strong></p>
            @if($contactEmail)
            <p>📧 Email: {{ $contactEmail }}</p>
            @endif
            @if($contactMobile)
            <p>📱 Mobile: {{ $contactMobile }}</p>
            @endif
            @if($contactViber)
            <p>💬 Viber: {{ $contactViber }}</p>
            @endif
        </div>
        @endif

        <div class="footer">
            <p>Thank you for your understanding.</p>
            <p style="font-size: 12px; color: #9ca3af; margin-top: 10px;">
                This is an automated message. Please do not reply to this email.
            </p>
        </div>
    </div>
</body>
</html>
