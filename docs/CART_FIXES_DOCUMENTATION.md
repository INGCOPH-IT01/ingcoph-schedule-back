# Cart System Fixes - Issue #11 and #12

## Overview
This document details the fixes applied to resolve Issues #11 (Waitlist Logic) and #12 (Midnight Crossing Time Calculation) in the cart booking system.

---

## Issue #11: Waitlist Logic Issues

### Problem
Previously, when a user attempted to add multiple items to cart and one of them triggered a waitlist condition, the system would:
1. Add only the first item to the waitlist
2. Return immediately with a 200 response
3. Ignore all remaining items in the request
4. Frontend would not properly handle mixed scenarios (some items added normally, some waitlisted)

This caused the error message "Failed to add items to cart" even though the waitlist operation was technically successful.

### Solution

#### Backend Changes (`app/Http/Controllers/Api/CartController.php`)

1. **Added tracking for waitlisted items:**
```php
// Track waitlisted items separately (Issue #11 fix)
$waitlistedItems = [];
$hasAnyWaitlist = false;
```

2. **Changed waitlist logic to continue processing:**
Instead of returning immediately when an item is waitlisted, we now:
- Add the item to the waitlist
- Track it in the `$waitlistedItems` array
- Continue processing remaining items with `continue` instead of `return`

3. **Improved response handling:**
The function now returns different responses based on the scenario:

**All items waitlisted:**
```json
{
  "message": "All time slots are currently pending approval. You have been added to the waitlist.",
  "waitlisted": true,
  "waitlist_entries": [...],
  "cart_items": [...],
  "total_waitlisted": 3
}
```

**Mixed (some added, some waitlisted):**
```json
{
  "message": "Successfully added 2 item(s) to cart. 1 item(s) added to waitlist.",
  "items": [...],
  "waitlisted_items": [...],
  "waitlist_entries": [...],
  "has_waitlist": true,
  "total_added": 2,
  "total_waitlisted": 1
}
```

**All items added successfully:**
```json
{
  "message": "Items added to cart successfully",
  "items": [...],
  "has_waitlist": false
}
```

#### Frontend Changes (`src/components/NewBookingDialog.vue`)

1. **Enhanced waitlist response handling:**
- Now properly displays information for multiple waitlisted items
- Shows summary view when more than 3 items are waitlisted
- Handles both single and multiple item scenarios

2. **Added mixed scenario handling:**
- New UI feedback when some items are added and others are waitlisted
- Clear breakdown of what was added vs what was waitlisted
- Proper event dispatching for cart updates

3. **Improved error path handling:**
- Same logic applied to error path to handle waitlist responses that come through error channel
- Consistent user experience regardless of response path

---

## Issue #12: Midnight Crossing Time Calculation Issues

### Problem
Time slots that cross midnight (e.g., 23:00 - 01:00) had inconsistent handling:

1. **Booking conflict detection was incomplete:**
   - Used `whereDate('start_time', $item['booking_date'])` which only checked bookings starting on the same date
   - Didn't check for conflicts with bookings from the previous day that might extend past midnight

2. **Cart item conflict detection:**
   - Used simple time comparisons without proper datetime concatenation
   - Didn't handle cases where existing cart items cross midnight

3. **Race condition potential:**
   - Midnight crossing slots could be double-booked if checks weren't comprehensive

### Solution

#### Backend Changes (`app/Http/Controllers/Api/CartController.php`)

1. **Added midnight crossing flag:**
```php
$startTime = \Carbon\Carbon::parse($item['start_time']);
$endTime = \Carbon\Carbon::parse($item['end_time']);
$crossesMidnight = $endTime->lte($startTime);
```

2. **Enhanced booking conflict detection:**
Now checks two scenarios:
- Bookings on the same date as requested slot
- If midnight crossing: Also checks bookings from previous day that might conflict

```php
$conflictingBooking = Booking::where('court_id', $item['court_id'])
    ->whereIn('status', ['pending', 'approved', 'completed', 'checked_in'])
    ->where(function ($query) use ($startDateTime, $endDateTime, $item, $crossesMidnight) {
        // Check bookings that start on the same day
        $query->where(function ($q) use ($startDateTime, $endDateTime, $item) {
            $q->whereDate('start_time', $item['booking_date'])
              ->where(function ($sq) use ($startDateTime, $endDateTime) {
                  // Comprehensive time overlap checks
              });
        });

        // If the new booking crosses midnight, also check previous day bookings
        if ($crossesMidnight) {
            $prevDate = \Carbon\Carbon::parse($item['booking_date'])->subDay()->format('Y-m-d');
            $query->orWhere(function ($q) use ($startDateTime, $endDateTime, $prevDate) {
                $q->whereDate('start_time', $prevDate)
                  ->where(function ($sq) use ($startDateTime, $endDateTime) {
                      // Same overlap checks for previous day
                  });
            });
        }
    })
    ->first();
```

