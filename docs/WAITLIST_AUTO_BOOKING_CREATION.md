# Automated Waitlist Booking Creation System

## Overview

This system **automatically creates bookings** for waitlisted users when the blocking booking is rejected. Users receive email notifications asking them to upload payment within 48 hours.

## How It Works

### 1. Waitlist Creation (When User Gets Waitlisted)

When a user tries to book a slot that's already pending:

```
User tries to book → Slot is pending → System creates:
  ✅ Cart Transaction
  ✅ Cart Item (court + time)
  ✅ Waitlist Entry (with pending_booking_id)  ← NEW!
```

**Key Change**: The waitlist entry now saves `pending_booking_id` - the ID of the blocking booking.

### 2. Automatic Booking Creation (When Blocking Booking is Rejected)

When admin rejects a booking:

```
Admin rejects booking
    ↓
System finds all waitlist entries with pending_booking_id = rejected_booking.id
    ↓
For each waitlisted user:
  ✅ Create new booking automatically
  ✅ Set status = 'pending' (awaiting payment)
  ✅ Set payment_status = 'unpaid'
  ✅ Send email notification
  ✅ Give 48-hour payment deadline
  ✅ Mark waitlist as 'notified'
```

## Database Changes

### Waitlist Entry Structure

```sql
booking_waitlists:
  - pending_booking_id        ← NOW POPULATED!
  - pending_cart_transaction_id
  - user_id
  - court_id, sport_id
  - start_time, end_time
  - price, number_of_players
  - position (queue position)
  - status ('pending' → 'notified' when converted)
  - notified_at (when email sent)
  - expires_at (48 hours from notification)
```

### Auto-Created Booking

```sql
bookings:
  - user_id (waitlisted user)
  - cart_transaction_id (null initially)
  - court_id, sport_id
  - start_time, end_time
  - total_price
  - status = 'pending' (awaiting payment)
  - payment_status = 'unpaid'
  - payment_method = 'pending'
  - notes = 'Auto-created from waitlist position #N'
```

## Email Notification

Waitlisted users receive an email with:

📧 **Subject**: "Your Waitlisted Slot is Now Available!"

**Content**:
- ✅ Slot is now available
- ⏰ 48-hour payment deadline
- 💳 Instructions to upload payment proof
- 🏢 Court details
- 📅 Date and time
- 💰 Price

## Complete Flow Example

### Step 1: Admin Books a Slot

```bash
# Admin creates booking
POST /api/cart/checkout (as admin)

# Result: Booking #1 created
# - status: 'pending'
# - user_id: 1 (admin)
# - court_id: 1
# - start_time: '2025-10-24 10:00:00'
```

### Step 2: Regular User Gets Waitlisted

```bash
# Regular user tries to book same slot
POST /api/cart (as regular user)

# Result:
# - Waitlist Entry #1 created
#   - pending_booking_id: 1  ← Links to admin's booking
#   - user_id: 2
#   - status: 'pending'
#   - position: 1
#
# - Cart Item created for user #2
# - Cart Transaction created for user #2
```

### Step 3: Admin Rejects the Booking

```bash
# Admin rejects their own booking
POST /api/cart-transactions/1/reject
{
  "reason": "Schedule conflict"
}

# System automatically:
# 1. Marks Booking #1 as 'rejected'
# 2. Finds Waitlist Entry #1 (pending_booking_id = 1)
# 3. Creates Booking #2 automatically:
#    - user_id: 2 (waitlisted user)
#    - status: 'pending' (awaiting payment)
#    - payment_status: 'unpaid'
#    - notes: 'Auto-created from waitlist position #1'
# 4. Marks Waitlist Entry #1 as 'notified'
# 5. Sets expires_at = now() + 48 hours
# 6. Sends email to user #2
```

### Step 4: Waitlisted User Uploads Payment

```bash
# User #2 receives email, uploads payment
# They can either:
# Option A: Use existing cart items to checkout
# Option B: Admin manually marks Booking #2 as paid

# If using cart:
POST /api/cart/checkout (as user #2)
{
  "payment_method": "gcash",
  "proof_of_payment": [base64...]
}

# Result:
# - Booking #2 status changes to 'approved'
# - Cart items marked as 'completed'
# - Waitlist marked as 'converted'
```

## Key Features

### ✅ Automatic Booking Creation
- No manual intervention needed
- Booking created immediately upon rejection
- Preserves all waitlist details

### ⏰ 48-Hour Payment Window
- Users have 48 hours to upload payment
- Configurable in `sendNotification(48)`
- Can be changed to any duration

### 📊 Position-Based Processing
- Waitlist entries processed in order
- Position #1 gets notified first
- Fair queue system

### 🔄 Multiple Waitlist Support
- Multiple users can be waitlisted for same slot
- All get notified when slot becomes available
- First to pay gets the slot (race condition)

### 📧 Email Notifications
- Professional HTML email
- Clear payment instructions
- Deadline reminder
- Booking details included

## API Endpoints Affected

### Cart Store (Waitlist Creation)
```
POST /api/cart
```

**New Response Fields**:
```json
{
  "waitlisted": true,
  "waitlist_entry": {
    "pending_booking_id": 1,  ← NEW!
    "pending_cart_transaction_id": 2,
    "user_id": 2,
    "position": 1
  }
}
```

