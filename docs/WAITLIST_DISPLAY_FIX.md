# Waitlist Display Fix - Unpaid Bookings

## Issue
Time slots with unpaid pending bookings were showing as "Available" instead of "Waitlist Available". This was confusing because there WAS a pending booking (even though unpaid), so users should be informed that they can join a waitlist.

## Solution
Updated the `availableSlots` API to:
1. Include unpaid pending bookings in the response
2. Mark them with `is_waitlist_available: true`
3. Set `type: "waitlist_available"` to distinguish from fully available slots

## Booking Display States

| approval_status | payment_status | Display Type | Flags | User Action |
|----------------|----------------|--------------|-------|-------------|
| `pending` | `unpaid` | **Waitlist Available** | `is_waitlist_available: true`<br>`is_unpaid: true`<br>`is_booked: false` | Join waitlist |
| `pending` | `paid` | **Pending Approval** | `is_pending_approval: true`<br>`is_unpaid: false`<br>`is_booked: false` | Join waitlist |
| `approved` | `paid` | **Booked** | `is_booked: true`<br>`is_waitlist_available: false` | Cannot book |
| None | N/A | **Available** | `available: true`<br>`is_booked: false` | Book directly |

## API Response Examples

### 1. Unpaid Pending Booking (New Behavior)

**Before:**
```json
{
  "start": "07:00",
  "end": "08:00",
  "available": true,
  "is_booked": false
}
```
❌ Wrong - Shows as available but there's a pending booking

**After:**
```json
{
  "start": "07:00",
  "end": "08:00",
  "available": false,
  "is_booked": false,
  "is_waitlist_available": true,
  "is_unpaid": true,
  "type": "waitlist_available",
  "payment_status": "unpaid",
  "approval_status": "pending"
}
```
✅ Correct - Shows as waitlist available

### 2. Paid Pending Booking

```json
{
  "start": "09:00",
  "end": "10:00",
  "available": false,
  "is_booked": false,
  "is_pending_approval": true,
  "is_waitlist_available": true,
  "is_unpaid": false,
  "type": "pending_approval",
  "payment_status": "paid",
  "approval_status": "pending"
}
```

### 3. Approved Booking

```json
{
  "start": "11:00",
  "end": "12:00",
  "available": false,
  "is_booked": true,
  "is_pending_approval": false,
  "is_waitlist_available": false,
  "is_unpaid": false,
  "type": "booked",
  "payment_status": "paid",
  "approval_status": "approved"
}
```

### 4. Fully Available Slot

```json
{
  "start": "13:00",
  "end": "14:00",
  "available": true,
  "is_booked": false
}
```

## Changes Made

### 1. BookingController.php - Include Unpaid Bookings

#### Query Update (Lines 579-615)

```php
->whereHas('cartTransaction', function($transQuery) use ($oneHourAgo) {
    // Include approved (must be paid)
    $transQuery->where(function($approvedQuery) {
            $approvedQuery->where('approval_status', 'approved')
                ->where('payment_status', 'paid');
        })
        // Include pending with payment
        ->orWhere(function($paidPendingQuery) use ($oneHourAgo) {
            $paidPendingQuery->where('approval_status', 'pending')
                ->where('payment_status', 'paid')
                ->where(...);
        })
        // ✅ NEW: Include pending WITHOUT payment (unpaid)
        ->orWhere(function($unpaidPendingQuery) use ($oneHourAgo) {
            $unpaidPendingQuery->where('approval_status', 'pending')
                ->where('payment_status', 'unpaid')
                ->where(...);
        });
})
```

#### Display Logic Update (Lines 688-730)

```php
// Check approval status and payment status
$approvalStatus = $cartTransaction->approval_status ?? 'pending';
$paymentStatus = $cartTransaction->payment_status ?? 'unpaid';
$isApproved = $approvalStatus === 'approved';
$isPaid = $paymentStatus === 'paid';

// Determine the display type
if ($isApproved && $isPaid) {
    $displayType = 'booked';
} elseif (!$isApproved && $isPaid) {
    $displayType = 'pending_approval';
} elseif (!$isApproved && !$isPaid) {
    $displayType = 'waitlist_available';  // ✅ Unpaid pending
}

$availableSlots[] = [
    'is_booked' => $isApproved && $isPaid,
    'is_pending_approval' => !$isApproved && $isPaid,
    'is_waitlist_available' => !$isApproved,  // ✅ NEW FLAG
    'is_unpaid' => !$isPaid,  // ✅ NEW FLAG
    'type' => $displayType,
    'payment_status' => $paymentStatus
];
```

