# Available Slots Display Fix

## Issue
Time slots were showing as "Booked" even when the booking had not yet been approved by admin (approval_status = 'pending'). This prevented users from seeing that they could join the waitlist for these slots.

## Solution
Updated the `availableSlots` API endpoint to differentiate between:
- **Approved bookings** - Show as "Booked" (cannot book, slot taken)
- **Pending approval bookings** - Show as "Pending Approval" (can join waitlist)

## Changes Made

### File: `app/Http/Controllers/Api/BookingController.php`

#### 1. Updated Cart Items Query (Lines 579-605)

**Before:**
```php
$cartItems = \App\Models\CartItem::with('cartTransaction.user')
    ->where('court_id', $courtId)
    ->where('booking_date', $date->format('Y-m-d'))
    ->where(function($query) use ($oneHourAgo) {
        // Checked only 'status' field, not approval_status
        $query->where('status', 'completed')...
    })
```

**After:**
```php
$cartItems = \App\Models\CartItem::with('cartTransaction.user')
    ->where('court_id', $courtId)
    ->where('booking_date', $date->format('Y-m-d'))
    ->where('status', '!=', 'cancelled')
    ->whereHas('cartTransaction', function($transQuery) use ($oneHourAgo) {
        // NOW checks approval_status
        $transQuery->where('approval_status', 'approved')
            ->orWhere(function($subQuery) {
                $subQuery->where('approval_status', 'pending')
                    ->whereHas('user', function($userQuery) {
                        $userQuery->where('role', 'admin');
                    });
            })
            ->orWhere(function($subQuery) use ($oneHourAgo) {
                $subQuery->where('approval_status', 'pending')
                    ->where('created_at', '>=', $oneHourAgo)
                    ->whereHas('user', function($userQuery) {
                        $userQuery->where('role', '!=', 'admin');
                    });
            });
    })
```

**Key Change:** Now explicitly checks `approval_status` field in cart transactions.

#### 2. Updated Cart Item Display Logic (Lines 678-708)

**Before:**
```php
$availableSlots[] = [
    // ...
    'available' => false,
    'is_booked' => true, // Always true for any conflict
    'type' => $conflictingCartItem->status === 'completed' ? 'paid' : 'in_cart',
    'status' => $conflictingCartItem->status
];
```

**After:**
```php
// Check approval status to determine if truly booked or pending approval
$approvalStatus = $conflictingCartItem->cartTransaction->approval_status ?? 'pending';
$isApproved = $approvalStatus === 'approved';

$displayType = 'pending_approval'; // Default
$displayStatus = 'pending_approval';

if ($isApproved) {
    $displayType = 'booked';
    $displayStatus = 'approved';
}

$availableSlots[] = [
    // ...
    'available' => false,
    'is_booked' => $isApproved, // Only true if approved
    'is_pending_approval' => !$isApproved, // True if pending approval (waitlist available)
    'type' => $displayType,
    'status' => $displayStatus,
    'approval_status' => $approvalStatus // Added for clarity
];
```

**Key Changes:**
- ✅ Added `is_pending_approval` field
- ✅ `is_booked` is only `true` when `approval_status = 'approved'`
- ✅ Added `approval_status` field to response
- ✅ Changed `type` to reflect actual status

#### 3. Updated Old Booking Display Logic (Lines 717-738)

Similar changes for backward compatibility with old Booking table records:

```php
// Check booking status - 'approved' means truly booked, 'pending' means waitlist available
$bookingStatus = $conflictingBooking->status ?? 'pending';
$isBookingApproved = $bookingStatus === 'approved';

$availableSlots[] = [
    // ...
    'is_booked' => $isBookingApproved, // Only true if approved
    'is_pending_approval' => !$isBookingApproved, // True if pending (waitlist available)
    'type' => $isBookingApproved ? 'booking' : 'pending_approval',
    'status' => $bookingStatus
];
```

## API Response Changes

### Before (Incorrect)

```json
{
  "start": "14:00",
  "end": "15:00",
  "available": false,
  "is_booked": true,  // ❌ Wrong - shows as booked even if pending
  "type": "in_cart",
  "status": "pending"
}
```

### After (Correct)

#### For Approved Bookings:
```json
{
  "start": "14:00",
  "end": "15:00",
  "available": false,
  "is_booked": true,  // ✅ Correct - truly booked
  "is_pending_approval": false,
  "type": "booked",
  "status": "approved",
  "approval_status": "approved"
}
```

