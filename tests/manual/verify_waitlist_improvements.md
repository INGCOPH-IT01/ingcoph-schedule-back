# Manual Test Script - Waitlist Improvements

## Prerequisites
- Admin account credentials
- Regular user account credentials
- Access to email inbox (for receiving notifications)
- Access to database (for verification queries)

---

## Test 1: Business Hours Payment Deadline

### Test 1A: Rejection During Business Hours (e.g., 2pm)
**Steps:**
1. Login as User A
2. Create a booking for tomorrow at 10am-11am
3. Login as User B
4. Join waitlist for the same slot
5. Login as Admin
6. Go to pending bookings
7. Reject User A's booking at 2pm
8. Check User B's email

**Expected Results:**
- ✅ Email received by User B
- ✅ Email shows deadline: "3:00 PM" (1 hour from rejection)
- ✅ Database `booking_waitlists.expires_at` is ~3:00 PM same day

**Database Check:**
```sql
SELECT id, user_id, status, notified_at, expires_at,
       TIMESTAMPDIFF(MINUTE, notified_at, expires_at) as minutes_to_expire
FROM booking_waitlists
WHERE status = 'notified'
ORDER BY notified_at DESC LIMIT 1;
-- Should show ~60 minutes
```

---

### Test 1B: Rejection After Business Hours (e.g., 6pm)
**Steps:**
1. Login as User A
2. Create a booking for tomorrow at 10am-11am
3. Login as User B
4. Join waitlist for the same slot
5. Login as Admin
6. Set system time to 6pm (or wait until 6pm)
7. Reject User A's booking
8. Check User B's email

**Expected Results:**
- ✅ Email received by User B
- ✅ Email shows deadline: "Tomorrow 9:00 AM"
- ✅ Email mentions "Payment window opens at 8:00 AM"
- ✅ Database `booking_waitlists.expires_at` is 9:00 AM next working day

**Database Check:**
```sql
SELECT id, user_id, status, notified_at, expires_at,
       DATE(expires_at) as expires_date,
       TIME(expires_at) as expires_time
FROM booking_waitlists
WHERE status = 'notified'
ORDER BY notified_at DESC LIMIT 1;
-- expires_time should be 09:00:00
-- expires_date should be next working day
```

---

### Test 1C: Rejection on Weekend
**Steps:**
1. Wait until Saturday
2. Login as User A
3. Create a booking for next week Monday at 10am-11am
4. Login as User B
5. Join waitlist for the same slot
6. Login as Admin
7. Reject User A's booking
8. Check User B's email

**Expected Results:**
- ✅ Email received by User B
- ✅ Email shows deadline: "Monday 9:00 AM"
- ✅ Database `booking_waitlists.expires_at` is Monday 9:00 AM

---

## Test 2: Parent Booking Approval - Simple Case

### Test 2A: Waitlist Pending (No Notification Yet)
**Steps:**
1. Login as User A
2. Create a booking for tomorrow at 10am-11am
3. Login as User B
4. Join waitlist for the same slot
5. Login as Admin
6. Approve User A's booking (without rejecting first)
7. Check User B's email

**Expected Results:**
- ✅ Email received by User B
- ✅ Email subject: "Waitlist Booking Cancelled - [Court Name]"
- ✅ Email says: "approved for another user"
- ✅ Database `booking_waitlists.status` is 'cancelled'
- ✅ No auto-created booking exists for User B

**Database Check:**
```sql
-- Check waitlist status
SELECT id, user_id, status, updated_at
FROM booking_waitlists
WHERE status = 'cancelled'
ORDER BY updated_at DESC LIMIT 1;

-- Verify no auto-created booking
SELECT COUNT(*)
FROM bookings
WHERE user_id = [User_B_ID]
AND notes LIKE '%Auto-created from waitlist%';
-- Should return 0
```

---

## Test 3: Parent Booking Approval - Complex Case

