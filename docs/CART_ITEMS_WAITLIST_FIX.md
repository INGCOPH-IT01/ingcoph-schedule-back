# Cart Items Should Not Trigger Waitlist - Fix

## Issue

When users added items to cart (without checking out), the `availableSlots` API was marking slots as "Waitlist" even though no actual `Booking` record existed. This was incorrect behavior because:

1. **Cart items are temporary** - Users can abandon their cart at any time
2. **Cart items are not confirmed bookings** - Only after checkout are `Booking` records created
3. **Cart items should not block slots** - Slots should remain available until an actual booking is made

### Example Problem
```
1. User A adds time slot to cart → CartItem created (no checkout)
2. User B views available slots → Slot shows as "Waitlist" ❌ WRONG
3. User A never checks out, cart expires
4. Slot was unnecessarily marked as waitlist
```

## Root Cause

The `availableSlots()` method in `BookingController.php` was querying both:
- `Booking` records (correct)
- `CartItem` records (incorrect)

Lines 723-768 of the original code included complex logic to check `CartItem` records and their payment/approval status, even though these were not actual bookings yet.

## Solution

**Remove all `CartItem` checking logic from `availableSlots()`**

The method now ONLY checks for actual `Booking` records to determine slot availability and waitlist status.

## Booking Workflow

```
┌─────────────────────────────────────────────────────────────┐
│ 1. Add to Cart                                              │
│    ├─ CartItem + CartTransaction created                    │
│    ├─ payment_status: 'unpaid'                              │
│    └─ Slot Status: AVAILABLE ✅                             │
│       (No Booking record exists yet)                         │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│ 2. Checkout with Payment                                    │
│    ├─ User uploads proof of payment                         │
│    ├─ Booking record created from CartItem                  │
│    ├─ payment_status: 'paid'                                │
│    ├─ status: 'pending' (waiting for admin approval)        │
│    └─ Slot Status: WAITLIST ⏳                              │
│       (Booking record now exists)                            │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│ 3. Admin Approves                                           │
│    ├─ status: 'approved'                                    │
│    ├─ payment_status: 'paid'                                │
│    └─ Slot Status: BOOKED 🔴                                │
│       (Fully confirmed booking)                              │
└─────────────────────────────────────────────────────────────┘
```

## Changes Made

### File: `app/Http/Controllers/Api/BookingController.php`

#### 1. Removed CartItem Query (Lines 723-768)

**Before:**
```php
// Get all non-cancelled bookings
$bookings = Booking::with(['user', 'bookingForUser'])
    ->where('court_id', $courtId)
    ->whereIn('status', ['pending', 'approved', 'completed'])
    ->whereBetween('start_time', [$startOfDay, $endOfDay])
    ->orderBy('start_time')
    ->get();

// Get cart items with various statuses
$cartItems = \App\Models\CartItem::with([...])
    ->where('court_id', $courtId)
    ->where('booking_date', $date->format('Y-m-d'))
    ->where('status', '!=', 'cancelled')
    ->whereHas('cartTransaction', function($transQuery) {
        // Complex logic checking payment/approval status
        // Including unpaid items, paid pending items, etc.
    })
    ->orderBy('start_time')
    ->get();
```

**After:**
```php
// Get all non-cancelled bookings for this court on the specified date
// IMPORTANT: Only actual Booking records should affect slot availability
// Cart items (not yet checked out) should NOT block slots or trigger waitlist
$bookings = Booking::with(['user', 'bookingForUser'])
    ->where('court_id', $courtId)
    ->whereIn('status', ['pending', 'approved', 'completed', 'checked_in'])
    ->whereBetween('start_time', [$startOfDay, $endOfDay])
    ->orderBy('start_time')
    ->get();
```

#### 2. Removed CartItem Conflict Checking (Lines 804-812)

**Before:**
```php
// Check if this time slot conflicts with any existing booking
$conflictingBooking = $bookings->first(function ($booking) use (...) {
    // ... booking overlap logic
});

// Check if this time slot conflicts with any cart item
$conflictingCartItem = $cartItems->first(function ($cartItem) use (...) {
    // ... cart item overlap logic
});

if (!$conflictingBooking && !$conflictingCartItem) {
    // Show as available
}
```

**After:**
```php
// Check if this time slot conflicts with any existing booking
$conflictingBooking = $bookings->first(function ($booking) use (...) {
    // ... booking overlap logic
});

if (!$conflictingBooking) {
    // Show as available
}
```

#### 3. Simplified Conflict Handling (Lines 774-850)

**Before:**
```php
if ($conflictingCartItem) {
    // Complex cart item display logic (100+ lines)
} elseif ($conflictingBooking) {
    // Booking display logic
}
```

**After:**
```php
// Slot has a conflicting booking - show booking info
// Only Booking records are checked now
```

## Slot Display Logic

| Condition | Has Booking? | Status | Payment | Display Type | Waitlist? |
|-----------|-------------|--------|---------|--------------|-----------|
| Just added to cart | ❌ No | N/A | unpaid | **Available** | No |
| Checked out | ✅ Yes | pending | paid | **Waitlist** | Yes |
| Admin approved | ✅ Yes | approved | paid | **Booked** | No |

## API Response Changes

### Before Fix (WRONG)

User adds to cart without checkout:
```json
{
  "start": "07:00",
  "end": "08:00",
  "available": false,
  "is_booked": false,
  "is_waitlist_available": true,
  "type": "waitlist_available",
  "cart_item_id": 123
}
```
❌ **Problem:** Shows as waitlist even though no booking exists

