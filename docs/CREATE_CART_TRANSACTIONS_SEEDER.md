# Create Cart Transactions for Bookings Seeder

## Overview

This seeder (`CreateCartTransactionsForBookingsSeeder`) creates `cart_transactions` and `cart_items` for bookings that are missing these records. This is useful for fixing data inconsistencies where bookings were created directly without going through the standard cart flow, or where cart items were not properly created.

## Two Scenarios Handled

### Scenario 1: Bookings without Cart Transactions
Handles bookings where `cart_transaction_id` is `NULL`:
1. **Finds Orphaned Bookings**: Identifies all bookings without cart transactions
2. **Groups Intelligently**: Groups bookings by user and creation time (within 5-minute windows) to create realistic cart transactions
3. **Creates Cart Transactions**: For each group, creates a `CartTransaction` with appropriate status and payment details
4. **Creates Cart Items**: Creates a `CartItem` for each booking with all relevant booking details
5. **Links Everything**: Updates the original booking with the new `cart_transaction_id`

### Scenario 2: Bookings with Transactions but Missing Cart Items
Handles bookings that have a `cart_transaction_id` but no corresponding cart item:
1. **Finds Incomplete Records**: Identifies bookings that are linked to a transaction but have no matching cart item
2. **Creates Missing Cart Items**: Creates the cart item record with all booking details
3. **Updates Transaction Total**: Recalculates and updates the cart transaction's total price if needed

## Features

- **Smart Grouping**: Bookings created within 5 minutes by the same user are grouped into a single cart transaction
- **Status Mapping**: Automatically maps booking statuses to appropriate transaction and approval statuses
- **Transaction Safety**: Uses database transactions to ensure data integrity
- **Detailed Reporting**: Provides comprehensive output with tables showing all processed bookings
- **Error Handling**: Rolls back on errors and continues processing other groups

## Status Mapping

### Transaction Status
- `approved`, `checked_in`, `completed` bookings → `completed` transaction
- `cancelled`, `rejected` bookings → `cancelled` transaction
- `pending` bookings → `pending` transaction

### Approval Status
- `approved`, `checked_in`, `completed` bookings → `approved` approval status
- `rejected` bookings → `rejected` approval status
- `pending` bookings → `pending` approval status

## How to Run

### Run the Seeder

```bash
cd /Users/karloalfonso/Documents/GitHub/schedule/ingcoph-schedule-back
php artisan db:seed --class=CreateCartTransactionsForBookingsSeeder
```

### Check Results

After running, the seeder will display:
- Number of booking groups processed
- Number of cart transactions created
- Number of cart items created
- Number of bookings updated
- Any errors encountered
- A detailed table of all processed bookings

## Example Output

```
Starting to create cart transactions and cart items for bookings...

Found 8 booking(s) without cart transactions.
Found 3 booking(s) without cart items.

=== SCENARIO 1: Creating cart transactions and cart items ===

Processing group: User ID 5 with 3 booking(s)
  ✓ Created Cart Transaction ID 123
  ✓ Created Cart Item ID 456 for Booking ID 1
  ✓ Updated Booking ID 1 with cart_transaction_id
  ✓ Created Cart Item ID 457 for Booking ID 2
  ✓ Updated Booking ID 2 with cart_transaction_id
  ✓ Created Cart Item ID 458 for Booking ID 3
  ✓ Updated Booking ID 3 with cart_transaction_id
  Group completed successfully.

Scenario 1 processing complete.

=== SCENARIO 2: Creating cart items for existing transactions ===

Processing Booking ID 15 (Transaction ID: 100)
  ✓ Created Cart Item ID 500
  Booking processed successfully.

Processing Booking ID 16 (Transaction ID: 101)
  ✓ Created Cart Item ID 501
  ✓ Updated Transaction total_price to ₱1,500.00
  Booking processed successfully.

Scenario 2 processing complete.

=================================
Seeding Complete!
=================================
Bookings without cart_transaction processed (Scenario 1): 8
Bookings without cart_item processed (Scenario 2): 3
Total bookings processed: 11
Cart transactions created: 3
Cart items created: 11
Errors encountered: 0

Details of processed bookings:
+----------+------------+----------------+---------------+----------+------------------+------------------+-----------+----------+-----------+
| Scenario | Booking ID | Transaction ID | Cart Item ID  | Court    | Start Time       | End Time         | Price     | Status   | User      |
+----------+------------+----------------+---------------+----------+------------------+------------------+-----------+----------+-----------+
| 1        | 1          | 123            | 456           | Court 1  | 2024-10-25 08:00 | 2024-10-25 09:00 | ₱500.00   | approved | John Doe  |
| 1        | 2          | 123            | 457           | Court 1  | 2024-10-25 09:00 | 2024-10-25 10:00 | ₱500.00   | approved | John Doe  |
| 2        | 15         | 100            | 500           | Court 2  | 2024-10-26 10:00 | 2024-10-26 11:00 | ₱600.00   | pending  | Jane Smith|
+----------+------------+----------------+---------------+----------+------------------+------------------+-----------+----------+-----------+
```

