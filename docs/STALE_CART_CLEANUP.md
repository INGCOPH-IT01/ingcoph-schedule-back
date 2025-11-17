# Stale Cart Items Cleanup

## Overview

This system provides tools to automatically clean up expired cart transactions and their associated items that have not been paid within the specified time window.

## What Gets Cleaned Up

1. **Cart Transactions**: Transactions with `approval_status='pending'` older than 1 hour
2. **Cart Items**: All items associated with expired transactions
3. **Bookings**: All bookings created from expired transactions
4. **Waitlist**: Automatically notifies next person in waitlist queue

## Tools Available

### 1. Artisan Command (Recommended)

**Command:**
```bash
php artisan cart:cleanup-stale
```

**Options:**
- `--hours=N`: Set custom expiration time (default: 1 hour)
- `--dry-run`: Preview what would be cleaned up without making changes

**Examples:**

```bash
# Standard cleanup (1 hour expiration)
php artisan cart:cleanup-stale

# Dry run to see what would be cleaned
php artisan cart:cleanup-stale --dry-run

# Clean transactions older than 2 hours
php artisan cart:cleanup-stale --hours=2

# Dry run with custom hours
php artisan cart:cleanup-stale --hours=3 --dry-run
```

### 2. Database Seeder

**Command:**
```bash
php artisan db:seed --class=CleanupStaleCartItemsSeeder
```

**Use Case:** One-time cleanup or during database maintenance

### 3. Scheduled Task (Automated)

Add to `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    // Run cleanup every 15 minutes
    $schedule->command('cart:cleanup-stale')
        ->everyFifteenMinutes()
        ->withoutOverlapping()
        ->onOneServer();

    // Or run every hour
    $schedule->command('cart:cleanup-stale')
        ->hourly()
        ->withoutOverlapping()
        ->onOneServer();
}
```

Then ensure Laravel scheduler is running:
```bash
# Add to crontab (crontab -e)
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

## What Happens During Cleanup

### Step 1: Identify Stale Transactions
- Finds cart transactions with `approval_status='pending'`
- Older than specified threshold (default: 1 hour)
- Not already cancelled

### Step 2: Reject Transaction
- Updates transaction:
  ```php
  'approval_status' => 'rejected'
  'status' => 'cancelled'
  'rejection_reason' => 'Automatically rejected: Payment timeout'
  ```

### Step 3: Reject Cart Items
- All associated cart items updated to `status='rejected'`
- Adds admin note explaining automatic rejection

### Step 4: Reject Bookings
- All associated bookings updated to `status='rejected'`
- Appends note explaining timeout

### Step 5: Process Waitlist
- Finds waitlist entries linked to rejected bookings
- **Only notifies Position #1** (first in line)
- Updates waitlist status to `'notified'`
- Sets expiration (1 hour to complete payment)
- Sends email notification to waitlisted user

### Step 6: Free Up Slots
- Rejected bookings no longer block time slots
- Slots become available for new bookings
- `availableSlots` API correctly shows them as available

## Example Output

```bash
$ php artisan cart:cleanup-stale

🧹 Starting cleanup of stale cart items...
Expiration threshold: 2025-11-17 15:00:00 (older than 1 hour(s))
Found 3 stale cart transactions

