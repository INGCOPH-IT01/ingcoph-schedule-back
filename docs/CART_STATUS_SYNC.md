# Cart Status Synchronization

## Overview

This system ensures data integrity by synchronizing statuses between related records:
- `CartTransaction` ↔ `CartItem`
- `CartTransaction` ↔ `Booking`

## Problem It Solves

### Scenario 1: Orphaned Pending Items
- CartTransaction is rejected/cancelled
- But CartItems still show status='pending'
- Result: Items appear available in queries but shouldn't exist

### Scenario 2: Orphaned Pending Transaction
- All CartItems are rejected/cancelled
- But CartTransaction still shows approval_status='pending'
- Result: Transaction appears active but has no valid items

### Scenario 3: Orphaned Records
- CartTransaction is deleted
- But CartItems/Bookings still exist with references to non-existent transaction
- Result: Database integrity violation

## Tools Available

### 1. Artisan Command (Recommended)

**Command:**
```bash
php artisan cart:sync-statuses
```

**Options:**
- `--dry-run`: Preview what would be changed without making changes

**Examples:**

```bash
# Preview changes (safe)
php artisan cart:sync-statuses --dry-run

# Actually perform sync
php artisan cart:sync-statuses
```

### 2. Database Seeder

**Command:**
```bash
php artisan db:seed --class=SyncCartStatusesSeeder
```

**Use Case:** One-time sync or during database maintenance

### 3. Scheduled Task (Automated)

Add to `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    // Run sync daily at midnight
    $schedule->command('cart:sync-statuses')
        ->daily()
        ->at('00:00')
        ->withoutOverlapping()
        ->onOneServer();

    // Or run every hour
    $schedule->command('cart:sync-statuses')
        ->hourly()
        ->withoutOverlapping()
        ->onOneServer();
}
```

## What Gets Synchronized

### Step 1: Rejected/Cancelled Transactions → Update Items

**Finds:**
- CartTransactions with `approval_status='rejected'` OR `status='cancelled'`
- That have CartItems with `status='pending'`

**Action:**
- Updates CartItems to `status='rejected'` or `status='cancelled'`
- Updates Bookings to match transaction status
- Processes waitlist for rejected bookings

**Example:**
```
Before:
  CartTransaction #455: approval_status='rejected', status='cancelled'
  ├─ CartItem #1586: status='pending' ⚠️ Mismatch
  ├─ CartItem #1587: status='pending' ⚠️ Mismatch
  └─ Booking #786: status='pending' ⚠️ Mismatch

After:
  CartTransaction #455: approval_status='rejected', status='cancelled'
  ├─ CartItem #1586: status='rejected' ✅
  ├─ CartItem #1587: status='rejected' ✅
  └─ Booking #786: status='rejected' ✅
```

### Step 2: All Items Rejected → Update Transaction

**Finds:**
- CartTransactions with `approval_status='pending'`
- Where ALL CartItems have `status='rejected'` or `status='cancelled'`

**Action:**
- Updates CartTransaction to `approval_status='rejected'`, `status='cancelled'`
- Updates Bookings to `status='rejected'`
- Processes waitlist for rejected bookings

**Example:**
```
Before:
  CartTransaction #500: approval_status='pending' ⚠️
  ├─ CartItem #2000: status='rejected'
  ├─ CartItem #2001: status='cancelled'
  └─ Booking #900: status='pending' ⚠️

After:
  CartTransaction #500: approval_status='rejected', status='cancelled' ✅
  ├─ CartItem #2000: status='rejected'
  ├─ CartItem #2001: status='cancelled'
  └─ Booking #900: status='rejected' ✅
```

### Step 3: Orphaned Bookings

**Finds:**
- Bookings with `status='pending'` or `status='approved'`
- That have `cart_transaction_id` set
- But the referenced CartTransaction no longer exists

**Action:**
- Updates Booking to `status='cancelled'`
- Adds note explaining orphaned state

**Example:**
```
Before:
  Booking #1000: cart_transaction_id=999, status='pending'
  CartTransaction #999: ❌ Doesn't exist

After:
  Booking #1000: status='cancelled', notes='Parent transaction no longer exists' ✅
```

### Step 4: Orphaned Cart Items

