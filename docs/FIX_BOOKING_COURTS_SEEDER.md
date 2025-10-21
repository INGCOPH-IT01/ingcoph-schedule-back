# Fix Booking Courts Seeder

## Purpose
These seeders fix historical data inconsistencies where booking records have a different `court_id` than their corresponding cart items. This issue occurred before the court change bug fix was implemented (see `COURT_CHANGE_AVAILABILITY_FIX.md`).

## Background
When admins changed a booking's court through the cart item update API, only the `cart_items` table was updated. The corresponding `bookings` table records were not updated, causing:
- Incorrect availability checks
- Mismatched court assignments
- Data integrity issues

## Two Seeder Options

### Option 1: FixBookingCourtsSeeder (Recommended)
**File:** `database/seeders/FixBookingCourtsSeeder.php`

**Features:**
- Uses efficient raw SQL queries
- Shows preview of changes before applying
- Requires confirmation before updating
- Verifies the fix after completion
- Best for large datasets

**Pros:**
- Fast and efficient
- Shows exactly what will be changed
- Safe with confirmation prompt
- Provides before/after verification

**Cons:**
- Less flexible for complex scenarios

### Option 2: UpdateBookingCourtsFromCartItemsSeeder
**File:** `database/seeders/UpdateBookingCourtsFromCartItemsSeeder.php`

**Features:**
- Uses Laravel Eloquent ORM
- More detailed logging per booking
- Better error handling per record
- Shows detailed table of changes

**Pros:**
- More readable code
- Better for debugging individual issues
- Detailed per-record reporting

**Cons:**
- Slower on large datasets
- More memory intensive

## How to Run

### Step 1: Check for Issues First
Before running any seeder, you can check if you have mismatches:

```bash
cd /Users/karloalfonso/Documents/GitHub/schedule/ingcoph-schedule-back

php artisan db:seed --class=FixBookingCourtsSeeder
```

This will show you a preview without making changes (requires confirmation).

### Step 2: Backup Your Database
**IMPORTANT:** Always backup before running data fixes!

```bash
# MySQL backup
mysqldump -u your_username -p your_database > backup_before_court_fix_$(date +%Y%m%d_%H%M%S).sql

# Or use Laravel backup if configured
php artisan backup:run
```

### Step 3: Run the Seeder

#### Option A: Using FixBookingCourtsSeeder (Recommended)
```bash
php artisan db:seed --class=FixBookingCourtsSeeder
```

**Output Example:**
```
Starting to fix booking court mismatches...
Found 15 booking(s) with court mismatches
Bookings to be updated:

+-----------+------------------+------------------+---------------------+--------------+
| Booking ID| Old Court        | New Court        | Start Time          | User         |
+-----------+------------------+------------------+---------------------+--------------+
| 145       | Court A (ID: 1)  | Court B (ID: 2)  | 2025-10-21 10:00:00 | John Doe     |
| 142       | Court B (ID: 2)  | Court C (ID: 3)  | 2025-10-20 14:00:00 | Jane Smith   |
+-----------+------------------+------------------+---------------------+--------------+

Do you want to proceed with the update? (yes/no) [yes]:
> yes

Updating bookings...

=================================
Update Complete!
=================================
Bookings updated: 15
✓ All bookings are now in sync with their cart items!
```

#### Option B: Using UpdateBookingCourtsFromCartItemsSeeder
```bash
php artisan db:seed --class=UpdateBookingCourtsFromCartItemsSeeder
```

**Output Example:**
```
Starting to sync booking courts with cart items...
Updated Booking ID 145: Court 1 → 2
Updated Booking ID 142: Court 2 → 3
...

=================================
Sync Complete!
=================================
Total bookings checked: 150
Bookings updated: 15
Bookings skipped: 135
Errors encountered: 0

Details of updated bookings:
+-----------+------------+------------+---------------------+---------------------+-----------+
| Booking ID| Old Court  | New Court  | Start Time          | End Time            | User      |
+-----------+------------+------------+---------------------+---------------------+-----------+
| 145       | 1          | 2          | 2025-10-21 10:00:00 | 2025-10-21 11:00:00 | John Doe  |
+-----------+------------+------------+---------------------+---------------------+-----------+
```