Processing Transaction #455 (Age: 2 hours, User: Michelin Baluyot)...
  ✓ Rejected cart transaction #455
  ✓ Rejected 5 cart items
  ✓ Rejected 1 bookings
    - Found 2 waitlist entries for Booking #786
    ✓ Notified waitlist user: John Doe (Position #1)
    ✓ Email sent to johndoe@example.com

Processing Transaction #462 (Age: 3 hours, User: Jane Smith)...
  ✓ Rejected cart transaction #462
  ✓ Rejected 3 cart items
  ✓ Rejected 1 bookings

Processing Transaction #834 (Age: 5 hours, User: Bob Johnson)...
  ✓ Rejected cart transaction #834
  ✓ Rejected 4 cart items
  ✓ Rejected 2 bookings

✅ Cleanup completed successfully!

+----------------------------+-------+
| Metric                     | Count |
+----------------------------+-------+
| Cart Transactions Rejected | 3     |
| Cart Items Rejected        | 12    |
| Bookings Rejected          | 4     |
| Waitlist Users Notified    | 1     |
+----------------------------+-------+
```

## Dry Run Example

```bash
$ php artisan cart:cleanup-stale --dry-run

🧹 Starting cleanup of stale cart items...
⚠️  DRY RUN MODE - No changes will be made
Expiration threshold: 2025-11-17 15:00:00 (older than 1 hour(s))
Found 2 stale cart transactions

Processing Transaction #455 (Age: 2 hours, User: Michelin Baluyot)...
  [DRY RUN] Would reject transaction #455
  [DRY RUN] Would reject 5 cart items
  [DRY RUN] Would reject 1 bookings

Processing Transaction #462 (Age: 3 hours, User: Jane Smith)...
  [DRY RUN] Would reject transaction #462
  [DRY RUN] Would reject 3 cart items
  [DRY RUN] Would reject 1 bookings

✅ Cleanup completed successfully!

+--------------------------------------+-------+
| Metric                               | Count |
+--------------------------------------+-------+
| Cart Transactions (Would Reject)     | 2     |
| Cart Items (Would Reject)            | 8     |
| Bookings (Would Reject)              | 2     |
| Waitlist Users (Would Notify)        | 0     |
+--------------------------------------+-------+

💡 Run without --dry-run to actually perform the cleanup
```

## Use Cases

### Use Case 1: Manual One-Time Cleanup

**Scenario:** Admin notices stale transactions in the database

**Solution:**
```bash
# Preview what will be cleaned
php artisan cart:cleanup-stale --dry-run

# If looks good, run the actual cleanup
php artisan cart:cleanup-stale
```

### Use Case 2: Automated Hourly Cleanup

**Scenario:** Prevent accumulation of expired transactions

**Solution:** Add to `app/Console/Kernel.php`:
```php
$schedule->command('cart:cleanup-stale')->hourly();
```

### Use Case 3: Extended Grace Period

**Scenario:** Give users more time (e.g., 2 hours instead of 1)

**Solution:**
```bash
php artisan cart:cleanup-stale --hours=2
```

### Use Case 4: Database Maintenance

**Scenario:** Cleaning up old data during maintenance window

**Solution:**
```bash
# Clean up very old transactions (older than 24 hours)
php artisan cart:cleanup-stale --hours=24
```

## Impact on System

### Before Cleanup
- **Booking #786**: Status='pending', blocks 08:00-10:00
- **Cart Items #1586-1587**: Status='pending', belong to transaction #455
- **availableSlots API**: Shows 08:00-10:00 as unavailable
- **User tries to book 10:00-13:00**: ✅ Works (no conflict)
- **User tries to book 09:00-10:00**: ❌ Error (conflict with pending items)

### After Cleanup
- **Booking #786**: Status='rejected', no longer blocks slots
- **Cart Items #1586-1587**: Status='rejected', no longer checked for conflicts
- **Transaction #455**: approval_status='rejected', status='cancelled'
- **availableSlots API**: Shows 08:00-10:00 as available
- **User tries to book any time**: ✅ Works (no conflicts)
- **Waitlist user**: Gets notified and can book

## Monitoring

### Check Logs
```bash
tail -f storage/logs/laravel.log | grep "Stale cart transaction"
```

### Manual Check for Stale Transactions
```bash
php artisan tinker

# Count stale transactions
$count = \App\Models\CartTransaction::where('approval_status', 'pending')
    ->where('created_at', '<', now()->subHour())
    ->count();
echo "Stale transactions: {$count}\n";

# List stale transactions
$stale = \App\Models\CartTransaction::where('approval_status', 'pending')
    ->where('created_at', '<', now()->subHour())
    ->with('user')
    ->get();
foreach ($stale as $t) {
    echo "Transaction #{$t->id} | User: {$t->user->name} | Age: " . $t->created_at->diffForHumans() . "\n";
}
```

## Safety Features

1. **Database Transactions**: All changes wrapped in DB transaction
2. **Rollback on Error**: If any error occurs, all changes are rolled back
3. **Dry Run Mode**: Preview changes before applying them
4. **Logging**: All actions logged to `storage/logs/laravel.log`
5. **Email Notifications**: Waitlist users are notified when slots become available

## Troubleshooting

### Issue: Command not found

**Solution:**
```bash
# Clear config cache
php artisan config:clear

# Dump autoload
composer dump-autoload

# Try again
php artisan cart:cleanup-stale
```

### Issue: Email notifications not sent

**Check:** Ensure mail configuration is correct in `.env`:
```env
MAIL_MAILER=smtp
MAIL_HOST=your-smtp-host
MAIL_PORT=587
MAIL_USERNAME=your-username
MAIL_PASSWORD=your-password
```

### Issue: Cleanup seems slow

**Cause:** Large number of stale transactions

**Solution:** Run with `--dry-run` first to see count, then run in batches:
```bash
# Clean oldest first (24 hours)
php artisan cart:cleanup-stale --hours=24

# Then 12 hours
php artisan cart:cleanup-stale --hours=12

# Then 2 hours
php artisan cart:cleanup-stale --hours=2

# Finally standard 1 hour
php artisan cart:cleanup-stale
```

## Recommendations

1. **Run automated cleanup**: Schedule hourly or every 15 minutes
2. **Monitor logs**: Check for any errors during cleanup
3. **Test with dry-run first**: Always preview changes before applying
4. **Set up proper email**: Ensure waitlist users get notified
5. **Adjust timeout as needed**: 1 hour might be too short/long for your use case

## Related Documentation

- [WAITLIST_DISABLED_SLOT_FIX.md](./WAITLIST_DISABLED_SLOT_FIX.md)
- [AVAILABLE_SLOTS_ACCURACY_SUMMARY.md](./AVAILABLE_SLOTS_ACCURACY_SUMMARY.md)
- [BOOKING_FAILED_FIXES.md](./BOOKING_FAILED_FIXES.md)
