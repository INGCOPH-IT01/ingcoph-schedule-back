# Troubleshooting: "Time slot pending approval and waitlist disabled" Error

## Quick Diagnostic Steps

### Step 1: Clear All Caches

**Backend:**
```bash
cd /path/to/ingcoph-schedule-back
php artisan config:clear
php artisan cache:clear
php artisan route:clear
```

**Frontend:**
```bash
# Hard refresh in browser
# Chrome/Firefox: Ctrl+Shift+R (Windows) or Cmd+Shift+R (Mac)
# Or open DevTools and right-click refresh button -> "Empty Cache and Hard Reload"
```

### Step 2: Verify Waitlist Setting

```bash
cd /path/to/ingcoph-schedule-back
php artisan tinker

# In tinker:
\App\Models\CompanySetting::where('key', 'waitlist_enabled')->first()
# Should show value='0' if disabled

# To manually set it:
\App\Models\CompanySetting::updateOrCreate(
    ['key' => 'waitlist_enabled'],
    ['value' => '0']
);
```

### Step 3: Check for Conflicting Bookings/Cart Items

```bash
php artisan tinker

# Check bookings
$bookings = \App\Models\Booking::where('court_id', 4)
    ->whereDate('start_time', '2025-11-22')
    ->whereIn('status', ['pending', 'approved'])
    ->get();
foreach ($bookings as $b) {
    echo "ID: {$b->id} | Status: {$b->status} | Time: {$b->start_time}\n";
}

# Check cart items
$items = \App\Models\CartItem::where('court_id', 4)
    ->where('booking_date', '2025-11-22')
    ->where('status', 'pending')
    ->with('cartTransaction')
    ->get();
foreach ($items as $i) {
    echo "ID: {$i->id} | Time: {$i->start_time} | Trans Status: {$i->cartTransaction->approval_status}\n";
}
```

### Step 4: Test API Directly

```bash
# Test available slots endpoint
curl -X GET "http://your-backend-url/api/courts/4/available-slots?date=2025-11-22" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

Look for slots between 10:00-13:00 and check:
- `available`: should be `true`
- `is_waitlist_available`: should be `false` or not present (for truly available slots)
- `is_booked`: should be `false`

### Step 5: Monitor Logs During Booking

```bash
# Tail the Laravel log
tail -f storage/logs/laravel.log
```

Then try to book the slot and watch for any errors or warnings.

## Common Issues and Solutions

### Issue 1: Browser Cache
**Symptoms:** Frontend still shows old slot states
**Solution:** Hard refresh (Ctrl+Shift+R) or clear browser cache

### Issue 2: Backend Not Restarted
**Symptoms:** Changes don't take effect
**Solution:** If using php artisan serve, restart it. If using nginx/apache, restart the service.

### Issue 3: Race Condition
**Symptoms:** Error only happens sometimes
**Solution:** This is normal - two users tried to book at the same time. First one gets it, second one gets error.

### Issue 4: Wrong Date Format
**Symptoms:** Slots show available but can't book
**Solution:** Ensure date is in YYYY-MM-DD format (2025-11-22, not 22-11-2025)

### Issue 5: Conflicting Pending Transaction
**Symptoms:** Slot appears available but returns error
**Solution:** Check for pending cart transactions that haven't been rejected:

```bash
php artisan tinker

# Find stale transactions
$stale = \App\Models\CartTransaction::where('approval_status', 'pending')
    ->where('created_at', '<', now()->subHour())
    ->with('cartItems')
    ->get();

foreach ($stale as $trans) {
    echo "Transaction #{$trans->id} | Created: {$trans->created_at} | Items: {$trans->cartItems->count()}\n";

    // If expired, you can reject it:
    // $trans->update(['approval_status' => 'rejected', 'rejection_reason' => 'Timeout']);
    // $trans->bookings()->update(['status' => 'rejected']);
}
```

## Expected Behavior After Fix

### When Waitlist is DISABLED (value='0'):

**Slot with no booking:**
```json
{
  "start": "10:00",
  "end": "11:00",
  "available": true,
  "is_booked": false
}
```
✅ User can select and book this slot

**Slot with pending booking:**
```json
{
  "start": "10:00",
  "end": "11:00",
  "available": false,
  "is_booked": false,
  "is_waitlist_available": false,  // ← Key change
  "is_pending_approval": true
}
```
❌ User CANNOT select this slot (grayed out)
❌ User WILL NOT get error at checkout (can't add to cart)

### When Waitlist is ENABLED (value='1'):

**Slot with pending booking:**
```json
{
  "start": "10:00",
  "end": "11:00",
  "available": false,
  "is_booked": false,
  "is_waitlist_available": true,  // ← Can join waitlist
  "is_pending_approval": true
}
```
✅ User CAN select this slot
✅ User joins waitlist (no error)

## Debug Checklist

- [ ] Backend caches cleared (`php artisan cache:clear`)
- [ ] Frontend hard refreshed (Ctrl+Shift+R)
- [ ] Verified `waitlist_enabled` setting in database
- [ ] Checked for conflicting bookings (status: pending/approved)
- [ ] Checked for conflicting cart items (status: pending)
- [ ] Tested API endpoint directly (curl/Postman)
- [ ] Checked laravel.log for errors
- [ ] Verified correct date format (YYYY-MM-DD)
- [ ] Confirmed correct court ID

## Still Having Issues?

If error persists after all checks:

1. **Capture the exact request:**
   - Open browser DevTools (F12)
   - Go to Network tab
   - Try to book
   - Find the failed request
   - Copy request URL, headers, and payload

2. **Check database state at time of error:**
   ```sql
   -- Check bookings
   SELECT id, status, payment_status, start_time, end_time
   FROM bookings
   WHERE court_id = 4
     AND DATE(start_time) = '2025-11-22'
     AND status IN ('pending', 'approved');

   -- Check cart items
   SELECT ci.id, ci.status, ci.start_time, ci.end_time, ct.approval_status
   FROM cart_items ci
   LEFT JOIN cart_transactions ct ON ci.cart_transaction_id = ct.id
   WHERE ci.court_id = 4
     AND ci.booking_date = '2025-11-22'
     AND ci.status = 'pending';
   ```

3. **Enable detailed logging:**
   Add to `.env`:
   ```
   LOG_LEVEL=debug
   ```

4. **Test with a fresh user:**
   - Create a new test user
   - Try booking with that user
   - Rules out user-specific issues
