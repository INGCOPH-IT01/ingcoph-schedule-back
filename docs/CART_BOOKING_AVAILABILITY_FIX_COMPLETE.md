# Cart Items + Booking Availability Check - Complete Fix

## Date: November 21, 2025

## Final Implementation

Cart items are now checked for slot availability conflicts **ONLY IF** their associated `CartTransaction` has **active** `Booking` records (excluding cancelled/rejected bookings).

## The Complete Logic

### PHP Implementation

```php
->whereHas('cartTransaction', function($query) {
    // Only check cart items whose transaction has active bookings
    $query->whereHas('bookings', function($bookingQuery) {
        // Only consider active bookings (not cancelled/rejected)
        $bookingQuery->whereIn('status', ['pending', 'approved', 'completed', 'checked_in']);
    });
})
```

### What This Does

1. ✅ Checks if `CartTransaction` has `Booking` records
2. ✅ Filters bookings to only include **active** statuses
3. ✅ Excludes `cancelled` and `rejected` bookings
4. ✅ Returns `false` if no active bookings exist

## Workflow Scenarios

### ✅ Scenario 1: Add to Cart (No Checkout)

```
User adds item to cart:
├─ CartItem created
├─ CartTransaction created
├─ Booking exists? NO
└─ Result: Slot shows AVAILABLE

Why? whereHas('bookings') returns false
```

### ✅ Scenario 2: Checkout (Creates Booking)

```
User checks out:
├─ Booking created (status: 'pending')
├─ CartTransaction.bookings includes active booking
└─ Result: Slot shows WAITLIST

Why? whereHas('bookings', [...'pending'...]) returns true
```

### ✅ Scenario 3: Admin Approves

```
Admin approves:
├─ Booking status: 'approved'
└─ Result: Slot shows BOOKED

Why? whereHas('bookings', [...'approved'...]) returns true
```

### ✅ Scenario 4: Admin Cancels/Rejects ⭐ NEW

```
Admin cancels or rejects:
├─ Booking status: 'cancelled' or 'rejected'
└─ Result: Slot shows AVAILABLE again

Why? whereHas('bookings', function($q) {
    $q->whereIn('status', ['pending', 'approved', ...])
    // 'cancelled' and 'rejected' NOT in this list
}) returns false
```

## Files Modified

### 1. BookingController.php

**Method: `availableSlots()`** (Line ~777-784)

```php
->whereHas('cartTransaction', function($query) {
    // Only check cart items whose transaction has active bookings (not cancelled/rejected)
    $query->whereHas('bookings', function($bookingQuery) {
        $bookingQuery->whereIn('status', ['pending', 'approved', 'completed', 'checked_in']);
    });
})
```

### 2. CartController.php

**Method: `store()`** - Add to Cart (Line ~261-267)
```php
->whereHas('cartTransaction', function($query) {
    $query->whereHas('bookings', function($bookingQuery) {
        $bookingQuery->whereIn('status', ['pending', 'approved', 'completed', 'checked_in']);
    });
})
```

**Method: `validateCartAvailability()`** (Line ~767-773)
```php
->whereHas('cartTransaction', function($query) {
    $query->whereHas('bookings', function($bookingQuery) {
        $bookingQuery->whereIn('status', ['pending', 'approved', 'completed', 'checked_in']);
    });
})
```

**Method: `checkout()`** - Final availability check (Line ~1207-1213)
```php
->whereHas('cartTransaction', function($query) {
    $query->whereHas('bookings', function($bookingQuery) {
        $bookingQuery->whereIn('status', ['pending', 'approved', 'completed', 'checked_in']);
    });
})
```

**Method: `updateCartItem()`** (Line ~1518-1524)
```php
->whereHas('cartTransaction', function($query) {
    $query->whereHas('bookings', function($bookingQuery) {
        $bookingQuery->whereIn('status', ['pending', 'approved', 'completed', 'checked_in']);
    });
})
```

**Method: `updateCartItemTime()`** (Line ~1661-1667)
```php
->whereHas('cartTransaction', function($query) {
    $query->whereHas('bookings', function($bookingQuery) {
        $bookingQuery->whereIn('status', ['pending', 'approved', 'completed', 'checked_in']);
    });
})
```

## Benefits

### 1. Accurate Availability
- Only confirmed, active bookings block slots
- Abandoned carts don't affect availability
- Cancelled/rejected bookings free up slots immediately

### 2. Proper Lifecycle Management

```
Cart Item Lifecycle:
├─ Add to cart → Available
├─ Checkout → Unavailable (pending)
├─ Admin approves → Booked
└─ Admin cancels/rejects → Available again ⭐
```

