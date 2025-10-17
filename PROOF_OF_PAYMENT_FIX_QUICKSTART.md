# Quick Start: Testing the Proof of Payment 403 Fix

## What Was Fixed
The "View Attachment" button in booking details was returning a 403 error. This has been fixed by implementing secure API endpoints for serving proof of payment images.

## Quick Test Steps

### 1. Start the Backend
```bash
cd /path/to/ingcoph-schedule-back
php artisan serve
```

### 2. Start the Frontend
```bash
cd /path/to/ingcoph-schedule-front
npm run dev
```

### 3. Test the Fix

#### Test as Admin:
1. Log in to the application as an admin user
2. Go to the **Bookings** page
3. Find a booking that has a proof of payment (look for bookings with payment method set)
4. Click on the booking to open the details dialog
5. Look for the **"View Attachment"** button under Payment Information
6. Click **"View Attachment"**
7. ✅ **Expected Result:** Image dialog opens showing the proof of payment (no 403 error)
8. Click **"Download"** to test the download functionality
9. ✅ **Expected Result:** Image downloads successfully

#### Test as Regular User:
1. Log out and log in as a regular user
2. Make a booking and upload proof of payment
3. View your booking details
4. Click **"View Attachment"**
5. ✅ **Expected Result:** Your proof of payment displays correctly

## What Changed

### Backend
- ✅ Added `/api/bookings/{id}/proof-of-payment` endpoint
- ✅ Added `/api/cart-transactions/{id}/proof-of-payment` endpoint
- ✅ Both endpoints require authentication
- ✅ Authorization checks ensure only authorized users can view

### Frontend
- ✅ Updated `BookingDetailsDialog.vue` to fetch images as blobs
- ✅ Blob URLs are created for display with authentication
- ✅ Memory cleanup implemented to prevent leaks

## Troubleshooting

### Still Getting 403 Error?
1. **Check if you're logged in:** The endpoints require authentication
2. **Check the browser console:** Look for any CORS or network errors
3. **Verify the backend is running:** Make sure Laravel is serving on the correct port
4. **Check API URL:** Verify `VITE_API_URL` in `.env` matches your backend URL

### Image Not Displaying?
1. **Check if proof of payment exists:** Look at the booking data in database
2. **Verify file exists:** Check `storage/app/public/proofs/` directory
3. **Check file permissions:** Ensure storage directory is writable
4. **Browser console:** Look for blob URL or network errors

### No "View Attachment" Button?
1. **Check if booking has proof_of_payment:** The button only shows if proof exists
2. **Verify you're an admin/staff:** Some features are admin-only
3. **Check the booking has payment_method set:** Required field

## API Endpoint Details

### GET /api/bookings/{id}/proof-of-payment
**Authentication:** Required (Bearer token)

**Authorization:**
- Booking owner
- Admin users
- Staff users

**Response:**
- Success: Image file (JPEG, PNG, etc.)
- 403: Unauthorized
- 404: Booking or file not found

### GET /api/cart-transactions/{id}/proof-of-payment
**Authentication:** Required (Bearer token)

**Authorization:**
- Transaction owner
- Admin users
- Staff users

**Response:**
- Success: Image file (JPEG, PNG, etc.)
- 403: Unauthorized
- 404: Transaction or file not found

## Need Help?

If you encounter any issues:

1. Check the browser console for JavaScript errors
2. Check Laravel logs: `storage/logs/laravel.log`
3. Verify authentication token is being sent in requests
4. Ensure storage symlink exists: `php artisan storage:link`

## Complete Documentation

For detailed information about the fix, see: `PROOF_OF_PAYMENT_403_FIX.md`