#### For Pending Approval Bookings:
```json
{
  "start": "14:00",
  "end": "15:00",
  "available": false,
  "is_booked": false,  // ✅ Correct - not yet booked
  "is_pending_approval": true,  // ✅ NEW - indicates waitlist available
  "type": "pending_approval",
  "status": "pending_approval",
  "approval_status": "pending"
}
```

## Frontend Impact

The frontend can now use the `is_pending_approval` flag to:

1. **Show different UI states:**
   - `is_booked: true` → Show "Booked" (red/blocked)
   - `is_pending_approval: true` → Show "Pending - Waitlist Available" (orange/warning)
   - `available: true` → Show "Available" (green)

2. **Enable waitlist feature:**
   - When user clicks a slot with `is_pending_approval: true`, show waitlist option
   - When user clicks a slot with `is_booked: true`, show "slot taken" message

## Testing

### Test Case 1: Slot with Pending Approval Booking

**Setup:**
```bash
# User creates booking and checks out
POST /api/cart/checkout
# Cart transaction created with approval_status = 'pending'
```

**Check Availability:**
```bash
GET /api/bookings/courts/1/available-slots?date=2025-10-21

# Expected Response:
{
  "success": true,
  "data": [
    {
      "start": "14:00",
      "end": "15:00",
      "is_booked": false,
      "is_pending_approval": true,  // ✅ Shows waitlist available
      "type": "pending_approval"
    }
  ]
}
```

### Test Case 2: Slot with Approved Booking

**Setup:**
```bash
# Admin approves the booking
POST /api/cart-transactions/1/approve
# Cart transaction now has approval_status = 'approved'
```

**Check Availability:**
```bash
GET /api/bookings/courts/1/available-slots?date=2025-10-21

# Expected Response:
{
  "success": true,
  "data": [
    {
      "start": "14:00",
      "end": "15:00",
      "is_booked": true,  // ✅ Shows as truly booked
      "is_pending_approval": false,
      "type": "booked"
    }
  ]
}
```

## Status Matrix

| Approval Status | is_booked | is_pending_approval | type | User Action |
|----------------|-----------|---------------------|------|-------------|
| `pending` | `false` | `true` | `pending_approval` | Can join waitlist |
| `approved` | `true` | `false` | `booked` | Cannot book |
| None (available) | `false` | `false` | N/A | Can book directly |

## Benefits

1. ✅ **Accurate Slot Display** - Slots only show as "booked" when actually approved
2. ✅ **Waitlist Visibility** - Users can see when waitlist is available
3. ✅ **Better UX** - Clear distinction between pending and approved bookings
4. ✅ **Backward Compatible** - Works with both old Booking table and new CartTransaction system

## Related Features

This fix works together with:
- Waitlist feature (users can join waitlist when `is_pending_approval: true`)
- Cart system (checks `approval_status` field)
- Admin approval workflow (approving changes status from pending to approved)

## Database Fields Used

### CartTransaction
- `approval_status` - Primary field checked
  - `'pending'` → Shows as pending approval (waitlist available)
  - `'approved'` → Shows as booked (slot taken)

### Booking (Legacy)
- `status` - Fallback for old bookings
  - `'pending'` → Shows as pending approval
  - `'approved'` → Shows as booked

## Migration Required

❌ **No database migration needed** - uses existing fields

## Breaking Changes

✅ **No breaking changes** - added new fields, didn't remove old ones

Frontend can still use `is_booked` field, but should also check `is_pending_approval` for waitlist feature.

## Files Modified

1. ✅ `app/Http/Controllers/Api/BookingController.php` - Updated `availableSlots()` method

## Verification

To verify the fix is working:

1. Create a booking and checkout (don't approve yet)
2. Call `GET /api/bookings/courts/{court_id}/available-slots?date={date}`
3. Check the response for the time slot:
   - Should have `is_booked: false`
   - Should have `is_pending_approval: true`
   - Should have `type: "pending_approval"`
4. Approve the booking
5. Call the API again
6. Check the response:
   - Should now have `is_booked: true`
   - Should have `is_pending_approval: false`
   - Should have `type: "booked"`

## Next Steps

Frontend should be updated to:
1. Check `is_pending_approval` flag
2. Display different colors/text for pending vs booked slots
3. Show "Join Waitlist" option when `is_pending_approval: true`
4. Show "Slot Taken" message when `is_booked: true`
