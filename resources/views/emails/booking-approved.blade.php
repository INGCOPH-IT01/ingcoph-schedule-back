<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Approved</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f5f7fa;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 600px;
            margin: 20px auto;
            background: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        .header {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 700;
        }
        .header p {
            margin: 10px 0 0 0;
            font-size: 16px;
            opacity: 0.95;
        }
        .content {
            padding: 30px;
        }
        .greeting {
            font-size: 18px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 20px;
        }
        .message {
            font-size: 15px;
            color: #475569;
            margin-bottom: 25px;
            line-height: 1.7;
        }
        .booking-details {
            background: #f8fafc;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
            border-left: 4px solid #10b981;
        }
        .booking-item {
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e2e8f0;
        }
        .booking-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        .court-name {
            font-size: 18px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 8px;
        }
        .sport-badge {
            display: inline-block;
            background: #3b82f6;
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 12px;
        }
        .booking-date {
            font-size: 15px;
            color: #64748b;
            margin-bottom: 12px;
            font-weight: 600;
        }
        .time-slots {
            margin: 10px 0;
        }
        .time-slot {
            background: white;
            padding: 10px 15px;
            margin: 6px 0;
            border-radius: 6px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid #e2e8f0;
        }
        .time-text {
            color: #1e293b;
            font-weight: 600;
            font-size: 14px;
        }
        .price-text {
            color: #10b981;
            font-weight: 700;
            font-size: 14px;
        }
        .total-price {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 12px 15px;
            border-radius: 6px;
            text-align: right;
            font-size: 16px;
            font-weight: 700;
            margin-top: 12px;
        }
        .qr-section {
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
            border: 2px dashed #3b82f6;
            border-radius: 8px;
            padding: 25px;
            margin: 25px 0;
            text-align: center;
        }
        .qr-icon {
            font-size: 48px;
            margin-bottom: 15px;
        }
        .qr-title {
            font-size: 20px;
            font-weight: 700;
            color: #1e40af;
            margin-bottom: 10px;
        }
        .qr-instructions {
            font-size: 14px;
            color: #1e40af;
            line-height: 1.6;
            margin-bottom: 15px;
        }
        .qr-steps {
            text-align: left;
            background: white;
            padding: 20px;
            border-radius: 6px;
            margin-top: 15px;
        }
        .qr-step {
            display: flex;
            align-items: flex-start;
            margin-bottom: 12px;
        }
        .qr-step:last-child {
            margin-bottom: 0;
        }
        .step-number {
            background: #3b82f6;
            color: white;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 12px;
            margin-right: 12px;
            flex-shrink: 0;
        }
        .step-text {
            color: #475569;
            font-size: 14px;
            line-height: 1.5;
        }
        .important-note {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .important-note strong {
            color: #92400e;
            display: block;
            margin-bottom: 5px;
        }
        .important-note p {
            color: #78350f;
            margin: 0;
            font-size: 14px;
        }
        .footer {
            background: #f8fafc;
            padding: 20px 30px;
            text-align: center;
            color: #64748b;
            font-size: 13px;
            border-top: 1px solid #e2e8f0;
        }
        .footer p {
            margin: 5px 0;
        }
        .footer a {
            color: #3b82f6;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>✅ Booking Approved!</h1>
            <p>Your court booking has been confirmed</p>
        </div>

        <!-- Content -->
        <div class="content">
            <div class="greeting">
                Hello {{ $user->name }},
            </div>

            <div class="message">
                Great news! Your court booking has been <strong>approved</strong>. You're all set to enjoy your scheduled time!
            </div>

            <!-- Booking Details -->
            <div class="booking-details">
                <h3 style="margin-top: 0; color: #1e293b;">📋 Booking Details</h3>

                @foreach($bookingDetails as $detail)
                <div class="booking-item">
                    <div class="court-name">
                        🏟️ {{ $detail['court']->name }}
                    </div>
                    <span class="sport-badge">{{ $detail['sport']->name }}</span>

                    <div class="booking-date">
                        📅 {{ \Carbon\Carbon::parse($detail['date'])->format('l, F j, Y') }}
                    </div>

                    <div class="time-slots">
                        @foreach($detail['slots'] as $slot)
                        <div class="time-slot">
                            <span class="time-text">
                                ⏰ {{ \Carbon\Carbon::parse($slot['start_time'])->format('g:i A') }} -
                                {{ \Carbon\Carbon::parse($slot['end_time'])->format('g:i A') }}
                            </span>
                            <span class="price-text">₱{{ number_format($slot['price'], 2) }}</span>
                        </div>
                        @endforeach
                    </div>

                    <div class="total-price">
                        Court Total: ₱{{ number_format($detail['total_price'], 2) }}
                    </div>
                </div>
                @endforeach

                <div style="background: white; padding: 15px; border-radius: 6px; margin-top: 15px; border: 2px solid #10b981;">
                    <strong style="color: #1e293b; font-size: 16px;">Grand Total: ₱{{ number_format($transaction->total_price, 2) }}</strong>
                </div>
            </div>

            <!-- QR Code Section -->
            <div class="qr-section">
                <div class="qr-icon">📱</div>
                <div class="qr-title">Present Your QR Code</div>
                <div class="qr-instructions">
                    To access your booked court, you'll need to present your QR code to the staff at the venue.
                </div>

                <div class="qr-steps">
                    <div class="qr-step">
                        <div class="step-number">1</div>
                        <div class="step-text">
                            Login to your account and go to <strong>"My Bookings"</strong>
                        </div>
                    </div>
                    <div class="qr-step">
                        <div class="step-number">2</div>
                        <div class="step-text">
                            Find your approved booking and click <strong>"View Details"</strong>
                        </div>
                    </div>
                    <div class="qr-step">
                        <div class="step-number">3</div>
                        <div class="step-text">
                            Your QR code will be displayed - show this to the staff
                        </div>
                    </div>
                    <div class="qr-step">
                        <div class="step-number">4</div>
                        <div class="step-text">
                            Staff will scan your QR code to verify and grant access
                        </div>
                    </div>
                </div>
            </div>

            <!-- Important Note -->
            <div class="important-note">
                <strong>⚠️ Important Reminders:</strong>
                <p>
                    • Please arrive at least 10 minutes before your scheduled time<br>
                    • Late arrival will not adjust the booking duration<br>
                    • Please bring your ID or proof of booking with you<br>
                    • Make sure your phone is charged to display the QR code<br>
                    • The QR code is unique to your booking and should not be shared<br>
                    • Present the QR code at the court reception/entrance<br>
                    • Payment is non-refundable
                </p>
            </div>

            <div class="message">
                Thank you for booking with us! We look forward to seeing you at the court.
            </div>

            <div class="message">
                If you have any questions or need to make changes, please contact us immediately.
            </div>

            @if($contactEmail || $contactMobile || $contactViber)
            <!-- Contact Information -->
            <div style="background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); border: 2px solid #10b981; border-radius: 8px; padding: 20px; margin: 20px 0;">
                <h3 style="margin-top: 0; color: #065f46; font-size: 18px;">
                    📞 Contact Us
                </h3>
                <div style="color: #047857; font-size: 14px; line-height: 1.8;">
                    @if($contactEmail)
                    <div style="margin: 8px 0;">
                        <strong>📧 Email:</strong>
                        <a href="mailto:{{ $contactEmail }}" style="color: #059669; text-decoration: none;">{{ $contactEmail }}</a>
                    </div>
                    @endif

                    @if($contactMobile)
                    <div style="margin: 8px 0;">
                        <strong>📱 Mobile:</strong>
                        <a href="tel:{{ $contactMobile }}" style="color: #059669; text-decoration: none;">{{ $contactMobile }}</a>
                    </div>
                    @endif

                    @if($contactViber)
                    <div style="margin: 8px 0;">
                        <strong>💬 Viber:</strong>
                        <span style="color: #047857;">{{ $contactViber }}</span>
                    </div>
                    @endif
                </div>
            </div>
            @endif
        </div>

        <!-- Footer -->
        <div class="footer">
            <p><strong>Court Booking System</strong></p>
            <p>This is an automated message. Please do not reply to this email.</p>
            <p>© {{ date('Y') }} Court Booking System. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
