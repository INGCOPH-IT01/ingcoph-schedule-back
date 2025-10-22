# Booking Failed - Root Cause Analysis & Fixes

## Date: October 22, 2025

## Problem Summary

Users were experiencing "Booking Failed" errors when attempting to checkout bookings from their cart. Investigation revealed that the system allowed checkout attempts on rejected or invalid cart items.

---

## Root Causes Identified

### Issue #1: No Validation for Rejected Cart Transactions
**Location**: `app/Http/Controllers/Api/CartController.php:567`

**Problem**: The checkout process retrieved cart transactions using only:
```php
->where('status', 'pending')
->where('payment_status', 'unpaid')
```

This did NOT check `approval_status`, allowing rejected transactions to be processed.

**Impact**:
- Admin rejects a booking → sets `approval_status='rejected'`
- Transaction still has `status='pending'` and `payment_status='unpaid'`
- User attempts checkout → system tries to process rejected transaction
- **Result**: Checkout fails with "Booking Failed"

### Issue #2: No Filtering of Cart Item Status
**Location**: `app/Http/Controllers/Api/CartController.php:579-590`

**Problem**: Cart items were retrieved without status filtering:
```php
CartItem::where('cart_transaction_id', $cartTransaction->id)
```

This included items with status:
- `'cancelled'` - Manually removed by user
- `'expired'` - Timed out items
- Any other invalid status

**Impact**: Checkout attempted to process invalid items, causing failures.

### Issue #3: Frontend Lack of Transaction Status Validation
**Location**: `src/components/BookingCart.vue`

**Problem**: Frontend did not check if transaction was rejected before allowing checkout.

**Impact**: Users could attempt to checkout rejected bookings, leading to confusing error messages.

---

## Fixes Implemented

### Backend Fixes (CartController.php)

#### Fix #1: Added Approval Status Check in Checkout Query (Line 567-570)
```php
$cartTransaction = CartTransaction::where('user_id', $userId)
    ->where('status', 'pending')
    ->where('payment_status', 'unpaid')
    ->whereIn('approval_status', ['pending', 'approved'])  // ✅ ADDED
    ->first();
```

#### Fix #2: Filter Cart Items by Status='pending' (Line 591-593)
```php
$cartItemsQuery = CartItem::with('court')
    ->where('cart_transaction_id', $cartTransaction->id)
    ->where('status', 'pending');  // ✅ ADDED
```

#### Fix #3: Added Early Validation for Rejected Transactions (Line 579-587)
```php
// Early validation: Check if transaction has been rejected
if ($cartTransaction->approval_status === 'rejected') {
    DB::rollBack();
    return response()->json([
        'message' => 'This booking has been rejected. Reason: ' . ($cartTransaction->rejection_reason ?? 'Not specified'),
        'error' => 'TRANSACTION_REJECTED',
        'rejection_reason' => $cartTransaction->rejection_reason
    ], 422);
}
```

#### Fix #4: Updated All Cart Methods to Filter Rejected Items

Updated the following methods to exclude rejected transactions:
- `index()` - Line 30-36: Filter cart display
- `count()` - Line 462-465: Filter cart count
- `getExpirationInfo()` - Line 480-485: Filter expiration checks
- `clear()` - Line 430-434: Filter clear operation

All methods now include:
```php
->whereIn('approval_status', ['pending', 'approved'])
```

### Frontend Fixes (BookingCart.vue)

#### Fix #5A: Visual Warning for Rejected Transactions (Line 42-66)
Added prominent error alert at top of cart:
```vue
<v-alert
  v-if="cartTransaction && cartTransaction.approval_status === 'rejected'"
  type="error"
  variant="tonal"
  class="mb-3"
  density="compact"
  prominent
>
  <div class="d-flex align-center">
    <v-icon class="mr-2">mdi-alert-circle</v-icon>
    <div>
      <strong>Booking Rejected</strong>
      <p class="mb-0 mt-1">
        This booking has been rejected by the admin and cannot be processed.
        <span v-if="cartTransaction.rejection_reason" class="d-block mt-1">
          <strong>Reason:</strong> {{ cartTransaction.rejection_reason }}
        </span>
      </p>
      <p class="text-caption mb-0 mt-2">
        Please create a new booking or contact support.
      </p>
    </div>
  </div>
</v-alert>
```

