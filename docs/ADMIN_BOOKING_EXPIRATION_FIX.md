# Admin Booking Expiration Fix

## Overview
This fix ensures that admin bookings are **not subject to automatic expiration** even when no payment proof is uploaded. This is crucial because admins often handle walk-in customers who pay cash or require more flexible payment arrangements.

## Problem
Previously, both user bookings and admin bookings would expire after 1 hour if:
- Payment status was `unpaid`
- Booking status was `pending`
- Created more than 1 hour ago

This caused issues for:
- Walk-in customers booked by admins who pay cash
- Admin bookings that need manual payment verification
- Special arrangements made by admins for VIP customers

## Solution
Admin bookings are now **exempted from automatic expiration**. The system checks if the booking creator (`user_id`) is an admin, and if so, skips the expiration logic.

## Changes Made

### 1. Backend - Cart Expiration Command
**File:** `app/Console/Commands/CancelExpiredCartItems.php`

**Changes:**
- Added `.with('user')` to eager load the user relationship
- Added admin check before expiring transactions
- Skips expiration if `transaction->user->isAdmin()` returns true
- Logs skipped admin transactions
- Added counter for skipped admin transactions

```php
// Skip admin bookings - they should not expire automatically
if ($transaction->user && $transaction->user->isAdmin()) {
    $skippedAdminCount++;
    Log::info("Skipped admin cart transaction #{$transaction->id} from expiration");
    continue;
}
```

### 2. Backend - Cart Controller
**File:** `app/Http/Controllers/Api/CartController.php`

**Method:** `checkAndExpireCartItems()`

**Changes:**
- Added `.with('user')` to eager load the user relationship
- Added admin check before expiring transactions
- Skips expiration if transaction belongs to admin
- Updated method documentation

```php
// Skip admin bookings - they should not expire automatically
if ($transaction->user && $transaction->user->isAdmin()) {
    Log::info("Skipped admin cart transaction #{$transaction->id} from expiration");
    continue;
}
```

### 3. Backend - Booking Model
**File:** `app/Models/Booking.php`

**Added Method:** `isAdminBooking()`

This new helper method checks if a booking was created by an admin:

```php
/**
 * Check if this booking was created by an admin
 * Returns true if the booking creator (user_id) is an admin
 */
public function isAdminBooking(): bool
{
    return $this->user && $this->user->isAdmin();
}
```

**Usage:** Can be used in other parts of the system to check if a booking is admin-created.

### 4. Frontend - Bookings View
**File:** `src/views/Bookings.vue`

**Function:** `isBookingExpired()`

**Changes:**
- Added admin check at the beginning of the function
- Returns `false` immediately if booking creator is admin
- Prevents expired UI state for admin bookings

```javascript
const isBookingExpired = (booking) => {
  // Admin bookings should not expire automatically
  if (booking.user && booking.user.role === 'admin') {
    return false
  }

  // ... rest of expiration logic
}
```

### 5. Frontend - Booking Cart Component
**File:** `src/components/BookingCart.vue`

**Changes:**
- Added `authService` import
- Added `currentUser` ref to track current user
- Added `onMounted` hook to load current user
- Updated `updateExpirationTimer()` to show "No expiration (Admin)" for admin users

```javascript
// Admin bookings should not expire - no timer needed
if (currentUser.value && currentUser.value.role === 'admin') {
  timeRemaining.value = 'No expiration (Admin)'
  expirationWarning.value = false
  return
}
```

## User Experience Changes

### For Regular Users
- **No change** - bookings still expire after 1 hour if unpaid
- Expiration timer continues to work as before
- Expired bookings show "Expired" status

### For Admin Users
- ✅ **No expiration** - admin cart transactions never expire
- ✅ **Timer shows** "No expiration (Admin)" instead of countdown
- ✅ **No warning** messages about expiration
- ✅ **Bookings remain active** until manually cancelled or approved

## Testing

### Test Case 1: Admin Booking Without Payment
1. Login as admin
2. Create booking for a customer
3. Do not upload payment proof
4. Wait more than 1 hour
5. **Expected:** Booking should still be active (not expired)

### Test Case 2: Regular User Booking Without Payment
1. Login as regular user
2. Create booking
3. Do not upload payment proof
4. Wait more than 1 hour
5. **Expected:** Booking should expire and slots become available

### Test Case 3: Admin Cart Timer
1. Login as admin
2. Add items to cart
3. Check cart timer
4. **Expected:** Timer shows "No expiration (Admin)"

### Test Case 4: Regular User Cart Timer
1. Login as regular user
2. Add items to cart
3. Check cart timer
4. **Expected:** Timer shows countdown (e.g., "59m 30s")

## Scheduled Commands

The expiration logic is triggered by:
1. **Scheduled Command:** `cart:cancel-expired` (runs via scheduler)
2. **On-Demand Check:** When user loads their cart (via `checkAndExpireCartItems()`)

Both now properly skip admin transactions.

## Database Implications

No database migrations required. The fix uses existing fields:
- `users.role` - to check if user is admin
- `cart_transactions.user_id` - to identify who created the transaction
- `bookings.user_id` - to identify who created the booking

## Benefits

1. ✅ **Flexibility for Admins** - No rush to process walk-in customer payments
2. ✅ **Cash Payments** - Admins can accept cash and approve later
3. ✅ **VIP Treatment** - Special arrangements without system interference
4. ✅ **Manual Control** - Admins have full control over their bookings
5. ✅ **No Data Loss** - Important admin bookings won't accidentally expire

## Important Notes

- Admin bookings must still be **manually approved** or **cancelled**
- This does **not** auto-approve admin bookings
- Admins should still process bookings in reasonable time
- Regular users' bookings still expire to prevent abuse
- Expired bookings free up slots for other users

## Related Files

### Backend
- `app/Console/Commands/CancelExpiredCartItems.php` - Scheduled expiration command
- `app/Http/Controllers/Api/CartController.php` - On-demand expiration check
- `app/Models/Booking.php` - Booking model with helper method
- `app/Models/User.php` - User model with `isAdmin()` method
- `app/Models/CartTransaction.php` - Cart transaction model

### Frontend
- `src/views/Bookings.vue` - Bookings list view
- `src/components/BookingCart.vue` - Cart component with timer
- `src/services/authService.js` - Auth service for user info

## Future Enhancements

Potential improvements:
1. Add admin dashboard widget showing unpaid admin bookings
2. Add configurable expiration time for different user roles
3. Add email reminders for pending admin bookings
4. Add bulk approval feature for admin bookings
5. Add payment status filter in admin dashboard
