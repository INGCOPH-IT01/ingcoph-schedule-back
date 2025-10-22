# Booking Expiration Policy Update

## Summary
Updated the booking expiration logic to ensure that only User role bookings **without proof of payment** are subject to the 1-hour expiration rule. Bookings with uploaded proof of payment or approved bookings will not expire, regardless of who created them.

## Changes Made

### 1. Updated `CancelExpiredCartItems` Command
**File:** `app/Console/Commands/CancelExpiredCartItems.php`

Added checks to skip expiration for:
- Transactions with uploaded proof of payment
- Transactions that have been approved (approval_status === 'approved')

```php
// Skip bookings with proof of payment - they should not expire
if ($transaction->proof_of_payment) {
    $skippedAdminCount++;
    continue;
}

// Skip approved bookings - approval should not expire
if ($transaction->approval_status === 'approved') {
    $skippedAdminCount++;
    continue;
}
```

### 2. Updated `CartController` - Expiration Check
**File:** `app/Http/Controllers/Api/CartController.php`
**Method:** `checkAndExpireCartItems()`

Added the same checks to the method that automatically expires cart items:
- Skip transactions with uploaded proof of payment
- Skip transactions that have been approved

```php
// Skip bookings with proof of payment - they should not expire
if ($transaction->proof_of_payment) {
    continue;
}

// Skip approved bookings - approval should not expire
if ($transaction->approval_status === 'approved') {
    continue;
}
```

### 3. Updated `CartController` - Expiration Info API
**File:** `app/Http/Controllers/Api/CartController.php`
**Method:** `getExpirationInfo()`

Added logic to return "No expiration" responses for:
- Transactions with uploaded proof of payment
- Transactions that have been approved

```php
// Check if transaction has proof of payment - no expiration
if ($cartTransaction->proof_of_payment) {
    return response()->json([
        'success' => true,
        'has_transaction' => true,
        'is_admin' => false,
        'has_proof_of_payment' => true,
        'expires_at' => null,
        'time_remaining_seconds' => null,
        'time_remaining_formatted' => 'No expiration (Proof of payment uploaded)',
        'is_expired' => false
    ]);
}

// Check if transaction is approved - no expiration
if ($cartTransaction->approval_status === 'approved') {
    return response()->json([
        'success' => true,
        'has_transaction' => true,
        'is_admin' => false,
        'is_approved' => true,
        'expires_at' => null,
        'time_remaining_seconds' => null,
        'time_remaining_formatted' => 'No expiration (Approved)',
        'is_expired' => false
    ]);
}
```

## Expiration Rules (Updated)

### Bookings That DO NOT Expire:
1. ✅ Bookings created by Admin or Staff users
2. ✅ Bookings with uploaded proof of payment (regardless of who created them)
3. ✅ Bookings that have been approved (regardless of who created them)

### Bookings That DO Expire (1-hour rule):
1. ⏰ User role bookings **without** proof of payment uploaded
2. ⏰ User role bookings that are still in **pending** approval status

## Business Logic
The 1-hour expiration time is calculated based on business hours (8 AM - 5 PM, Monday - Saturday, excluding holidays) as defined in `BusinessHoursHelper.php`. This remains unchanged.

## Impact
- Users who upload proof of payment will no longer see their bookings expire after 1 hour
- Once a booking is approved by admin/staff, it will not expire
- Only pending user bookings without proof of payment are subject to the 1-hour expiration rule
- This provides a better user experience and prevents approved/paid bookings from being automatically cancelled

## Testing Recommendations
1. Test User booking without proof of payment → should expire after 1 hour
2. Test User booking with proof of payment → should NOT expire
3. Test User booking after approval → should NOT expire
4. Test Admin/Staff booking → should NOT expire (existing behavior)
5. Verify the `getExpirationInfo()` API returns correct expiration status for each scenario
6. Run the `php artisan cart:cancel-expired` command and verify correct bookings are expired

## Files Modified
- `app/Console/Commands/CancelExpiredCartItems.php`
- `app/Http/Controllers/Api/CartController.php`

## DRY Refactoring (Update)

After implementing the expiration policy changes, the code was further refactored to follow DRY (Don't Repeat Yourself) principles. All expiration logic has been centralized into universal helper methods in `BusinessHoursHelper`:

### New Universal Methods:
1. **`isExemptFromExpiration($transaction)`** - Checks if a transaction is exempt from expiration
2. **`shouldExpire($transaction, ?Carbon $checkTime = null)`** - Comprehensive check combining exemption and time-based expiration

### Benefits:
- Single source of truth for expiration logic
- Eliminated ~100+ lines of duplicate code
- Easier to test and maintain
- Better code readability

See `DRY_EXPIRATION_REFACTOR.md` for complete documentation on the refactored code and usage examples.

## Date
October 22, 2025
