# Available Slots Logic Verification

## Date: November 17, 2025

This document verifies the correctness of the `availableSlots` API logic, especially after adding the `waitlist_enabled` check.

---

## Core Logic

### Query for Bookings (Line 729-734)

```php
$bookings = Booking::with(['user', 'bookingForUser'])
    ->where('court_id', $courtId)
    ->whereIn('status', ['pending', 'approved', 'completed', 'checked_in']) // Only consider active bookings
    ->whereBetween('start_time', [$startOfDay, $endOfDay])
    ->orderBy('start_time')
    ->get();
```

**Key Points:**
- ✅ Only includes active bookings (pending, approved, completed, checked_in)
- ✅ **Excludes** 'cancelled' and 'rejected' bookings → Slots become available when booking is cancelled/rejected
- ✅ Filters by date range → Only shows bookings for the requested date

### Waitlist Availability Logic (Line 809, 825)

```php
// Check if waitlist feature is enabled
$isWaitlistEnabled = WaitlistHelper::isWaitlistEnabled();

// Calculate if waitlist is available
// Waitlist is only available if:
// 1. The slot is not fully booked (not approved+paid or admin booking)
// 2. AND waitlist feature is enabled
$isWaitlistAvailable = !(($isBookingApproved && $isBookingPaid) || $isBookingAdminBooking) && $isWaitlistEnabled;
```

**Key Points:**
- ✅ Checks `waitlist_enabled` company setting
- ✅ Admin bookings are always treated as fully booked (no waitlist)
- ✅ User bookings are fully booked only when both approved AND paid
- ✅ Waitlist is available for partially completed bookings (approved but unpaid, pending but paid, etc.)

---

## Scenario Matrix

### Complete Test Coverage

| # | Booking Status | Payment Status | User Type | Waitlist Enabled | `is_waitlist_available` | `is_booked` | Can Select? | Label |
|---|---------------|----------------|-----------|-----------------|----------------------|-------------|-------------|-------|
| 1 | approved | paid | user | ✅ Yes | `false` | `true` | ❌ No | Booked |
| 2 | approved | paid | user | ❌ No | `false` | `true` | ❌ No | Booked |
| 3 | pending | paid | user | ✅ Yes | `true` | `false` | ✅ Yes | Waitlist |
| 4 | pending | paid | user | ❌ No | **`false`** | `false` | ❌ No | Booked |
| 5 | pending | unpaid | user | ✅ Yes | `true` | `false` | ✅ Yes | Waitlist |
| 6 | pending | unpaid | user | ❌ No | **`false`** | `false` | ❌ No | Booked |
| 7 | approved | unpaid | user | ✅ Yes | `true` | `false` | ✅ Yes | Waitlist |
| 8 | approved | unpaid | user | ❌ No | **`false`** | `false` | ❌ No | Booked |
| 9 | any | any | admin/staff | ✅ Yes | `false` | `true` | ❌ No | Booked |
| 10 | any | any | admin/staff | ❌ No | `false` | `true` | ❌ No | Booked |
| 11 | cancelled | any | any | any | N/A - Not in query | N/A | ✅ Yes | Available |
| 12 | rejected | any | any | any | N/A - Not in query | N/A | ✅ Yes | Available |

**Bold** values indicate changes from the fix (scenarios 4, 6, 8 now correctly respect waitlist_enabled setting)

---

## Change/Delete Scenarios

### Scenario 1: Booking Status Changed to 'Cancelled'

**Action:** Admin/user cancels a booking
```php
$booking->update(['status' => 'cancelled']);
```

**Effect:**
- ✅ Booking is excluded from `availableSlots` query (line 731 - not in `['pending', 'approved', 'completed', 'checked_in']`)
- ✅ Slot becomes available again
- ✅ Frontend will show slot as "Available" (green)

**Example:**
- Booking: Court 4, Nov 22, 10:00-11:00, status='approved', payment_status='paid'
- API returns: `is_waitlist_available: false, is_booked: true` (slot booked)
- Admin cancels booking → status='cancelled'
- API now returns: Slot not in bookings list, shows as available slot with `available: true, is_booked: false`

### Scenario 2: Booking Status Changed to 'Rejected'

**Action:** Admin rejects a pending booking
```php
$booking->update(['status' => 'rejected']);
```

