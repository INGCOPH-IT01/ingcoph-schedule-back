# Waitlist Payment Deadline Fix

## Problem
When a parent booking was rejected between 5pm and the next day 8am, the payment deadline for waitlisted users was incorrectly calculated. The system was using a simple "add 48 hours" logic that didn't respect business hours (8am - 5pm).

## Solution
Updated the waitlist notification system to use business hours logic when calculating payment deadlines.

### Business Hours Rules
- **Office Hours**: 8:00 AM - 5:00 PM (Monday - Saturday, excluding holidays)
- **During business hours**: Payment deadline is 1 hour from notification
- **After 5pm or before 8am**: Payment deadline is 9:00 AM next working day
- **On weekends/holidays**: Payment deadline is 9:00 AM next working day

## Changes Made

### 1. BookingWaitlist Model (`app/Models/BookingWaitlist.php`)
- Added `BusinessHoursHelper` import
- Updated `sendNotification()` method to use `BusinessHoursHelper::calculateExpirationTime()`
- Added comprehensive documentation explaining the business hours logic

**Before:**
```php
public function sendNotification(int $expirationHours = 1): void
{
    $now = Carbon::now();
    $this->update([
        'status' => self::STATUS_NOTIFIED,
        'notified_at' => $now,
        'expires_at' => $now->addHours($expirationHours)
    ]);
}
```

**After:**
```php
public function sendNotification(int $expirationHours = 1): void
{
    $now = Carbon::now();
    $expiresAt = BusinessHoursHelper::calculateExpirationTime($now);

    $this->update([
        'status' => self::STATUS_NOTIFIED,
        'notified_at' => $now,
        'expires_at' => $expiresAt
    ]);
}
```

### 2. CartTransactionController (`app/Http/Controllers/Api/CartTransactionController.php`)
- Updated `notifyWaitlistUsers()` method
- Removed hardcoded 48-hour parameter
- Added comments explaining business hours behavior

**Line 422-425:**
```php
// Send notification email with business-hours-aware payment deadline
// If rejected after 5pm or before 8am: deadline is 9am next working day
// If rejected during business hours: deadline is 1 hour from now
$waitlistEntry->sendNotification();
```

### 3. BookingController (`app/Http/Controllers/Api/BookingController.php`)
- Updated `processWaitlistForRejectedBooking()` method
- Removed hardcoded 48-hour parameter
- Added comments explaining business hours behavior

**Line 1099-1102:**
```php
// Send notification email with business-hours-aware payment deadline
// If rejected after 5pm or before 8am: deadline is 9am next working day
// If rejected during business hours: deadline is 1 hour from now
$waitlistEntry->sendNotification();
```

### 4. Email Template (`resources/views/emails/waitlist-notification.blade.php`)
- Enhanced payment deadline display to better handle next-day deadlines
- Shows full date and time when deadline is more than 2 hours away or next day
- Shows countdown in minutes for same-day deadlines within 2 hours
- Added office hours information for next-day deadlines
- Improved deadline messaging throughout the email

**Key improvements:**
- Dynamic display based on when deadline occurs
- Clear indication of office hours for next-day deadlines
- Better formatting for both same-day and next-day scenarios

## Testing Scenarios

### Scenario 1: Rejection During Business Hours
- **Time**: 2:00 PM (Tuesday)
- **Expected**: Deadline at 3:00 PM (same day)
- **Email should show**: "60 minutes" countdown

### Scenario 2: Rejection After Business Hours
- **Time**: 6:00 PM (Tuesday)
- **Expected**: Deadline at 9:00 AM (Wednesday)
- **Email should show**: Full date "Wed, Oct 23, 2025" and "9:00 AM"

### Scenario 3: Rejection Before Business Hours
- **Time**: 7:00 AM (Tuesday)
- **Expected**: Deadline at 9:00 AM (same day)
- **Email should show**: "9:00 AM" with countdown

### Scenario 4: Rejection on Saturday Evening
- **Time**: 6:00 PM (Saturday)
- **Expected**: Deadline at 9:00 AM (Monday)
- **Email should show**: Full date with Monday's date and "9:00 AM"

### Scenario 5: Rejection on Sunday
- **Time**: Any time (Sunday)
- **Expected**: Deadline at 9:00 AM (Monday)
- **Email should show**: Full date with Monday's date and "9:00 AM"

## How to Test

1. **Setup**: Ensure you have a booking that has waitlist entries
2. **Reject booking** at different times:
   - During business hours (10am)
   - After hours (6pm)
   - Before hours (7am)
   - On weekend
3. **Check email** sent to waitlisted user
4. **Verify deadline** in database (`booking_waitlists.expires_at`)
5. **Confirm** the deadline matches the business hours rules

## Database Verification

```sql
-- Check waitlist expiration times
SELECT
    id,
    user_id,
    status,
    notified_at,
    expires_at,
    TIMESTAMPDIFF(MINUTE, notified_at, expires_at) as minutes_to_expire
FROM booking_waitlists
WHERE status = 'notified'
ORDER BY notified_at DESC
LIMIT 10;
```

## Related Files
- `app/Helpers/BusinessHoursHelper.php` - Contains business hours calculation logic
- `app/Models/Holiday.php` - Defines holidays (affects working days)
- `app/Console/Commands/ExpireWaitlistEntries.php` - Cron job that expires old waitlist entries

## Notes
- This change ensures fair treatment of all waitlisted users regardless of when the parent booking is rejected
- The 1-hour payment window during business hours gives users adequate time to upload payment
- The 9am deadline for off-hours rejections ensures users have time during next business day
- The system automatically skips weekends and holidays when calculating next working day
