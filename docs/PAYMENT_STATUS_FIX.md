# Payment Status Check Fix

## Issue
Time slots were showing as "Booked" or "Pending Approval" even when the booking was **unpaid** (no proof of payment uploaded). This blocked slots for users who had only added items to cart but never completed checkout with payment.

### Example Case
- User adds time slot to cart → `payment_status = 'unpaid'`
- User never uploads proof of payment
- Slot shows as "booked" to other users ❌ **WRONG**
- Slot should be **available** ✅ **CORRECT**

## Root Cause
The system was checking only `approval_status` but not `payment_status`. This meant:
- Cart items with `approval_status = 'pending'` AND `payment_status = 'unpaid'` were blocking slots
- These unpaid reservations had no payment proof and should not hold the slot

## Solution
Added `payment_status = 'paid'` check to both:
1. **availableSlots API** - Only show slots as booked/pending if payment has been made
2. **Cart booking logic** - Only trigger waitlist if payment has been made

## Booking States

### Complete Booking Flow

```
1. Add to Cart
   ├─ CartItem created
   └─ CartTransaction: approval_status = 'pending', payment_status = 'unpaid'
   └─ Slot Status: AVAILABLE (not blocking)

2. Upload Proof & Checkout
   ├─ payment_status = 'paid'
   └─ approval_status = 'pending' (waiting for admin)
   └─ Slot Status: PENDING APPROVAL (triggers waitlist for other users)

3. Admin Approves
   ├─ approval_status = 'approved'
   └─ payment_status = 'paid'
   └─ Slot Status: BOOKED (slot taken, cannot book)
```

### Slot Display Logic Matrix

| approval_status | payment_status | Slot Display | Waitlist Available | User Can Book |
|----------------|----------------|--------------|-------------------|---------------|
| `pending` | `unpaid` | **Available** | No | ✅ Yes |
| `pending` | `paid` | **Pending Approval** | ✅ Yes | Via Waitlist |
| `approved` | `paid` | **Booked** | No | ❌ No |
| `rejected` | any | **Available** | No | ✅ Yes |

## Changes Made

### 1. BookingController.php - availableSlots()

#### Query Update (Lines 579-604)

**Before:**
```php
->whereHas('cartTransaction', function($transQuery) {
    $transQuery->where('approval_status', 'approved')
        ->orWhere(function($subQuery) {
            $subQuery->where('approval_status', 'pending')
                // Missing payment_status check!
        });
})
```

**After:**
```php
->whereHas('cartTransaction', function($transQuery) use ($oneHourAgo) {
    // Include approved transactions (definitely booked)
    $transQuery->where('approval_status', 'approved')
        // OR include pending approval BUT ONLY if payment_status = 'paid'
        ->orWhere(function($subQuery) use ($oneHourAgo) {
            $subQuery->where('approval_status', 'pending')
                ->where('payment_status', 'paid') // ✅ MUST have paid
                ->where(function($timeQuery) use ($oneHourAgo) {
                    $timeQuery->whereHas('user', function($userQuery) {
                            $userQuery->where('role', 'admin');
                        })
                        ->orWhere('created_at', '>=', $oneHourAgo);
                });
        });
})
```

**Key Addition:** `->where('payment_status', 'paid')` ensures only paid bookings block slots.

### 2. CartController.php - Waitlist Trigger Logic

#### Pending Approval Check (Lines 218-234)

**Before:**
```php
if ($cartTrans &&
    $cartTrans->approval_status === 'pending' &&
    $cartTrans->user &&
    $cartTrans->user->role === 'user') {
    // Missing payment_status check!
    $isPendingApprovalBooking = true;
}
```

**After:**
```php
if ($cartTrans &&
    $cartTrans->approval_status === 'pending' &&
    $cartTrans->payment_status === 'paid' &&  // ✅ User has uploaded proof
    $cartTrans->user &&
    $cartTrans->user->role === 'user') {
    $isPendingApprovalBooking = true;
}
```

#### Approved Conflict Check (Lines 297-306)

**Before:**
```php
if ($cartTrans &&
    $cartTrans->approval_status === 'approved') {
    $hasApprovedConflict = true;
}
```

**After:**
```php
if ($cartTrans &&
    $cartTrans->approval_status === 'approved' &&
    $cartTrans->payment_status === 'paid') {  // ✅ Must be paid
    $hasApprovedConflict = true;
}
```

## Testing

### Test Case 1: Unpaid Booking (Should Not Block)

```bash
# 1. Add to cart (doesn't upload payment)
POST /api/cart
{
  "items": [{
    "court_id": 1,
    "booking_date": "2025-10-23",
    "start_time": "07:00",
    "end_time": "08:00"
  }]
}

# Cart created with:
# - approval_status: 'pending'
# - payment_status: 'unpaid'  ← No payment proof

# 2. Check available slots
GET /api/bookings/courts/1/available-slots?date=2025-10-23

# Expected Result:
# - 07:00-08:00 shows as AVAILABLE ✅
# - Other users CAN book this slot
```

