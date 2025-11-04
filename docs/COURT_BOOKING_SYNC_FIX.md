# Court Booking Sync Fix

## Issue
When an admin changed the court of a cart item via the BookingDetailsDialog, the booking record in the `bookings` table was not being updated. This caused inconsistencies where:
- Cart items showed the new court
- Booking records still showed the old court
- Availability checks would be incorrect
- The booking details displayed wrong information

## Root Cause
The system has a dual-table structure:
1. `cart_items` - Contains individual time slot bookings
2. `bookings` - Contains consolidated booking records (created during checkout)

During checkout, multiple consecutive cart items on the same court are **grouped** into a single booking record. For example:
- Cart items: Court A 9-10am, Court A 10-11am, Court A 11-12pm
- Creates ONE booking: Court A 9am-12pm

### The Problem
When a cart item's court was changed:
1. The `cart_items` table was updated correctly ✅
2. The `bookings` table was NOT updated ❌
3. The previous fix in `CartController::updateCartItem()` attempted to update bookings but used an **exact time match**
4. This failed because the booking time span (9am-12pm) didn't match the cart item time (10-11am)

## Solution

### Backend Fix (CartItemObserver.php)

Enhanced the `CartItemObserver` to properly handle court changes by:

1. **Detecting Court Changes**: Observer now watches for `court_id` changes on cart items
2. **Finding Affected Bookings**: Uses overlap detection instead of exact time matching
3. **Re-grouping Cart Items**: Recalculates booking groups based on updated cart items
4. **Smart Updates**: Handles three scenarios:
   - **Simple case**: All cart items still on same court → Update times/price only
   - **Full move**: All cart items moved to new court → Update booking to new court
   - **Split case**: Cart items now span multiple courts → Split booking into multiple records

```php
protected function syncBookingAfterCourtChange(CartItem $cartItem)
{
    // Find bookings that OVERLAP with the cart item's time (not exact match)
    $affectedBookings = Booking::where('cart_transaction_id', $cartItem->cart_transaction_id)
        ->where('court_id', $oldCourtId)
        ->where(function ($query) use ($startDateTime, $endDateTime) {
            $query->where('start_time', '<', $endDateTime)
                  ->where('end_time', '>', $startDateTime);
        })
        ->get();

    // Re-group cart items and update/split bookings accordingly
    foreach ($affectedBookings as $booking) {
        $newGroups = $this->groupCartItemsByCourtAndTime($relevantCartItems, $bookingDate);
        // Update or split booking based on new groups
    }
}
```

### Changes Made

#### 1. CartItemObserver.php (New Logic)
**File**: `/app/Observers/CartItemObserver.php`

**Added**:
- Detection of `court_id` changes in the `updated()` method
- `syncBookingAfterCourtChange()` method to handle court updates
- `groupCartItemsByCourtAndTime()` helper to re-group cart items
- Proper handling of grouped bookings and booking splits

**Key Features**:
- Uses **overlap detection** instead of exact time matching
- Handles midnight-crossing bookings
- Properly splits bookings when needed
- Comprehensive logging for debugging

#### 2. CartController.php (Cleanup)
**File**: `/app/Http/Controllers/Api/CartController.php`

**Removed**: Lines 1417-1448 - Manual booking update code
- This code used exact time matching which didn't work for grouped bookings
- Now redundant since CartItemObserver handles it properly

**Added**: Comment explaining that observer handles the sync

## How It Works

### Example Scenario

**Initial State**:
- Booking #1: Court A, 9am-12pm (3 hours)
- Cart Items:
  - Item #1: Court A, 9-10am
  - Item #2: Court A, 10-11am
  - Item #3: Court A, 11-12pm

**Admin Changes Item #2 to Court B**:

1. Cart item #2 updated to Court B
2. Observer detects `court_id` change
3. Observer finds Booking #1 (overlaps with 10-11am)
4. Observer re-groups cart items:
   - Group 1: Court A, 9-10am (Item #1)
   - Group 2: Court B, 10-11am (Item #2)
   - Group 3: Court A, 11-12pm (Item #3)
5. Observer splits Booking #1:
   - Booking #1 updated: Court A, 9-10am
   - Booking #2 created: Court B, 10-11am
   - Booking #3 created: Court A, 11-12pm

**Result**: Bookings table now accurately reflects the cart items! ✅

## Testing

### Test Cases

1. **Single Cart Item Booking**
   - Change court of a booking with only one cart item
   - Expected: Booking court updated directly

2. **Multiple Consecutive Cart Items (Same Court)**
   - Change one cart item in the middle to a different court
   - Expected: Original booking split into 2-3 separate bookings

3. **Multiple Consecutive Cart Items (All Move)**
   - Change court of first item, others follow
   - Expected: Booking updated to new court with adjusted times

4. **Edge Cases**
   - Midnight-crossing bookings
   - Cancelled cart items
   - Bookings without cart_transaction_id

### Logs to Monitor
When a court change happens, you'll see logs like:
```
Cart item #123 court changed from 1 to 2
Processing booking #456 (was 2024-01-15 09:00:00 to 2024-01-15 12:00:00 on court 1)
Updated booking #456 to court 2
Completed court change sync for cart item #123
```

## Impact

### Before Fix
- ❌ Booking records showed incorrect court
- ❌ Availability checks could show wrong status
- ❌ Admin had to manually fix database inconsistencies
- ❌ Reports and analytics showed wrong data

### After Fix
- ✅ Booking records automatically stay in sync with cart items
- ✅ Availability checks are accurate
- ✅ No manual database fixes needed
- ✅ Reports and analytics are reliable
- ✅ Can handle complex scenarios (grouped bookings, splits)

## Related Files

- `/app/Observers/CartItemObserver.php` - Observer with sync logic
- `/app/Http/Controllers/Api/CartController.php` - Cart item update endpoint
- `/app/Models/Booking.php` - Booking model
- `/app/Models/CartItem.php` - Cart item model
- `/Front-End/src/components/BookingDetailsDialog.vue` - Frontend dialog for editing bookings

## Migration/Rollout

No database migrations required. The fix works with existing data structure.

### For Existing Inconsistent Data
If you have existing bookings with court inconsistencies, run the seeder:
```bash
php artisan db:seed --class=UpdateBookingCourtsFromCartItemsSeeder
```

This will scan all existing bookings and fix any court mismatches.

## Future Improvements

1. **Extend to handle time changes** - Currently only handles court_id changes
2. **Batch processing** - Optimize for multiple simultaneous cart item updates
3. **Event broadcasting** - Broadcast booking changes to connected clients
4. **Audit trail** - Log all booking modifications for compliance

## Notes

- The observer runs within the same database transaction as the cart item update
- If the observer fails, the entire transaction (including cart item update) is rolled back
- Logging can be enabled/disabled by commenting/uncommenting Log::info() statements
- The grouping logic mirrors the checkout process for consistency
