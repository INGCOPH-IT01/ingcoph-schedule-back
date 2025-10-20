# is_waitlist_available Flag Logic Fix

## Issue
Approved and paid bookings were still showing `is_waitlist_available: true`, which caused the frontend to display them as "Waitlist" instead of "Booked".

## Root Cause
The `is_waitlist_available` flag was incorrectly set to:
```php
'is_waitlist_available' => !$isApproved, // Wrong!
```

This meant ANY booking that wasn't approved would show as waitlist available, even if it was approved and paid (which should show as fully "Booked").

## Correct Logic

A slot should be "Booked" (NOT waitlist available) ONLY when it is **BOTH** approved **AND** paid.

| approval_status | payment_status | is_booked | is_waitlist_available | Display |
|----------------|----------------|-----------|----------------------|---------|
| `approved` | `paid` | `true` | `false` | 🔴 **Booked** |
| `pending` | `paid` | `false` | `true` | 🟠 Waitlist (Paid) |
| `pending` | `unpaid` | `false` | `true` | 🟠 Waitlist (Unpaid) |
| `approved` | `unpaid` | `false` | `true` | 🟠 Waitlist* |

*Edge case - shouldn't normally happen

## Solution

Changed the logic to:
```php
'is_waitlist_available' => !($isApproved && $isPaid),
```

This can be read as:
- Waitlist is available = NOT (approved AND paid)
- Waitlist is available when the booking is NOT fully confirmed
- A fully confirmed booking = approved AND paid

Using De Morgan's Law, this is equivalent to:
```php
'is_waitlist_available' => !$isApproved || !$isPaid,
```

## Changes Made

### File: `BookingController.php`

#### 1. Cart Items Display (Line 723)

**Before:**
```php
'is_waitlist_available' => !$isApproved, // Any pending = waitlist available
```

**After:**
```php
'is_waitlist_available' => !($isApproved && $isPaid), // False only when fully booked (approved AND paid)
```

#### 2. Old Bookings Display (Line 766)

**Before:**
```php
'is_waitlist_available' => !$isBookingApproved,
```

**After:**
```php
'is_waitlist_available' => !($isBookingApproved && $isBookingPaid), // False only when fully booked
```

## Truth Table

### For `is_waitlist_available`

| isApproved | isPaid | isApproved && isPaid | !( isApproved && isPaid) | Result |
|------------|--------|---------------------|--------------------------|--------|
| true | true | true | **false** | NOT waitlist ✅ |
| true | false | false | **true** | Waitlist |
| false | true | false | **true** | Waitlist |
| false | false | false | **true** | Waitlist |

### For `is_booked`

| isApproved | isPaid | isApproved && isPaid | Result |
|------------|--------|---------------------|--------|
| true | true | **true** | Booked ✅ |
| true | false | **false** | NOT booked |
| false | true | **false** | NOT booked |
| false | false | **false** | NOT booked |

## Frontend Display Logic

The frontend uses these flags correctly:

```javascript
// Chip label
if (slot.is_booked) {
  display = "Booked";          // Red chip
} else if (slot.is_waitlist_available) {
  display = "Waitlist";        // Orange chip
} else {
  display = "Available";       // Green chip
}

// Disabled state
disabled = !slot.available && !slot.is_waitlist_available;
// This means: disabled ONLY when NOT available AND NOT waitlist
// Which correctly disables only fully booked slots
```

## Test Cases

### Case 1: Fully Booked Slot
```json
{
  "approval_status": "approved",
  "payment_status": "paid",
  "is_booked": true,           ✅
  "is_waitlist_available": false,  ✅
  "available": false
}
```
**Frontend:** Shows "Booked" (red), disabled ✅

### Case 2: Paid Pending Approval
```json
{
  "approval_status": "pending",
  "payment_status": "paid",
  "is_booked": false,
  "is_waitlist_available": true,   ✅
  "available": false
}
```
**Frontend:** Shows "Waitlist" (orange), clickable ✅

### Case 3: Unpaid Pending
```json
{
  "approval_status": "pending",
  "payment_status": "unpaid",
  "is_booked": false,
  "is_waitlist_available": true,   ✅
  "available": false
}
```
**Frontend:** Shows "Waitlist" (orange), clickable ✅

### Case 4: Available Slot
```json
{
  "available": true,
  "is_booked": false,
  "is_waitlist_available": false
}
```
**Frontend:** Shows "Available" (green), clickable ✅

## Verification

To test with an approved and paid booking:

```bash
# 1. Create a booking
POST /api/cart
POST /api/cart/checkout (with payment proof)

# 2. Approve it
POST /api/cart-transactions/{id}/approve

# 3. Check available slots
GET /api/bookings/courts/{court_id}/available-slots?date={date}

# Expected response:
{
  "is_booked": true,
  "is_waitlist_available": false,  // Should be false!
  "type": "booked"
}
```

## Benefits

1. ✅ **Correct Display** - Approved bookings now show as "Booked"
2. ✅ **Proper Disabling** - Only truly booked slots are disabled
3. ✅ **Consistent Logic** - Flags work together correctly
4. ✅ **Clear States** - Three distinct, mutually exclusive states

## Flag Relationships

```
Mutually Exclusive States:
┌─────────────────────────────────────────┐
│ available: true                         │ → Green "Available"
│   (No bookings at all)                  │
└─────────────────────────────────────────┘

┌─────────────────────────────────────────┐
│ is_waitlist_available: true             │ → Orange "Waitlist"
│ is_booked: false                        │
│   (Pending bookings)                    │
└─────────────────────────────────────────┘

┌─────────────────────────────────────────┐
│ is_booked: true                         │ → Red "Booked"
│ is_waitlist_available: false            │
│   (Approved AND Paid)                   │
└─────────────────────────────────────────┘
```

## Migration Required

❌ No database migration needed

## Breaking Changes

✅ No breaking changes - this is a bug fix

## Files Modified

1. ✅ `app/Http/Controllers/Api/BookingController.php`
   - Line 723: Fixed cart items `is_waitlist_available` flag
   - Line 766: Fixed old bookings `is_waitlist_available` flag

## Related Documentation

- `WAITLIST_FEATURE.md` - Waitlist implementation
- `WAITLIST_DISPLAY_FIX.md` - Frontend display fix
- `FRONTEND_WAITLIST_FIX.md` - Frontend logic

## Summary

**Problem:** Approved + paid bookings showed `is_waitlist_available: true`

**Fix:** Changed to `!($isApproved && $isPaid)` - false only when BOTH approved AND paid

**Result:** Approved bookings now correctly show as "Booked" (red) instead of "Waitlist" (orange)