### After Fix (CORRECT)

User adds to cart without checkout:
```json
{
  "start": "07:00",
  "end": "08:00",
  "available": true,
  "is_booked": false
}
```
✅ **Correct:** Shows as available since no Booking record exists

User checks out (creates Booking):
```json
{
  "start": "07:00",
  "end": "08:00",
  "available": false,
  "is_booked": false,
  "is_waitlist_available": true,
  "type": "waitlist_available",
  "booking_id": 456,
  "payment_status": "paid",
  "status": "pending"
}
```
✅ **Correct:** Shows as waitlist because Booking record exists

## Benefits

1. ✅ **Accurate Availability** - Only confirmed bookings affect slot status
2. ✅ **No False Waitlists** - Cart items don't trigger waitlist unnecessarily
3. ✅ **Fair System** - Users can't hold slots just by adding to cart
4. ✅ **Cleaner Code** - Removed 150+ lines of unnecessary cart item logic
5. ✅ **Performance** - One less database query (removed CartItem query)
6. ✅ **Clear Workflow** - Slots only become unavailable after checkout

## Testing

### Test Case 1: Add to Cart (No Checkout)

```bash
# 1. User adds item to cart (no checkout)
POST /api/cart
{
  "items": [{
    "court_id": 1,
    "booking_date": "2025-11-10",
    "start_time": "07:00",
    "end_time": "08:00"
  }]
}

# 2. Check available slots
GET /api/bookings/courts/1/available-slots?date=2025-11-10

# Expected Result:
{
  "start": "07:00",
  "end": "08:00",
  "available": true,    // ✅ Available
  "is_booked": false
}
```

### Test Case 2: Checkout (Creates Booking)

```bash
# 1. Checkout cart
POST /api/cart/checkout
{
  "payment_method": "gcash",
  "proof_of_payment": [file]
}

# Creates Booking record with:
# - status: 'pending'
# - payment_status: 'paid'

# 2. Check available slots
GET /api/bookings/courts/1/available-slots?date=2025-11-10

# Expected Result:
{
  "start": "07:00",
  "end": "08:00",
  "available": false,
  "is_booked": false,
  "is_waitlist_available": true,  // ✅ Waitlist
  "booking_id": 123,
  "type": "waitlist_available"
}
```

### Test Case 3: Admin Approves

```bash
# 1. Admin approves booking
POST /api/bookings/123/approve

# 2. Check available slots
GET /api/bookings/courts/1/available-slots?date=2025-11-10

# Expected Result:
{
  "start": "07:00",
  "end": "08:00",
  "available": false,
  "is_booked": true,               // ✅ Booked
  "is_waitlist_available": false,
  "booking_id": 123,
  "type": "booking"
}
```

## Important Notes

### When Booking Records Are Created

`Booking` records are created during checkout in `CartController::checkout()`:

```php
$booking = Booking::create([
    'user_id' => $userId,
    'cart_transaction_id' => $cartTransaction->id,
    'court_id' => $group['court_id'],
    'start_time' => $startDateTime,
    'end_time' => $endDateTime,
    'status' => 'pending',
    'payment_status' => $paymentStatus,
    'payment_method' => $paymentMethod,
    // ... more fields
]);
```

### Booking Status Flow

1. **Created**: `status: 'pending'`, `payment_status: 'paid'` → Shows as **Waitlist**
2. **Approved**: `status: 'approved'`, `payment_status: 'paid'` → Shows as **Booked**
3. **Checked In**: `status: 'checked_in'` → Still shows as **Booked**
4. **Completed**: `status: 'completed'` → Still considered in availability check

### Admin/Staff Bookings

Admin and staff bookings are always considered "booked" regardless of approval status:

```php
$isBookingAdminBooking = $bookingCreatedByUser &&
    in_array($bookingCreatedByUser->role, ['admin', 'staff']);

'is_booked' => ($isBookingApproved && $isBookingPaid) || $isBookingAdminBooking
```

## Migration Required

❌ **No database migration needed** - Only logic changes

## Breaking Changes

✅ **No breaking changes to API response structure**
- Response format remains the same
- Just fixed incorrect behavior

However, frontend/users will notice:
- Slots no longer show as "Waitlist" when items are just in cart
- More accurate availability information

## Related Files

1. ✅ `app/Http/Controllers/Api/BookingController.php` - Fixed availableSlots()
2. `app/Http/Controllers/Api/CartController.php` - Creates Booking records on checkout
3. `app/Models/Booking.php` - Booking model reference

## Related Documentation

- `PAYMENT_STATUS_FIX.md` - Payment status requirements (now obsolete for cart items)
- `WAITLIST_DISPLAY_FIX.md` - Previous waitlist display logic (now obsolete)
- `AVAILABLE_SLOTS_FIX.md` - Original availability logic

## Summary

**Problem:** Cart items (not checked out) were triggering "Waitlist" status

**Solution:** Only check `Booking` records in `availableSlots()`, ignore `CartItem` records

**Result:**
- ✅ Accurate slot availability
- ✅ Slots only unavailable when actual bookings exist
- ✅ Cart items no longer affect availability
- ✅ Cleaner, simpler code

**Key Principle:** Only `Booking` records should determine slot availability, not temporary cart items.

---

**Date:** November 8, 2025
**Fixed by:** System update based on user requirements
**Status:** ✅ Complete
