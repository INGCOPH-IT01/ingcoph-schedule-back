# Manual Test: Court Change Booking Sync

## Purpose
Verify that when a cart item's court is changed, the corresponding booking record is automatically updated.

## Prerequisites
- Backend server running
- Admin user authenticated
- At least 2 active courts available
- Test data: A booking with multiple consecutive time slots

## Test Setup

### 1. Create Test Booking
Use the frontend or API to create a booking with multiple consecutive time slots:

**Request**: POST `/api/cart`
```json
{
  "items": [
    {
      "court_id": 1,
      "sport_id": 1,
      "booking_date": "2025-01-15",
      "start_time": "09:00",
      "end_time": "10:00",
      "price": 100,
      "number_of_players": 2
    },
    {
      "court_id": 1,
      "sport_id": 1,
      "booking_date": "2025-01-15",
      "start_time": "10:00",
      "end_time": "11:00",
      "price": 100,
      "number_of_players": 2
    },
    {
      "court_id": 1,
      "sport_id": 1,
      "booking_date": "2025-01-15",
      "start_time": "11:00",
      "end_time": "12:00",
      "price": 100,
      "number_of_players": 2
    }
  ]
}
```

### 2. Checkout the Cart
**Request**: POST `/api/cart/checkout`
```json
{
  "selected_items": [/* cart item IDs */],
  "payment_method": "gcash",
  "payment_status": "paid"
}
```

**Expected Result**:
- 3 cart items created
- 1 booking created (9am-12pm on Court 1)

### 3. Verify Initial State
Query the database:
```sql
SELECT id, cart_transaction_id, court_id, start_time, end_time, status
FROM bookings
WHERE cart_transaction_id = <transaction_id>;

SELECT id, court_id, booking_date, start_time, end_time, status
FROM cart_items
WHERE cart_transaction_id = <transaction_id>;
```

## Test Cases

### Test Case 1: Change Court of Middle Cart Item

**Action**: Update cart item #2 (10-11am) to Court 2
**Request**: PUT `/api/admin/cart-items/{cart_item_id}`
```json
{
  "court_id": 2
}
```

**Expected Database State After**:
```
Bookings Table:
- Booking #1: Court 1, 9-10am (original booking updated)
- Booking #2: Court 2, 10-11am (NEW booking created)
- Booking #3: Court 1, 11-12pm (NEW booking created)

Cart Items Table:
- Item #1: Court 1, 9-10am
- Item #2: Court 2, 10-11am ← Changed
- Item #3: Court 1, 11-12pm
```

**Expected Logs**:
```
Cart item #X court changed from 1 to 2
Processing booking #Y (was 2025-01-15 09:00:00 to 2025-01-15 12:00:00 on court 1)
Updated booking #Y to court 1
Created new booking #Z for court 2
Created new booking #W for court 1
Completed court change sync for cart item #X
```

### Test Case 2: Change Court of All Cart Items

**Setup**: Create a new booking with 2 consecutive slots on Court 1

**Action**: Change both cart items to Court 2 (one by one)

**Expected Result**:
- First update: Booking split into 2 bookings
- Second update: Second booking moved to Court 2
- Final state: All bookings on Court 2

### Test Case 3: Change Court Back

**Setup**: Use booking from Test Case 1 with split bookings

**Action**: Change the middle cart item (Court 2) back to Court 1

**Expected Result**:
- System re-groups cart items
- Should consolidate back into fewer bookings (if consecutive)
- All cart items and bookings in sync

### Test Case 4: Change Court of Single-Slot Booking

**Setup**: Create a booking with only 1 time slot

**Action**: Change the court

**Expected Result**:
- Booking updated to new court
- No new bookings created
- Simple 1-to-1 update

## Verification Queries

### Check Booking-Cart Item Consistency
```sql
SELECT
    b.id as booking_id,
    b.court_id as booking_court,
    b.start_time as booking_start,
    b.end_time as booking_end,
    ci.id as cart_item_id,
    ci.court_id as cart_court,
    ci.booking_date,
    ci.start_time as cart_start,
    ci.end_time as cart_end
FROM bookings b
JOIN cart_items ci ON b.cart_transaction_id = ci.cart_transaction_id
WHERE b.cart_transaction_id = <transaction_id>
ORDER BY ci.booking_date, ci.start_time;
```

### Check for Orphaned Bookings
```sql
-- Bookings that don't match any cart items
SELECT b.*
FROM bookings b
WHERE b.cart_transaction_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM cart_items ci
      WHERE ci.cart_transaction_id = b.cart_transaction_id
        AND ci.court_id = b.court_id
        AND ci.status != 'cancelled'
  );
```

## Success Criteria

✅ **Pass Criteria**:
1. After court change, all bookings reflect the correct court
2. Grouped bookings are properly split when cart items move to different courts
3. No orphaned bookings exist
4. Booking times match the consolidated cart item times
5. Prices are recalculated correctly
6. No database errors in logs
7. Frontend displays updated information correctly

❌ **Fail Criteria**:
1. Booking still shows old court after cart item update
2. Multiple bookings exist for the same time slot on same court
3. Database errors or constraint violations
4. Orphaned bookings with no matching cart items
5. Price mismatches between bookings and cart items

## Rollback

If test fails and you need to rollback:
1. Stop the backend server
2. Restore database from backup
3. Revert code changes:
   ```bash
   cd /Users/karloalfonso/Documents/GitHub/schedule/ingcoph-schedule-back
   git checkout app/Observers/CartItemObserver.php
   git checkout app/Http/Controllers/Api/CartController.php
   ```
4. Restart server

## Troubleshooting

### Issue: Bookings not updating
- Check if observer is registered in `AppServiceProvider.php`
- Verify observer is being called (check logs)
- Check if transaction is being committed

### Issue: Duplicate bookings created
- Check grouping logic in `groupCartItemsByCourtAndTime()`
- Verify overlap detection logic

### Issue: Orphaned bookings
- Check if cart items are being properly filtered (status != 'cancelled')
- Verify transaction IDs match

## Notes
- All changes happen within a database transaction
- If any step fails, the entire transaction is rolled back
- Observer automatically handles the sync - no manual intervention needed
- Logs are written to `storage/logs/laravel.log`