### 3. Fair System
- Users can't hold slots by just adding to cart
- Cancelled bookings return to availability pool
- Rejected bookings don't permanently block slots

### 4. Database Efficiency
- Single nested whereHas query
- Filters at database level
- No extra queries needed

## Testing Scenarios

### Test 1: Cart Without Checkout
```bash
POST /api/cart
# Add item to cart

GET /api/bookings/courts/1/available-slots?date=2025-11-22
# Expected: available=true (no booking exists)
```

### Test 2: After Checkout
```bash
POST /api/cart/checkout
# Creates booking with status='pending'

GET /api/bookings/courts/1/available-slots?date=2025-11-22
# Expected: available=false, is_waitlist_available=true
```

### Test 3: After Cancellation ⭐
```bash
POST /api/bookings/{id}/cancel
# Changes booking status to 'cancelled'

GET /api/bookings/courts/1/available-slots?date=2025-11-22
# Expected: available=true (slot freed up)
```

### Test 4: After Rejection ⭐
```bash
POST /api/bookings/{id}/reject
# Changes booking status to 'rejected'

GET /api/bookings/courts/1/available-slots?date=2025-11-22
# Expected: available=true (slot freed up)
```

## Database Query Breakdown

```sql
-- Check if cart item has active bookings
SELECT ci.id, ci.cart_transaction_id,
       COUNT(b.id) as active_booking_count
FROM cart_items ci
LEFT JOIN cart_transactions ct ON ct.id = ci.cart_transaction_id
LEFT JOIN bookings b ON b.cart_transaction_id = ct.id
    AND b.status IN ('pending', 'approved', 'completed', 'checked_in')
WHERE ci.court_id = 1
  AND ci.booking_date = '2025-11-22'
GROUP BY ci.id;

-- Results:
-- active_booking_count = 0: Cart item won't block slots ✅
-- active_booking_count > 0: Cart item will block slots ✅
```

## Edge Cases Handled

### ✅ Case 1: Multiple Bookings, Some Cancelled
```
CartTransaction has:
├─ Booking 1: status='approved' ✅
├─ Booking 2: status='cancelled' ❌
└─ Result: whereHas returns true (at least 1 active)
```

### ✅ Case 2: All Bookings Cancelled
```
CartTransaction has:
├─ Booking 1: status='cancelled' ❌
├─ Booking 2: status='rejected' ❌
└─ Result: whereHas returns false (no active bookings)
    Slot becomes AVAILABLE ✅
```

### ✅ Case 3: Booking Status Changes
```
Initial: status='pending' → Slot unavailable
User cancels: status='cancelled' → Slot available
Admin re-activates: status='pending' → Slot unavailable again
```

## Key Principles

1. **Only Active Bookings Matter**
   - `['pending', 'approved', 'completed', 'checked_in']` are considered active
   - `['cancelled', 'rejected']` are excluded

2. **Cart Items Are Proxies**
   - Cart items themselves don't block slots
   - Only their associated active bookings do

3. **Dynamic Availability**
   - Slots become available when bookings are cancelled/rejected
   - No manual intervention needed
   - Real-time accuracy

## Comparison: Before vs After

### Before This Fix ❌

```php
// Cart items checked regardless of booking status
->whereHas('cartTransaction', function($query) {
    $query->where('status', 'pending')
          ->whereIn('approval_status', ['pending', 'approved']);
})

Problems:
- Cart items without bookings blocked slots
- Cancelled bookings still blocked slots
- Rejected bookings still blocked slots
```

### After This Fix ✅

```php
// Cart items checked ONLY if active bookings exist
->whereHas('cartTransaction', function($query) {
    $query->whereHas('bookings', function($bookingQuery) {
        $bookingQuery->whereIn('status', ['pending', 'approved', 'completed', 'checked_in']);
    });
})

Benefits:
- Only cart items with active bookings block slots
- Cancelled bookings free up slots immediately
- Rejected bookings free up slots immediately
```

## Summary

**Before:** Cart items blocked slots based on their transaction status, regardless of whether bookings existed or their status.

**After:** Cart items block slots **ONLY IF** their transaction has **active** bookings (excluding cancelled/rejected).

**Result:**
- ✅ Accurate availability
- ✅ Proper lifecycle management
- ✅ Cancelled/rejected bookings free slots
- ✅ Fair system for all users

---

**Status:** ✅ FULLY IMPLEMENTED
**Date:** November 21, 2025
**Verified:** All 6 methods updated across 2 controllers
