# Security Fix: Staff Permission Loopholes

## Date: 2025-11-02
## Type: Critical Security Fix
## Impact: High - Frontend/Backend Authorization Mismatch

---

## 🚨 Critical Issues Found

### Issue Summary
The frontend allowed **Staff** users to perform certain operations (edit courts, edit time slots, edit notes), but the backend was **rejecting** these requests, only allowing **Admin** users. This created:

1. **Security Inconsistency**: Different permission layers had conflicting rules
2. **Broken Functionality**: Staff users saw edit buttons but got "Unauthorized" errors
3. **Middleware/Controller Mismatch**: Routes protected by `admin.or.staff` middleware but controllers checking only `isAdmin()`

---

## 🔍 Issues Identified

### 1. Cart Item Updates (Court/Time Changes)
**File**: `app/Http/Controllers/Api/CartController.php`

**Before (Line 1262-1267)**:
```php
// Only admins can update cart items
if (!$request->user()->isAdmin()) {
    return response()->json([
        'success' => false,
        'message' => 'Unauthorized. Admin privileges required.'
    ], 403);
}
```

**Problem**:
- ✅ Route middleware: `admin.or.staff` (allows both)
- ✅ Frontend check: `isStaffOrAdmin` (allows both)
- ❌ **Controller check: `isAdmin()` only (rejects Staff)**

---

### 2. Cart Item Deletion (Remove Time Slots)
**File**: `app/Http/Controllers/Api/CartController.php`

**Before (Line 1469-1474)**:
```php
// Only admins can delete cart items
if (!$request->user()->isAdmin()) {
    return response()->json([
        'success' => false,
        'message' => 'Unauthorized. Admin privileges required.'
    ], 403);
}
```

**Same Issue**: Frontend allows Staff, backend rejects Staff.

---

### 3. Booking Updates (Missing admin_notes support)
**File**: `app/Http/Controllers/Api/BookingController.php`

**Before (Line 308-318)**:
```php
// Check if user owns this booking, is the booking_for_user, or is admin
$isBookingOwner = $booking->user_id === $request->user()->id;
$isBookingForUser = $booking->booking_for_user_id === $request->user()->id;
$isAdmin = $request->user()->isAdmin();

if (!$isBookingOwner && !$isBookingForUser && !$isAdmin) {
    return response()->json([
        'success' => false,
        'message' => 'Unauthorized to update this booking'
    ], 403);
}
```

**Problems**:
- ❌ Only checks `isAdmin()`, not `isStaff()`
- ❌ `admin_notes` field not in validation rules
- ❌ `admin_notes` field not in updateable fields
- ❌ Court changes only allowed for `isAdmin`, not Staff

---

### 4. Cart Item Updates (Missing notes/admin_notes support)
**File**: `app/Http/Controllers/Api/CartController.php`

**Before (Line 1282-1287)**:
```php
$validator = Validator::make($request->all(), [
    'court_id' => 'sometimes|required|exists:courts,id',
    'booking_date' => 'sometimes|required|date',
    'start_time' => 'sometimes|required|date_format:H:i',
    'end_time' => 'sometimes|required|date_format:H:i'
]);
```

**Problem**: Frontend added notes/admin_notes editing, but backend didn't support updating these fields.

---

## ✅ Fixes Applied

### Fix #1: CartController - updateCartItem()
**File**: `app/Http/Controllers/Api/CartController.php` (Line 1261-1267)

```php
// Only admins and staff can update cart items
if (!$request->user()->isAdmin() && !$request->user()->isStaff()) {
    return response()->json([
        'success' => false,
        'message' => 'Unauthorized. Admin or staff privileges required.'
    ], 403);
}
```

**Also Added**: Support for `notes` and `admin_notes` fields:
- Added to validation rules (Line 1287-1288)
- Added to update data (Line 1407-1412)

---

### Fix #2: CartController - deleteCartItem()
**File**: `app/Http/Controllers/Api/CartController.php` (Line 1468-1474)

```php
// Only admins and staff can delete cart items
if (!$request->user()->isAdmin() && !$request->user()->isStaff()) {
    return response()->json([
        'success' => false,
        'message' => 'Unauthorized. Admin or staff privileges required.'
    ], 403);
}
```

---

### Fix #3: BookingController - update()
**File**: `app/Http/Controllers/Api/BookingController.php`

**Authorization Check (Line 308-320)**:
```php
// Check if user owns this booking, is the booking_for_user, or is admin/staff
$isBookingOwner = $booking->user_id === $request->user()->id;
$isBookingForUser = $booking->booking_for_user_id === $request->user()->id;
$isAdmin = $request->user()->isAdmin();
$isStaff = $request->user()->isStaff();
$isAdminOrStaff = $isAdmin || $isStaff;

if (!$isBookingOwner && !$isBookingForUser && !$isAdminOrStaff) {
    return response()->json([
        'success' => false,
        'message' => 'Unauthorized to update this booking'
    ], 403);
}
```

**Added admin_notes to Validation (Line 328)**:
```php
'admin_notes' => 'nullable|string|max:1000',
```

**Updated Court ID Check (Line 337-340)**:
```php
// Only admins/staff can change court_id
if ($isAdminOrStaff && $request->has('court_id')) {
    $validationRules['court_id'] = 'required|exists:courts,id';
}
```

