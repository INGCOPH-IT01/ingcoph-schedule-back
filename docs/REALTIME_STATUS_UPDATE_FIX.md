# Real-Time Booking Status Update Fix

## Problem
When an admin approved a booking, the status change was not displayed in real-time on the user's booking list. The popup notification appeared, but the list didn't update immediately.

## Root Causes

### 1. Missing User Data in LocalStorage
**Issue:** The `authService` only stored the authentication token, not the user object. The WebSocket composable needed the user ID to subscribe to the private channel `user.{userId}`.

**Solution:** Updated `authService.js` to store/update the user object in localStorage:
- On login
- On registration
- When fetching user data
- Remove on logout

**Files Changed:**
- `ingcoph-schedule-front/src/services/authService.js`

### 2. Missing WebSocket Broadcasts in Cart Transaction Approval
**Issue:** When admin approved a cart transaction via `CartTransactionController`, it updated the booking status but didn't broadcast any WebSocket events.

**Solution:** Added broadcast calls in `CartTransactionController` for both approval and rejection:
```php
// Broadcast real-time status change for each booking
foreach ($transaction->bookings as $booking) {
    broadcast(new BookingStatusChanged($booking->fresh(['user', 'court.sport']), 'pending', 'approved'))->toOthers();
}
```

**Files Changed:**
- `ingcoph-schedule-back/app/Http/Controllers/Api/CartTransactionController.php`

### 3. Missing Transaction ID in WebSocket Payload
**Issue:** The `BookingStatusChanged` event didn't include the `cart_transaction_id`, making it difficult to update the specific transaction in the frontend list.

**Solution:** Added `transaction_id` to the broadcast payload:
```php
'transaction_id' => $this->booking->cart_transaction_id
```

**Files Changed:**
- `ingcoph-schedule-back/app/Events/BookingStatusChanged.php`
- `ingcoph-schedule-back/app/Events/BookingCreated.php`

### 4. Frontend Wasn't Updating List Immediately
**Issue:** The `onOwnBookingStatusChanged` callback was calling `fetchBookings()` which refetched all data from the server, causing a delay.

**Solution:** Updated the callback to immediately update the specific transaction in the existing array before refetching:
```javascript
// Find and update the transaction in the list
const transactionIndex = transactions.value.findIndex(t => t.id === transactionId)
if (transactionIndex !== -1) {
  transactions.value[transactionIndex].approval_status = data.new_status
  // Also update cart items if they exist
  if (transactions.value[transactionIndex].cart_items) {
    transactions.value[transactionIndex].cart_items.forEach(item => {
      item.status = data.new_status
    })
  }
}
```

**Files Changed:**
- `ingcoph-schedule-front/src/views/Bookings.vue`

## How It Works Now

### When Admin Approves a Booking:

1. **Admin Side:**
   - Admin clicks "Approve" on a cart transaction
   - Frontend calls `/admin/cart-transactions/{id}/approve`

2. **Backend Processing:**
   - `CartTransactionController::approve()` updates the transaction and bookings
   - Broadcasts `BookingStatusChanged` event for each booking via WebSocket
   - Event includes `transaction_id`, `old_status`, `new_status`, and booking details

3. **Real-Time Update (User Side):**
   - User's browser receives WebSocket event on private channel `user.{userId}`
   - `onOwnBookingStatusChanged` callback is triggered
   - **Immediately** updates the specific transaction in the list
   - Shows popup notification: "Your booking has been approved!"
   - Shows toast notification
   - Refetches all bookings as backup

4. **Admin Dashboard:**
   - Also receives the event on public `bookings` channel
   - Refreshes statistics and pending bookings list

## Important Note for Users

**All currently logged-in users must logout and login again** for the WebSocket notifications to work properly. This is because their user data needs to be stored in localStorage.

## Testing Instructions

### 1. Test as User:
```bash
1. Logout (if already logged in)
2. Login with user credentials
3. Create a new booking (add to booking and checkout)
4. Keep the Bookings page open
```

