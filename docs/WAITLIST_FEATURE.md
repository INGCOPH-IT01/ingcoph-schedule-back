# Booking Waitlist Feature

## Quick Reference

| Scenario | Condition | Result |
|----------|-----------|--------|
| **Waitlist Trigger** | `approval_status = 'pending'` + Regular User | ⏳ Added to Waitlist |
| **Slot Taken** | `approval_status = 'approved'` | ❌ Booking Rejected |
| **Admin Booking** | Admin/Staff user | ✅ Bypass Waitlist |
| **Notification** | Admin rejects pending booking | 📧 Email sent to waitlist users |
| **Timer** | Email received | ⏱️ 1 hour to book |

### Key Point
**Waitlist is only shown when the conflicting booking's `approval_status` is `'pending'` (not yet approved by admin).**

## Overview
This feature allows users to join a waitlist when attempting to book a time slot that is currently pending approval by another user. Users on the waitlist receive email notifications when the slot becomes available (when admin rejects the pending booking), with a time-limited opportunity (1 hour) to complete their booking.

## How It Works

### For Regular Users Creating Bookings

1. **User attempts to book a slot** that has `approval_status = 'pending'` (not yet approved by admin) by another user
2. **Instead of being rejected**, the user is automatically added to a waitlist
3. **Waitlist confirmation** is shown with their position in the queue
4. **Email notification** is sent when the pending booking is **rejected** by admin
5. **Timer starts** only when the notification email is sent (not when joining the waitlist)
6. **User has 1 hour** from notification to complete their booking
7. **Waitlist entry expires** if not acted upon within the time limit

**Important:** Waitlist is ONLY triggered when:
- The conflicting booking's `approval_status` is **'pending'** (not yet reviewed/approved by admin)
- The conflicting booking is from a regular user (not admin/staff)
- The current user trying to book is also a regular user (admins bypass waitlist)

### Admin Behavior
- Admins can always book slots without being waitlisted
- Admin bookings bypass the waitlist system
- When admin books a pending slot, the pending user's booking becomes invalid (admin takes priority)

## When Time Slots Show as "Waitlist Available"

A time slot will show waitlist behavior when:

### ✅ Waitlist Triggered (User can join waitlist)
- Conflicting booking exists with `approval_status = 'pending'`
- Conflicting booking is from a regular user (role = 'user')
- Current user is a regular user (not admin/staff)
- **Result:** User is added to waitlist instead of being rejected

### ❌ Booking Rejected (Slot taken)
- Conflicting booking exists with `approval_status = 'approved'`
- Slot is already confirmed/paid
- **Result:** User receives "time slot no longer available" error

### ✅ Booking Allowed (No conflict)
- No conflicting bookings
- OR current user is admin/staff (bypass waitlist)
- **Result:** Booking proceeds normally

## Visual Flow Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                    User Tries to Book a Slot                    │
└────────────────────────┬────────────────────────────────────────┘
                         │
                         ▼
              ┌──────────────────────┐
              │ Is there a conflict? │
              └──────────┬───────────┘
                         │
           ┌─────────────┴─────────────┐
           │                           │
          NO                          YES
           │                           │
           ▼                           ▼
  ┌────────────────┐    ┌─────────────────────────────┐
  │ Booking Allowed│    │ Check Conflict Details      │
  └────────────────┘    └──────────┬──────────────────┘
                                   │
                     ┌─────────────┴─────────────┐
                     │                           │
           ┌─────────▼────────┐       ┌─────────▼────────┐
           │ approval_status  │       │ approval_status  │
           │   = 'approved'   │       │   = 'pending'    │
           └─────────┬────────┘       └─────────┬────────┘
                     │                           │
                     ▼                           ▼
           ┌──────────────────┐      ┌────────────────────┐
           │ Slot Taken       │      │ Is User Regular?   │
           │ (Rejected)       │      └─────────┬──────────┘
           └──────────────────┘                │
                                  ┌─────────────┴─────────────┐
                                  │                           │
                                YES                          NO
                                  │                     (Admin/Staff)
                                  ▼                           │
                    ┌───────────────────────┐                 ▼
                    │ Add to WAITLIST       │    ┌────────────────────┐
                    │ Position: #N          │    │ Booking Allowed    │
                    │ Email on rejection    │    │ (Bypass Waitlist)  │
                    └───────────────────────┘    └────────────────────┘
