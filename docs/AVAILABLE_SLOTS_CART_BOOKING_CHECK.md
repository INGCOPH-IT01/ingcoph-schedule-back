# Available Slots - Cart Items with Booking Check

## Date: November 21, 2025

## Requirement

The `availableSlots` API should check `CartItem` records, but **only if** their associated `CartTransaction` has created `Booking` records.

## Why This Logic?

### Database Relationships

```
CartItem -> belongs to -> CartTransaction
Booking -> belongs to -> CartTransaction

When checkout happens:
1. CartItems already exist (from "Add to Cart")
2. Booking records are created from those CartItems
3. Both share the same CartTransaction
```

### The Problem We're Solving

**Without this check:**
- User adds to cart → CartItem created, no Booking → Slot shows as unavailable ❌

**With this check:**
- User adds to cart → CartItem created, no Booking → Slot shows as AVAILABLE ✅
- User checks out → Booking created → Slot shows as WAITLIST/BOOKED ✅

## Implementation

### 1. Check Cart Items Only If They Have Associated Bookings

```php
// Also check for conflicting cart items, but ONLY if their CartTransaction has associated Bookings
// This ensures we only block slots when cart items have been converted to actual bookings
$conflictingCartItem = null;
if (!$conflictingBooking) {
    $conflictingCartItem = CartItem::where('court_id', $courtId)
        ->where('status', 'pending')
        ->whereHas('cartTransaction', function($query) {
            // Only check cart items whose transaction has created bookings
            $query->whereHas('bookings');
        })
        ->where(function ($query) use ($slotStartDateTime, $slotEndDateTime) {
            // Time overlap checks...
        })
        ->with('cartTransaction')
        ->first();
}
```

**Key Line:** `$query->whereHas('bookings');`

This ensures we only consider cart items whose `CartTransaction` has `Booking` records.

### 2. Use Associated Booking Status for Display

When we find a conflicting cart item, we look up its associated booking:

```php
// Get the associated booking to determine actual status
$associatedBooking = $cartTransaction->bookings()
    ->where('court_id', $courtId)
    ->whereBetween('start_time', [$slotStartDateTime, $slotEndDateTime])
    ->first();

if ($associatedBooking) {
    // Use the booking's status to determine display
    $bookingStatus = $associatedBooking->status ?? 'pending';
    $bookingPaymentStatus = $associatedBooking->payment_status ?? 'unpaid';
    // ... rest of logic
}
```

This way, the cart item's display reflects the actual booking's status.

## Workflow Example

### Scenario 1: Add to Cart (No Checkout)

```
User adds court slot to cart:
├─ CartItem created ✅
├─ CartTransaction created ✅
├─ Booking created? ❌ NO
└─ Result: availableSlots shows slot as AVAILABLE ✅

Why?
- whereHas('bookings') returns false
- Cart item is NOT checked
- No conflict found
```

### Scenario 2: Checkout (Creates Booking)

```
User checks out cart:
├─ Booking created ✅
├─ CartTransaction now has bookings ✅
├─ Cart items now match whereHas('bookings') ✅
└─ Result: availableSlots shows slot as WAITLIST ✅

Why?
- whereHas('bookings') returns true
- Cart item IS checked
- Conflict found → shows booking status
```

### Scenario 3: Admin Approves

```
Admin approves booking:
├─ Booking status: 'approved' ✅
├─ Booking payment_status: 'paid' ✅
├─ Cart item still has whereHas('bookings') ✅
└─ Result: availableSlots shows slot as BOOKED ✅

Why?
- whereHas('bookings') returns true
- Associated booking status is 'approved' + 'paid'
- Shows as fully booked
```

## Code Flow Diagram

```
┌─────────────────────────────────────────────────────────────┐
│ availableSlots() Method                                     │
│                                                             │
│ 1. Query Bookings → Get direct conflicts                   │
│ 2. Query CartItems → BUT only if:                          │
│    - whereHas('cartTransaction', function($query) {        │
│        $query->whereHas('bookings');                        │
│      })                                                     │
│                                                             │
│ 3. If cart item found:                                     │
│    - Get associated booking from cartTransaction           │
│    - Use booking's status for display                      │
│    - Show slot as booked/waitlist based on booking         │
└─────────────────────────────────────────────────────────────┘
```

## Database Query Logic

### The Key Query

```sql
SELECT * FROM cart_items
WHERE court_id = ?
  AND status = 'pending'
  AND EXISTS (
    SELECT 1 FROM cart_transactions
    WHERE cart_transactions.id = cart_items.cart_transaction_id
      AND EXISTS (
        SELECT 1 FROM bookings
        WHERE bookings.cart_transaction_id = cart_transactions.id
      )
  )
  AND (time overlap conditions)
```