**Finds:**
- CartItems with `status='pending'`
- That have `cart_transaction_id` set
- But the referenced CartTransaction no longer exists

**Action:**
- Updates CartItem to `status='cancelled'`
- Adds admin note explaining orphaned state

**Example:**
```
Before:
  CartItem #3000: cart_transaction_id=777, status='pending'
  CartTransaction #777: ❌ Doesn't exist

After:
  CartItem #3000: status='cancelled', admin_notes='Parent transaction no longer exists' ✅
```

## Example Output

```bash
$ php artisan cart:sync-statuses

🔄 Starting status synchronization...

📋 Step 1: Finding rejected/cancelled transactions with pending cart items...
Found 5 rejected/cancelled transactions
  Transaction #455: Syncing 2 cart items to 'rejected'
  Transaction #455: Syncing 1 bookings to 'rejected'
      Notified waitlist user: John Doe (Position #1)
      Email sent to johndoe@example.com
  Transaction #462: Syncing 3 cart items to 'cancelled'

📋 Step 2: Finding transactions where all cart items are rejected/cancelled...
Found 3 transactions to update
  Transaction #500: All cart items rejected/cancelled, updating transaction
    Updated 1 bookings
  Transaction #501: All cart items rejected/cancelled, updating transaction
    Updated 2 bookings

📋 Step 3: Finding orphaned bookings...
Found 2 orphaned bookings
  Booking #1000: No matching transaction, marking as cancelled
  Booking #1001: No matching transaction, marking as cancelled

📋 Step 4: Finding orphaned cart items...
Found 4 orphaned cart items
  Cart Item #3000: No matching transaction, marking as cancelled
  Cart Item #3001: No matching transaction, marking as cancelled
  Cart Item #3002: No matching transaction, marking as cancelled
  Cart Item #3003: No matching transaction, marking as cancelled

✅ Status synchronization completed successfully!

+---------------------------+-------+
| Metric                    | Count |
+---------------------------+-------+
| Cart Items Updated        | 9     |
| Cart Transactions Updated | 3     |
| Bookings Updated          | 5     |
| Waitlist Entries Processed| 1     |
+---------------------------+-------+
```

## Dry Run Example

```bash
$ php artisan cart:sync-statuses --dry-run

🔄 Starting status synchronization...
⚠️  DRY RUN MODE - No changes will be made

📋 Step 1: Finding rejected/cancelled transactions with pending cart items...
Found 5 rejected/cancelled transactions
  Transaction #455: Would sync 2 cart items to 'rejected'
  Transaction #455: Would sync 1 bookings to 'rejected'
      Would notify waitlist user: John Doe (Position #1)

📋 Step 2: Finding transactions where all cart items are rejected/cancelled...
Found 3 transactions to update
  Transaction #500: All cart items rejected/cancelled, would update transaction

📋 Step 3: Finding orphaned bookings...
Found 2 orphaned bookings
  Booking #1000: No matching transaction, would mark as cancelled

📋 Step 4: Finding orphaned cart items...
Found 4 orphaned cart items
  Cart Item #3000: No matching transaction, would mark as cancelled

✅ Status synchronization completed successfully!

+--------------------------------+-------+
| Metric                         | Count |
+--------------------------------+-------+
| Cart Items (Would Update)      | 9     |
| Cart Transactions (Would Update)| 3    |
| Bookings (Would Update)        | 5     |
| Waitlist Entries (Would Process)| 1    |
+--------------------------------+-------+

💡 Run without --dry-run to actually perform the synchronization
```

## Impact on Your Issue

For Transaction #455 (your specific case):

**Before Sync:**
```
CartTransaction #455:
  approval_status: 'pending'
  status: 'completed'

CartItems:
  #1586: 08:00-09:00, status='pending'
  #1587: 09:00-10:00, status='pending'
  #1588: 10:00-11:00, status='cancelled'
  #1589: 11:00-12:00, status='cancelled'
  #1590: 12:00-13:00, status='cancelled'

Booking #786:
  08:00-10:00, status='pending'
```

