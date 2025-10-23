# Waitlist Notification on Parent Booking Approval

## Overview
When a parent booking (that has waitlist entries) is approved, the system now:
1. Sends notification emails to all waitlisted users
2. Automatically rejects any auto-created bookings for those waitlisted users
3. Cancels all waitlist entries for that time slot

## Problem Being Solved
Previously, when a booking with waitlist entries was approved:
- Waitlist users who were notified might have already created bookings and attempted to upload payment
- These users were unaware that the parent booking was approved
- Their bookings would remain pending indefinitely without proper notification
- This caused confusion and wasted user effort

## Solution Implemented

### 1. Automatic Waitlist Cancellation
When a booking is approved (either individual booking or cart transaction), the system:
- Finds ALL waitlist entries (pending AND notified) for the same time slot
- Cancels each waitlist entry
- Sends cancellation email to each waitlisted user

### 2. Automatic Booking Rejection
For waitlisted users who were previously notified (when parent booking was rejected):
- Finds any auto-created bookings for the waitlisted user
- Automatically rejects these bookings
- Adds a note explaining the rejection
- Broadcasts the status change in real-time

### 3. Email Notification
Sends a clear email to waitlisted users explaining:
- The waitlist entry has been cancelled
- The parent booking was approved
- Any booking they created will be automatically rejected
- No payment upload is needed

## Technical Implementation

### Files Modified

#### 1. `app/Http/Controllers/Api/CartTransactionController.php`
**Method:** `cancelWaitlistUsers()`

**Changes:**
- Updated to find both `PENDING` and `NOTIFIED` waitlist entries
- Added logic to find and reject auto-created bookings
- Added status broadcasting for rejected bookings
- Enhanced logging for better debugging

**Location in code:**
```php
Lines 333-409
```

**Key logic:**
```php
// Find ALL waitlist entries (pending and notified)
$waitlistEntries = BookingWaitlist::where('court_id', $cartItem->court_id)
    ->where('start_time', $startDateTime)
    ->where('end_time', $endDateTime)
    ->whereIn('status', [BookingWaitlist::STATUS_PENDING, BookingWaitlist::STATUS_NOTIFIED])
    ->get();

// Find and reject auto-created bookings
$autoCreatedBookings = \App\Models\Booking::where('user_id', $waitlistEntry->user_id)
    ->where('court_id', $waitlistEntry->court_id)
    ->where('start_time', $waitlistEntry->start_time)
    ->where('end_time', $waitlistEntry->end_time)
    ->whereIn('status', ['pending', 'approved'])
    ->where('notes', 'like', '%Auto-created from waitlist%')
    ->get();

foreach ($autoCreatedBookings as $booking) {
    $booking->update([
        'status' => 'rejected',
        'notes' => $booking->notes . "\n\nAuto-rejected: Parent booking was approved."
    ]);
    broadcast(new \App\Events\BookingStatusChanged($booking, $booking->status, 'rejected'))->toOthers();
}
```

#### 2. `app/Http/Controllers/Api/BookingController.php`

**Method:** `approveBooking()`
**Changes:** Added call to `cancelWaitlistForApprovedBooking()`

**Location:**
```php
Lines 984-992
```

**New Method:** `cancelWaitlistForApprovedBooking()`
**Location:**
```php
Lines 1139-1206
```

**Description:** Handles waitlist cancellation and booking rejection for individual booking approvals (same logic as cart transaction approval)

#### 3. `resources/views/emails/waitlist-cancelled.blade.php`

**Changes:**
- Added prominent notice that auto-created bookings will be rejected
- Clarified that no payment upload is needed
- Enhanced messaging for better user understanding

**Key additions:**
```html
<p style="margin-top: 10px;">
    <strong>Any booking you made for this slot will be automatically rejected.</strong>
</p>

<p><strong>Important:</strong> If you received a notification to upload payment for
this time slot and created a booking, that booking will be automatically rejected.
You do not need to upload any payment.</p>
```

