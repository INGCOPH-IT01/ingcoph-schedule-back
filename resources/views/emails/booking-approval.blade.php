<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Approved</title>
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
            border-bottom: 2px solid #4CAF50;
        }
        .header h1 {
            color: #4CAF50;
            margin: 0;
            font-size: 28px;
        }
        .status-badge {
            background-color: #4CAF50;
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: bold;
            display: inline-block;
            margin: 10px 0;
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
            color: #4CAF50;
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
            background-color: #4CAF50;
            color: white;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 5px;
            display: inline-block;
            margin: 20px 0;
            font-weight: bold;
        }
        .cta-button:hover {
            background-color: #45a049;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎉 Booking Approved!</h1>
            <div class="status-badge">APPROVED</div>
        </div>

        <p>Dear {{ $user->name }},</p>

        <p>Great news! Your booking has been <strong>approved</strong> and is now confirmed. We're excited to see you at our facility!</p>

        <div class="booking-details">
            <h3 style="margin-top: 0; color: #4CAF50;">📅 Booking Details</h3>
            
            <div class="detail-row">
                <span class="detail-label">Court:</span>
                <span class="detail-value">{{ $court->name }} ({{ $sport->name }})</span>
            </div>
            
            <div class="detail-row">
                <span class="detail-label">Date:</span>
                <span class="detail-value">{{ \Carbon\Carbon::parse($booking->start_time)->format('F j, Y') }}</span>
            </div>
            
            <div class="detail-row">
                <span class="detail-label">Time:</span>
                <span class="detail-value">{{ \Carbon\Carbon::parse($booking->start_time)->format('g:i A') }} - {{ \Carbon\Carbon::parse($booking->end_time)->format('g:i A') }}</span>
            </div>
            
            <div class="detail-row">
                <span class="detail-label">Duration:</span>
                <span class="detail-value">{{ \Carbon\Carbon::parse($booking->start_time)->diffInHours(\Carbon\Carbon::parse($booking->end_time)) }} hour(s)</span>
            </div>
            
            <div class="detail-row">
                <span class="detail-label">Total Price:</span>
                <span class="detail-value price">₱{{ number_format($booking->total_price, 2) }}</span>
            </div>
            
            @if($booking->payment_method)
            <div class="detail-row">
                <span class="detail-label">Payment Method:</span>
                <span class="detail-value">{{ ucfirst($booking->payment_method) }}</span>
            </div>
            @endif
            
            @if($booking->notes)
            <div class="detail-row">
                <span class="detail-label">Notes:</span>
                <span class="detail-value">{{ $booking->notes }}</span>
            </div>
            @endif
        </div>

        <div style="background-color: #e8f5e8; padding: 15px; border-radius: 8px; margin: 20px 0;">
            <h4 style="margin-top: 0; color: #2e7d32;">📋 Important Reminders:</h4>
            <ul style="margin: 10px 0; padding-left: 20px;">
                <li>Please arrive 10 minutes before your scheduled time</li>
                <li>Bring appropriate sports equipment and attire</li>
                <li>If you need to cancel or reschedule, please contact us at least 24 hours in advance</li>
                <li>Payment should be completed before your session starts</li>
            </ul>
        </div>

        <div style="text-align: center;">
            <a href="{{ env('APP_URL') }}/bookings" class="cta-button">View My Bookings</a>
        </div>

        <div class="footer">
            <p>Thank you for choosing our facility!</p>
            <p>If you have any questions, please don't hesitate to contact us.</p>
            <p><strong>Court Schedule Management System</strong></p>
        </div>
    </div>
</body>
</html>
