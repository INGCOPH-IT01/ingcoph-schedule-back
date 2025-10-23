# Verify Waitlist Status Update Fix

## What Was Fixed

### Issue
The `booking_waitlists` status was not being updated to 'cancelled' even though emails were being sent.

### Root Cause
The `cancel()` method was using `$this->update(['status' => ...])` which might not always persist properly.

### Solution Applied

**Changed in `app/Models/BookingWaitlist.php`:**

**Before:**
```php
public function cancel(): void
{
    $this->update(['status' => self::STATUS_CANCELLED]);
}
```

**After:**
```php
public function cancel(): void
{
    $this->status = self::STATUS_CANCELLED;
    $this->save();
}
```

**Added Enhanced Logging** in both controllers:
- Logs the old status before calling cancel()
- Calls `refresh()` to reload from database
- Logs the new status after cancel()
- Verifies the status was actually updated

## How to Test

### Step 1: Setup Test Scenario
1. **Create a booking** (User A) for tomorrow at 10am-11am
2. **Join waitlist** (User B) for the same slot
3. Check database before approval:
```sql
SELECT id, user_id, pending_booking_id, status, updated_at
FROM booking_waitlists
WHERE user_id = [User_B_ID]
ORDER BY created_at DESC LIMIT 1;
```
Expected status: `pending`

### Step 2: Approve the Booking

4. **Login as Admin**
5. **Approve User A's booking**
6. **Monitor logs in real-time:**
```bash
tail -f storage/logs/laravel.log | grep -E "Waitlist status update|Waitlist cancelled"
```

Expected log output:
```
[timestamp] local.INFO: Processing waitlist cancellation for approved booking
{"booking_id":123,"waitlist_entries_found":1,...}

[timestamp] local.INFO: Waitlist status update
{"waitlist_id":456,"old_status":"pending","new_status":"cancelled","status_updated":true}

[timestamp] local.INFO: Waitlist cancelled due to parent booking approval
{"waitlist_id":456,...,"final_status":"cancelled"}
```

### Step 3: Verify Database

7. **Check database immediately after approval:**
```sql
SELECT id, user_id, pending_booking_id, status, updated_at
FROM booking_waitlists
WHERE user_id = [User_B_ID]
ORDER BY updated_at DESC LIMIT 1;
```

**Expected Result:**
- `status` = `'cancelled'`
- `updated_at` = just now (recent timestamp)

### Step 4: Verify Email

8. **Check User B's email inbox**

**Expected:**
- ✅ Email received: "Waitlist Booking Cancelled - [Court Name]"
- ✅ Email mentions booking will be rejected
- ✅ Email sent at approximately the same time as status update

## Diagnostic Queries

### Check Recent Status Changes
```sql
-- See all recent waitlist status updates
SELECT
    id,
    user_id,
    status,
    created_at,
    updated_at,
    TIMESTAMPDIFF(SECOND, created_at, updated_at) as seconds_until_cancelled
FROM booking_waitlists
WHERE updated_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
ORDER BY updated_at DESC;
```

### Check if Status Update Happened
```sql
-- Count cancelled vs other statuses in last hour
SELECT
    status,
    COUNT(*) as count,
    MAX(updated_at) as last_updated
FROM booking_waitlists
WHERE updated_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
GROUP BY status;
```

### Detailed Status History
```sql
-- See complete details of recent waitlist entry
SELECT
    w.*,
    u.name as user_name,
    u.email as user_email,
    c.name as court_name,
    b.id as parent_booking_id,
    b.status as parent_booking_status
FROM booking_waitlists w
JOIN users u ON w.user_id = u.id
JOIN courts c ON w.court_id = c.id
LEFT JOIN bookings b ON w.pending_booking_id = b.id
WHERE w.updated_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
ORDER BY w.updated_at DESC;
```

## What the Logs Will Tell You

### ✅ Success Scenario
```
Waitlist status update: {"old_status":"pending","new_status":"cancelled","status_updated":true}
Waitlist cancelled due to parent booking approval: {"final_status":"cancelled"}
```
- Database shows status = 'cancelled'
- Email sent successfully

