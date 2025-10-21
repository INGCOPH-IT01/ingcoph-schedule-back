<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Waitlist Notification</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f4f4f4;
        }
        .container {
            background-color: #ffffff;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #FF9800;
        }
        .header h1 {
            color: #FF9800;
            margin: 0;
            font-size: 28px;
        }
        .status-badge {
            background-color: #FF9800;
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: bold;
            display: inline-block;
            margin: 10px 0;
        }
        .urgent-notice {
            background-color: #fff3cd;
            border-left: 4px solid #FF9800;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }
        .urgent-notice h3 {
            margin-top: 0;
            color: #856404;
        }
        .booking-details {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            margin: 10px 0;
            padding: 8px 0;
            border-bottom: 1px solid #e0e0e0;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-label {
            font-weight: bold;
            color: #555;
        }
        .detail-value {
            color: #333;
        }
        .price {
            font-size: 18px;
            font-weight: bold;
            color: #FF9800;
        }
        .timer-box {
            background: linear-gradient(135deg, #FF9800 0%, #F57C00 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            margin: 20px 0;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .timer-box h3 {
            margin: 0 0 10px 0;
            font-size: 22px;
        }
        .timer-box .time {
            font-size: 32px;
            font-weight: bold;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
            color: #666;
            font-size: 14px;
        }
        .cta-button {
            background-color: #FF9800;
            color: white;
            padding: 15px 30px;
            text-decoration: none;
            border-radius: 5px;
            display: inline-block;
            margin: 20px 0;
            font-weight: bold;
            font-size: 16px;
        }
        .cta-button:hover {
            background-color: #F57C00;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>⏰ Booking Slot Available!</h1>
            <div class="status-badge">WAITLIST NOTIFICATION</div>
        </div>

        <p>Dear {{ $user->name }},</p>

        @if($notificationType === 'rejected')
            <p>Good news! The booking that was previously pending for your desired time slot has been <strong>rejected</strong>. The time slot is now available for you to book!</p>
        @else
            <p>Great news! The booking that was holding your desired time slot has been completed or cancelled. The time slot is now available for you to book!</p>
        @endif

        <div class="urgent-notice">
            <h3>⚡ Action Required - Limited Time Offer</h3>
            <p><strong>You have been on the waitlist for this time slot.</strong> You now have the opportunity to secure this booking, but you must act quickly!</p>
        </div>

        <div class="timer-box">
            <h3>⏱️ Time Remaining to Book</h3>
            <div class="time">{{ \Carbon\Carbon::parse($expiresAt)->diffInMinutes(now()) }} minutes</div>
            <p style="margin: 5px 0 0 0; font-size: 14px;">Expires at: {{ \Carbon\Carbon::parse($expiresAt)->format('g:i A') }}</p>
        </div>

        <div class="booking-details">
            <h3 style="margin-top: 0; color: #FF9800;">📅 Time Slot Details</h3>

            <div class="detail-row">
                <span class="detail-label">Court:</span>
                <span class="detail-value">{{ $court->name }} ({{ $sport->name }})</span>
            </div>

            <div class="detail-row">
                <span class="detail-label">Date:</span>
                <span class="detail-value">{{ \Carbon\Carbon::parse($waitlist->start_time)->format('F j, Y') }}</span>
            </div>

            <div class="detail-row">
                <span class="detail-label">Time:</span>
                <span class="detail-value">{{ \Carbon\Carbon::parse($waitlist->start_time)->format('g:i A') }} - {{ \Carbon\Carbon::parse($waitlist->end_time)->format('g:i A') }}</span>
            </div>

            <div class="detail-row">
                <span class="detail-label">Duration:</span>
                <span class="detail-value">{{ \Carbon\Carbon::parse($waitlist->start_time)->diffInHours(\Carbon\Carbon::parse($waitlist->end_time)) }} hour(s)</span>
            </div>

            <div class="detail-row">
                <span class="detail-label">Price:</span>
                <span class="detail-value price">₱{{ number_format($waitlist->price, 2) }}</span>
            </div>
        </div>

        <div style="background-color: #fff3cd; padding: 15px; border-radius: 8px; margin: 20px 0;">
            <h4 style="margin-top: 0; color: #856404;">📋 What to Do Next:</h4>
            <ol style="margin: 10px 0; padding-left: 20px; color: #856404;">
                <li><strong>Click the button below</strong> to go to the booking page</li>
                <li><strong>Complete your booking</strong> by uploading proof of payment</li>
                <li><strong>Act fast!</strong> This slot will be released to other users if you don't book within {{ \Carbon\Carbon::parse($expiresAt)->diffInMinutes(now()) }} minutes</li>
            </ol>
        </div>

        <div style="text-align: center;">
            <a href="{{ env('APP_URL') }}/courts" class="cta-button">🎯 Book Now!</a>
        </div>

        <p style="color: #d32f2f; font-weight: bold; text-align: center;">⚠️ This opportunity expires at {{ \Carbon\Carbon::parse($expiresAt)->format('g:i A') }}. Don't miss out!</p>

        @if($contactEmail || $contactMobile || $contactViber)
        <!-- Contact Information -->
        <div style="background-color: #fff3cd; border: 2px solid #FF9800; border-radius: 8px; padding: 20px; margin: 20px 0;">
            <h4 style="margin-top: 0; color: #856404;">📞 Need Help? Contact Us</h4>
            <div style="color: #856404; font-size: 14px; line-height: 1.8;">
                @if($contactEmail)
                <div style="margin: 8px 0;">
                    <strong>📧 Email:</strong>
                    <a href="mailto:{{ $contactEmail }}" style="color: #856404; text-decoration: none;">{{ $contactEmail }}</a>
                </div>
                @endif
<!--
                @if($contactMobile)
                <div style="margin: 8px 0;">
                    <strong>📱 Mobile:</strong>
                    <a href="tel:{{ $contactMobile }}" style="color: #856404; text-decoration: none;">{{ $contactMobile }}</a>
                </div>
                @endif

                @if($contactViber)
                <div style="margin: 8px 0;">
                    <strong>💬 Viber:</strong>
                    <span style="color: #856404;">{{ $contactViber }}</span>
                </div>
                @endif -->
            </div>
        </div>
        @endif

        <div class="footer">
            <p>You received this email because you were on the waitlist for this time slot.</p>
            <p><strong>Court Schedule Management System</strong></p>
        </div>
    </div>
</body>
</html>
