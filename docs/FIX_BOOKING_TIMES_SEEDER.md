# Fix Booking Times Seeder

## Overview

The `FixBookingTimesSeeder` is a database seeder that fixes inconsistent booking `start_time`, `end_time`, and `total_price` values by recalculating them from their associated cart items. This is useful for:

- **Data cleanup** after system updates
- **Migration scenarios** where booking times may have gotten out of sync
- **One-time fixes** after bugs that caused inconsistencies
- **Verification** that all bookings are correctly synced with their cart items

## When to Use

Use this seeder when:

1. You suspect bookings have incorrect start/end times compared to their cart items
2. After migrating from an older system version
3. After fixing bugs related to booking time synchronization
4. As part of data validation/cleanup procedures
5. After manual database changes that may have affected cart items

## How It Works

The seeder:

1. **Finds all bookings** that have a `cart_transaction_id` (bookings created from cart checkout)
2. **Excludes** bookings with status `cancelled` or `rejected`
3. **For each booking:**
   - Gets all active (non-cancelled, non-rejected) cart items for that booking's court
   - Calculates the earliest `start_time` across all cart items
   - Calculates the latest `end_time` across all cart items
   - Sums up the total price from all cart items
   - Compares with current booking values
   - Updates booking if values don't match
4. **Handles edge cases:**
   - Midnight crossing time slots
   - Multiple dates across cart items
   - Price rounding differences (tolerance of 0.01)
5. **Provides detailed output** showing what was changed

## Usage

### Basic Usage

Run the seeder using artisan:

```bash
php artisan db:seed --class=FixBookingTimesSeeder
```

### Dry Run (Preview Changes)

To see what would be changed without actually updating:

```bash
# Edit the seeder temporarily and comment out the update line
# Then run:
php artisan db:seed --class=FixBookingTimesSeeder
```

### Running in Production

For production environments:

```bash
# Run with explicit confirmation
php artisan db:seed --class=FixBookingTimesSeeder

# Check logs after running
tail -f storage/logs/laravel.log | grep FixBookingTimesSeeder
```

## Output Example

```
Starting to fix booking times from cart items...
Found 45 bookings to check.

Booking #123 needs update:
  Cart Transaction ID: 456
  Court: Court A (ID: 1)
  Cart Items: 3 items
  Start Time: 2024-01-15 10:00:00 → 2024-01-15 09:00:00
  End Time:   2024-01-15 13:00:00 → 2024-01-15 14:00:00
  Price:      ₱300.00 → ₱350.00

Booking #124 is already correct. Skipping.

Booking #125 needs update:
  Cart Transaction ID: 457
  Court: Court B (ID: 2)
  Cart Items: 2 items
  End Time:   2024-01-16 12:00:00 → 2024-01-16 13:00:00

=== Summary ===
Total bookings checked: 45
Bookings updated: 12
Bookings already correct: 33
Errors: 0

Done!
```

## What Gets Updated

The seeder updates three fields on the `Booking` model:

1. **`start_time`** - Set to the earliest start time across all associated cart items
2. **`end_time`** - Set to the latest end time across all associated cart items
3. **`total_price`** - Set to the sum of prices from all associated cart items

## Calculation Logic

### Start Time Calculation

```php
// Find the earliest start time across all cart items
foreach ($cartItems as $item) {
    $itemStartDateTime = Carbon::parse($item->booking_date . ' ' . $item->start_time);

    if ($earliestStart === null || $itemStartDateTime->lt($earliestStart)) {
        $earliestStart = $itemStartDateTime;
    }
}
```

### End Time Calculation

```php
// Find the latest end time across all cart items
foreach ($cartItems as $item) {
    $itemEndDateTime = Carbon::parse($item->booking_date . ' ' . $item->end_time);

    // Handle midnight crossing
    if ($itemEndDateTime->lte($itemStartDateTime)) {
        $itemEndDateTime->addDay();
    }

    if ($latestEnd === null || $itemEndDateTime->gt($latestEnd)) {
        $latestEnd = $itemEndDateTime;
    }
}
```

### Price Calculation

```php
// Sum up all cart item prices
$totalPrice = 0;
foreach ($cartItems as $item) {
    $totalPrice += floatval($item->price);
}
```

## Safety Features

### 1. Transaction Safety
All updates happen within a database transaction. If any error occurs, all changes are rolled back.

### 2. Status Filtering
Only processes bookings that are NOT:
- `cancelled`
- `rejected`

This ensures finalized/closed bookings are not modified.

### 3. Cart Item Filtering
Only considers cart items that are NOT:
- `cancelled` status
- `rejected` status

### 4. Detailed Logging
Every update is logged to `storage/logs/laravel.log`:

```json
{
  "message": "FixBookingTimesSeeder: Updated booking #123",
  "old_start": "2024-01-15 10:00:00",
  "new_start": "2024-01-15 09:00:00",
  "old_end": "2024-01-15 13:00:00",
  "new_end": "2024-01-15 14:00:00",
  "old_price": 300.00,
  "new_price": 350.00,
  "cart_items_count": 3
}
```

### 5. Error Handling
If an error occurs for a specific booking:
- Error is logged with full details
- Processing continues for remaining bookings
- Summary shows count of errors

## Edge Cases Handled

### 1. Midnight Crossing
Time slots that cross midnight (e.g., 23:00-01:00):

```php
if ($itemEndDateTime->lte($itemStartDateTime)) {
    $itemEndDateTime->addDay();
}
```

### 2. Multiple Dates
Cart items spanning multiple dates are handled correctly by comparing full datetime values.

### 3. Price Precision
Floating-point comparison uses a tolerance of 0.01:

```php
abs($currentPrice - $calculatedPrice) > 0.01
```

### 4. No Cart Items
If a booking has no active cart items, it's skipped with a warning.

### 5. Missing Data
If start/end times cannot be calculated, the booking is skipped with a warning.

## Verification

After running the seeder, verify the results:

### 1. Check Summary Output
Review the summary to see how many bookings were updated.

### 2. Check Logs
```bash
grep "FixBookingTimesSeeder" storage/logs/laravel.log | tail -20
```

### 3. Manual Verification
For critical bookings, manually verify:

```sql
SELECT
    b.id,
    b.cart_transaction_id,
    b.court_id,
    b.start_time AS booking_start,
    b.end_time AS booking_end,
    b.total_price AS booking_price,
    MIN(CONCAT(ci.booking_date, ' ', ci.start_time)) AS calc_start,
    MAX(CONCAT(ci.booking_date, ' ', ci.end_time)) AS calc_end,
    SUM(ci.price) AS calc_price
FROM bookings b
JOIN cart_items ci ON ci.cart_transaction_id = b.cart_transaction_id
                   AND ci.court_id = b.court_id
                   AND ci.status NOT IN ('cancelled', 'rejected')
WHERE b.cart_transaction_id IS NOT NULL
  AND b.status NOT IN ('cancelled', 'rejected')
GROUP BY b.id, b.cart_transaction_id, b.court_id, b.start_time, b.end_time, b.total_price
HAVING booking_start != calc_start
    OR booking_end != calc_end
    OR ABS(booking_price - calc_price) > 0.01;
```

This query will show any remaining inconsistencies.

## Common Issues

### Issue: "Booking has no active cart items"

**Cause:** The booking's cart items have all been cancelled or deleted.

**Solution:** This is normal. The booking should probably be cancelled. You may want to manually review these bookings.

### Issue: "Could not calculate times for booking"

**Cause:** Data issue preventing time calculation.

**Solution:** Manually investigate the specific booking and its cart items.

### Issue: Transaction timeout for large datasets

**Cause:** Too many bookings to process in one transaction.

**Solution:** Modify the seeder to process in batches:

```php
// In the run() method, add chunking
Booking::whereNotNull('cart_transaction_id')
    ->whereNotIn('status', ['cancelled', 'rejected'])
    ->chunk(100, function ($bookings) {
        DB::transaction(function () use ($bookings) {
            foreach ($bookings as $booking) {
                // ... process booking
            }
        });
    });
```

## Integration with Automatic Sync

This seeder complements the automatic `CartItemObserver`:

- **Observer:** Keeps bookings in sync as cart items are modified (real-time)
- **Seeder:** Fixes any bookings that became inconsistent (one-time fix)

After running this seeder, the observer will keep everything in sync going forward.

## Rollback

If you need to rollback changes (not recommended):

1. Restore from database backup taken before running the seeder
2. Or manually revert specific bookings using the log data

**Note:** There is no automatic rollback mechanism. Always backup before running on production!

## Testing

Before running on production, test on a staging environment:

```bash
# On staging
php artisan db:seed --class=FixBookingTimesSeeder

# Review output and logs
tail -f storage/logs/laravel.log | grep FixBookingTimesSeeder

# Manually verify a few bookings
# If satisfied, run on production
```

## Maintenance

### Running Periodically

You can add this seeder to a maintenance command that runs periodically:

```php
// In a custom artisan command
public function handle()
{
    $this->call(FixBookingTimesSeeder::class);
}
```

### Monitoring

Set up monitoring to detect when bookings become out of sync, then run this seeder as needed.

## Related Documentation

- [Booking Time Synchronization](./BOOKING_TIME_SYNC.md) - Automatic sync via observer
- `app/Observers/CartItemObserver.php` - Observer implementation
- `app/Models/Booking.php` - Booking model
- `app/Models/CartItem.php` - Cart item model
