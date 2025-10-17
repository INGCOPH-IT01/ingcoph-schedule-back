# Fix for 403 Error on View Attachment Button

## Problem
The "View Attachment" button in the BookingDetailsDialog was returning a 403 error when trying to view proof of payment images. This occurred because the frontend was trying to access storage files directly via URL without proper authentication.

## Root Cause
- Proof of payment images are stored in Laravel's `storage/app/public` directory
- The frontend was trying to access them via direct URL: `{API_URL}/storage/{path}`
- While the storage symlink exists, image tags cannot send authentication headers
- This caused a 403 Forbidden error when accessing protected files

## Solution Implemented

### Backend Changes

#### 1. BookingController.php
Added a new method `getProofOfPayment()` to serve proof of payment images securely:

**Location:** `app/Http/Controllers/Api/BookingController.php` (line 400-448)

**Features:**
- Authenticates the user before serving the image
- Checks authorization (only booking owner, admin, or staff can view)
- Returns the file with proper headers
- Handles missing files gracefully
- Adds caching headers for better performance

```php
public function getProofOfPayment(Request $request, $id)
{
    $booking = Booking::find($id);

    // Authorization checks...

    $path = storage_path('app/public/' . $booking->proof_of_payment);

    return response()->file($path, [
        'Content-Type' => mime_content_type($path),
        'Cache-Control' => 'public, max-age=3600'
    ]);
}
```

#### 2. CartTransactionController.php
Added the same method for cart transactions:

**Location:** `app/Http/Controllers/Api/CartTransactionController.php` (line 359-407)

This ensures that both regular bookings and cart transactions can securely serve proof of payment images.

#### 3. API Routes
Added new routes in `routes/api.php`:

```php
// Proof of payment routes
Route::get('/bookings/{id}/proof-of-payment', [BookingController::class, 'getProofOfPayment']);
Route::get('/cart-transactions/{id}/proof-of-payment', [CartTransactionController::class, 'getProofOfPayment']);
```

**Note:** These routes are inside the `auth:sanctum` middleware group, ensuring only authenticated users can access them.

### Frontend Changes

#### BookingDetailsDialog.vue
Updated the proof of payment viewing logic:

**Location:** `src/components/BookingDetailsDialog.vue`

**Changes:**
1. **Async Image Loading** (line 703-727):
   - Changed `viewProofOfPayment()` to async function
   - Uses axios to fetch image as blob with authentication headers
   - Creates a blob URL for display in the img tag
   - Handles errors gracefully

```javascript
const viewProofOfPayment = async () => {
  const endpoint = isTransaction.value
    ? `/cart-transactions/${props.booking.id}/proof-of-payment`
    : `/bookings/${props.booking.id}/proof-of-payment`

  const response = await api.get(endpoint, {
    responseType: 'blob'
  })

  const imageBlob = new Blob([response.data], { type: response.headers['content-type'] })
  selectedImageUrl.value = URL.createObjectURL(imageBlob)
  imageDialog.value = true
}
```

2. **Memory Management** (line 499-507, 745-751):
   - Added cleanup in `closeDialog()` to revoke blob URLs
   - Added `onImageDialogClose()` handler for proper cleanup
   - Prevents memory leaks from blob URLs

3. **Smart Endpoint Selection**:
   - Automatically selects the correct endpoint based on whether the booking is a transaction or regular booking
   - Uses the `isTransaction` computed property for detection

## Security Improvements

1. **Authentication Required**: All image access now requires a valid authentication token
2. **Authorization Checks**: Users can only view proof of payment for:
   - Their own bookings/transactions
   - Or if they are admin/staff
3. **No Direct File Access**: Files are served through controlled endpoints, not directly from storage
4. **Proper Error Handling**: Returns appropriate HTTP status codes (404, 403, etc.)

## Testing Checklist

- [x] Storage symlink exists in `public/storage`
- [x] Backend route added for bookings proof of payment
- [x] Backend route added for cart transactions proof of payment
- [x] Frontend displays images with authentication
- [x] Memory cleanup for blob URLs implemented
- [ ] Test viewing proof of payment as booking owner
- [ ] Test viewing proof of payment as admin
- [ ] Test viewing proof of payment as staff
- [ ] Test 403 error for unauthorized users
- [ ] Test 404 error for missing images
- [ ] Test download functionality

## How to Test

1. **As Admin/Staff:**
   - Log in as admin or staff user
   - Navigate to Bookings page
   - Click on a booking with proof of payment
   - Click "View Attachment" button
   - Image should display without 403 error

2. **As Regular User:**
   - Log in as regular user
   - View your own booking with proof of payment
   - Click "View Attachment" button
   - Image should display
   - Try viewing another user's booking (should get 403 if not authorized)

3. **Download Test:**
   - View a proof of payment image
   - Click the "Download" button
   - Image should download correctly

## Files Modified

### Backend
1. `app/Http/Controllers/Api/BookingController.php`
2. `app/Http/Controllers/Api/CartTransactionController.php`
3. `routes/api.php`

### Frontend
1. `src/components/BookingDetailsDialog.vue`

## Additional Notes

- The solution uses blob URLs to allow img tags to display authenticated images
- Blob URLs are automatically cleaned up when dialogs are closed to prevent memory leaks
- The storage symlink still exists as a backup for public assets
- Cache headers are set to reduce server load for frequently accessed images
- The solution works for both regular bookings and cart transactions

## Future Enhancements

Potential improvements for future iterations:

1. Add loading spinner while fetching image
2. Add image zoom functionality
3. Add support for multiple proof of payment images
4. Add image preview thumbnails in the booking list
5. Add image compression on upload to reduce storage
