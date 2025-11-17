# Cleanup Tools Summary

## Overview

Two complementary tools to maintain database integrity and clean up stale data:

1. **Status Sync** - Syncs statuses between related records
2. **Time-Based Cleanup** - Removes expired/timeout transactions

---

## Tool Comparison

| Feature | Status Sync | Time-Based Cleanup |
|---------|-------------|-------------------|
| **Command** | `php artisan cart:sync-statuses` | `php artisan cart:cleanup-stale` |
| **Seeder** | `SyncCartStatusesSeeder` | `CleanupStaleCartItemsSeeder` |
| **Purpose** | Fix status mismatches | Remove expired transactions |
| **Trigger** | Data integrity issues | Time-based (1+ hours old) |
| **Scope** | Any transaction with mismatched status | Only expired pending transactions |
| **When to Use** | After bulk operations, data imports | Automated timeout enforcement |
| **Schedule** | Daily or after admin actions | Hourly or every 15 minutes |
| **Dry Run** | `--dry-run` | `--dry-run` |

---

## Tool 1: Status Synchronization

### Purpose
Ensures consistency between `CartTransaction`, `CartItem`, and `Booking` records.

### Command
```bash
# Preview changes
php artisan cart:sync-statuses --dry-run

# Actually sync
php artisan cart:sync-statuses
```

### What It Does
1. **Rejected Transaction → Sync Items**: Updates cart items and bookings to match rejected transaction
2. **All Items Rejected → Sync Transaction**: Marks transaction as rejected when all items are rejected
3. **Fix Orphaned Bookings**: Cancels bookings with non-existent transactions
4. **Fix Orphaned Cart Items**: Cancels cart items with non-existent transactions

### Use Cases
- After admin bulk rejects transactions
- After manual database changes
- After data migrations/imports
- When you notice status inconsistencies
- **Your specific issue**: Transaction #455 has mismatched statuses

### Recommended Schedule
```php
// In app/Console/Kernel.php
$schedule->command('cart:sync-statuses')
    ->daily()
    ->at('00:00');
```

### Example Output
```
Found 166 rejected/cancelled transactions
  Transaction #455: Would sync 2 cart items to 'rejected'
  Transaction #455: Would sync 1 bookings to 'rejected'

Cart Items (Would Update): 45
Bookings (Would Update): 18
```

---

## Tool 2: Time-Based Cleanup

### Purpose
Automatically rejects cart transactions that have been pending for too long (payment timeout).

### Command
```bash
# Preview what will be cleaned
php artisan cart:cleanup-stale --dry-run

# Clean with default 1 hour timeout
php artisan cart:cleanup-stale

# Clean with custom timeout (2 hours)
php artisan cart:cleanup-stale --hours=2
```

### What It Does
1. Finds transactions older than threshold (default: 1 hour)
2. Rejects the transaction
3. Rejects all associated cart items
4. Rejects all associated bookings
5. Notifies waitlist users (Position #1 only)
6. Frees up time slots

### Use Cases
- Enforce payment timeout policy
- Prevent slot hogging
- Automated cleanup of expired bookings
- Free up slots for other users

### Recommended Schedule
```php
// In app/Console/Kernel.php
$schedule->command('cart:cleanup-stale')
    ->everyFifteenMinutes();
    // or ->hourly();
```

### Example Output
```
Found 191 stale cart transactions

Transaction #455 (Age: 336 hours, User: Michelin)...
  ✓ Rejected cart transaction #455
  ✓ Rejected 6 cart items
  ✓ Rejected 3 bookings

Cart Transactions Rejected: 191
Cart Items Rejected: 966
Bookings Rejected: 333
```

---

## Which Tool to Use?

### Use Status Sync When:
- ✅ You notice mismatched statuses
- ✅ After admin manually rejects transactions
- ✅ After bulk database operations
- ✅ For general data integrity maintenance
- ✅ **Your current issue**: Pending items blocking slots

### Use Time-Based Cleanup When:
- ✅ You want to enforce payment timeouts
- ✅ Automated slot release for unpaid bookings
- ✅ Cleaning up very old abandoned carts
- ✅ Regular maintenance to prevent accumulation

### Use Both:
**Recommended approach** - Schedule both for comprehensive maintenance:

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    // Status sync: Daily at midnight
    $schedule->command('cart:sync-statuses')
        ->daily()
        ->at('00:00')
        ->withoutOverlapping()
        ->onOneServer();

    // Time-based cleanup: Every 15 minutes
    $schedule->command('cart:cleanup-stale')
        ->everyFifteenMinutes()
        ->withoutOverlapping()
        ->onOneServer();
}
```

---

## Your Specific Issue: Transaction #455

### Problem
- **Transaction #455**: `approval_status='pending'`, `status='completed'`
- **Cart Items #1586-1587**: `status='pending'` (08:00-10:00)
- **Cart Items #1588-1590**: `status='cancelled'` (10:00-13:00)
- **Booking #786**: `status='pending'` (08:00-10:00)

Result: Slots 08:00-10:00 appear blocked even though user cancelled them.

### Solution 1: Status Sync (Recommended for immediate fix)

```bash
# Preview
php artisan cart:sync-statuses --dry-run

