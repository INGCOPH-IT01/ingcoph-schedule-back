# Booking For User Permissions

## Overview
When an Admin creates a booking on behalf of another user (using the "Booking For" feature), the selected user now has full access to manage their booking, including uploading proof of payment, viewing their booking details, and accessing their QR code.

## Changes Made

### 1. BookingController.php

#### uploadProofOfPayment() - Lines 339-349
**Before:** Only the booking creator (`user_id`) or admin could upload proof of payment.

**After:** The user selected in "Booking For" (`booking_for_user_id`) can also upload proof of payment.

```php
// Check if user owns this booking, is the booking_for_user, or is admin
$isBookingOwner = $booking->user_id === $request->user()->id;
$isBookingForUser = $booking->booking_for_user_id === $request->user()->id;
$isAdmin = $request->user()->role === 'admin';

if (!$isBookingOwner && !$isBookingForUser && !$isAdmin) {
    return response()->json([
        'success' => false,
        'message' => 'Unauthorized to upload proof for this booking'
    ], 403);
}
```

#### getProofOfPayment() - Lines 455-468
**Before:** Only booking owner, admin, or staff could view proof of payment.

**After:** The booking_for_user can also view proof of payment.

```php
// Check if user is authorized to view this proof
// Only the booking owner, booking_for_user, admin, or staff can view
$user = $request->user();
$isBookingOwner = $user->id === $booking->user_id;
$isBookingForUser = $user->id === $booking->booking_for_user_id;
$isAdmin = $user->role === 'admin';
$isStaff = $user->role === 'staff';

if (!$isBookingOwner && !$isBookingForUser && !$isAdmin && !$isStaff) {
    return response()->json([
        'success' => false,
        'message' => 'Unauthorized to view this proof of payment'
    ], 403);
}
```

#### update() - Lines 218-228
**Before:** Only booking owner or admin could update the booking.

**After:** The booking_for_user can also update their booking.

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

#### getQrCode() - Lines 991-1001
**Before:** Only booking owner or admin could access the QR code.

**After:** The booking_for_user can also access their booking QR code.

```php
// Check if user owns this booking, is the booking_for_user, or is admin
$isBookingOwner = $booking->user_id === $request->user()->id;
$isBookingForUser = $booking->booking_for_user_id === $request->user()->id;
$isAdmin = $request->user()->isAdmin();

if (!$isBookingOwner && !$isBookingForUser && !$isAdmin) {
    return response()->json([
        'success' => false,
        'message' => 'Unauthorized to access this booking'
    ], 403);
}
```

#### index() - Lines 30-34
**Already Implemented:** Users can already see bookings where they are the booking_for_user.

```php
if (!$request->user()->isAdmin()) {
    $query->where(function($q) use ($request) {
        $q->where('user_id', $request->user()->id)
          ->orWhere('booking_for_user_id', $request->user()->id);
    });
}
```

### 2. CartTransactionController.php

#### index() - Lines 18-35
**Before:** Only showed transactions where user was the owner.

**After:** Also shows transactions where user is the booking_for_user in any cart item.

```php
$userId = $request->user()->id;

// Get transactions where user is either the owner OR the booking_for_user in any cart item
$transactions = CartTransaction::with([...])
    ->where(function($query) use ($userId) {
        $query->where('user_id', $userId)
              ->orWhereHas('cartItems', function($q) use ($userId) {
                  $q->where('booking_for_user_id', $userId);
              });
    })
    ->whereIn('status', ['pending', 'completed'])
    ->orderBy('created_at', 'asc')
    ->get();
```

#### show() - Lines 50-57
**Before:** Only transaction owner or admin/staff could view transaction details.

**After:** booking_for_user can also view the transaction details.