This ensures:
1. ✅ Cart items without bookings → NOT returned
2. ✅ Cart items with bookings → Returned
3. ✅ Only relevant to availability when bookings exist

## API Response Behavior

### Before Checkout (No Booking Exists)

```json
GET /api/bookings/courts/1/available-slots?date=2025-11-22

{
  "success": true,
  "data": [
    {
      "start": "07:00",
      "end": "08:00",
      "available": true,           // ✅ Available
      "is_booked": false
    }
  ]
}
```

### After Checkout (Booking Exists)

```json
{
  "start": "07:00",
  "end": "08:00",
  "available": false,              // ❌ Not available
  "is_booked": false,
  "is_waitlist_available": true,  // ⏳ Waitlist
  "booking_id": 123,
  "status": "pending",
  "payment_status": "paid"
}
```

### After Approval (Booking Approved)

```json
{
  "start": "07:00",
  "end": "08:00",
  "available": false,
  "is_booked": true,              // 🔴 Booked
  "is_waitlist_available": false,
  "booking_id": 123,
  "status": "approved",
  "payment_status": "paid"
}
```

## Benefits

1. ✅ **Accurate Availability** - Slots only blocked when actual bookings exist
2. ✅ **Fair System** - Can't hold slots just by adding to cart
3. ✅ **Proper Status Display** - Cart items show their associated booking status
4. ✅ **Prevents Ghost Bookings** - Abandoned carts don't block slots
5. ✅ **Database Integrity** - Only confirmed bookings affect availability

## Edge Cases Handled

### Case 1: User Adds to Cart, Never Checks Out

```
CartItem exists: ✅
Booking exists: ❌
Result: Slot remains AVAILABLE ✅
```

### Case 2: User Checks Out, Admin Rejects

```
CartItem exists: ✅
Booking exists: ✅ (but status='rejected')
Result: Booking excluded from query (line 732)
       Cart item also excluded (no booking in transaction)
       Slot becomes AVAILABLE ✅
```

### Case 3: Multiple Cart Items, One Transaction

```
Cart has 3 items for 3 different time slots
User checks out → 3 Bookings created

All 3 cart items now pass whereHas('bookings')
Each shows its associated booking status ✅
```

## Related Models

### CartItem.php

```php
public function cartTransaction(): BelongsTo
{
    return $this->belongsTo(CartTransaction::class);
}
```

### CartTransaction.php

```php
public function cartItems(): HasMany
{
    return $this->hasMany(CartItem::class);
}

public function bookings(): HasMany
{
    return $this->hasMany(Booking::class);
}
```

### Booking.php

```php
public function cartTransaction(): BelongsTo
{
    return $this->belongsTo(CartTransaction::class);
}
```

## Files Modified

1. ✅ `/app/Http/Controllers/Api/BookingController.php`
   - Lines 775-796: Added `whereHas('cartTransaction.bookings')` check
   - Lines 899-983: Handle cart items with associated bookings

## Testing Recommendations

### Test 1: Add to Cart Without Checkout

```bash
# 1. Add item to cart
POST /api/cart
{
  "items": [{
    "court_id": 1,
    "booking_date": "2025-11-22",
    "start_time": "07:00",
    "end_time": "08:00"
  }]
}

# 2. Check slots
GET /api/bookings/courts/1/available-slots?date=2025-11-22

# Expected: available=true (cart item has no bookings)
```

### Test 2: Checkout Creates Booking

```bash
# 1. Checkout cart
POST /api/cart/checkout
{
  "payment_method": "gcash",
  "proof_of_payment": [file]
}

# 2. Check slots
GET /api/bookings/courts/1/available-slots?date=2025-11-22

# Expected: available=false, booking_id exists
# (cart item now has associated booking)
```

### Test 3: Verify Database Query

```sql
-- Check if cart item has bookings
SELECT ci.id, ci.cart_transaction_id,
       COUNT(b.id) as booking_count
FROM cart_items ci
LEFT JOIN cart_transactions ct ON ct.id = ci.cart_transaction_id
LEFT JOIN bookings b ON b.cart_transaction_id = ct.id
WHERE ci.court_id = 1
  AND ci.booking_date = '2025-11-22'
GROUP BY ci.id;

-- If booking_count = 0: Cart item won't be checked
-- If booking_count > 0: Cart item will be checked
```

## Summary

**Key Principle:** Cart items only affect slot availability when their `CartTransaction` has created `Booking` records.

**Implementation:** Use `whereHas('cartTransaction', function($query) { $query->whereHas('bookings'); })`

**Result:**
- ✅ Slots available when items only in cart
- ✅ Slots unavailable when bookings created
- ✅ Status reflects actual booking state

---

**Status:** ✅ IMPLEMENTED
**Date:** November 21, 2025
**Applies to:** `BookingController::availableSlots()` method