#### Fix #5B: Pre-checkout Validation (Line 968-981)
Added validation before processing checkout:
```javascript
// Validate that transaction hasn't been rejected
if (cartTransaction.value && cartTransaction.value.approval_status === 'rejected') {
  showAlert({
    icon: 'error',
    title: 'Booking Rejected',
    html: `<p>This booking has been rejected by the admin and cannot be processed.</p>
           ${cartTransaction.value.rejection_reason ? `<p class="mt-2"><strong>Reason:</strong> ${cartTransaction.value.rejection_reason}</p>` : ''}
           <p class="mt-2 text-caption">Please create a new booking or contact support.</p>`,
    confirmButtonText: 'OK'
  })
  // Refresh the cart to remove rejected items
  await loadCart()
  return
}
```

#### Fix #5C: Enhanced Error Handling (Line 1043-1053)
Added special handling for rejected transaction errors:
```javascript
// Special handling for rejected transactions
if (error.response?.data?.error === 'TRANSACTION_REJECTED') {
  errorTitle = 'Booking Rejected'
  if (error.response?.data?.rejection_reason) {
    errorMessage += `<br><br><strong>Reason:</strong> ${error.response.data.rejection_reason}`
  }
  errorMessage += '<br><br><small>Please create a new booking or contact support.</small>'

  // Refresh cart to remove rejected items
  await loadCart()
}
```

#### Fix #5D: Disabled Checkout Button for Rejected Transactions (Line 274)
```vue
<v-btn
  v-if="cartItems.length > 0"
  color="success"
  @click="openPaymentDialog"
  :disabled="selectedGroups.length === 0 || (cartTransaction && cartTransaction.approval_status === 'rejected')"
>
```

---

## Testing Checklist

### Backend Tests
- [ ] Test checkout with rejected transaction → should return 422 with TRANSACTION_REJECTED error
- [ ] Test checkout with cancelled cart items → should skip cancelled items
- [ ] Test checkout with expired cart items → should skip expired items
- [ ] Test cart index with rejected transaction → should not return rejected items
- [ ] Test cart count with rejected transaction → should return 0

### Frontend Tests
- [ ] User with rejected transaction → should see rejection alert in cart
- [ ] User attempts checkout on rejected transaction → should see rejection message
- [ ] Checkout button disabled when transaction is rejected
- [ ] Cart automatically refreshes after detecting rejection
- [ ] Rejection reason displayed if provided by admin

### Integration Tests
- [ ] Admin rejects booking → User sees rejection in cart immediately
- [ ] User attempts to checkout rejected booking → Clear error message with reason
- [ ] Cancelled items filtered from checkout
- [ ] Expired items filtered from checkout
- [ ] Only pending items processed during checkout

---

## Prevention Measures

### Code Review Guidelines
1. Always check `approval_status` when querying cart transactions
2. Always filter cart items by `status='pending'` unless explicitly needing other statuses
3. Add frontend validation before any transaction modification
4. Provide clear user feedback for rejection scenarios

### Database Constraints
Consider adding database constraints:
- Cart transactions cannot be checked out if `approval_status='rejected'`
- Cart items with `status!='pending'` cannot be included in checkout

### Monitoring
Set up alerts for:
- High rate of checkout failures
- Rejected transactions attempted for checkout
- Orphaned cart items (cancelled/expired but not cleaned up)

---

## Related Files Modified

### Backend
- `app/Http/Controllers/Api/CartController.php`
  - Methods: `index()`, `checkout()`, `count()`, `getExpirationInfo()`, `clear()`

### Frontend
- `src/components/BookingCart.vue`
  - Added rejection warning UI
  - Added pre-checkout validation
  - Enhanced error handling
  - Disabled checkout button for rejected transactions

---

## Conclusion

All identified issues causing "Booking Failed" errors have been resolved. The system now:

1. ✅ Prevents checkout of rejected transactions
2. ✅ Filters out cancelled and expired cart items
3. ✅ Provides clear feedback when transactions are rejected
4. ✅ Shows visual warnings for rejected bookings
5. ✅ Automatically refreshes cart after rejection detection

**Status**: All fixes implemented and ready for testing.