## Verification Queries

After running the seeder, verify the results:

```sql
-- SCENARIO 1: Check that all bookings now have cart_transaction_id
SELECT COUNT(*) as bookings_without_transaction
FROM bookings
WHERE cart_transaction_id IS NULL;
-- Should return 0

-- SCENARIO 2: Check for bookings without matching cart items
SELECT b.id as booking_id, b.cart_transaction_id, b.court_id, b.start_time, b.end_time
FROM bookings b
LEFT JOIN cart_items ci ON
    ci.cart_transaction_id = b.cart_transaction_id
    AND ci.court_id = b.court_id
    AND ci.booking_date = DATE(b.start_time)
    AND ci.start_time = TIME(b.start_time)
    AND ci.end_time = TIME(b.end_time)
WHERE b.cart_transaction_id IS NOT NULL
AND ci.id IS NULL;
-- Should return 0 rows

-- Verify cart transactions were created with correct booking counts
SELECT ct.id, ct.user_id, ct.total_price, ct.status, COUNT(b.id) as booking_count
FROM cart_transactions ct
LEFT JOIN bookings b ON b.cart_transaction_id = ct.id
GROUP BY ct.id, ct.user_id, ct.total_price, ct.status;

-- Verify cart items match bookings (should have equal counts per transaction)
SELECT
    ct.id as transaction_id,
    COUNT(DISTINCT ci.id) as cart_items_count,
    COUNT(DISTINCT b.id) as bookings_count,
    ct.total_price as transaction_total,
    SUM(ci.price) as cart_items_total,
    SUM(b.total_price) as bookings_total
FROM cart_transactions ct
LEFT JOIN cart_items ci ON ci.cart_transaction_id = ct.id
LEFT JOIN bookings b ON b.cart_transaction_id = ct.id
GROUP BY ct.id, ct.total_price
HAVING cart_items_count != bookings_count OR transaction_total != cart_items_total;
-- Should return 0 rows if everything is in sync

-- Check for orphaned cart items (cart items without corresponding bookings)
SELECT ci.id as cart_item_id, ci.cart_transaction_id, ci.court_id, ci.booking_date, ci.start_time, ci.end_time
FROM cart_items ci
LEFT JOIN bookings b ON
    b.cart_transaction_id = ci.cart_transaction_id
    AND b.court_id = ci.court_id
    AND DATE(b.start_time) = ci.booking_date
    AND TIME(b.start_time) = ci.start_time
    AND TIME(b.end_time) = ci.end_time
WHERE ci.cart_transaction_id IS NOT NULL
AND b.id IS NULL;
-- Shows cart items that don't have corresponding bookings (this might be expected in some cases)
```

## Safety Notes

- The seeder uses database transactions to ensure atomicity
- If an error occurs in a group, only that group is rolled back
- The seeder preserves original timestamps (`created_at`, `updated_at`) from bookings
- Can be run multiple times safely - will only process bookings without cart transactions

## Related Seeders

- `CreateBookingsForCartTransactionsSeeder`: Does the opposite - creates bookings for cart transactions that have no bookings

## Troubleshooting

### No bookings found that need processing
Message: `✓ No bookings found that need cart transactions or cart items.`

This means:
- All bookings have cart_transaction_id assigned (Scenario 1 complete)
- All bookings with cart_transaction_id have matching cart items (Scenario 2 complete)
- No action needed

### Errors during processing
Check the error message in the output. Common issues:

**Scenario 1 errors:**
- Missing user_id in bookings
- Missing court_id or sport_id
- Database constraints violations when creating transactions

**Scenario 2 errors:**
- Transaction not found (cart_transaction_id points to non-existent transaction)
- Missing required booking fields
- Duplicate cart items (cart item with same details already exists)

### Mismatched totals after running
If transaction totals don't match the sum of cart item prices:
- The seeder automatically recalculates totals in Scenario 2
- Run the verification queries to identify any remaining mismatches
- Consider running the seeder again if issues persist

### Verify data integrity after running
Use the verification queries above to ensure:
- All bookings have cart_transaction_id (Scenario 1)
- All bookings have matching cart items (Scenario 2)
- Cart transactions have matching cart items
- Total prices match across transactions, cart items, and bookings
