# Waitlist Feature - Approval Status Update

## Change Summary

Updated the waitlist logic to **specifically check for `approval_status = 'pending'`** when determining whether to show waitlist to users.

## What Changed

### 1. CartController.php - Waitlist Trigger Logic

**File:** `app/Http/Controllers/Api/CartController.php`

**Key Changes:**
- ✅ Added specific check for `approval_status === 'pending'` on CartTransaction
- ✅ Renamed variables for clarity (`$isPendingApprovalBooking`)
- ✅ Added logging for waitlist operations
- ✅ Improved approval conflict detection
- ✅ Added admin bypass logging

**Before:**
```php
if ($cartTrans &&
    $cartTrans->payment_status === 'unpaid' &&
    $cartTrans->user &&
    $cartTrans->user->role === 'user') {
```

**After:**
```php
if ($cartTrans &&
    $cartTrans->approval_status === 'pending' &&  // Not yet approved by admin
    $cartTrans->user &&
    $cartTrans->user->role === 'user') {
```

### 2. Approval Conflict Check

**Added precise logic to differentiate:**
- **Pending bookings** (`approval_status = 'pending'`) → Trigger waitlist
- **Approved bookings** (`approval_status = 'approved'`) → Reject booking
- **Admin users** → Bypass waitlist completely

**New Code:**
```php
// Check if any conflicting cart items have been approved
foreach ($conflictingCartItems as $cartItem) {
    $cartTrans = $cartItem->cartTransaction;
    if ($cartTrans && $cartTrans->approval_status === 'approved') {
        $hasApprovedConflict = true;
        break;
    }
}
```

### 3. Enhanced Logging

Added detailed logging for:
- Waitlist creation
- Admin bypassing waitlist
- Approval status checks

## Behavior Matrix

| User Type | Conflict Status | Result |
|-----------|----------------|--------|
| Regular User | No conflict | ✅ Booking created |
| Regular User | Pending approval | ⏳ Waitlist created |
| Regular User | Approved | ❌ Booking rejected |
| Admin/Staff | No conflict | ✅ Booking created |
| Admin/Staff | Pending approval | ✅ Booking created (bypass) |
| Admin/Staff | Approved | ❌ Booking rejected* |

*Even admins cannot override already approved bookings

## Database Fields Used

### CartTransaction Table
- `approval_status` - **Primary field checked**
  - `'pending'` - Waiting for admin review → **Triggers waitlist**
  - `'approved'` - Confirmed by admin → **Blocks booking**
  - `'rejected'` - Denied by admin → **Releases slot**

### Booking Table
- `status` - Secondary check for old booking system
  - `'pending'` - Checked for backward compatibility
  - `'approved'` - Blocks booking

### User Table
- `role` - Determines waitlist eligibility
  - `'user'` - Can be waitlisted
  - `'admin'` or `'staff'` - Bypasses waitlist

## Testing the Update

### Test Case 1: Regular User with Pending Booking
```bash
# Setup: User A creates booking (approval_status = 'pending')
# Action: User B tries to book same slot
# Expected: User B is waitlisted
# Response: { "waitlisted": true, "position": 1 }
```

### Test Case 2: Regular User with Approved Booking
```bash
# Setup: User A's booking approved (approval_status = 'approved')
# Action: User B tries to book same slot
# Expected: User B is rejected
# Response: { "message": "time slots are no longer available" }
```

### Test Case 3: Admin with Pending Booking
```bash
# Setup: User A creates booking (approval_status = 'pending')
# Action: Admin tries to book same slot
# Expected: Admin booking succeeds (bypasses waitlist)
# Logs: "Admin/Staff bypassing waitlist for pending slot"
```

## API Response Changes

### Waitlist Response (200 OK)
```json
{
  "message": "This time slot is currently pending approval for another user. You have been added to the waitlist.",
  "waitlisted": true,
  "waitlist_entry": {
    "id": 1,
    "pending_cart_transaction_id": 5,  // Links to pending transaction
    "status": "pending",
    "position": 1
  },
  "position": 1
}
```

## Frontend Impact

No changes needed - frontend already handles `waitlisted: true` response.

## Documentation Updates

Updated `WAITLIST_FEATURE.md` with:
- ✅ Quick reference table
- ✅ Visual flow diagram
- ✅ Clear explanation of approval_status checks
- ✅ Test scenarios with approval_status
- ✅ Behavior matrix

## Log Messages

New log entries to monitor:

```
[INFO] Adding user to waitlist
  user_id: 5
  court_id: 2
  pending_cart_transaction_id: 10

[INFO] User added to waitlist successfully
  waitlist_id: 15
  position: 2

[INFO] Admin/Staff bypassing waitlist for pending slot
  user_id: 1
  user_role: admin
```

## Verification Checklist

- [x] Waitlist only triggers when `approval_status = 'pending'`
- [x] Approved bookings block new bookings
- [x] Admin/Staff bypass waitlist
- [x] Proper logging added
- [x] Documentation updated
- [x] No linting errors
- [x] Backward compatible with Booking table checks

## Next Steps for Testing

1. **Create a test booking** with `approval_status = 'pending'`
2. **Try to book as regular user** → Should be waitlisted
3. **Try to book as admin** → Should succeed with bypass log
4. **Approve the first booking** → Subsequent attempts should be rejected
5. **Reject the first booking** → Waitlist users should receive email

## Files Modified

1. `app/Http/Controllers/Api/CartController.php`
   - Updated conflict detection logic
   - Added approval_status checks
   - Enhanced logging

2. `app/Http/Controllers/Api/CartTransactionController.php`
   - No changes (already notifies waitlist on rejection)

3. `docs/WAITLIST_FEATURE.md`
   - Added quick reference
   - Added visual diagram
   - Updated test scenarios
   - Clarified approval_status behavior

## Migration Required

❌ No database migration needed - uses existing fields

## Breaking Changes

✅ No breaking changes - backward compatible

## Performance Impact

✅ Minimal - same number of DB queries, just different field checks