## Flow Diagram

### Scenario 1: Parent Booking Approved (Waitlist Pending)
```
1. Parent Booking Created → Waitlist Entry Created (STATUS_PENDING)
2. Admin Approves Parent Booking
   ├─→ Waitlist Entry Cancelled (STATUS_CANCELLED)
   └─→ Email Sent to Waitlisted User
```

### Scenario 2: Parent Booking Rejected then Approved (Waitlist Notified)
```
1. Parent Booking Created → Waitlist Entry Created (STATUS_PENDING)
2. Admin Rejects Parent Booking
   ├─→ Waitlist Entry Updated (STATUS_NOTIFIED)
   ├─→ Auto-Created Booking for Waitlisted User (STATUS_PENDING)
   └─→ Email Sent: "Upload Payment by [deadline]"
3. Admin Approves Parent Booking (reverses the rejection)
   ├─→ Waitlist Entry Cancelled (STATUS_CANCELLED)
   ├─→ Auto-Created Booking Rejected (STATUS_REJECTED)
   ├─→ Status Broadcast to Real-time Listeners
   └─→ Email Sent: "Waitlist Cancelled, Booking Rejected"
```

## Database Changes

### Waitlist Status Flow
```
PENDING → NOTIFIED → CANCELLED (when parent approved)
                   → CONVERTED (when user completes payment)
                   → EXPIRED (when deadline passes)
```

### Booking Status Flow (for auto-created bookings)
```
PENDING → REJECTED (when parent booking approved after rejection)
       → APPROVED (when user uploads payment and admin approves)
```

## Email Template Content

### Subject Line
"Waitlist Booking Cancelled - [Court Name]"

### Key Messages
1. **Alert Box:** Clear indication that waitlist is cancelled
2. **Booking Details:** Shows what time slot was affected
3. **Action Required:** Explains no action needed from user
4. **Contact Info:** Support contact details if user has questions

## Testing Scenarios

### Test 1: Simple Waitlist Cancellation
**Steps:**
1. User A creates booking for Court 1, 10am-11am
2. User B joins waitlist for same slot
3. Admin approves User A's booking

**Expected Results:**
- User B's waitlist entry status: `cancelled`
- User B receives email notification
- No bookings created for User B

### Test 2: Waitlist with Auto-Created Booking
**Steps:**
1. User A creates booking for Court 1, 10am-11am
2. User B joins waitlist for same slot
3. Admin rejects User A's booking
   - User B's waitlist becomes `notified`
   - Auto-created booking for User B (status: `pending`)
   - Email sent to User B to upload payment
4. Admin approves User A's booking (reverses decision)

**Expected Results:**
- User B's waitlist entry status: `cancelled`
- User B's auto-created booking status: `rejected`
- User B receives cancellation email
- Real-time status update broadcast
- Log entry created with rejection details

### Test 3: Multiple Waitlist Users
**Steps:**
1. User A creates booking for Court 1, 10am-11am
2. User B, User C, User D join waitlist (positions 1, 2, 3)
3. Admin rejects User A's booking
   - All three users notified
   - Auto-created bookings for all three
4. Admin approves User A's booking

**Expected Results:**
- All three waitlist entries cancelled
- All three auto-created bookings rejected
- All three users receive cancellation emails
- Proper logging for each user

### Test 4: Cart Transaction Approval with Multiple Bookings
**Steps:**
1. User A creates cart with 3 bookings (different time slots)
2. User B, C, D join waitlist for all three slots
3. Admin approves User A's cart transaction

**Expected Results:**
- All waitlist entries for all three slots cancelled
- Cancellation emails sent to all affected users
- All auto-created bookings rejected (if any exist)

## Logging

### Success Logs
```php
Log::info('Waitlist cancelled due to parent booking approval', [
    'waitlist_id' => $waitlistEntry->id,
    'user_id' => $waitlistEntry->user_id,
    'court_id' => $waitlistEntry->court_id,
    'approved_booking_id' => $approvedBooking->id,
    'rejected_bookings' => $autoCreatedBookings->pluck('id')->toArray()
]);
```