**After Sync:**
```
CartTransaction #455:
  approval_status: 'rejected' ✅
  status: 'cancelled' ✅

CartItems:
  #1586: 08:00-09:00, status='rejected' ✅
  #1587: 09:00-10:00, status='rejected' ✅
  #1588: 10:00-11:00, status='cancelled'
  #1589: 11:00-12:00, status='cancelled'
  #1590: 12:00-13:00, status='cancelled'

Booking #786:
  08:00-10:00, status='rejected' ✅
```

**Result:**
- ✅ Slots 08:00-10:00 are freed up
- ✅ No more conflicts when booking 10:00-13:00
- ✅ `availableSlots` API correctly shows all slots as available
- ✅ No "pending approval" errors

## When to Run

### Manual Run
```bash
# When you notice status inconsistencies
php artisan cart:sync-statuses
```

### After Admin Actions
- After bulk rejecting transactions
- After manual database changes
- After data imports/migrations

### Automated Schedule
- Daily at midnight (recommended)
- Or hourly for high-traffic systems

## Monitoring

### Check for Inconsistencies
```bash
php artisan tinker

# Count transactions with mismatched statuses
$mismatch = \App\Models\CartTransaction::whereIn('approval_status', ['rejected'])
    ->whereHas('cartItems', function($q) {
        $q->whereNotIn('status', ['rejected', 'cancelled']);
    })
    ->count();
echo "Transactions with mismatched items: {$mismatch}\n";

# Count orphaned bookings
$orphaned = \App\Models\Booking::whereNotIn('status', ['rejected', 'cancelled'])
    ->whereNotNull('cart_transaction_id')
    ->whereDoesntHave('cartTransaction')
    ->count();
echo "Orphaned bookings: {$orphaned}\n";
```

### Check Logs
```bash
tail -f storage/logs/laravel.log | grep "Cart status sync"
```

## Safety Features

1. **Database Transactions**: All changes wrapped in DB transaction
2. **Rollback on Error**: If any error occurs, all changes are rolled back
3. **Dry Run Mode**: Preview changes before applying them
4. **Logging**: All actions logged to `storage/logs/laravel.log`
5. **Waitlist Processing**: Automatically notifies next user in queue
6. **Email Notifications**: Waitlist users get notified when slots become available

## Differences from Time-Based Cleanup

| Feature | Status Sync | Time-Based Cleanup |
|---------|-------------|-------------------|
| **Trigger** | Status mismatch | Time expired (1+ hours) |
| **Scope** | Any transaction | Only expired ones |
| **Action** | Sync statuses | Reject & cleanup |
| **Use Case** | Data integrity | Timeout enforcement |
| **Schedule** | Daily/Hourly | Every 15 min/Hourly |

**Both tools complement each other:**
- Use **time-based cleanup** to handle payment timeouts
- Use **status sync** to fix data inconsistencies

## Troubleshooting

### Issue: Command not found

**Solution:**
```bash
php artisan config:clear
composer dump-autoload
php artisan cart:sync-statuses
```

### Issue: Sync seems incomplete

**Check:**
```bash
# Run dry-run first to see what's detected
php artisan cart:sync-statuses --dry-run

# If looks good, run actual sync
php artisan cart:sync-statuses
```

### Issue: Still seeing conflicts

**Possible causes:**
1. Frontend cache - hard refresh browser
2. New transactions created after sync
3. Other users booking simultaneously

**Solution:**
```bash
# Re-run sync
php artisan cart:sync-statuses

# Clear API cache if exists
php artisan cache:clear
```

## Recommendations

1. **Run status sync first**: Before time-based cleanup
2. **Schedule both**: Status sync daily, cleanup hourly
3. **Monitor logs**: Check for patterns of inconsistencies
4. **Test with dry-run**: Always preview before applying
5. **Combine with cleanup**: Use both tools for complete maintenance

## Related Documentation

- [STALE_CART_CLEANUP.md](./STALE_CART_CLEANUP.md) - Time-based cleanup
- [WAITLIST_DISABLED_SLOT_FIX.md](./WAITLIST_DISABLED_SLOT_FIX.md) - Waitlist handling
- [AVAILABLE_SLOTS_ACCURACY_SUMMARY.md](./AVAILABLE_SLOTS_ACCURACY_SUMMARY.md) - Slot accuracy verification