```php
// Check if user owns this transaction, is the booking_for_user in any cart item, or is admin/staff
$isOwner = $transaction->user_id === $request->user()->id;
$isBookingForUser = $transaction->cartItems()->where('booking_for_user_id', $request->user()->id)->exists();
$isAdminOrStaff = in_array($request->user()->role, ['admin', 'staff']);

if (!$isOwner && !$isBookingForUser && !$isAdminOrStaff) {
    return response()->json(['message' => 'Unauthorized'], 403);
}
```

#### getProofOfPayment() - Lines 385-409
**Before:** Only transaction owner, admin, or staff could view proof.

**After:** booking_for_user can also view the proof of payment.

```php
// Check if user is authorized to view this proof
// Only the transaction owner, booking_for_user in any cart item, admin, or staff can view
$user = $request->user();
$isOwner = $user->id === $transaction->user_id;
$isBookingForUser = $transaction->cartItems()->where('booking_for_user_id', $user->id)->exists();
$isAdmin = $user->role === 'admin';
$isStaff = $user->role === 'staff';

if (!$isOwner && !$isBookingForUser && !$isAdmin && !$isStaff) {
    return response()->json([
        'success' => false,
        'message' => 'Unauthorized to view this proof of payment'
    ], 403);
}
```

## Use Case Example

### Scenario: Admin creates booking for regular user

1. **Admin (ID: 1)** creates a booking for **User John (ID: 5)**
   - Booking data stored:
     ```php
     [
         'user_id' => 1,                    // Admin who created it
         'booking_for_user_id' => 5,        // John's ID
         'booking_for_user_name' => 'John Doe',
         'payment_status' => 'unpaid',
         // ... other fields
     ]
     ```

2. **John (User ID: 5)** logs in to his account
   - ✅ Can view the booking in his bookings list
   - ✅ Can upload proof of payment
   - ✅ Can view the proof of payment after uploading
   - ✅ Can update booking details (reschedule, etc.)
   - ✅ Can access the QR code once booking is approved
   - ✅ Can view cart transaction details if booking was created via cart

3. **Admin (User ID: 1)** retains full access
   - ✅ Can view all bookings
   - ✅ Can approve/reject bookings
   - ✅ Can view all proof of payments
   - ✅ Full administrative access

## API Endpoints Affected

All these endpoints now support booking_for_user authorization:

- `POST /api/bookings/{id}/proof-of-payment` - Upload proof of payment
- `GET /api/bookings/{id}/proof-of-payment` - View proof of payment
- `PUT /api/bookings/{id}` - Update booking
- `GET /api/bookings/{id}/qr-code` - Get booking QR code
- `GET /api/bookings` - List bookings (already supported)
- `GET /api/cart-transactions` - List cart transactions
- `GET /api/cart-transactions/{id}` - View cart transaction details
- `GET /api/cart-transactions/{id}/proof-of-payment` - View cart transaction proof

## Testing Recommendations

1. **Test as Admin:**
   - Create booking for another user
   - Verify booking appears in that user's list
   - Verify admin still has access

2. **Test as booking_for_user:**
   - Log in as the user the booking was created for
   - Upload proof of payment
   - View proof of payment
   - Update booking details
   - Access QR code after approval

3. **Test security:**
   - Verify unrelated users cannot access bookings they're not involved in
   - Verify booking_for_user cannot access admin-only features

## Database Schema

No database changes were required. The existing fields are used:

**bookings table:**
- `user_id` - Admin who created the booking
- `booking_for_user_id` - User the booking is for (nullable)
- `booking_for_user_name` - Display name (from dropdown or custom)

**cart_items table:**
- `user_id` - Admin who created the cart item
- `booking_for_user_id` - User the booking is for (nullable)
- `booking_for_user_name` - Display name (from dropdown or custom)

## Notes

- All authorization checks follow a consistent pattern: check owner → check booking_for_user → check admin/staff
- The booking_for_user has the same level of access as the booking owner for managing their own bookings
- Admin-only operations (approve/reject, stats, etc.) remain restricted to admin users only
- This implementation maintains backward compatibility with existing bookings that don't have a booking_for_user_id set
