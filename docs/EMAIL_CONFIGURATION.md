# Email Configuration Guide

## Setup Email Notifications for Booking Approvals

When a booking is approved by an admin, the user will receive an email notification with their booking details and instructions on how to present their QR code.

### 1. Configure Email Settings in `.env`

Add the following to your `.env` file:

```env
# Email Configuration
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your_email@gmail.com
MAIL_PASSWORD=your_app_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@courtbooking.com
MAIL_FROM_NAME="${APP_NAME}"
```

### 2. Gmail Setup (Recommended)

If using Gmail:

1. Go to your Google Account settings
2. Enable 2-Factor Authentication
3. Generate an App Password:
   - Go to Security → 2-Step Verification → App passwords
   - Select "Mail" and your device
   - Copy the generated password
4. Use this app password in `MAIL_PASSWORD`

### 3. Alternative Email Providers

#### Mailtrap (Testing)
```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=your_mailtrap_username
MAIL_PASSWORD=your_mailtrap_password
MAIL_ENCRYPTION=tls
```

#### SendGrid
```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.sendgrid.net
MAIL_PORT=587
MAIL_USERNAME=apikey
MAIL_PASSWORD=your_sendgrid_api_key
MAIL_ENCRYPTION=tls
```

#### Mailgun
```env
MAIL_MAILER=mailgun
MAILGUN_DOMAIN=your_domain.mailgun.org
MAILGUN_SECRET=your_mailgun_api_key
```

### 4. Test Email Configuration

Run this command to test your email setup:

```bash
php artisan tinker
```

Then execute:

```php
Mail::raw('Test email from Court Booking System', function ($message) {
    $message->to('your_test_email@example.com')
            ->subject('Test Email');
});
```

### 5. Email Content

The email includes:

✅ **Booking Approval Confirmation**
- Personalized greeting with user's name
- Detailed booking information:
  - Court name and sport
  - Date and time slots
  - Individual slot prices
  - Total price

✅ **QR Code Instructions**
- Step-by-step guide on how to access QR code
- Instructions to present at venue
- Security reminders

✅ **Important Reminders**
- Arrive 10 minutes early
- Keep phone charged
- Don't share QR code
- Present at reception

### 6. When Emails Are Sent

Emails are automatically sent when:
- ✅ Admin approves a booking transaction
- 📧 Sent to the user's registered email
- 📱 Contains instructions for QR code presentation

### 7. Email Preview

Subject: **Booking Approved - Present Your QR Code**

The email is beautifully designed with:
- ✅ Approval header with green gradient
- 📋 Complete booking details
- 📱 QR code access instructions
- ⚠️ Important reminders
- 📧 Professional footer

### 8. Troubleshooting

**Problem**: Emails not sending
- Check `.env` configuration
- Verify email credentials
- Check `storage/logs/laravel.log` for errors
- Ensure port 587 is not blocked by firewall

**Problem**: Emails go to spam
- Use a verified domain
- Set proper SPF/DKIM records
- Use professional email service (SendGrid, Mailgun)

**Problem**: Gmail authentication errors
- Use App Password, not regular password
- Enable "Less secure app access" (not recommended)
- Or use App Password with 2FA (recommended)

### 9. Queue Configuration (Optional)

For better performance, queue emails:

```env
QUEUE_CONNECTION=database
```

Then run:
```bash
php artisan queue:table
php artisan migrate
php artisan queue:work
```

Update `BookingApproved.php`:
```php
class BookingApproved extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;
    // ...
}
```

### 10. Email Logs

Email sending is logged in `storage/logs/laravel.log`:

```
[2025-10-09 12:34:56] local.INFO: Approval email sent to: user@example.com
```

If email fails:
```
[2025-10-09 12:34:56] local.ERROR: Failed to send approval email: Connection error
```

### 11. Testing in Development

For local testing without sending real emails, use `log` driver:

```env
MAIL_MAILER=log
```

Emails will be written to `storage/logs/laravel.log`

---

## Production Checklist

- [ ] Configure email credentials in `.env`
- [ ] Test email sending
- [ ] Verify emails don't go to spam
- [ ] Set up email queue (optional)
- [ ] Monitor email logs
- [ ] Configure proper FROM address
- [ ] Set up email bounce handling
- [ ] Test with real user accounts

---

**Note**: Never commit `.env` file to version control! Keep email credentials secure.

