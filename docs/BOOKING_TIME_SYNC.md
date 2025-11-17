# Booking Time Synchronization

## Overview

The system automatically updates `Booking` model's `start_time`, `end_time`, and `total_price` fields whenever cart items (time slots) are updated or deleted. This ensures that booking records always reflect the current state of their associated cart items.

## Implementation

### Architecture

The synchronization is implemented using a **Model Observer** pattern:

1. **CartItemObserver** (`app/Observers/CartItemObserver.php`) - Listens for changes to cart items
2. **Automatic Registration** (`app/Providers/AppServiceProvider.php`) - Observer is registered at boot time
3. **Transaction Safety** - All operations run within database transactions for atomicity

### Synchronization Scenarios

#### 1. Cart Item Deleted/Cancelled

**When:** A cart item is deleted or marked as cancelled
**Method:** `syncBookingAfterCartItemCancellation()`

**Behavior:**
- Finds all bookings associated with the cart transaction and court
- Recalculates booking times from remaining active cart items (excludes cancelled and rejected items):
  - `start_time` = earliest start time across all remaining items
  - `end_time` = latest end time across all remaining items
  - `total_price` = sum of all remaining item prices
- If no cart items remain, the booking is cancelled

**Example:**
```
Initial state:
- Cart items: 10:00-11:00, 11:00-12:00, 12:00-13:00
- Booking: 10:00-13:00, price: 300

Delete item 11:00-12:00:
- Cart items: 10:00-11:00, 12:00-13:00
- Booking: 10:00-13:00, price: 200
```

#### 2. Cart Item Time Updated

**When:** A cart item's `booking_date`, `start_time`, or `end_time` is changed
**Method:** `syncBookingAfterDateTimeChange()`

**Behavior:**
- Finds all bookings for the cart transaction and court
- Recalculates booking times from ALL cart items:
  - `start_time` = earliest start time across all items
  - `end_time` = latest end time across all items
  - `total_price` = sum of all item prices
- Handles midnight crossing scenarios

**Example:**
```
Initial state:
- Cart item: 10:00-11:00
- Booking: 10:00-11:00, price: 100

Update item to 14:00-15:00:
- Cart item: 14:00-15:00
- Booking: 14:00-15:00, price: 100
```

#### 3. Cart Item Court Changed

**When:** A cart item's `court_id` is changed
**Method:** `syncBookingAfterCourtChange()`

**Behavior:**
- Finds bookings affected by the court change
- Updates booking's court and recalculates times
- May split bookings if cart items are now on different courts

**Example:**
```
Initial state:
- Cart items on Court A: 10:00-11:00, 11:00-12:00
- Booking on Court A: 10:00-12:00

Change first item to Court B:
- Cart items: Court B (10:00-11:00), Court A (11:00-12:00)
- Bookings: Court B (10:00-11:00), Court A (11:00-12:00)
```

## Status Filtering

Bookings are only updated if their status is NOT:
- `cancelled`
- `rejected`

This ensures that finalized bookings are not accidentally modified.

## Frontend Integration

### BookingDetailsDialog.vue

The frontend dialog allows admins/staff to:
1. **Update cart item date/time** - Calls `cartService.updateCartItem()`
2. **Update cart item court** - Calls API endpoint for court updates
3. **Delete cart item** - Calls API endpoint for deletion

All these operations trigger the observer automatically on the backend.

### API Endpoints

```
PUT  /api/admin/cart-items/{id}  - Update cart item (court, date, time, notes)
DELETE /api/admin/cart-items/{id}  - Delete cart item (marks as cancelled)
```

## Logging

All synchronization operations are logged with detailed information:

```php
Log::info("Booking #{$booking->id} synced after cart item cancellation", [
    'old_start' => '2024-01-15 10:00:00',
    'new_start' => '2024-01-15 11:00:00',
    'old_end' => '2024-01-15 13:00:00',
    'new_end' => '2024-01-15 14:00:00',
    'old_price' => 300.00,
    'new_price' => 200.00
]);
```

## Database Transactions

All synchronization happens within database transactions to ensure:
- **Atomicity** - All updates succeed or fail together
- **Consistency** - Data is always in a valid state
- **No Partial Updates** - Either all changes are applied or none

## Testing Scenarios

To verify the functionality works correctly:

### Test 1: Delete Time Slot
1. Create a cart transaction with 3 time slots
2. Verify booking has correct start/end times
3. Delete the middle time slot
4. Verify booking times updated correctly
5. Verify total price decreased

### Test 2: Update Time Slot
1. Create a cart transaction with 1 time slot
2. Update the time slot to a different time
3. Verify booking start/end times updated
4. Verify no conflicts created

### Test 3: Change Court
1. Create a cart transaction with 2 time slots on Court A
2. Change one slot to Court B
3. Verify bookings split correctly
4. Verify each booking has correct times

## Edge Cases Handled

1. **Midnight Crossing** - Time slots that cross midnight (e.g., 23:00-01:00)
2. **Multiple Courts** - Cart items spanning multiple courts
3. **Multiple Dates** - Cart items on different dates
4. **Last Item Deletion** - Cannot delete the last item (validated in controller)
5. **Cancelled Bookings** - Excluded from updates
6. **Rejected Bookings** - Excluded from updates

## Performance Considerations

- Observer methods are called within existing transactions (no additional overhead)
- Queries are optimized with proper filtering and indexing
- Only affected bookings are updated (not all bookings)
- Bulk operations use efficient queries

## Data Cleanup / Fix Seeder

### FixBookingTimesSeeder

A database seeder is available to fix any inconsistent booking times that may have occurred before the observer was implemented or due to data issues.

**Location:** `database/seeders/FixBookingTimesSeeder.php`

**Usage:**
```bash
php artisan db:seed --class=FixBookingTimesSeeder
```

**What it does:**
- Finds all bookings with a `cart_transaction_id`
- Recalculates `start_time`, `end_time`, and `total_price` from cart items
- Updates bookings that don't match calculated values
- Provides detailed output of changes made

**When to use:**
- After system updates
- To fix historical data inconsistencies
- As part of data validation procedures
- After manual database changes

See [FIX_BOOKING_TIMES_SEEDER.md](./FIX_BOOKING_TIMES_SEEDER.md) for detailed documentation.

## Relationship Between Observer and Seeder

- **CartItemObserver** (Real-time) - Automatically keeps bookings in sync as cart items are modified
- **FixBookingTimesSeeder** (One-time) - Fixes any bookings that became inconsistent in the past

Run the seeder once to fix historical data, then the observer keeps everything in sync going forward.

## Future Enhancements

Potential improvements:
1. Add caching for frequently accessed cart items
2. Implement event broadcasting for real-time UI updates
3. Add booking version tracking for audit trail
4. Optimize for very large cart transactions (100+ items)
5. Add automated scheduled task to detect and fix inconsistencies