**Effect:**
- ✅ Booking is excluded from `availableSlots` query
- ✅ Slot becomes available again
- ✅ Waitlist is processed (Position #1 gets notified)
- ✅ If no waitlist exists, slot shows as available to all users

**Example:**
- Booking: Court 4, Nov 22, 10:00-11:00, status='pending', payment_status='paid'
- With waitlist disabled: API returns `is_waitlist_available: false, is_booked: false` (not selectable)
- Admin rejects booking → status='rejected'
- API now returns: Slot not in bookings list, shows as `available: true, is_booked: false` (selectable)

### Scenario 3: Booking Time Changed

**Action:** Admin/user updates booking start_time or end_time
```php
$booking->update([
    'start_time' => '2025-11-22 14:00:00', // Changed from 10:00:00
    'end_time' => '2025-11-22 15:00:00'    // Changed from 11:00:00
]);
```

**Effect:**
- ✅ Old time slot (10:00-11:00) is freed up
  - Query (line 762-769) checks for overlap: `$currentTime->lt($bookingEnd) && $slotEnd->gt($bookingStart)`
  - Old slot no longer overlaps with booking times
  - API returns old slot as `available: true`
- ✅ New time slot (14:00-15:00) is now occupied
  - Query finds overlap with new booking times
  - API returns new slot as occupied (based on status/payment)

**Example:**
- Original: Court 4, Nov 22, 10:00-11:00 → shows as booked
- Updated to: Court 4, Nov 22, 14:00-15:00
- API for 10:00-11:00 slot: Now shows `available: true, is_booked: false`
- API for 14:00-15:00 slot: Now shows as booked/waitlist based on status

### Scenario 4: Booking Court Changed

**Action:** Admin changes booking to different court
```php
$booking->update(['court_id' => 5]); // Changed from Court 4
```

**Effect:**
- ✅ Original court (Court 4) slot is freed up
  - Query (line 730) filters by `court_id`
  - When requesting Court 4 slots, booking no longer appears in results
  - API returns slot as available
- ✅ New court (Court 5) slot is now occupied
  - When requesting Court 5 slots, booking appears in results
  - API returns slot as occupied

**Example:**
- Original: Court 4, Nov 22, 10:00-11:00, status='approved', payment_status='paid'
- Updated to: Court 5, Nov 22, 10:00-11:00
- API for Court 4, 10:00-11:00: Now shows `available: true, is_booked: false`
- API for Court 5, 10:00-11:00: Now shows `is_waitlist_available: false, is_booked: true`

### Scenario 5: Booking Deleted

**Action:** Booking is soft/hard deleted
```php
$booking->delete(); // Soft delete or hard delete
```

**Effect:**
- ✅ If soft delete: Booking has `deleted_at` timestamp
  - Query doesn't explicitly filter by `deleted_at`, so depends on model setup
  - If `SoftDeletes` trait is used, deleted bookings are automatically excluded
- ✅ If hard delete: Booking is removed from database
  - Query finds no booking for that time slot
- ✅ Slot becomes available

**Note:** Need to verify if `Booking` model uses `SoftDeletes` trait.

### Scenario 6: Cart Transaction Rejected (Before Checkout)

**Action:** Admin rejects a cart transaction with `approval_status='rejected'`
```php
$cartTransaction->update(['approval_status' => 'rejected']);
```

**Effect:**
- ⚠️ **Important:** Cart items do NOT affect `availableSlots` API
- ✅ Only `Booking` records affect slot availability (line 728 comment)
- ✅ If booking was created from this transaction, booking needs to be updated separately
- ✅ Rejected cart transactions are excluded from conflict checks in `addToCart` (CartController line 263)

**Example:**
- Cart transaction with pending approval → Shows as `is_waitlist_available: true/false` based on waitlist setting
- Admin rejects cart transaction → Status changes to 'rejected'
- **Issue:** If a Booking was already created, it still exists with status='pending'
- Need to verify: Are bookings auto-cancelled when cart transaction is rejected?

---

## Potential Issues to Verify

### Issue 1: Soft Deletes

**Question:** Does the `Booking` model use `SoftDeletes` trait?

**Check:**
```php
// app/Models/Booking.php
use Illuminate\Database\Eloquent\SoftDeletes;

class Booking extends Model
{
    use SoftDeletes; // ← Check if this exists
}
```

**Impact:**
- If yes: Deleted bookings are automatically excluded from queries ✅
- If no: Deleted bookings might still appear in results ⚠️

### Issue 2: Cart Transaction Rejection

**Question:** When a cart transaction is rejected, are associated bookings also cancelled/rejected?

**Risk:**
- Cart transaction rejected but booking still has status='pending'
- Slot shows as waitlist-available (if waitlist enabled) but shouldn't exist
- Users join waitlist for a booking that's already rejected

**Mitigation:** Need to verify CartTransactionController updates related bookings when rejecting.

### Issue 3: Payment Timeout

**Question:** When a pending cart transaction expires (1 hour timeout), are bookings cancelled?

**Risk:**
- User creates booking, doesn't pay within 1 hour
- Cart transaction expires but booking still exists with status='pending'
- Slot appears occupied but user no longer has claim to it

**Mitigation:** Need to verify automatic cancellation logic for expired transactions.

### Issue 4: Waitlist Setting Toggle

**Question:** What happens to existing pending bookings when waitlist is toggled on/off?

**Current Behavior:**
- Waitlist disabled → `is_waitlist_available: false` for all pending bookings
- Waitlist enabled → `is_waitlist_available: true` for all pending bookings
- ✅ This is correct - setting is checked in real-time

**Edge Case:**
- User has pending booking, waitlist is enabled
- Another user joins waitlist
- Admin disables waitlist
- Original user's booking is still pending
- Waitlist user should be cancelled/notified?

---

## Verification Steps

### Step 1: Verify Soft Deletes
```bash
# Check if Booking model uses SoftDeletes
grep -n "use SoftDeletes" app/Models/Booking.php
```

### Step 2: Test Booking Cancellation
```bash
# Create booking
POST /api/bookings

# Cancel booking
PUT /api/bookings/{id}
Content-Type: application/json
{
  "status": "cancelled"
}

# Check slot availability
GET /api/courts/{court_id}/available-slots?date={date}
# Expect: Slot shows as available
```

### Step 3: Test Booking Time Change
```bash
# Update booking time
PUT /api/bookings/{id}
Content-Type: application/json
{
  "start_time": "2025-11-22 14:00:00",
  "end_time": "2025-11-22 15:00:00"
}

# Check old slot
GET /api/courts/{court_id}/available-slots?date=2025-11-22
# Expect: 10:00-11:00 shows as available

# Check new slot
# Expect: 14:00-15:00 shows as booked
```

### Step 4: Test Court Change
```bash
# Update booking court
PUT /api/bookings/{id}
Content-Type: application/json
{
  "court_id": 5
}

# Check old court slot
GET /api/courts/4/available-slots?date=2025-11-22
# Expect: 10:00-11:00 shows as available

# Check new court slot
GET /api/courts/5/available-slots?date=2025-11-22
# Expect: 10:00-11:00 shows as booked
```

### Step 5: Test Waitlist Setting Toggle
```bash
# Disable waitlist
UPDATE company_settings SET value = '0' WHERE key = 'waitlist_enabled';

# Check slots with pending bookings
GET /api/courts/{court_id}/available-slots?date={date}
# Expect: is_waitlist_available = false for pending bookings

# Enable waitlist
UPDATE company_settings SET value = '1' WHERE key = 'waitlist_enabled';

# Check slots again
GET /api/courts/{court_id}/available-slots?date={date}
# Expect: is_waitlist_available = true for pending bookings
```

---

## Conclusion

### ✅ Verified as Correct

1. **Booking Status Changes:** Cancelled/rejected bookings are correctly excluded from query
2. **Waitlist Setting:** Now properly checked before marking slots as waitlist-available
3. **Time Overlap Logic:** Correctly detects conflicts between time slots and bookings
4. **Court Filtering:** Query correctly filters by court_id

### ⚠️ Needs Verification

1. **Soft Deletes:** Verify if Booking model uses SoftDeletes trait
2. **Cart Transaction Lifecycle:** Verify booking status is updated when cart transaction is rejected/expired
3. **Payment Timeout:** Verify bookings are cancelled when payment times out

### 🔧 Recommended Next Steps

1. Check `app/Models/Booking.php` for SoftDeletes trait
2. Review cart transaction rejection logic in `CartTransactionController.php`
3. Review payment timeout/expiration logic
4. Add comprehensive tests for all scenarios
5. Consider adding database indexes on:
   - `bookings.court_id`
   - `bookings.status`
   - `bookings.start_time`, `bookings.end_time`
   - `bookings.deleted_at` (if using soft deletes)

---

## Summary

The fix to check `waitlist_enabled` setting is **correct and necessary**. The overall `availableSlots` logic is **sound** for handling:

- ✅ Booking cancellations
- ✅ Booking rejections
- ✅ Booking time changes
- ✅ Booking court changes
- ✅ Waitlist setting changes

The query correctly excludes cancelled and rejected bookings, ensuring slots become available again when bookings are removed or cancelled.