### Test 3A: Rejection Then Approval (Reversal)
**Steps:**
1. Login as User A
2. Create a booking for tomorrow at 10am-11am
3. Login as User B
4. Join waitlist for the same slot
5. Login as Admin
6. **Reject** User A's booking
7. Wait for User B to receive notification email
8. Verify auto-created booking exists for User B
9. **Approve** User A's booking (reversing the rejection)
10. Check User B's email (new one)

**Expected Results:**
- ✅ First email received (rejection notification)
- ✅ Auto-created booking created for User B (status: pending)
- ✅ Second email received (cancellation notification)
- ✅ Second email mentions "booking will be automatically rejected"
- ✅ Auto-created booking status changed to 'rejected'
- ✅ Waitlist status is 'cancelled'

**Database Check:**
```sql
-- Check waitlist status
SELECT id, user_id, status, notified_at, updated_at
FROM booking_waitlists
WHERE status = 'cancelled'
ORDER BY updated_at DESC LIMIT 1;

-- Check auto-created booking was rejected
SELECT id, user_id, status, notes, updated_at
FROM bookings
WHERE notes LIKE '%Auto-created from waitlist%'
AND notes LIKE '%Auto-rejected: Parent booking was approved%'
ORDER BY updated_at DESC LIMIT 1;
-- Should find the rejected booking
```

---

### Test 3B: User Uploads Payment Before Approval
**Steps:**
1. Login as User A
2. Create a booking for tomorrow at 10am-11am
3. Login as User B
4. Join waitlist for the same slot
5. Login as Admin at 6pm
6. Reject User A's booking
7. Wait for User B's notification email
8. Login as User B next day at 8:30am
9. Upload payment proof for the auto-created booking
10. Login as Admin
11. Approve User A's booking (reversing the rejection)
12. Check User B's email

**Expected Results:**
- ✅ Auto-created booking shows payment proof uploaded
- ✅ After approval, booking status changes to 'rejected'
- ✅ Cancellation email sent to User B
- ✅ Email clearly states booking will be rejected
- ✅ Payment proof file still exists (not deleted)

**Database Check:**
```sql
SELECT id, user_id, status, payment_status,
       proof_of_payment, notes, updated_at
FROM bookings
WHERE user_id = [User_B_ID]
AND notes LIKE '%Auto-created from waitlist%'
ORDER BY updated_at DESC LIMIT 1;
-- Should show status: rejected, proof_of_payment: [file path]
```

---

## Test 4: Cart Transaction with Multiple Bookings

### Test 4A: Multiple Slots with Waitlists
**Steps:**
1. Login as User A
2. Add 3 different bookings to cart (different times)
3. Checkout
4. Login as User B
5. Join waitlist for all 3 slots
6. Login as User C
7. Join waitlist for all 3 slots
8. Login as Admin
9. Approve User A's cart transaction
10. Check emails for User B and User C

**Expected Results:**
- ✅ User B receives 3 cancellation emails (one per slot)
- ✅ User C receives 3 cancellation emails (one per slot)
- ✅ All 6 waitlist entries status is 'cancelled'
- ✅ No auto-created bookings remain pending

**Database Check:**
```sql
-- Check all waitlist entries cancelled
SELECT user_id, COUNT(*) as cancelled_count
FROM booking_waitlists
WHERE status = 'cancelled'
AND updated_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
GROUP BY user_id;
-- Should show User B: 3, User C: 3
```

---

## Test 5: Real-Time Updates

### Test 5A: Admin Dashboard Real-Time Update
**Steps:**
1. Open two browser windows
2. Window 1: Login as User A, create booking
3. Window 2: Login as Admin, view pending bookings
4. Window 1: Login as User B (different tab), join waitlist
5. Window 2: (Admin) Reject User A's booking
6. Window 1: (User B tab) Watch for real-time updates
7. Window 2: (Admin) Approve User A's booking
8. Window 1: (User B tab) Watch for real-time updates