### Step 4: Verify the Fix

#### Manual SQL Verification
```sql
-- Check for remaining mismatches
SELECT
    b.id as booking_id,
    b.court_id as booking_court,
    ci.court_id as cart_item_court,
    b.start_time,
    c1.name as booking_court_name,
    c2.name as cart_court_name
FROM bookings b
INNER JOIN cart_items ci ON
    b.cart_transaction_id = ci.cart_transaction_id
    AND DATE(b.start_time) = ci.booking_date
    AND TIME(b.start_time) = ci.start_time
    AND TIME(b.end_time) = ci.end_time
LEFT JOIN courts c1 ON b.court_id = c1.id
LEFT JOIN courts c2 ON ci.court_id = c2.id
WHERE b.cart_transaction_id IS NOT NULL
    AND b.status IN ('pending', 'approved', 'completed')
    AND b.court_id != ci.court_id;

-- Should return 0 rows if fix is complete
```

#### Test in Application
1. Open the admin panel
2. Check a booking that was previously changed
3. Verify the court matches in:
   - Booking details
   - Cart item details
4. Open New Booking dialog
5. Verify availability shows correctly for both old and new courts

## What the Seeder Does

### Matching Logic
The seeder matches bookings to cart items using:
1. **cart_transaction_id** - Links the booking to the transaction
2. **booking_date** - Date must match
3. **start_time** - Start time must match
4. **end_time** - End time must match

### Update Logic
For each matched pair where `court_id` differs:
1. Update `bookings.court_id` to match `cart_items.court_id`
2. Log the change
3. Verify the update

### Safety Features
- Only updates bookings with status: pending, approved, or completed
- Skips cancelled or rejected bookings
- Requires confirmation before making changes (FixBookingCourtsSeeder)
- Shows preview of changes
- Verifies fix after completion

## Troubleshooting

### Issue: "No matching cart item found"
**Cause:** Booking exists without a corresponding cart item, or time slots don't match exactly.

**Solution:**
- These are usually old bookings created before the cart system
- They can be safely skipped
- Manually verify these bookings if needed

### Issue: "Cart transaction not found"
**Cause:** Booking has a `cart_transaction_id` but the transaction was deleted.

**Solution:**
- Set `cart_transaction_id` to NULL for these bookings
- Or manually verify and fix the relationship

### Issue: Seeder times out
**Cause:** Too many bookings to process.

**Solution:**
```php
// Increase memory limit in seeder
ini_set('memory_limit', '512M');
set_time_limit(300); // 5 minutes
```

Or process in batches:
```bash
# Run for specific date range
php artisan tinker

>> \App\Models\Booking::whereDate('start_time', '>=', '2025-10-01')
     ->whereDate('start_time', '<=', '2025-10-31')
     ->chunk(100, function($bookings) {
         // Process logic here
     });
```

## Running in Production

### Production Checklist
1. ✅ Create full database backup
2. ✅ Test seeder on staging environment first
3. ✅ Schedule during low-traffic period
4. ✅ Monitor for errors
5. ✅ Verify results immediately after
6. ✅ Keep backup for at least 7 days

### Production Command
```bash
# Run with output logging
php artisan db:seed --class=FixBookingCourtsSeeder 2>&1 | tee booking_court_fix_$(date +%Y%m%d_%H%M%S).log
```

## Rollback Procedure

If something goes wrong:

```bash
# Restore from backup
mysql -u your_username -p your_database < backup_before_court_fix_YYYYMMDD_HHMMSS.sql
```

## Performance Notes

### FixBookingCourtsSeeder (SQL)
- **Speed:** ~1000 bookings/second
- **Memory:** Low (constant)
- **Database Load:** Single UPDATE query

### UpdateBookingCourtsFromCartItemsSeeder (Eloquent)
- **Speed:** ~100 bookings/second
- **Memory:** High (loads models)
- **Database Load:** Multiple queries per booking

## Related Documentation
- `COURT_CHANGE_AVAILABILITY_FIX.md` - Original bug fix documentation
- Database schema: `cart_items` and `bookings` tables
- API endpoint: `PUT /api/cart-items/{id}`

## Date
October 21, 2025