### ❌ Failure Scenario (If It Still Fails)
```
Waitlist status update: {"old_status":"pending","new_status":"pending","status_updated":false}
```
- Status didn't change
- Would indicate a deeper database or model issue

## If Status Still Not Updating

### Check 1: Database Permissions
```sql
-- Verify user can update the table
SHOW GRANTS FOR CURRENT_USER;
```

### Check 2: Model Fillable/Guarded
Verify in `BookingWaitlist.php`:
```php
protected $fillable = [
    'user_id',
    'pending_booking_id',
    // ... other fields ...
    'status',  // <-- Make sure this is here
];
```

### Check 3: Database Triggers
```sql
-- Check if there are any triggers that might interfere
SHOW TRIGGERS LIKE 'booking_waitlists';
```

### Check 4: Test Cancel Method Directly
```bash
php artisan tinker
```
```php
$waitlist = App\Models\BookingWaitlist::find([WAITLIST_ID]);
echo "Before: " . $waitlist->status . "\n";
$waitlist->cancel();
echo "After (in memory): " . $waitlist->status . "\n";
$waitlist->refresh();
echo "After (from DB): " . $waitlist->status . "\n";
```

Expected output:
```
Before: pending
After (in memory): cancelled
After (from DB): cancelled
```

### Check 5: Database Connection
```bash
php artisan tinker
```
```php
// Test basic update works
$waitlist = App\Models\BookingWaitlist::find([WAITLIST_ID]);
$waitlist->status = 'cancelled';
$saved = $waitlist->save();
echo "Save returned: " . ($saved ? 'true' : 'false') . "\n";
$waitlist->refresh();
echo "Status in DB: " . $waitlist->status . "\n";
```

## Alternative Direct Update (If Model Method Fails)

If the `cancel()` method still doesn't work, we can use a direct query:

**Add to controllers as fallback:**
```php
// Mark waitlist as cancelled
$waitlistEntry->cancel();

// Fallback: Direct database update to ensure it's saved
DB::table('booking_waitlists')
    ->where('id', $waitlistEntry->id)
    ->update([
        'status' => 'cancelled',
        'updated_at' => now()
    ]);

$waitlistEntry->refresh();
```

## Testing Checklist

- [ ] Waitlist entry created successfully
- [ ] Parent booking approved successfully
- [ ] Logs show "Waitlist status update" with `status_updated: true`
- [ ] Logs show "final_status: cancelled"
- [ ] Database query shows `status = 'cancelled'`
- [ ] Email received by waitlisted user
- [ ] Auto-created booking rejected (if applicable)
- [ ] No errors in Laravel logs

## Expected Timeline

| Time | Event | Database Status | Email Status |
|------|-------|----------------|--------------|
| T+0s | Admin clicks approve | pending | Not sent |
| T+1s | Code executes cancel() | cancelled | Queued |
| T+2s | Log confirms update | cancelled | Sending |
| T+3s | Email delivered | cancelled | Delivered |

## Success Criteria

✅ **Database:**
```sql
SELECT status FROM booking_waitlists WHERE id = [ID];
-- Returns: cancelled
```

✅ **Logs:**
```
"status_updated": true
"final_status": "cancelled"
```

✅ **Email:**
- Subject line received
- Body content correct
- Sent to correct user

## Support

If status still doesn't update after these changes:

1. **Export the logs:**
```bash
grep "Waitlist status update" storage/logs/laravel.log > status_debug.log
```

2. **Take database snapshot before and after:**
```sql
-- Before approval
SELECT * FROM booking_waitlists WHERE id = [ID] \G

-- After approval (run immediately)
SELECT * FROM booking_waitlists WHERE id = [ID] \G
```

3. **Check Laravel cache:**
```bash
php artisan cache:clear
php artisan config:clear
```

4. **Restart queue workers:**
```bash
php artisan queue:restart
```

Share the logs and database snapshots for further investigation.
