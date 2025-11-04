# Court Change Booking Sync - Fix Summary

## Problem
**Symptom**: When changing a cart item's court, the booking record was not updated, causing inconsistencies.

**Example**:
```
Before: Cart Item (Court B) → Booking (Court A) ❌
After:  Cart Item (Court B) → Booking (Court B) ✅
```

## Root Cause
During checkout, multiple cart items are **grouped** into single booking records:
- 3 cart items (9-10am, 10-11am, 11-12pm on Court A)
- Creates 1 booking (9am-12pm on Court A)

When changing one cart item's court, the old code looked for a booking with **exact time match** (10-11am) but the actual booking spanned 9am-12pm. **No match = no update.**

## Solution
Enhanced `CartItemObserver` to:
1. ✅ Watch for court_id changes on cart items
2. ✅ Find bookings using **overlap detection** (not exact match)
3. ✅ Re-group cart items based on new courts
4. ✅ Update or split bookings as needed

## Files Changed

### `/app/Observers/CartItemObserver.php`
- Added `syncBookingAfterCourtChange()` method
- Added `groupCartItemsByCourtAndTime()` helper
- Handles complex scenarios: single updates, splits, consolidations

### `/app/Http/Controllers/Api/CartController.php`
- Removed redundant manual booking update code (lines 1417-1448)
- Added comment explaining observer handles the sync

## How It Works Now

### Simple Case: Single Cart Item
```
Cart Item #1 (Court A → Court B)
→ Booking #1 updated to Court B
```

### Complex Case: Grouped Booking Split
```
Initial:
- Booking #1: Court A, 9am-12pm
- Items: [9-10am Court A, 10-11am Court A, 11-12pm Court A]

After changing middle item to Court B:
- Booking #1: Court A, 9-10am (updated)
- Booking #2: Court B, 10-11am (NEW)
- Booking #3: Court A, 11-12pm (NEW)
```

## Testing
See `/docs/TEST_COURT_CHANGE.md` for detailed test procedures.

**Quick Verification**:
1. Create a multi-slot booking
2. Change one cart item's court
3. Check `bookings` table - should reflect the change
4. Check logs for sync messages

## Impact
- ✅ Bookings always match cart items
- ✅ No manual database fixes needed
- ✅ Accurate availability checks
- ✅ Reliable reports and analytics

## Rollout
No database migrations needed. Works with existing schema.

To fix existing inconsistencies:
```bash
php artisan db:seed --class=UpdateBookingCourtsFromCartItemsSeeder
```
