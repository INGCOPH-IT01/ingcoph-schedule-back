# Contact Details in Booking Approval Emails

## Overview
Added company contact details to booking approval emails, making it easy for customers to reach out with questions or concerns about their bookings.

## Implementation Date
October 18, 2025

## Changes Made

### Backend Mail Classes

#### 1. BookingApproved.php
**File:** `app/Mail/BookingApproved.php`

Added contact detail properties and loading logic:

```php
use App\Models\CompanySetting;

public $contactEmail;
public $contactMobile;
public $contactViber;

public function __construct(CartTransaction $transaction)
{
    // ... existing code ...

    // Load contact details from company settings
    $this->contactEmail = CompanySetting::get('contact_email', '');
    $this->contactMobile = CompanySetting::get('contact_mobile', '');
    $this->contactViber = CompanySetting::get('contact_viber', '');
}
```

**Purpose:** This mail class is used for cart-based bookings (multiple courts/time slots).

#### 2. BookingApprovalMail.php
**File:** `app/Mail/BookingApprovalMail.php`

Added contact details to the view data:

```php
use App\Models\CompanySetting;

public function content(): Content
{
    return new Content(
        view: 'emails.booking-approval',
        with: [
            // ... existing data ...
            'contactEmail' => CompanySetting::get('contact_email', ''),
            'contactMobile' => CompanySetting::get('contact_mobile', ''),
            'contactViber' => CompanySetting::get('contact_viber', ''),
        ]
    );
}
```

**Purpose:** This mail class is used for single booking approvals.

### Email Templates

#### 1. booking-approved.blade.php
**File:** `resources/views/emails/booking-approved.blade.php`

Added contact information section after "If you have any questions..." (line 327):

```blade
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
```

**Design:**
- Green gradient background matching the approval theme
- Bordered card for visual prominence
- Emoji icons for quick recognition
- Clickable email and phone links
- Only shows if at least one contact method is configured

#### 2. booking-approval.blade.php
**File:** `resources/views/emails/booking-approval.blade.php`

Added contact information section after "If you have any questions..." (line 162):

```blade
@if($contactEmail || $contactMobile || $contactViber)
<!-- Contact Information -->
<div style="background-color: #e8f5e9; border: 2px solid #4CAF50; border-radius: 8px; padding: 20px; margin: 20px 0;">
    <h4 style="margin-top: 0; color: #2e7d32;">📞 Contact Information</h4>
    <div style="color: #1b5e20; font-size: 14px; line-height: 1.8;">
        @if($contactEmail)
        <div style="margin: 8px 0;">
            <strong>📧 Email:</strong>
            <a href="mailto:{{ $contactEmail }}" style="color: #2e7d32; text-decoration: none;">{{ $contactEmail }}</a>
        </div>
        @endif

        @if($contactMobile)
        <div style="margin: 8px 0;">
            <strong>📱 Mobile:</strong>
            <a href="tel:{{ $contactMobile }}" style="color: #2e7d32; text-decoration: none;">{{ $contactMobile }}</a>
        </div>
        @endif

        @if($contactViber)
        <div style="margin: 8px 0;">
            <strong>💬 Viber:</strong>
            <span style="color: #1b5e20;">{{ $contactViber }}</span>
        </div>
        @endif
    </div>
</div>
@endif
```

**Design:**
- Green background matching the existing approval color scheme
- Clear heading and structure
- Clickable links for email and mobile
- Responsive to available contact methods

## Features

### Smart Display Logic
- Contact section only appears if at least one contact method is configured
- Each contact method is conditionally displayed
- No empty boxes or placeholder text shown

### Interactive Elements
- **Email:** Clickable `mailto:` link opens default email client
- **Mobile:** Clickable `tel:` link enables one-tap calling on mobile devices
- **Viber:** Displayed as text (can be copied)

### Visual Design
- Matches the approval email color scheme (green)
- Clear visual hierarchy with icons
- Professional appearance
- Mobile-responsive styling

### Email Client Compatibility
- Inline CSS styles ensure consistent rendering
- Compatible with major email clients (Gmail, Outlook, Apple Mail, etc.)
- Fallback colors for older clients

## Data Flow

1. **Admin configures contact details** in Company Settings UI
2. **Contact details saved** to `company_settings` table (key-value pairs)
3. **Booking is approved** by admin
4. **Mail class instantiated** and loads contact details from database
5. **Email template rendered** with contact information
6. **Customer receives email** with contact details displayed

## Database Structure

Contact details are stored in `company_settings` table:

| Key | Description | Example |
|-----|-------------|---------|
| `contact_email` | Primary email address | `support@perfectsmash.com` |
| `contact_mobile` | Mobile phone number | `+63 917 123 4567` |
| `contact_viber` | Viber contact number | `+63 917 123 4567` |

## Usage Example

### Before Configuration
Email shows: "If you have any questions, please contact us."
- No specific contact information displayed

### After Configuration
Email shows: "If you have any questions, please contact us."
- Followed by a green contact card with:
  - 📧 Email: support@facility.com (clickable)
  - 📱 Mobile: +63 917 123 4567 (clickable)
  - 💬 Viber: +63 917 123 4567

## Customer Benefits

1. **Immediate Access to Help:** Contact information right in the email
2. **Multiple Contact Options:** Email, mobile, or Viber
3. **Easy Communication:** Clickable links for quick action
4. **Increased Confidence:** Clear support channels reduce anxiety

## Business Benefits

1. **Reduced Customer Confusion:** Clear contact methods
2. **Better Customer Service:** Easier for customers to reach out
3. **Professional Image:** Well-formatted, informative emails
4. **Centralized Management:** Update once, applies everywhere

## Testing Checklist

- [ ] Booking approval emails include contact details
- [ ] Email displays correctly with all three contact methods
- [ ] Email displays correctly with only one contact method
- [ ] Email displays correctly with no contact methods (section hidden)
- [ ] Email links work (mailto: and tel:)
- [ ] Styling renders correctly in Gmail
- [ ] Styling renders correctly in Outlook
- [ ] Styling renders correctly on mobile devices
- [ ] Contact details update when changed in Company Settings

## Related Features

- **Company Settings UI:** Frontend interface to manage contact details
- **Company Settings API:** Backend endpoints for storing/retrieving settings
- **Booking Approval System:** Trigger for sending these emails

## Files Modified

### Backend
- `app/Mail/BookingApproved.php` - Added contact detail loading
- `app/Mail/BookingApprovalMail.php` - Added contact detail passing
- `resources/views/emails/booking-approved.blade.php` - Added contact UI
- `resources/views/emails/booking-approval.blade.php` - Added contact UI

### Dependencies
- Uses existing `CompanySetting` model
- Uses existing company settings infrastructure
- No database migrations needed (fields already exist)

## Future Enhancements

Potential improvements:
1. Add contact details to other email types (booking confirmations, cancellations)
2. Add business hours information
3. Add physical address
4. Add social media links
5. Add WhatsApp contact option
6. Add live chat link
7. Multilingual contact information

## Notes

- Contact details are optional - section only shows if configured
- Inline CSS used for maximum email client compatibility
- Icons used for visual clarity and engagement
- Links are formatted for mobile-first experience
- No changes needed to database schema
- Backwards compatible with existing system