```

### Key Decision Points:

1. **Conflict Check:** Is there an existing booking for this time slot?
2. **Approval Status:** Is the conflicting booking `approval_status = 'pending'` or `'approved'`?
3. **User Role:** Is the current user a regular user or admin/staff?

### Results:
- **No Conflict** → ✅ Booking Allowed
- **Approved Conflict** → ❌ Booking Rejected (Slot Taken)
- **Pending Conflict + Regular User** → ⏳ Added to Waitlist
- **Pending Conflict + Admin** → ✅ Booking Allowed (Bypass)

## Database Schema

### New Table: `booking_waitlists`

```sql
- id
- user_id (FK to users)
- pending_booking_id (FK to bookings, nullable)
- pending_cart_transaction_id (FK to cart_transactions, nullable)
- court_id (FK to courts)
- sport_id (FK to sports)
- start_time (datetime)
- end_time (datetime)
- price (decimal)
- number_of_players (integer)
- position (integer) - Position in waitlist queue
- status (enum: pending, notified, converted, expired, cancelled)
- notified_at (datetime, nullable) - When email was sent (starts timer)
- expires_at (datetime, nullable) - When waitlist entry expires
- converted_cart_transaction_id (FK to cart_transactions, nullable)
- notes (text, nullable)
- created_at
- updated_at
```

## Backend Implementation

### 1. Model: `BookingWaitlist`
**Location:** `app/Models/BookingWaitlist.php`

Key methods:
- `sendNotification($expirationHours = 1)` - Send email and start timer
- `isExpired()` - Check if waitlist entry has expired
- `convert($cartTransaction)` - Convert waitlist to actual booking
- `markAsExpired()` - Mark entry as expired
- `getPendingForTimeSlot($courtId, $startTime, $endTime)` - Get pending waitlist entries

### 2. Mail Class: `WaitlistNotificationMail`
**Location:** `app/Mail/WaitlistNotificationMail.php`

Sends notification emails to waitlisted users when slots become available.

### 3. Email Template
**Location:** `resources/views/emails/waitlist-notification.blade.php`

Beautiful HTML email template showing:
- Urgent action required notice
- Time remaining countdown
- Booking slot details
- Call-to-action button
- Contact information

### 4. Cart Controller Updates
**Location:** `app/Http/Controllers/Api/CartController.php`

Modified `store()` method to:
- Check for conflicting pending bookings without payment
- Identify if conflict is with a regular user's pending booking
- Create waitlist entry instead of rejecting the booking
- Return special waitlist response to frontend

### 5. Cart Transaction Controller Updates
**Location:** `app/Http/Controllers/Api/CartTransactionController.php`

Added `notifyWaitlistUsers()` method to:
- Find all waitlist entries for the rejected/approved transaction
- Send notification emails to waitlisted users
- Start expiration timer (1 hour by default)
- Update waitlist status to 'notified'

### 6. Expiration Command
**Location:** `app/Console/Commands/ExpireWaitlistEntries.php`

Command: `php artisan waitlist:expire`

This command should be scheduled to run periodically (e.g., every 5 minutes) to:
- Find expired waitlist entries
- Mark them as expired
- Log the expiration

Add to `app/Console/Kernel.php`:
```php
protected function schedule(Schedule $schedule)
{
    $schedule->command('waitlist:expire')->everyFiveMinutes();
}
```

## Frontend Implementation

### Updated: `NewBookingDialog.vue`
**Location:** `src/components/NewBookingDialog.vue`

Modified `addToCart()` method to:
- Handle waitlist response from backend
- Show special waitlist confirmation dialog
- Display position in queue
- Inform user about email notification
- Exit booking flow gracefully

## API Responses

### Waitlist Response (200 OK)
```json
{
  "message": "This time slot is currently pending approval for another user. You have been added to the waitlist.",
  "waitlisted": true,
  "waitlist_entry": {
    "id": 1,
    "user_id": 5,
    "court_id": 2,
    "sport_id": 1,
    "start_time": "2025-10-21 14:00:00",
    "end_time": "2025-10-21 15:00:00",
    "price": 500.00,
    "position": 1,
    "status": "pending",
    "court": { ... },
    "sport": { ... }
  },
  "position": 1
}
```

## Email Flow

### Waitlist Notification Email

Sent when a pending booking is rejected, making the slot available.

**Subject:** "Booking Slot Now Available - [Court Name]"

**Content:**
- Urgent notice that slot is available
- Time remaining countdown
- Booking details (court, date, time, price)
- Call-to-action button to book now
- Expiration warning
- Contact information

**Timer:** Starts when email is sent, expires after 1 hour

## Status Flow

```
PENDING → NOTIFIED → CONVERTED (successful booking)
                   → EXPIRED (timer ran out)
                   → CANCELLED (user cancelled)
```

## Testing the Feature

### Test Scenario: Waitlist Flow

#### 1. User A Creates a Pending Booking
```bash
# User A (regular user) creates a booking with payment proof
POST /api/cart
{
  "items": [{
    "court_id": 1,
    "sport_id": 1,
    "booking_date": "2025-10-21",
    "start_time": "14:00",
    "end_time": "15:00",
    "price": 500,
    "number_of_players": 4
  }]
}

