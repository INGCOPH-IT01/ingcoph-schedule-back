# Waitlist Approval Notification - Diagnostic Guide

## Issue Fixed
The waitlist cancellation on parent booking approval was not working because the code was searching for waitlist entries by court/time slots instead of using the `pending_booking_id` relationship.

## What Was Changed

### Before (Incorrect)
```php
// Was searching by court_id, start_time, end_time
$waitlistEntries = BookingWaitlist::where('court_id', $approvedBooking->court_id)
    ->where('start_time', $approvedBooking->start_time)
    ->where('end_time', $approvedBooking->end_time)
    ->get();
```

### After (Correct)
```php
// Now searching by pending_booking_id (the correct relationship)
$waitlistEntries = BookingWaitlist::where('pending_booking_id', $approvedBooking->id)
    ->whereIn('status', ['pending', 'notified'])
    ->get();
```

## How to Test

### Step 1: Create Test Scenario
1. Login as **User A**
2. Create a booking for tomorrow at 10am-11am
3. Login as **User B**
4. Try to book the same slot → Should show "Join Waitlist" option
5. Join the waitlist
6. Verify in database:
```sql
SELECT id, user_id, pending_booking_id, status, court_id, start_time
FROM booking_waitlists
WHERE user_id = [User_B_ID]
ORDER BY created_at DESC LIMIT 1;
```
Expected: `pending_booking_id` should match User A's booking ID

### Step 2: Reject Parent Booking (Optional - for complex test)
7. Login as **Admin**
8. Reject User A's booking
9. Check User B's email → Should receive notification to upload payment
10. Verify in database:
```sql
-- Check waitlist status changed to notified
SELECT id, user_id, status, notified_at, expires_at
FROM booking_waitlists
WHERE user_id = [User_B_ID]
ORDER BY updated_at DESC LIMIT 1;

-- Check auto-created booking exists
SELECT id, user_id, status, notes
FROM bookings
WHERE user_id = [User_B_ID]
AND notes LIKE '%Auto-created from waitlist%'
ORDER BY created_at DESC LIMIT 1;
```

### Step 3: Approve Parent Booking
11. Login as **Admin**
12. Approve User A's booking
13. **Watch the logs in real-time:**
```bash
tail -f storage/logs/laravel.log | grep -i waitlist
```

Expected log output:
```
[timestamp] local.INFO: Processing waitlist cancellation for approved booking
{"booking_id":123,"waitlist_entries_found":1,"court_id":1,"start_time":"...","end_time":"..."}

[timestamp] local.INFO: Waitlist cancelled due to parent booking approval
{"waitlist_id":456,"user_id":789,"court_id":1,"approved_booking_id":123,"rejected_bookings":[999]}
```

14. Check User B's email → Should receive cancellation email
15. Verify in database:
```sql
-- Check waitlist is cancelled
SELECT id, user_id, status, updated_at
FROM booking_waitlists
WHERE user_id = [User_B_ID]
ORDER BY updated_at DESC LIMIT 1;
-- Expected status: 'cancelled'

-- Check auto-created booking is rejected (if it existed)
SELECT id, user_id, status, notes, updated_at
FROM bookings
WHERE user_id = [User_B_ID]
AND notes LIKE '%Auto-created from waitlist%'
ORDER BY updated_at DESC LIMIT 1;
-- Expected status: 'rejected'
-- Expected notes: Contains "Auto-rejected: Parent booking was approved"
```

## Diagnostic Queries

### Check if waitlist entries exist for a booking
```sql
SELECT
    w.id as waitlist_id,
    w.user_id,
    w.pending_booking_id,
    w.status,
    u.name as user_name,
    u.email,
    b.id as parent_booking_id,
    b.status as parent_booking_status
FROM booking_waitlists w
JOIN users u ON w.user_id = u.id
LEFT JOIN bookings b ON w.pending_booking_id = b.id
WHERE w.pending_booking_id = [BOOKING_ID]
ORDER BY w.position;
```

### Check if cancellation method was called
```bash
# Search logs for cancellation processing
grep "Processing waitlist cancellation" storage/logs/laravel.log | tail -5

# Check if entries were found
grep "waitlist_entries_found" storage/logs/laravel.log | tail -5

# Check for errors
grep "waitlist_cancellation_error" storage/logs/laravel.log | tail -5
```

### Check recent waitlist status changes
```sql
SELECT
    id,
    user_id,
    status,
    created_at,
    updated_at,
    TIMESTAMPDIFF(SECOND, created_at, updated_at) as seconds_to_cancel
FROM booking_waitlists
WHERE updated_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
AND status = 'cancelled'
ORDER BY updated_at DESC;
```

### Check recent booking rejections
```sql
SELECT
    id,
    user_id,
    status,
    notes,
    updated_at
FROM bookings
WHERE status = 'rejected'
AND notes LIKE '%Auto-rejected: Parent booking was approved%'
AND updated_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
ORDER BY updated_at DESC;
```

## Common Issues and Solutions

### Issue 1: "waitlist_entries_found: 0" in logs
**Cause:** Waitlist entries not properly linked to booking