### Test Case 2: Paid but Not Approved (Should Show Pending)

```bash
# 1. Add to cart and checkout with payment
POST /api/cart
POST /api/cart/checkout
{
  "payment_method": "gcash",
  "proof_of_payment": [file]
}

# Cart updated to:
# - approval_status: 'pending'
# - payment_status: 'paid'  ← Payment proof uploaded

# 2. Check available slots
GET /api/bookings/courts/1/available-slots?date=2025-10-23

# Expected Result:
# - 07:00-08:00 shows as PENDING APPROVAL ⏳
# - Other users see waitlist option
# - is_pending_approval: true
```

### Test Case 3: Paid and Approved (Should Show Booked)

```bash
# 1. Admin approves
POST /api/cart-transactions/1/approve

# Cart updated to:
# - approval_status: 'approved'
# - payment_status: 'paid'

# 2. Check available slots
GET /api/bookings/courts/1/available-slots?date=2025-10-23

# Expected Result:
# - 07:00-08:00 shows as BOOKED 🔴
# - Other users CANNOT book
# - is_booked: true
```

## Verify Fix for 10/23/2025

The bookings on 10/23/2025 had:
- `approval_status: pending`
- `payment_status: unpaid` ❌
- `proof_of_payment: No` ❌

**Before Fix:** Showed as "Booked" (blocked slot)
**After Fix:** Shows as "Available" (slot open for booking)

Run this to verify:
```bash
GET /api/bookings/courts/1/available-slots?date=2025-10-23

# Should show 07:00-08:00 and 08:00-09:00 as AVAILABLE now
```

## API Response Changes

### Unpaid Booking (NEW - Won't appear in response)

```json
// These slots won't be in the response at all
// They're available for booking by anyone
```

### Paid + Pending Approval

```json
{
  "start": "07:00",
  "end": "08:00",
  "is_booked": false,
  "is_pending_approval": true,  // Waitlist available
  "type": "pending_approval",
  "approval_status": "pending",
  "payment_status": "paid"  // ✅ Has paid
}
```

### Paid + Approved

```json
{
  "start": "07:00",
  "end": "08:00",
  "is_booked": true,  // Truly booked
  "is_pending_approval": false,
  "type": "booked",
  "approval_status": "approved",
  "payment_status": "paid"  // ✅ Has paid
}
```

## Cart Expiration Strategy

For unpaid cart items, we recommend implementing auto-expiration:

```php
// In CartController or scheduled command
$oneHourAgo = Carbon::now()->subHour();

CartItem::whereHas('cartTransaction', function($query) use ($oneHourAgo) {
    $query->where('payment_status', 'unpaid')
          ->where('created_at', '<', $oneHourAgo);
})->update(['status' => 'expired']);
```

This ensures:
- Unpaid items expire after 1 hour
- Slots become available again
- Users must complete payment to hold slot

## Benefits

1. ✅ **Accurate Availability** - Unpaid bookings don't block slots
2. ✅ **Fair System** - Only paid bookings hold slots
3. ✅ **Prevents Abuse** - Users can't hold slots without payment
4. ✅ **Clear States** - Three distinct states (available, pending, booked)
5. ✅ **Better UX** - Users see true availability

## Important Notes

### Payment Status Values

- `'unpaid'` - No proof of payment uploaded → **Doesn't block slot**
- `'paid'` - Proof uploaded, waiting for admin review → **Blocks slot (pending approval)**
- `'approved'` - Approved by admin (legacy, same as approval_status) → **Blocks slot (booked)**

### Approval Status Values

- `'pending'` - Waiting for admin review
- `'approved'` - Confirmed by admin
- `'rejected'` - Denied by admin (slot becomes available)

### Required Conditions for Slot Blocking

A booking blocks a slot ONLY when:
1. ✅ `payment_status = 'paid'` (proof uploaded)
2. ✅ `approval_status = 'pending'` OR `'approved'`
3. ✅ Recent (< 1 hour old for users, any age for admin)

## Migration Required

❌ **No database migration needed** - uses existing fields

## Breaking Changes

✅ **No breaking changes**
- More restrictive (better for users)
- Unpaid bookings no longer block slots (improvement)

## Files Modified

1. ✅ `app/Http/Controllers/Api/BookingController.php`
   - Updated `availableSlots()` query

2. ✅ `app/Http/Controllers/Api/CartController.php`
   - Updated pending approval check
   - Updated approved conflict check

## Related Documentation

- `WAITLIST_FEATURE.md` - Waitlist functionality
- `AVAILABLE_SLOTS_FIX.md` - Approval status check
- `PAYMENT_STATUS_FIX.md` - This document

## Summary

**Problem:** Unpaid bookings were blocking time slots

**Solution:** Added `payment_status = 'paid'` requirement

**Result:** Only paid bookings block slots, unpaid items don't reserve slots

This ensures fair slot allocation and prevents users from holding slots without commitment to pay.