# Then checkout with payment
POST /api/cart/checkout
{
  "payment_method": "gcash",
  "proof_of_payment": [file]
}

# At this point:
# - CartTransaction created with approval_status = 'pending'
# - Booking is waiting for admin approval
```

#### 2. User B Tries to Book Same Slot (Gets Waitlisted)
```bash
# User B (regular user) tries to book the same slot
POST /api/cart
{
  "items": [{
    "court_id": 1,
    "sport_id": 1,
    "booking_date": "2025-10-21",
    "start_time": "14:00",
    "end_time": "15:00",
    "price": 500,
    "number_of_players": 2
  }]
}

# Response: User B is waitlisted (because User A's approval_status is 'pending')
{
  "message": "This time slot is currently pending approval for another user...",
  "waitlisted": true,
  "waitlist_entry": {
    "id": 1,
    "user_id": 2,
    "court_id": 1,
    "position": 1,
    "status": "pending",
    ...
  },
  "position": 1
}
```

#### 3. Admin Reviews User A's Booking

**Case 3a: Admin Rejects User A's Booking**
```bash
# Admin rejects User A's booking
POST /api/cart-transactions/{userA_transaction_id}/reject
{
  "reason": "Invalid payment proof"
}

# This triggers:
# 1. User A's booking is rejected (approval_status = 'rejected')
# 2. Email sent to User B (waitlist notification)
# 3. User B's waitlist status changes to 'notified'
# 4. Timer starts for User B (expires_at = now + 1 hour)
```

**Case 3b: Admin Approves User A's Booking**
```bash
# Admin approves User A's booking
POST /api/cart-transactions/{userA_transaction_id}/approve

# This results in:
# 1. User A's booking is confirmed (approval_status = 'approved')
# 2. User B's waitlist entry remains 'pending' (no notification)
# 3. If User B tries to book again, they get "slot not available" error
```

### 4. User B Receives Email
- User B gets email notification
- Has 1 hour to complete booking
- Timer shown in email

### 5. Check Expiration
```bash
# Run manually or via cron
php artisan waitlist:expire
```

## Configuration

### Expiration Time
Default is 1 hour. Can be modified in:
- `BookingWaitlist::sendNotification($expirationHours)` method
- Pass different hours when calling `sendNotification(2)` for 2 hours

### Schedule Command
Add to `app/Console/Kernel.php`:
```php
protected function schedule(Schedule $schedule)
{
    // Check every 5 minutes for expired waitlist entries
    $schedule->command('waitlist:expire')->everyFiveMinutes();
}
```

Make sure cron is running:
```bash
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
```

## Key Features

✅ **Smart Waitlist Logic** - Only waitlists for pending user bookings without payment
✅ **Position Tracking** - Users know their position in the queue
✅ **Email Notifications** - Professional HTML emails with countdown timers
✅ **Timer Only on Notification** - Timer starts when email is sent, not when joining waitlist
✅ **Automatic Expiration** - Console command handles expiration cleanup
✅ **Admin Bypass** - Admins never get waitlisted
✅ **Frontend Integration** - Beautiful dialogs showing waitlist status
✅ **Multiple Waitlist Support** - Multiple users can waitlist the same slot

## Future Enhancements

1. **Automatic Next User Notification** - When a waitlist entry expires, automatically notify the next user
2. **SMS Notifications** - Add SMS alerts for time-sensitive notifications
3. **Waitlist Dashboard** - User dashboard showing their waitlist entries
4. **Priority Waitlist** - VIP users get higher priority in waitlist
5. **Waitlist History** - Track waitlist conversion rates and analytics
6. **Push Notifications** - Browser push notifications for real-time alerts

## Troubleshooting

### Emails Not Sending
- Check mail configuration in `.env`
- Check `storage/logs/laravel.log` for email errors
- Verify email template exists at `resources/views/emails/waitlist-notification.blade.php`

### Waitlist Not Expiring
- Ensure cron is running: `php artisan schedule:list`
- Run manually: `php artisan waitlist:expire`
- Check logs: `tail -f storage/logs/laravel.log`

### Users Not Being Waitlisted
- Verify the pending booking is from a regular user (not admin)
- Check that pending booking has `payment_status = 'unpaid'`
- Verify user role is 'user' (not 'admin' or 'staff')

## Database Migration

Run the migration:
```bash
php artisan migrate
```

Rollback if needed:
```bash
php artisan migrate:rollback
```

## Conclusion

The waitlist feature provides a fair and efficient way to handle overlapping booking requests while maintaining user satisfaction through transparent communication and time-bound opportunities.
