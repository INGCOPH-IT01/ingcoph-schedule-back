<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset OTP</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .email-header {
            background: linear-gradient(135deg, #B71C1C 0%, #C62828 100%);
            color: #ffffff;
            padding: 40px 30px;
            text-align: center;
        }
        .email-header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 700;
        }
        .email-body {
            padding: 40px 30px;
        }
        .greeting {
            font-size: 18px;
            margin-bottom: 20px;
            color: #333;
        }
        .message {
            font-size: 16px;
            color: #666;
            margin-bottom: 30px;
            line-height: 1.8;
        }
        .otp-container {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            border: 2px dashed #B71C1C;
            border-radius: 12px;
            padding: 30px;
            text-align: center;
            margin: 30px 0;
        }
        .otp-label {
            font-size: 14px;
            color: #666;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }
        .otp-code {
            font-size: 42px;
            font-weight: 800;
            color: #B71C1C;
            letter-spacing: 8px;
            margin: 10px 0;
            font-family: 'Courier New', monospace;
        }
        .otp-validity {
            font-size: 13px;
            color: #999;
            margin-top: 10px;
        }
        .warning {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .warning p {
            margin: 0;
            font-size: 14px;
            color: #856404;
        }
        .email-footer {
            background-color: #f8f9fa;
            padding: 30px;
            text-align: center;
            font-size: 13px;
            color: #999;
            border-top: 1px solid #dee2e6;
        }
        .email-footer p {
            margin: 5px 0;
        }
        .button {
            display: inline-block;
            padding: 12px 30px;
            background: linear-gradient(135deg, #B71C1C 0%, #C62828 100%);
            color: #ffffff;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header">
            <h1>🔒 Password Reset</h1>
        </div>

        <div class="email-body">
            <div class="greeting">
                Hello {{ $userName }},
            </div>

            <div class="message">
                We received a request to reset your password. Please use the following One-Time Password (OTP) to complete your password reset:
            </div>

            <div class="otp-container">
                <div class="otp-label">Your OTP Code</div>
                <div class="otp-code">{{ $otp }}</div>
                <div class="otp-validity">⏱️ Valid for 10 minutes</div>
            </div>

            <div class="message">
                Enter this code in the password reset form to proceed with setting your new password.
            </div>

            <div class="warning">
                <p><strong>⚠️ Security Notice:</strong> If you did not request a password reset, please ignore this email and ensure your account is secure. This OTP will expire in 10 minutes.</p>
            </div>

            <div class="message" style="margin-top: 30px; font-size: 14px; color: #999;">
                For security reasons, never share this OTP with anyone. Our team will never ask for your OTP.
            </div>
        </div>

        <div class="email-footer">
            <p><strong>Perfect Smash Badminton & Pickleball Courts</strong></p>
            <p>This is an automated email. Please do not reply to this message.</p>
            <p>&copy; {{ date('Y') }} Perfect Smash. All rights reserved.</p>
        </div>
    </div>
</body>
</html>

