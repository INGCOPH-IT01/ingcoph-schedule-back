# Time Overlap Checking Logic - Comprehensive Analysis

## Date: November 21, 2025

## Overview

This document explains how time overlap checking works across the booking system to prevent double bookings and ensure slot availability accuracy.

## Key Principle

**Only check actual `Booking` records for conflicts**, not `CartItem` records (unless they have associated active bookings).

## Two Different Contexts

### 1. Display Availability (`BookingController::availableSlots`)

**Purpose:** Show users which slots are available

**Logic:** Check only `Booking` table
```php
// Get all active bookings
$bookings = Booking::with(['user', 'bookingForUser'])
    ->where('court_id', $courtId)
    ->whereIn('status', ['pending', 'approved', 'completed', 'checked_in'])
    ->whereBetween('start_time', [$startOfDay, $endOfDay])
    ->get();

// Check overlap with each slot
$conflictingBooking = $bookings->first(function ($booking) use ($currentTime, $slotEnd) {
    $bookingStart = Carbon::createFromFormat('Y-m-d H:i:s', $booking->start_time);
    $bookingEnd = Carbon::createFromFormat('Y-m-d H:i:s', $booking->end_time);

    // Overlap condition
    return $currentTime->lt($bookingEnd) && $slotEnd->gt($bookingStart);
});
```

**Why no CartItem check?**
- `Booking` records are created during checkout
- The initial query includes ALL bookings (from cart or direct)
- No need to separately check cart items

### 2. Checkout Validation (`CartController::checkout`)

**Purpose:** Prevent double bookings during checkout race conditions

**Logic:** Check `Booking` table + `CartItem` table (with active bookings)

```php
// Check for direct booking conflicts
$isBooked = Booking::where('court_id', $group['court_id'])
    ->whereIn('status', ['pending', 'approved', 'completed', 'checked_in'])
    ->where(function ($query) use ($startDateTime, $endDateTime) {
        // Time overlap logic
    })
    ->exists();

// ALSO check for cart items with active bookings (race condition)
if (!$isBooked) {
    $conflictingCartItems = CartItem::where('court_id', $group['court_id'])
        ->where('cart_transaction_id', '!=', $cartTransaction->id)
        ->where('status', 'pending')
        ->whereHas('cartTransaction', function($query) {
            // Only check cart items whose transaction has active bookings
            $query->whereHas('bookings', function($bookingQuery) {
                $bookingQuery->whereIn('status', ['pending', 'approved', 'completed', 'checked_in']);
            });
        })
        ->where(function ($query) use ($startDateTime, $endDateTime) {
            // Time overlap logic
        })
        ->exists();
}
```

**Why check CartItems here?**
- Race condition: Another user might be checking out the same slot simultaneously
- Their booking might not exist yet in the DB when our check runs
- Checking cart items with bookings catches these edge cases

## Time Overlap Logic Explained

### The Overlap Condition

Two time ranges overlap if:
```php
$currentTime->lt($bookingEnd) && $slotEnd->gt($bookingStart)
```

**Translation:** Slot starts before booking ends AND slot ends after booking starts

### Visual Representation

```
Scenario 1: Overlap (slot starts during booking)
Booking:   |-----------|
Slot:            |-----------|
Result: $currentTime(slot) < $bookingEnd ✅ && $slotEnd > $bookingStart ✅ = CONFLICT

Scenario 2: Overlap (slot ends during booking)
Booking:         |-----------|
Slot:      |-----------|
Result: $currentTime < $bookingEnd ✅ && $slotEnd > $bookingStart ✅ = CONFLICT

Scenario 3: Overlap (booking contained in slot)
Booking:      |-----|
Slot:      |-----------|
Result: $currentTime < $bookingEnd ✅ && $slotEnd > $bookingStart ✅ = CONFLICT

Scenario 4: Overlap (slot contained in booking)
Booking:   |-----------|
Slot:        |-----|
Result: $currentTime < $bookingEnd ✅ && $slotEnd > $bookingStart ✅ = CONFLICT

Scenario 5: No overlap (slot after booking)
Booking:   |-----|
Slot:              |-----|
Result: $currentTime < $bookingEnd ❌ = NO CONFLICT

Scenario 6: No overlap (slot before booking)
Booking:           |-----|
Slot:      |-----|
Result: $slotEnd > $bookingStart ❌ = NO CONFLICT
```

### Alternative Overlap Logic (Used in checkout)

More explicit boundary checks:
```php
->where(function ($query) use ($startDateTime, $endDateTime) {
    $query->where(function ($q) use ($startDateTime, $endDateTime) {
        // Booking starts during slot (exclusive boundaries)
        $q->where('start_time', '>=', $startDateTime)
          ->where('start_time', '<', $endDateTime);
    })->orWhere(function ($q) use ($startDateTime, $endDateTime) {
        // Booking ends during slot (exclusive boundaries)
        $q->where('end_time', '>', $startDateTime)
          ->where('end_time', '<=', $endDateTime);
    })->orWhere(function ($q) use ($startDateTime, $endDateTime) {
        // Booking completely contains slot
        $q->where('start_time', '<=', $startDateTime)
          ->where('end_time', '>=', $endDateTime);
    });
})
```

**Both approaches are equivalent** and catch all overlap scenarios.

## CartItem Overlap Logic

When checking cart items, we must handle the fact that cart items store date and time separately:

```php
->where(function ($query) use ($startDateTime, $endDateTime) {
    // Construct full datetime using CONCAT
    $query->whereRaw("CONCAT(DATE(booking_date), ' ', start_time) >= ? AND CONCAT(DATE(booking_date), ' ', start_time) < ?",
        [$startDateTime, $endDateTime])
      ->orWhereRaw("CONCAT(DATE(booking_date), ' ', end_time) > ? AND CONCAT(DATE(booking_date), ' ', end_time) <= ?",
        [$startDateTime, $endDateTime])
      ->orWhereRaw("CONCAT(DATE(booking_date), ' ', start_time) <= ? AND CONCAT(DATE(booking_date), ' ', end_time) >= ?",
        [$startDateTime, $endDateTime]);
})
```

**Why CONCAT?**
- `CartItem` has separate `booking_date` (DATE) and `start_time`/`end_time` (TIME) columns
- `Booking` has combined `start_time`/`end_time` (DATETIME) columns
- CONCAT combines them for proper comparison

## Midnight Crossing Handling

When bookings cross midnight (e.g., 23:00 - 01:00):

```php
$startTime = Carbon::parse($group['start_time']);
$endTime = Carbon::parse($group['end_time']);

if ($endTime->lte($startTime)) {
    // Slot crosses midnight
    $endDate = Carbon::parse($group['booking_date'])->addDay()->format('Y-m-d');
    $endDateTime = $endDate . ' ' . $group['end_time'];
} else {
    $endDateTime = $group['booking_date'] . ' ' . $group['end_time'];
}
```

**Example:**
- Booking date: 2025-11-22
- Start time: 23:00
- End time: 01:00
- Result: `start_time = 2025-11-22 23:00:00`, `end_time = 2025-11-23 01:00:00`

## Summary of Changes

### Before Fix ❌

```php
// BookingController::availableSlots
- Checked both Booking AND CartItem tables
- Cart items without bookings blocked slots
- Redundant checks
- Slower performance (extra queries)
```

### After Fix ✅

```php
// BookingController::availableSlots
- Checks ONLY Booking table
- Simpler logic
- Better performance
- Accurate results

// CartController::checkout
- Checks Booking table first
- Then checks CartItem table (with active bookings only)
- Prevents race conditions
- Proper validation
```

## Edge Cases Handled

### ✅ Case 1: Adjacent Time Slots (No Overlap)

```
Booking: 08:00 - 09:00
Slot:    09:00 - 10:00

Check: 09:00 < 09:00 ❌ = NO CONFLICT
```

### ✅ Case 2: Same Start Time (Overlap)

```
Booking: 08:00 - 09:00
Slot:    08:00 - 09:00

Check: 08:00 < 09:00 ✅ && 09:00 > 08:00 ✅ = CONFLICT
```

### ✅ Case 3: Partial Overlap (1 minute)

```
Booking: 08:00 - 09:00
Slot:    08:59 - 09:59

Check: 08:59 < 09:00 ✅ && 09:59 > 08:00 ✅ = CONFLICT
```

### ✅ Case 4: Crossing Midnight

```
Booking: 2025-11-22 23:00 - 2025-11-23 01:00
Slot:    2025-11-23 00:00 - 2025-11-23 01:00

Check: 2025-11-23 00:00 < 2025-11-23 01:00 ✅ &&
       2025-11-23 01:00 > 2025-11-22 23:00 ✅ = CONFLICT
```

### ✅ Case 5: Multiple Bookings, Some Cancelled

```
Booking 1: status='approved' → Checked ✅
Booking 2: status='cancelled' → Excluded ✅
Booking 3: status='pending' → Checked ✅

Result: Only active bookings affect availability
```

## Performance Considerations

### BookingController::availableSlots

**Before:**
- 1 query for bookings
- 1 query for cart items (per slot check)
- Total: 1 + N queries (N = number of slots)

**After:**
- 1 query for bookings
- Total: 1 query only

**Improvement:** ~90% reduction in queries

### CartController::checkout

**Before:**
- 1 query for bookings
- 1 query for cart items (all statuses)

**After:**
- 1 query for bookings
- 1 query for cart items (only with active bookings)

**Improvement:** Smaller result set, faster query

## Testing Scenarios

### Test 1: Simple Overlap

```bash
# Create booking 08:00-09:00
POST /api/bookings
{
  "court_id": 1,
  "start_time": "2025-11-22 08:00:00",
  "end_time": "2025-11-22 09:00:00"
}

# Try to book overlapping slot 08:30-09:30
POST /api/cart/checkout
{
  "items": [{
    "start_time": "08:30",
    "end_time": "09:30"
  }]
}

# Expected: Error - slot conflict
```

### Test 2: Adjacent Slots (No Overlap)

```bash
# Create booking 08:00-09:00
# Try to book 09:00-10:00

# Expected: Success - no conflict
```

### Test 3: Cancelled Booking Frees Slot

```bash
# Create booking 08:00-09:00
# Cancel booking
# Try to book 08:00-09:00 again

# Expected: Success - slot available
```

### Test 4: Race Condition

```bash
# User A adds 08:00-09:00 to cart
# User B adds 08:00-09:00 to cart
# User A checks out (creates booking)
# User B tries to checkout

# Expected: User B gets error - slot taken
```

## Key Takeaways

1. **Only Active Bookings Matter**
   - `whereIn('status', ['pending', 'approved', 'completed', 'checked_in'])`
   - Cancelled/rejected bookings are excluded

2. **Simple Overlap Formula**
   - `$start1 < $end2 && $end1 > $start2`
   - Works for all overlap scenarios

3. **Context Matters**
   - Display: Check only Bookings
   - Validation: Check Bookings + CartItems (with active bookings)

4. **Handle Midnight Crossing**
   - Adjust end date when `end_time <= start_time`

5. **Performance Optimization**
   - Avoid redundant cart item checks
   - Use single booking query when possible

---

**Status:** ✅ OPTIMIZED
**Date:** November 21, 2025
**Impact:** Improved accuracy and performance