**Expected Results:**
- ✅ After rejection: User B's auto-booking appears in real-time
- ✅ After approval: User B's auto-booking changes to rejected in real-time
- ✅ No page refresh needed
- ✅ Status changes broadcast correctly

---

## Test 6: Error Handling

### Test 6A: Email Failure (Simulated)
**Steps:**
1. Temporarily misconfigure mail settings
2. Create booking and waitlist scenario
3. Approve parent booking
4. Check logs

**Expected Results:**
- ✅ Approval still succeeds
- ✅ Waitlist entries still cancelled
- ✅ Bookings still rejected
- ✅ Error logged but process continues
- ✅ Log shows: "waitlist_cancellation_individual_error"

**Log Check:**
```bash
tail -f storage/logs/laravel.log | grep waitlist
```

---

## Test 7: Edge Cases

### Test 7A: Multiple Waitlist Users with Different Statuses
**Steps:**
1. Create booking (User A)
2. User B joins waitlist → status: pending
3. User C joins waitlist → status: pending
4. Reject booking → User B notified, User C notified
5. User B uploads payment
6. Approve original booking
7. Check both users' statuses

**Expected Results:**
- ✅ Both User B and User C waitlists cancelled
- ✅ Both auto-created bookings rejected
- ✅ User B's booking rejected even with payment proof
- ✅ Both users receive cancellation emails

---

### Test 7B: Approval Immediately After Rejection
**Steps:**
1. Create booking
2. Add user to waitlist
3. Reject booking
4. Immediately approve the same booking (within seconds)
5. Check for race conditions

**Expected Results:**
- ✅ No duplicate emails sent
- ✅ Final state is correct (waitlist cancelled, auto-booking rejected)
- ✅ No database inconsistencies
- ✅ Logs show proper sequence

---

## Verification Queries

### Check All Recent Waitlist Activities
```sql
SELECT
    w.id,
    w.status,
    w.notified_at,
    w.expires_at,
    u.name as user_name,
    u.email,
    c.name as court_name,
    w.start_time,
    w.end_time,
    w.created_at,
    w.updated_at
FROM booking_waitlists w
JOIN users u ON w.user_id = u.id
JOIN courts c ON w.court_id = c.id
WHERE w.updated_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
ORDER BY w.updated_at DESC;
```

### Check Auto-Rejected Bookings
```sql
SELECT
    b.id,
    b.status,
    b.payment_status,
    b.proof_of_payment,
    b.notes,
    u.name as user_name,
    c.name as court_name,
    b.start_time,
    b.end_time,
    b.updated_at
FROM bookings b
JOIN users u ON b.user_id = u.id
JOIN courts c ON b.court_id = c.id
WHERE b.notes LIKE '%Auto-rejected: Parent booking was approved%'
ORDER BY b.updated_at DESC;
```

### Check Email Logs
```sql
-- If using database mail queue
SELECT * FROM jobs
WHERE queue = 'default'
AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
ORDER BY created_at DESC;
```

---

## Success Criteria

All tests should pass with:
- ✅ Correct payment deadline calculation based on business hours
- ✅ Cancellation emails sent when parent booking approved
- ✅ Auto-created bookings properly rejected
- ✅ Real-time updates working
- ✅ No data inconsistencies
- ✅ Proper error handling and logging
- ✅ Email content is clear and accurate
- ✅ Database states are correct

---

## Rollback Plan

If issues are found:
1. Revert changes to controllers
2. Revert email template changes
3. Revert model changes
4. Clear cache: `php artisan cache:clear`
5. Restart queue workers: `php artisan queue:restart`
6. Check for any orphaned data in database

---

## Notes
- Test during off-peak hours to avoid affecting real users
- Keep database backups before testing
- Monitor logs continuously during testing
- Document any unexpected behavior
- Take screenshots of email content for reference
