# Real-Time QR Code Validation - Test Guide

## Overview
When staff validates a QR code, the user's booking list now updates in **real-time** with instant visual feedback!

## What Was Added

### CartTransactionController::verifyQr()
Added WebSocket broadcast when QR code is validated:
```php
// Broadcast real-time status change for each booking
foreach ($transaction->bookings as $booking) {
    broadcast(new BookingStatusChanged($booking->fresh(['user', 'court.sport']), 'approved', 'completed'))->toOthers();
}
```

## Complete Real-Time Flow

### All Status Changes Now Broadcast:
1. ✅ **Admin Approves** → `pending` → `approved`
2. ✅ **Staff Scans QR** → `approved` → `completed`
3. ✅ **Admin Rejects** → `pending` → `rejected`

## Testing QR Code Real-Time Updates

### Setup (3 Devices/Windows)
1. **Window 1:** User logged in (keep Bookings page open)
2. **Window 2:** Admin logged in
3. **Window 3 (Mobile):** Staff logged in with QR scanner ready

### Test Scenario

#### Step 1: User Creates Booking
```bash
Window 1 (User):
1. Login as regular user
2. Add courts to cart
3. Checkout with payment
4. Go to Bookings page
5. See status: "Pending Approval" (orange badge)
6. KEEP THIS PAGE OPEN - Don't refresh!
```

#### Step 2: Admin Approves
```bash
Window 2 (Admin):
1. Login as admin
2. Go to Admin Dashboard
3. Find the user's pending transaction
4. Click "Approve"

Expected Result in Window 1 (User):
✅ Status badge changes: orange → green (pending → approved)
✅ Big popup: "🎉 Real-Time Update - Your booking has been approved!"
✅ Toast notification appears
✅ NO PAGE REFRESH NEEDED!
```

#### Step 3: Staff Validates QR
```bash
Window 3 (Staff):
1. Login as staff
2. Go to QR Scanner
3. Scan the user's QR code (from their booking)

Expected Result in Window 1 (User):
✅ Status badge changes: green → blue (approved → completed)
✅ Popup: "🎉 Real-Time Update - Your booking is completed!"
✅ Toast notification appears
✅ Booking marked as checked-in
✅ NO PAGE REFRESH NEEDED!
```

## Visual Status Flow

```
┌─────────────────┐
│  User Creates   │
│    Booking      │ → Status: pending (🟠 orange)
└────────┬────────┘
         │
         ↓ Admin approves (real-time ⚡)
         │
┌────────┴────────┐
│  Booking        │
│  Approved       │ → Status: approved (🟢 green)
└────────┬────────┘
         │
         ↓ Staff scans QR (real-time ⚡)
         │
┌────────┴────────┐
│  Booking        │
│  Completed      │ → Status: completed (🔵 blue)
└─────────────────┘
```

## Expected User Notifications

### When Admin Approves:
```
🎉 Real-Time Update

Your booking has been approved!
Court: Basketball Court 1
Date: 10/15/2024

[View Details]  [Close]
```

### When Staff Validates QR:
```
🎉 Real-Time Update

Your booking is completed!
Court: Basketball Court 1
Date: 10/15/2024

[View Details]  [Close]
```

## Browser Console Logs (Debugging)

### User's Browser Console:
```javascript
// On admin approval:
"📱 Your booking status changed" {new_status: "approved", ...}
"✅ Updated transaction status in real-time: 45 → approved"

// On QR validation:
"📱 Your booking status changed" {new_status: "completed", ...}
"✅ Updated transaction status in real-time: 45 → completed"
```

### Backend Logs:
```bash
# storage/logs/laravel.log

# On approval:
[date] Cart transaction approved
[date] Broadcasting: booking.status.changed (pending → approved)

# On QR validation:
[date] QR code verified successfully
[date] Broadcasting: booking.status.changed (approved → completed)
```

## Testing Tips

### 1. Test Rapid Updates
Try approving and checking in multiple bookings quickly. All should update in real-time without conflicts.

### 2. Test with Multiple Users
Have multiple users with bookings. When staff checks in User A's booking, only User A should see their update.

### 3. Test Network Resilience
- Temporarily disconnect WiFi on user's device
- Admin approves during disconnection
- Reconnect WiFi
- User should eventually receive the update (WebSocket reconnects)

### 4. Test Multiple Tabs
Open the Bookings page in multiple tabs for the same user. All tabs should update simultaneously!

## Common Issues & Solutions

### Issue: Status doesn't update in real-time
**Solution:** User must logout and login again to store user data in localStorage

**Check:**
```javascript
// In browser console:
localStorage.getItem('user')
// Should return: {"id":1,"name":"...","email":"..."}
```

### Issue: Popup appears but list doesn't update
**Solution:** Check browser console for errors. The transaction ID might not be matching.

**Debug:**
```javascript
// Check if transaction_id is in the payload:
console.log('Transaction ID:', data.booking?.transaction_id)
```

### Issue: WebSocket not connecting
**Solution:** 
1. Check Laravel Reverb is running: `php artisan reverb:start`
2. Check `.env` has correct Reverb configuration
3. Check browser console for WebSocket errors

## Staff QR Scanner Testing

### Test Invalid QR Codes:
1. **Expired QR:** QR from past booking → Should show "Not within time window"
2. **Already Used QR:** Scan same QR twice → Should show "Already checked in"
3. **Unapproved QR:** QR from pending booking → Should show "Not approved"
4. **Invalid Format:** Random QR code → Should show "Invalid QR code format"

### Test Valid QR Code:
1. Approved booking within time window
2. Not yet checked in
3. Should successfully check in and broadcast status change

## Performance Expectations

- **Approval to User Update:** < 500ms
- **QR Scan to User Update:** < 500ms
- **Multiple Users:** All updates isolated, no crosstalk
- **Large Lists:** Instant update of specific transaction, no full page reload

## API Endpoints Involved

### Cart Transaction Approval:
```
POST /api/admin/cart-transactions/{id}/approve
→ Broadcasts BookingStatusChanged (pending → approved)
```

### QR Code Validation:
```
POST /api/staff/cart-transactions/verify-qr
→ Broadcasts BookingStatusChanged (approved → completed)

POST /api/bookings/validate-qr
→ Broadcasts BookingStatusChanged (approved → checked_in)
```

## WebSocket Channels

### Private Channel (User-specific):
```javascript
Echo.private(`user.${userId}`)
  .listen('.booking.status.changed', (data) => {
    // Update UI immediately
    // Show notification
  })
```

### Public Channel (All admins/staff):
```javascript
Echo.channel('bookings')
  .listen('.booking.status.changed', (data) => {
    // Refresh dashboard stats
    // Update pending list
  })
```

## Success Criteria

✅ All status changes (approve, reject, check-in) broadcast in real-time
✅ User sees immediate visual feedback without refresh
✅ Notifications appear instantly
✅ Specific transaction updates (not full page reload)
✅ No crosstalk between different users
✅ Staff can validate multiple QR codes rapidly
✅ All changes logged in backend

---

**Your real-time QR validation system is now fully operational!** 🚀

Test all scenarios above to ensure everything works smoothly.