### Error Logs
```php
Log::error('waitlist_cancellation_individual_error', [
    'waitlist_id' => $waitlistEntry->id,
    'error' => $e->getMessage(),
]);
```

## Real-Time Features

### Broadcasting
When auto-created bookings are rejected, the system broadcasts:
```php
broadcast(new \App\Events\BookingStatusChanged($booking, $booking->status, 'rejected'))
    ->toOthers();
```

This ensures:
- Admin dashboard updates in real-time
- User's booking list updates automatically
- Calendar view reflects the rejection immediately

## Error Handling

### Graceful Failure
- Email failures don't prevent booking approval
- Individual waitlist cancellation failures don't affect others
- All errors are logged for debugging
- Transaction continues even if notification fails

### Try-Catch Blocks
```php
try {
    $this->cancelWaitlistForApprovedBooking($booking);
} catch (\Exception $e) {
    Log::error('waitlist_cancellation_error_on_booking_approval', [
        'booking_id' => $booking->id,
        'error' => $e->getMessage()
    ]);
}
```

## Related Features

### Related Files
- `app/Models/BookingWaitlist.php` - Waitlist model
- `app/Mail/WaitlistCancelledMail.php` - Email mailable
- `app/Events/BookingStatusChanged.php` - Broadcasting event
- `WAITLIST_PAYMENT_DEADLINE_FIX.md` - Payment deadline calculation

### Related Commands
- `ExpireWaitlistEntries` - Cron job to expire old waitlist entries

## Future Enhancements

### Potential Improvements
1. **SMS Notifications:** Add SMS alerts for urgent cancellations
2. **Push Notifications:** Mobile app notifications
3. **Undo Feature:** Allow admin to undo approval within grace period
4. **Auto-Reassign:** Automatically offer slot to next waitlist user
5. **Compensation System:** Offer discount/credit to affected users

## Database Queries for Verification

### Check Waitlist Cancellations
```sql
SELECT
    w.id,
    w.user_id,
    u.name as user_name,
    w.status,
    w.created_at,
    w.updated_at,
    c.name as court_name,
    w.start_time,
    w.end_time
FROM booking_waitlists w
JOIN users u ON w.user_id = u.id
JOIN courts c ON w.court_id = c.id
WHERE w.status = 'cancelled'
ORDER BY w.updated_at DESC
LIMIT 20;
```

### Check Auto-Rejected Bookings
```sql
SELECT
    b.id,
    b.user_id,
    u.name as user_name,
    b.status,
    b.notes,
    b.created_at,
    b.updated_at,
    c.name as court_name
FROM bookings b
JOIN users u ON b.user_id = u.id
JOIN courts c ON b.court_id = c.id
WHERE b.status = 'rejected'
AND b.notes LIKE '%Auto-rejected: Parent booking was approved%'
ORDER BY b.updated_at DESC
LIMIT 20;
```

### Verify Email Sent
Check Laravel logs:
```bash
tail -f storage/logs/laravel.log | grep "waitlist_cancellation"
```

## Support & Troubleshooting

### Common Issues

**Issue:** Emails not sending
**Solution:** Check mail configuration, queue workers, and logs

**Issue:** Bookings not being rejected
**Solution:** Verify booking notes contain "Auto-created from waitlist"

**Issue:** Real-time updates not working
**Solution:** Check broadcasting configuration and websocket connection

### Debug Commands
```bash
# Check mail queue
php artisan queue:work --once

# View logs
tail -f storage/logs/laravel.log

# Check waitlist status
php artisan tinker
>>> App\Models\BookingWaitlist::where('status', 'cancelled')->count()
```

## Conclusion
This feature ensures waitlisted users are properly notified when parent bookings are approved, preventing confusion and wasted effort. The automatic booking rejection and clear email communication provide a seamless user experience even in complex booking scenarios.