### 2. CartController.php - Trigger Waitlist for Any Pending

Reverted to NOT require payment for waitlist triggering:

```php
if ($cartTrans &&
    $cartTrans->approval_status === 'pending' &&  // Any pending
    // Removed: payment_status check
    $cartTrans->user &&
    $cartTrans->user->role === 'user') {
    $isPendingApprovalBooking = true;
}
```

This means BOTH paid and unpaid pending bookings trigger waitlist.

## Frontend Integration

The frontend can now use these flags:

```javascript
if (slot.is_booked) {
  // Show "Booked" - red, cannot book
  display = "🔴 Booked";
} else if (slot.is_waitlist_available) {
  // Show "Waitlist Available" - orange, can join waitlist
  if (slot.is_unpaid) {
    display = "🟠 Waitlist (Unpaid Booking)";
  } else {
    display = "🟠 Waitlist (Pending Approval)";
  }
  showWaitlistButton = true;
} else if (slot.available) {
  // Show "Available" - green, can book directly
  display = "🟢 Available";
  showBookButton = true;
}
```

## User Experience

### Unpaid Pending Booking Flow

1. **User A adds to cart** (no payment yet)
   - Slot shows as "Waitlist Available" to others
   - User B can join waitlist

2. **User B joins waitlist**
   - User B position #1 in waitlist
   - Waiting for User A's booking resolution

3. **Two Possible Outcomes:**

   **A. User A pays and gets approved:**
   - Slot becomes "Booked"
   - User B's waitlist entry remains (no notification)

   **B. User A never pays / cart expires:**
   - Slot becomes "Available"
   - User B can book directly (no waitlist needed)

   **C. User A pays but gets rejected:**
   - Slot becomes "Available"
   - User B gets email notification
   - User B has 1 hour to book

## Testing

### Verify 10/23/2025 Slots

```bash
GET /api/bookings/courts/1/available-slots?date=2025-10-23

# Expected for 07:00-08:00 and 08:00-09:00:
{
  "type": "waitlist_available",
  "is_waitlist_available": true,
  "is_unpaid": true,
  "payment_status": "unpaid",
  "approval_status": "pending"
}
```

✅ **Verified:** Slots correctly show as `waitlist_available`

## Benefits

1. ✅ **Accurate Status** - Shows waitlist when there's a pending booking
2. ✅ **User Awareness** - Users know they need to join waitlist
3. ✅ **Differentiation** - Three clear states: available, waitlist, booked
4. ✅ **Payment Context** - Shows whether pending booking is paid or unpaid
5. ✅ **Fair System** - Even unpaid bookings trigger waitlist (first come, first serve)

## Key Flags Summary

| Flag | Purpose | True When |
|------|---------|-----------|
| `available` | Slot is completely free | No bookings at all |
| `is_booked` | Slot is confirmed taken | Approved + Paid |
| `is_waitlist_available` | Can join waitlist | Any pending booking exists |
| `is_pending_approval` | Paid but not approved | Paid + Pending |
| `is_unpaid` | No payment uploaded | Payment status unpaid |

## Migration Required

❌ No database migration needed

## Breaking Changes

✅ No breaking changes
- Added new flags (backward compatible)
- `available` and `is_booked` still work as before
- New flags provide additional context

## Files Modified

1. ✅ `app/Http/Controllers/Api/BookingController.php`
   - Updated query to include unpaid bookings
   - Added `is_waitlist_available` and `is_unpaid` flags
   - Added `type: "waitlist_available"` status

2. ✅ `app/Http/Controllers/Api/CartController.php`
   - Reverted to allow unpaid bookings to trigger waitlist

## Related Documentation

- `WAITLIST_FEATURE.md` - Complete waitlist functionality
- `AVAILABLE_SLOTS_FIX.md` - Approval status checks
- `PAYMENT_STATUS_FIX.md` - Payment status implementation
- `WAITLIST_DISPLAY_FIX.md` - This document

## Summary

**Problem:** Unpaid pending bookings showed as "Available"

**Solution:** Include unpaid bookings and mark them as "Waitlist Available"

**Result:** Three distinct states for slots:
- 🟢 **Available** (no bookings)
- 🟠 **Waitlist** (pending bookings, paid or unpaid)
- 🔴 **Booked** (approved + paid)

This provides accurate information to users and allows proper waitlist functionality.
