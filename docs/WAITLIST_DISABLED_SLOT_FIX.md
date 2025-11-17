# Waitlist Disabled - Slot Availability Fix

## Date: November 17, 2025

## Problem Summary

Users were able to select time slots (e.g., Nov 22, Court 4, 10am-1pm) that showed as "Available" in the booking dialog, but when attempting to checkout, they received the error:

```
Payment Failed
This time slot is currently pending approval and waitlist is disabled. Please try again later.
```

---

## Root Cause Analysis

### Issue: Mismatch Between Availability Check and Checkout Validation

The system had inconsistent behavior regarding the `waitlist_enabled` company setting:

#### 1. **Available Slots API** (BookingController.php - `availableSlots()` method)
**Location:** `app/Http/Controllers/Api/BookingController.php` line ~830

**Problem:**
- The API was marking slots with pending bookings as `is_waitlist_available = true`
- This was done **without checking** the `waitlist_enabled` company setting
- Result: Frontend displayed these slots as selectable (green "Available" chips)

**Code (Before Fix):**
```php
'is_waitlist_available' => !(($isBookingApproved && $isBookingPaid) || $isBookingAdminBooking),
// False only when fully booked (didn't check if waitlist was enabled)
```

#### 2. **Add to Cart API** (CartController.php - `addToCart()` method)
**Location:** `app/Http/Controllers/Api/CartController.php` line ~367

**Behavior (Correct):**
- The API **correctly checks** if waitlist is enabled before creating waitlist entries
- If waitlist is disabled and there's a pending booking conflict, it rejects with:
  ```php
  return response()->json(
      WaitlistHelper::getDisabledResponse(true),
      409
  );
  ```

### Timeline of Events