### Transaction Rejection (Triggers Auto-Creation)
```
POST /api/cart-transactions/{id}/reject
```

**Background Process**: Creates bookings for all waitlisted users

### Booking Rejection (Triggers Auto-Creation)
```
POST /api/bookings/{id}/reject
```

**Background Process**: Creates bookings for all waitlisted users

## Configuration

### Payment Deadline

In both `CartTransactionController` and `BookingController`:

```php
// Current: 48 hours
$waitlistEntry->sendNotification(48);

// To change to 24 hours:
$waitlistEntry->sendNotification(24);

// To change to 1 hour:
$waitlistEntry->sendNotification(1);
```

### Email Template

Located at: `resources/views/emails/waitlist-notification.blade.php`

## Monitoring & Logs

### Success Logs
```
[INFO] Waitlist auto-converted to booking
{
  "waitlist_id": 5,
  "new_booking_id": 10,
  "user_id": 2,
  "rejected_booking_id": 1,
  "court_id": 1
}
```

### Error Logs
```
[ERROR] Waitlist conversion failed
{
  "waitlist_id": 5,
  "error": "Duplicate booking exists"
}
```

## Edge Cases Handled

### 1. Multiple Waitlist Entries
- All waitlisted users for the same slot get bookings
- Processed in order of position
- Each gets 48-hour window

### 2. Payment Race Condition
- Multiple users can have pending bookings
- First to upload valid payment gets the slot
- Others' bookings remain pending until admin reviews

### 3. Booking Already Exists
- System checks for existing bookings
- Prevents duplicate bookings
- Logs error but continues with other waitlist entries

### 4. Email Failure
- Booking still created even if email fails
- Error logged for manual follow-up
- User can still see booking in their account

### 5. Transaction vs Individual Booking Rejection
- Both trigger waitlist processing
- Transaction rejection: processes all bookings in transaction
- Individual rejection: processes only that booking

## Testing

### Test Scenario 1: Basic Auto-Creation

```bash
# 1. Admin creates booking
POST /api/cart/checkout (as admin)
# Booking #1 created

# 2. User gets waitlisted
POST /api/cart (as user)
# Waitlist #1 created with pending_booking_id = 1

# 3. Admin rejects booking
POST /api/cart-transactions/1/reject
{
  "reason": "Test"
}

# 4. Verify:
# - Booking #2 created for user
# - status = 'pending'
# - payment_status = 'unpaid'
# - Waitlist #1 status = 'notified'
# - Email sent to user
```

### Test Scenario 2: Multiple Waitlist Users

```bash
# 1. Admin creates booking
# Booking #1

# 2. User A gets waitlisted
# Waitlist #1 (position 1)

# 3. User B gets waitlisted
# Waitlist #2 (position 2)

# 4. Admin rejects booking
# System creates:
# - Booking #2 for User A
# - Booking #3 for User B
# Both have 48 hours to pay
```

## Files Modified

1. **CartController.php** (Lines 230-311)
   - Save `pending_booking_id` when creating waitlist
   - Link waitlist to blocking booking

2. **CartTransactionController.php** (Lines 384-448)
   - Auto-create bookings in `notifyWaitlistUsers()`
   - Process waitlist by `pending_booking_id`

3. **BookingController.php** (Lines 1045-1124)
   - Added `processWaitlistForRejectedBooking()` method
   - Trigger waitlist processing on individual booking rejection

## Benefits

✅ **Automated** - No manual booking creation needed
✅ **Fair** - Position-based queue system
✅ **Time-bound** - 48-hour payment deadline
✅ **Transparent** - Email notifications with clear instructions
✅ **Audit trail** - All conversions logged
✅ **Efficient** - Reduces admin workload

## Future Enhancements

1. **Auto-expiration** - Cancel unpaid bookings after 48 hours
2. **Next-in-queue** - Auto-notify next user if first doesn't pay
3. **SMS notifications** - In addition to email
4. **Payment reminders** - Send reminder at 24 hours, 1 hour remaining
5. **Priority waitlist** - VIP users get higher positions

## Troubleshooting

### Bookings Not Auto-Creating

**Check**:
1. Is `pending_booking_id` set in waitlist entry?
   ```sql
   SELECT * FROM booking_waitlists WHERE pending_booking_id IS NOT NULL;
   ```

2. Are there waitlist entries for the rejected booking?
   ```sql
   SELECT * FROM booking_waitlists
   WHERE pending_booking_id = [rejected_booking_id]
   AND status = 'pending';
   ```

3. Check logs for errors:
   ```bash
   tail -f storage/logs/laravel.log | grep "Waitlist"
   ```

### Emails Not Sending

**Check**:
1. Mail configuration in `.env`
2. Email exists for user
3. Check mail logs
4. Test email manually

## Summary

The automated waitlist booking creation system:
- **Saves** the blocking booking ID when creating waitlist
- **Auto-creates** bookings when blocking booking is rejected
- **Notifies** users via email with 48-hour payment deadline
- **Reduces** manual work for admins
- **Improves** user experience with automatic slot allocation

**Status**: 🟢 FULLY IMPLEMENTED