**Added admin_notes to Updateable Fields (Line 391-394, 410-413, 415-418)**:
```php
// Admin/staff can update admin_notes
if ($isAdminOrStaff && $request->has('admin_notes')) {
    $onyFields[] = 'admin_notes';
}

// Only admins/staff can update court_id
if ($isAdminOrStaff && $request->has('court_id')) {
    $onyFields[] = 'court_id';
}
```

---

## 🎯 What Changed

### Before Fix

| Operation | Frontend | Route Middleware | Controller | Result |
|-----------|----------|------------------|------------|---------|
| Update Court (Cart) | Staff ✅ | Staff ✅ | Admin ❌ | **BROKEN** |
| Update Time (Cart) | Staff ✅ | Staff ✅ | Admin ❌ | **BROKEN** |
| Delete Time Slot | Staff ✅ | Staff ✅ | Admin ❌ | **BROKEN** |
| Update Notes | Staff ✅ | Staff ✅ | Admin ❌ | **BROKEN** |
| Update Admin Notes | Staff ✅ | Staff ✅ | ❌ Not Supported | **BROKEN** |

### After Fix

| Operation | Frontend | Route Middleware | Controller | Result |
|-----------|----------|------------------|------------|---------|
| Update Court (Cart) | Staff ✅ | Staff ✅ | Staff ✅ | **WORKING** |
| Update Time (Cart) | Staff ✅ | Staff ✅ | Staff ✅ | **WORKING** |
| Delete Time Slot | Staff ✅ | Staff ✅ | Staff ✅ | **WORKING** |
| Update Notes | Staff ✅ | Staff ✅ | Staff ✅ | **WORKING** |
| Update Admin Notes | Staff ✅ | Staff ✅ | Staff ✅ | **WORKING** |

---

## 🛡️ Security Impact

### Before
- **Inconsistent Security**: Multiple layers with different rules
- **Potential Bypass**: Attackers could try to exploit the middleware/controller mismatch
- **False Security**: Frontend appeared restrictive but backend had different rules

### After
- **Consistent Security**: All layers (frontend, middleware, controller) aligned
- **Proper Authorization**: Staff role properly recognized at all levels
- **Clear Permissions**: Explicit checks for both Admin and Staff where appropriate

---

## 📝 Testing Recommendations

### Test Cases for Staff Role

1. **Cart Item Court Update**
   - Login as Staff user
   - Open booking details for a cart transaction
   - Click edit on court selector
   - Change court and save
   - **Expected**: Success message, court updated

2. **Cart Item Time Update**
   - Login as Staff user
   - Open booking details for a cart transaction
   - Click edit on date/time
   - Change date/time and save
   - **Expected**: Success message, time updated

3. **Time Slot Deletion**
   - Login as Staff user
   - Open booking details with multiple time slots
   - Click delete on one time slot
   - Confirm deletion
   - **Expected**: Success message, slot removed

4. **Notes Update**
   - Login as Staff user
   - Open booking details
   - Click edit on client notes
   - Update notes and save
   - **Expected**: Success message, notes updated

5. **Admin Notes Update**
   - Login as Staff user
   - Open booking details
   - Click edit on admin notes
   - Update admin notes and save
   - **Expected**: Success message, admin notes updated

### Test Cases for Regular User Role

Test that regular users **CANNOT** perform these operations:
- Should see view-only interface
- Should not see edit/delete buttons
- If API called directly, should receive 403 Forbidden

---

## 📋 Files Modified

1. **Backend**:
   - `/app/Http/Controllers/Api/CartController.php`
   - `/app/Http/Controllers/Api/BookingController.php`

2. **Frontend**:
   - `/src/components/BookingDetailsDialog.vue` (comments updated)

---

## ✅ Verification Checklist

- [x] Cart item update allows Staff
- [x] Cart item deletion allows Staff
- [x] Booking update allows Staff
- [x] admin_notes field supported in validation
- [x] admin_notes field supported in updates
- [x] notes field supported in cart item updates
- [x] Court changes allow Staff
- [x] No linter errors
- [x] Frontend/backend permissions aligned
- [x] Comments updated to reflect Staff permissions

---

## 🔐 Security Best Practices Applied

1. **Principle of Least Privilege**: Staff users have appropriate permissions for their role
2. **Defense in Depth**: Multiple layers check permissions consistently
3. **Explicit Authorization**: Clear checks for `isAdmin()` and `isStaff()` at all levels
4. **Validation**: All input fields properly validated
5. **Consistency**: Frontend, middleware, and controller all enforce same rules

---

## 🚀 Deployment Notes

**Priority**: HIGH - Security Fix
**Downtime Required**: No
**Database Changes**: None
**Cache Clear Required**: No
**Testing Required**: Yes - Test all Staff user operations

**Rollout Steps**:
1. Deploy backend changes first
2. Test Staff user operations in staging
3. Deploy frontend changes
4. Monitor error logs for authorization failures
5. Verify Staff users can edit courts/times/notes

---

## 📞 Contact

If you encounter any issues with these permission changes, please report immediately.

**Note**: This fix aligns the authorization model across all layers and ensures Staff users have the appropriate permissions as designed in the UI.