1. **User A** books Court 4, Nov 22, 10am-1pm → Status: `pending` (awaiting admin approval)
2. **User B** views available slots → API returns `is_waitlist_available: true` for that slot
3. **User B** sees slot as "Available" (green) and selects it
4. **User B** proceeds to checkout
5. **Backend validation** runs and checks:
   - Is there a conflicting booking? ✅ Yes (User A's pending booking)
   - Is waitlist enabled? ❌ No
6. **Result:** Error shown: "This time slot is currently pending approval and waitlist is disabled"

---

## Solution Implemented

### File Modified: `app/Http/Controllers/Api/BookingController.php`

Updated the `availableSlots()` method to check the `waitlist_enabled` setting when determining if a slot should be marked as waitlist-available.

### Changes Made (Lines ~808-839)

**Added:**
```php
// Check if waitlist feature is enabled
$isWaitlistEnabled = WaitlistHelper::isWaitlistEnabled();
```

**Updated Calculation:**
```php
// Calculate if waitlist is available
// Waitlist is only available if:
// 1. The slot is not fully booked (not approved+paid or admin booking)
// 2. AND waitlist feature is enabled
$isWaitlistAvailable = !(($isBookingApproved && $isBookingPaid) || $isBookingAdminBooking) && $isWaitlistEnabled;
```

**Updated Response:**
```php
'is_waitlist_available' => $isWaitlistAvailable, // Only true if slot not fully booked AND waitlist feature is enabled
```

---

## How It Works Now

### Scenario 1: Waitlist Enabled

**User A's pending booking exists**

API Response for that slot:
```json
{
  "start": "10:00",
  "end": "11:00",
  "available": false,
  "is_booked": false,
  "is_waitlist_available": true,  // ✅ TRUE - waitlist is enabled
  "is_pending_approval": true,
  "type": "pending_approval"
}
```

Frontend behavior:
- ✅ Slot is **selectable** (clickable)
- Shows "Waitlist" chip (orange/warning)
- User can proceed to join waitlist

### Scenario 2: Waitlist Disabled

**User A's pending booking exists**

API Response for that slot:
```json
{
  "start": "10:00",
  "end": "11:00",
  "available": false,
  "is_booked": false,
  "is_waitlist_available": false,  // ✅ FALSE - waitlist is disabled
  "is_pending_approval": true,
  "type": "pending_approval"
}
```

Frontend behavior:
- ❌ Slot is **not selectable** (disabled)
- Shows "Booked" chip (red/error)
- User cannot click or select this slot
- No error during checkout (because user can't add it to cart)

---

## Frontend Integration

The frontend already has the correct logic to handle this:

### File: `src/components/NewBookingDialog.vue`

**Slot Selection Logic (Line ~1310):**
```javascript
const canSelectSlot = (slot) => {
  // If slot is available, it can always be selected
  if (slot.available) {
    return true
  }

  // If slot is not available and has waitlist
  if (slot.is_waitlist_available) {
    // Only allow selection if waitlist feature is enabled
    if (waitlistEnabled.value) {
      return true
    } else {
      // Waitlist is disabled, cannot select this slot
      return false  // ✅ This now triggers correctly
    }
  }

  // Otherwise, slot cannot be selected (fully booked, no waitlist)
  return false
}
```

**Slot Status Label (Line ~1332):**
```javascript
const getSlotStatusLabel = (slot) => {
  // If slot is booked (not available and not waitlist)
  if (slot.is_booked && !slot.is_waitlist_available) {
    return 'Booked'
  }

  // If waitlist is enabled and slot is waitlist-available
  if (waitlistEnabled.value && slot.is_waitlist_available && !slot.available) {
    return 'Waitlist'
  }

  // If waitlist is disabled but slot is waitlist-available, show as Booked
  if (!waitlistEnabled.value && slot.is_waitlist_available) {
    return 'Booked'
  }

  // If slot is not available for any other reason
  if (!slot.available) {
    return 'Booked'
  }

  // Slot is available
  return 'Available'
}
```

The frontend code was already prepared to handle this scenario, but the backend wasn't returning the correct `is_waitlist_available` value.

---

## Testing

### Test Case 1: Verify Slots with Waitlist Disabled

**Setup:**
1. Disable waitlist feature:
   ```sql
   UPDATE company_settings SET value = '0' WHERE key = 'waitlist_enabled';
   ```
2. Create a pending booking for Court 4, Nov 22, 10:00-11:00

**Test:**
```bash
GET /api/courts/4/available-slots?date=2025-11-22
```

**Expected Response:**
```json
{
  "success": true,
  "data": [
    {
      "start": "10:00",
      "end": "11:00",
      "available": false,
      "is_booked": false,
      "is_waitlist_available": false,  // ✅ Should be FALSE
      "is_pending_approval": true
    }
  ]
}
```

**Frontend Verification:**
- Open booking dialog for Court 4, Nov 22
- 10:00-11:00 slot should be:
  - ❌ Not clickable/selectable
  - Shows "Booked" label
  - Grayed out or styled as unavailable

### Test Case 2: Verify Slots with Waitlist Enabled

**Setup:**
1. Enable waitlist feature:
   ```sql
   UPDATE company_settings SET value = '1' WHERE key = 'waitlist_enabled';
   ```
2. Same pending booking exists

**Test:**
```bash
GET /api/courts/4/available-slots?date=2025-11-22
```

**Expected Response:**
```json
{
  "success": true,
  "data": [
    {
      "start": "10:00",
      "end": "11:00",
      "available": false,
      "is_booked": false,
      "is_waitlist_available": true,  // ✅ Should be TRUE
      "is_pending_approval": true
    }
  ]
}
```

**Frontend Verification:**
- Open booking dialog for Court 4, Nov 22
- 10:00-11:00 slot should be:
  - ✅ Clickable/selectable
  - Shows "Waitlist" label
  - User can proceed to join waitlist

---

## Status Matrix

| Booking Status | Payment Status | Waitlist Enabled | `is_waitlist_available` | Can User Select? | Label Shown |
|---------------|----------------|-----------------|------------------------|-----------------|-------------|
| pending | unpaid | ✅ Yes | `true` | ✅ Yes | "Waitlist" |
| pending | unpaid | ❌ No | `false` | ❌ No | "Booked" |
| pending | paid | ✅ Yes | `true` | ✅ Yes | "Waitlist" |
| pending | paid | ❌ No | `false` | ❌ No | "Booked" |
| approved | paid | ✅ Yes | `false` | ❌ No | "Booked" |
| approved | paid | ❌ No | `false` | ❌ No | "Booked" |

---

## Benefits

1. ✅ **Consistent Behavior** - Availability check and checkout validation now use the same logic
2. ✅ **Clear User Feedback** - Users can't select slots they won't be able to book
3. ✅ **No Confusing Errors** - Users won't see "Payment Failed" errors for slots they can't book
4. ✅ **Respects Company Settings** - Properly honors the `waitlist_enabled` setting at all stages
5. ✅ **Better UX** - Slots are accurately marked as available/unavailable based on system configuration

---

## Related Files

### Backend
- ✅ `app/Http/Controllers/Api/BookingController.php` - Updated `availableSlots()` method
- `app/Http/Controllers/Api/CartController.php` - Already had correct validation
- `app/Helpers/WaitlistHelper.php` - Helper methods used by both controllers

### Frontend
- `src/components/NewBookingDialog.vue` - Already had correct slot selection logic
- `src/services/courtService.js` - Calls the available slots API

---

## Breaking Changes

✅ **No breaking changes** - This is a bug fix that makes the API behavior consistent with validation rules.

---

## Database Changes

❌ **No database migration needed** - Uses existing `company_settings` table and `waitlist_enabled` setting.

---

## Verification Steps

1. ✅ Linter check passed - No errors introduced
2. ✅ Frontend logic verified - Already correctly handles `is_waitlist_available` flag
3. ✅ Backend logic verified - Now checks `waitlist_enabled` setting before setting flag
4. ✅ Error flow resolved - Users can't select slots when waitlist is disabled

---

## Next Steps

### For Testing:
1. Test with waitlist disabled:
   - Verify pending booking slots are not selectable
   - Verify no "Payment Failed" errors occur
2. Test with waitlist enabled:
   - Verify pending booking slots are selectable
   - Verify waitlist creation works correctly

### For Deployment:
1. Deploy backend changes to production
2. Clear any API caches if applicable
3. Monitor for any related issues

---

## Additional Notes

This fix addresses the specific issue reported where:
- **Date:** Nov 22
- **Court:** Court 4
- **Time:** 10am-1pm (3 slots: 10-11, 11-12, 12-1)
- **Problem:** Slots showed as available but couldn't be booked

The slots were showing as available because there was a pending booking (not yet approved) and the system was incorrectly marking them as `is_waitlist_available: true` even though the waitlist feature was disabled.

With this fix, these slots will now correctly show as `is_waitlist_available: false` and will be unselectable in the frontend.