3. **Enhanced cart item conflict detection:**
Uses proper datetime concatenation and checks multiple dates:

```php
$conflictingCartItems = CartItem::where('court_id', $item['court_id'])
    ->where('status', 'pending')
    ->where(function ($query) use ($item, $startDateTime, $endDateTime, $crossesMidnight) {
        // Check cart items on the same date
        $query->where(function ($q) use ($item, $startDateTime, $endDateTime) {
            $q->where('booking_date', $item['booking_date'])
              ->where(function ($sq) use ($startDateTime, $endDateTime, $item) {
                  // Use full datetime comparison with CONCAT
                  $sq->whereRaw("CONCAT(booking_date, ' ', start_time) >= ? AND CONCAT(booking_date, ' ', start_time) < ?",
                      [$startDateTime, $endDateTime])
                     // Additional overlap checks...
              });
        });

        // If midnight crossing, also check previous day cart items
        if ($crossesMidnight) {
            $prevDate = \Carbon\Carbon::parse($item['booking_date'])->subDay()->format('Y-m-d');
            // Same checks for previous day
        }
    })
    ->with('cartTransaction.user')
    ->get();
```

4. **Consistent midnight handling throughout:**
The same `$crossesMidnight` logic is applied to:
- Initial datetime calculation
- Booking conflict detection
- Cart item conflict detection
- Waitlist time tracking

---

## Benefits

### Issue #11 Fix Benefits:
1. ✅ All items in a batch request are now processed
2. ✅ Users get clear feedback about what was added vs waitlisted
3. ✅ No more "Failed to add items to cart" when waitlist is actually successful
4. ✅ Better UX with detailed breakdown of booking status
5. ✅ Proper cart count updates for all scenarios

### Issue #12 Fix Benefits:
1. ✅ Prevents double-booking of midnight crossing slots
2. ✅ Accurate conflict detection across date boundaries
3. ✅ Consistent time calculation throughout the system
4. ✅ Eliminates race conditions for late-night bookings
5. ✅ More reliable availability checking

---

## Testing Recommendations

### Test Case 1: Multiple Items with Waitlist (Issue #11)
1. Book time slot A (Court 1, 10:00-11:00) as User A
2. As User B, try to book multiple slots:
   - Court 1, 10:00-11:00 (should be waitlisted)
   - Court 2, 10:00-11:00 (should be added to cart)
   - Court 1, 14:00-15:00 (should be added to cart)
3. Verify User B gets mixed response showing 2 added, 1 waitlisted
4. Verify all items appear in cart

### Test Case 2: Midnight Crossing Conflict Detection (Issue #12)
1. Book slot 23:00-01:00 on Court 1 for Date A
2. Try to book:
   - Same slot (23:00-01:00) on Date A → Should conflict
   - Overlapping slot (00:00-02:00) on Date A+1 → Should conflict
   - Overlapping slot (22:00-23:30) on Date A → Should conflict
3. Verify all conflicts are properly detected

### Test Case 3: Midnight Crossing with Waitlist
1. User A books 23:00-01:00 (pending approval)
2. User B tries to book same slot
3. Verify User B is added to waitlist
4. Verify waitlist shows correct times including midnight crossing

---

## Migration Notes

### No Database Changes Required
These fixes are code-only changes and don't require database migrations.

### Backward Compatibility
- Old frontend code will still work but won't show enhanced messaging
- API responses maintain backward compatibility with `waitlisted` flag
- New fields (`has_waitlist`, `total_waitlisted`, etc.) are additive

### Deployment Steps
1. Deploy backend changes first
2. Test API endpoints with existing frontend
3. Deploy frontend changes
4. Monitor logs for any issues

---

## Code Comments
All changes are marked with either:
- `// FIX #11:` for waitlist logic improvements
- `// FIX #12:` for midnight crossing improvements
- `// Issue #11 fix` or `// Issue #12 fix` for inline comments

This makes it easy to identify and review the fixes in the codebase.

---

## Related Files Modified

### Backend
- `app/Http/Controllers/Api/CartController.php` (Lines 154-507)

### Frontend
- `src/components/NewBookingDialog.vue` (Lines 1509-1716)

---

## Author
Created: 2025-10-25
Issues Addressed: #11, #12
