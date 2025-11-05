# Multiple Time Slots Booking Fix

## Issue Description

When a user booked multiple consecutive time slots in a single request, only the first time slot was saved as a regular booking, while subsequent time slots were incorrectly placed in the waitlist. This created a split booking where parts of the same booking session were treated differently.

### Example of the Bug:
1. User selects 3 consecutive time slots: 10:00-11:00, 11:00-12:00, 12:00-13:00
2. All slots are available with no conflicts
3. Expected: All 3 slots added to cart as a single booking
4. **Bug**: Only slot 1 added to cart normally, slots 2 and 3 placed in waitlist

## Root Cause

The issue was in the conflict checking logic in `CartController@store()` method (line 254-291). When processing multiple time slots in a single request:

1. A new cart transaction is created for the entire booking
2. Each time slot is processed individually in a loop
3. When checking the second slot for conflicts with existing cart items, the code didn't exclude items from the **current transaction being built**
4. This caused slot 2 to potentially "conflict" with slot 1 (which was just added in the same request)
5. Any perceived conflict triggered the waitlist logic, incorrectly splitting the booking

### Code Flow:
```
Request: [Slot 1: 10-11, Slot 2: 11-12, Slot 3: 12-13]
  ↓
Create new CartTransaction #123
  ↓
Process Slot 1 (10-11):
  - Check conflicts → None found
  - Add to CartTransaction #123 ✓
  ↓
Process Slot 2 (11-12):
  - Check conflicts in OTHER cart items
  - BUG: Didn't exclude CartTransaction #123
  - Found Slot 1 as "conflicting cart item"
  - Triggered waitlist logic ✗
  ↓
Process Slot 3 (12-13):
  - Same issue ✗
```

## Solution

Added a filter to exclude cart items from the current transaction when checking for conflicts:

### File: `app/Http/Controllers/Api/CartController.php`

**Line 258 - Added filter:**
```php
->where('cart_transaction_id', '!=', $cartTransaction->id) // Exclude items from current transaction
```

This ensures that when checking for conflicting cart items, the system only looks at items from OTHER transactions, not the items being added in the same request.

### Fixed Code Flow:
```
Request: [Slot 1: 10-11, Slot 2: 11-12, Slot 3: 12-13]
  ↓
Create new CartTransaction #123
  ↓
Process Slot 1 (10-11):
  - Check conflicts (excluding CartTransaction #123) → None
  - Add to CartTransaction #123 ✓
  ↓
Process Slot 2 (11-12):
  - Check conflicts (excluding CartTransaction #123) → None
  - Add to CartTransaction #123 ✓
  ↓
Process Slot 3 (12-13):
  - Check conflicts (excluding CartTransaction #123) → None
  - Add to CartTransaction #123 ✓
  ↓
All slots in one booking! ✓
```

## Impact

### Before Fix:
- ✗ Multiple time slots split between booking and waitlist
- ✗ Users confused about partial waitlisting
- ✗ Only first slot in actual booking
- ✗ Remaining slots incorrectly waitlisted

### After Fix:
- ✓ All time slots in single request treated as one booking
- ✓ Either all slots added to cart OR all waitlisted (if there's a real conflict)
- ✓ Consistent booking behavior
- ✓ No more incorrect waitlisting of user's own time slots

## Testing Recommendations

1. **Test consecutive slots on same court:**
   - Book 3 consecutive slots (e.g., 10-11, 11-12, 12-13)
   - Verify all 3 appear in cart as one booking
   - Verify all 3 create separate booking records but same cart_transaction_id

2. **Test non-consecutive slots on same court:**
   - Book 2 non-consecutive slots (e.g., 10-11, 14-15)
   - Verify both are added to cart normally

3. **Test multiple courts:**
   - Book slots on different courts simultaneously
   - Verify all are added to cart

4. **Test legitimate waitlist scenario:**
   - Have User A book slot 10-11 (pending)
   - Have User B book the same slot 10-11
   - Verify User B is correctly waitlisted
   - Verify if User B also books 11-12, it's also waitlisted (not split)

## Files Changed

- `app/Http/Controllers/Api/CartController.php` (Line 258)
  - Added filter to exclude current transaction from conflict checking

## Related Issues

This fix ensures that the waitlist feature works as intended - only triggering when there's a legitimate conflict with another user's booking, not with the user's own time slots being added in the same request.