**Check:**
```sql
-- Verify pending_booking_id is set
SELECT id, user_id, pending_booking_id, status
FROM booking_waitlists
WHERE pending_booking_id IS NULL;
-- Should return 0 rows
```

**Solution:** Ensure waitlist entries are created with proper `pending_booking_id` when users join waitlist

### Issue 2: Emails not being sent
**Cause:** Mail queue not processing or mail configuration issue

**Check:**
```bash
# Check if jobs are in queue
php artisan queue:work --once

# Check mail logs
grep "WaitlistCancelledMail" storage/logs/laravel.log | tail -5

# Test mail configuration
php artisan tinker
>>> Mail::raw('Test', function($msg) { $msg->to('your@email.com'); });
```

**Solution:**
- Start queue worker: `php artisan queue:work`
- Check `.env` mail configuration
- Verify SMTP credentials

### Issue 3: Bookings not being rejected
**Cause:** Booking notes don't contain expected text

**Check:**
```sql
-- Check exact notes text in auto-created bookings
SELECT id, user_id, status, notes
FROM bookings
WHERE user_id = [User_B_ID]
AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
ORDER BY created_at DESC;
```

**Solution:** Verify the notes contain "Auto-created from waitlist" exactly as expected

### Issue 4: Method not being called
**Cause:** Exception thrown before method execution

**Check:**
```bash
# Look for exceptions in approval
grep "booking.*approved" storage/logs/laravel.log | tail -10

# Check for any errors during approval
grep "ERROR" storage/logs/laravel.log | tail -20
```

**Solution:** Review error logs and fix any exceptions

## Quick Verification Script

Run this SQL to get a complete picture:

```sql
-- Complete diagnostic query
SELECT
    'Booking' as type,
    b.id,
    b.user_id,
    u.name as user_name,
    b.status,
    b.court_id,
    c.name as court_name,
    b.start_time,
    b.end_time,
    b.created_at
FROM bookings b
JOIN users u ON b.user_id = u.id
JOIN courts c ON b.court_id = c.id
WHERE b.id = [BOOKING_ID]

UNION ALL

SELECT
    'Waitlist' as type,
    w.id,
    w.user_id,
    u.name as user_name,
    w.status,
    w.court_id,
    c.name as court_name,
    w.start_time,
    w.end_time,
    w.created_at
FROM booking_waitlists w
JOIN users u ON w.user_id = u.id
JOIN courts c ON w.court_id = c.id
WHERE w.pending_booking_id = [BOOKING_ID]

ORDER BY type, created_at;
```

## Real-Time Monitoring

While testing, keep these commands running in separate terminals:

**Terminal 1 - Laravel Logs:**
```bash
tail -f storage/logs/laravel.log | grep -i waitlist
```

**Terminal 2 - Queue Worker:**
```bash
php artisan queue:work --verbose
```

**Terminal 3 - Database Changes:**
```bash
# MySQL/MariaDB
watch -n 1 "mysql -u [user] -p[pass] [database] -e \"SELECT COUNT(*) as cancelled FROM booking_waitlists WHERE status='cancelled' AND updated_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE);\""
```

## Success Criteria

When working correctly, you should see:

✅ **Logs show:**
- "Processing waitlist cancellation for approved booking"
- "waitlist_entries_found: [number > 0]"
- "Waitlist cancelled due to parent booking approval"

✅ **Database shows:**
- `booking_waitlists.status` = 'cancelled'
- Auto-created `bookings.status` = 'rejected'
- `bookings.notes` contains "Auto-rejected: Parent booking was approved"

✅ **Email received:**
- Subject: "Waitlist Booking Cancelled - [Court Name]"
- Body mentions booking will be automatically rejected
- No payment upload needed

✅ **Real-time updates:**
- Status changes broadcast via WebSocket
- Admin dashboard updates automatically
- User's booking list shows rejected status

## If Still Not Working

1. **Clear all caches:**
```bash
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

2. **Restart queue workers:**
```bash
php artisan queue:restart
```

3. **Enable debug mode temporarily:**
Edit `.env`:
```
APP_DEBUG=true
LOG_LEVEL=debug
```

4. **Add temporary debug output:**
In `BookingController.php` after line 986:
```php
Log::debug('About to call cancelWaitlistForApprovedBooking', [
    'booking_id' => $booking->id,
    'booking_status' => $booking->status
]);
```

5. **Check the call stack:**
```bash
# Find where approval happens
grep -n "approveBooking" app/Http/Controllers/Api/BookingController.php
grep -n "cancelWaitlistForApprovedBooking" app/Http/Controllers/Api/BookingController.php
```

## Contact & Support

If issue persists:
1. Export logs: `cat storage/logs/laravel.log > debug_logs.txt`
2. Export diagnostic query results
3. Take screenshots of:
   - Admin approval screen
   - Database records
   - Email (if received)
4. Share for further investigation

## Version Info
- Fix applied: 2024
- Files modified:
  - `app/Http/Controllers/Api/BookingController.php`
  - `app/Http/Controllers/Api/CartTransactionController.php`
- Key change: Using `pending_booking_id` instead of court/time matching