### 2. Test as Admin:
```bash
1. In a different browser/incognito tab, login as admin
2. Go to Admin Dashboard
3. Click "Approve" on the user's pending transaction
```

### 3. Expected Result (User's Browser):
```
✅ Transaction status changes immediately from "pending" to "approved"
✅ Status badge updates in real-time (orange → green)
✅ Popup appears: "🎉 Real-Time Update - Your booking has been approved!"
✅ Toast notification appears in top-right
✅ All without manual page refresh
```

## Files Modified

### Backend:
1. `app/Events/BookingStatusChanged.php` - Added `transaction_id` to payload
2. `app/Events/BookingCreated.php` - Added `transaction_id` to payload
3. `app/Http/Controllers/Api/CartTransactionController.php` - Added WebSocket broadcasts for:
   - Cart transaction approval
   - Cart transaction rejection
   - QR code validation (check-in)
4. `app/Http/Controllers/Api/BookingController.php` - Already has broadcast for QR validation ✓

### Frontend:
1. `src/services/authService.js` - Store/remove user in localStorage
2. `src/views/Bookings.vue` - Immediate list update on status change

## Technical Details

### WebSocket Flow:
```
Admin Approves → CartTransactionController::approve()
                ↓
        Broadcast BookingStatusChanged
                ↓
        ┌───────────────┬───────────────┐
        ↓               ↓               ↓
   Public Channel   Private Channel   Admin Channel
   (bookings)       (user.{id})       (bookings)
        ↓               ↓               ↓
   All Admins    Specific User    All Admins
   Refresh Stats  Update + Popup   Refresh List
```

### Data Structure:
```javascript
// WebSocket Event Payload
{
  booking: {
    id: 123,
    transaction_id: 45,  // ← Key for finding transaction
    user_id: 1,
    court_id: 2,
    start_time: "2024-10-15 10:00:00",
    end_time: "2024-10-15 11:00:00",
    status: "approved",
    user: { id, name, email },
    court: { id, name, sport: {...} }
  },
  old_status: "pending",
  new_status: "approved",
  timestamp: "2024-10-15T10:30:00.000Z"
}
```

## Debugging

### Check Browser Console:
```javascript
// Should see these logs:
"Echo initialized with config: {...}"
"✅ Real-time listeners setup complete"
"📱 Your booking status changed"
"✅ Updated transaction status in real-time: 45 → approved"
```

### Check localStorage:
```javascript
// In browser console:
console.log(localStorage.getItem('user'))
// Should show: {"id":1,"name":"...","email":"..."}
```

### Check Backend Logs:
```bash
tail -f storage/logs/laravel.log
# Should see:
# [date] Cart transaction approved
# [date] Broadcasting: booking.status.changed
```

## Real-Time QR Code Validation

When staff validates a QR code (checks in a booking), the status also updates in real-time:

### Flow:
1. **Staff Side:**
   - Staff scans QR code
   - Frontend calls `/staff/cart-transactions/verify-qr` or `/bookings/validate-qr`

2. **Backend Processing:**
   - Updates transaction status to 'checked_in'
   - Updates booking status to 'completed'
   - Broadcasts `BookingStatusChanged` event

3. **Real-Time Update (User Side):**
   - User receives notification: "Your booking is completed"
   - Status updates from "approved" → "completed"
   - Visual feedback without page refresh

### Status Change Scenarios:
```
Admin Approval:     pending → approved  (Cart Transaction approval)
QR Check-in:        approved → completed (Staff validates QR)
Admin Rejection:    pending → rejected  (Admin rejects transaction)
```

All three scenarios now trigger real-time WebSocket broadcasts! 🎉

## Performance Note

The immediate list update provides a smooth user experience while the background `fetchBookings()` ensures data consistency. This is a "optimistic update" pattern commonly used in real-time applications.