# Apply
php artisan cart:sync-statuses
```

**What it will do:**
- Find that Transaction #455 has some cancelled items
- Keep items #1586-1587 as `pending` (they weren't cancelled)
- **But** Booking #786 will remain pending (it's valid for 08:00-10:00)

**Result:**
- ✅ Data consistency maintained
- ✅ 08:00-10:00 still shows as booked (correct, because items are pending)
- ✅ 10:00-13:00 shows as available (correct, items were cancelled)

### Solution 2: Time-Based Cleanup (Recommended for full cleanup)

```bash
# Preview
php artisan cart:cleanup-stale --dry-run

# Apply
php artisan cart:cleanup-stale
```

**What it will do:**
- Find Transaction #455 is 336 hours old
- Reject transaction completely
- Reject ALL cart items (#1586-1590)
- Reject Booking #786
- Free up ALL time slots (08:00-13:00)

**Result:**
- ✅ All slots 08:00-13:00 become available
- ✅ No more conflicts
- ✅ User can book 10:00-13:00 (or even 08:00-13:00)

### Recommended Action

**For your immediate issue:** Use **Time-Based Cleanup**

```bash
# Step 1: Preview
php artisan cart:cleanup-stale --dry-run

# Step 2: Verify Transaction #455 is in the list
# Look for: "Processing Transaction #455..."

# Step 3: Apply cleanup
php artisan cart:cleanup-stale

# Step 4: Verify slots are free
# Test booking 10:00-13:00 in frontend
```

**For ongoing maintenance:** Schedule both tools

---

## Quick Reference

### Immediate Fix (Your Issue)
```bash
php artisan cart:cleanup-stale
```

### Data Integrity Check
```bash
php artisan cart:sync-statuses --dry-run
```

### Full Cleanup (Both Tools)
```bash
# Step 1: Sync statuses first
php artisan cart:sync-statuses

# Step 2: Clean up stale items
php artisan cart:cleanup-stale

# Step 3: Clear caches
php artisan cache:clear
php artisan config:clear
```

### Manual Database Check
```bash
php artisan tinker

# Check Transaction #455
$trans = \App\Models\CartTransaction::with('cartItems', 'bookings')->find(455);
echo "Transaction: {$trans->approval_status}, {$trans->status}\n";
foreach ($trans->cartItems as $item) {
    echo "  Item #{$item->id}: {$item->start_time}-{$item->end_time}, status={$item->status}\n";
}
foreach ($trans->bookings as $booking) {
    echo "  Booking #{$booking->id}: {$booking->start_time}-{$booking->end_time}, status={$booking->status}\n";
}
```

---

## Documentation

- **Status Sync**: See [CART_STATUS_SYNC.md](./CART_STATUS_SYNC.md)
- **Time-Based Cleanup**: See [STALE_CART_CLEANUP.md](./STALE_CART_CLEANUP.md)
- **Waitlist Fix**: See [WAITLIST_DISABLED_SLOT_FIX.md](./WAITLIST_DISABLED_SLOT_FIX.md)
- **Slot Accuracy**: See [AVAILABLE_SLOTS_ACCURACY_SUMMARY.md](./AVAILABLE_SLOTS_ACCURACY_SUMMARY.md)

---

## Safety Tips

1. ✅ **Always run with `--dry-run` first**
2. ✅ **Backup database before bulk operations**
3. ✅ **Test on staging environment first**
4. ✅ **Monitor logs after running**: `tail -f storage/logs/laravel.log`
5. ✅ **Clear frontend cache after cleanup**: Browser hard refresh
6. ✅ **Notify users if large cleanup**: Many slots will suddenly become available

---

## Troubleshooting

### Issue: Still seeing conflicts after cleanup

**Possible causes:**
1. Frontend cache - hard refresh browser (Ctrl+Shift+R)
2. Backend cache - run `php artisan cache:clear`
3. New transaction created between cleanup and test
4. Different transaction causing conflict

**Solution:**
```bash
# Clear all caches
php artisan cache:clear
php artisan config:clear
php artisan route:clear

# Re-run cleanup
php artisan cart:cleanup-stale

# Check specific slot
php artisan tinker
$controller = new \App\Http\Controllers\Api\BookingController();
$request = new \Illuminate\Http\Request();
$request->merge(['date' => '2025-11-22']);
$response = $controller->availableSlots($request, 4);
$data = json_decode($response->getContent(), true);
print_r(array_filter($data['data'], function($slot) {
    return $slot['start'] >= '10:00' && $slot['start'] < '13:00';
}));
```

### Issue: Cleanup rejected wrong transactions

**Prevention:**
- Always use `--dry-run` first
- Review the output carefully
- Adjust `--hours` parameter if needed

**Recovery:**
- Database transactions are used, so partial failures are rolled back
- Check logs: `storage/logs/laravel.log`
- Manual database restore from backup if needed

---

## Summary

✅ **Created two powerful tools** for database maintenance
✅ **Status Sync** fixes data inconsistencies
✅ **Time-Based Cleanup** enforces payment timeouts
✅ **Both have dry-run mode** for safety
✅ **Both can be scheduled** for automation
✅ **Your specific issue** will be resolved by running `cart:cleanup-stale`

**Next Steps:**
1. Run `php artisan cart:cleanup-stale` to fix Transaction #455
2. Test booking 10:00-13:00 in frontend
3. Schedule both tools for ongoing maintenance
