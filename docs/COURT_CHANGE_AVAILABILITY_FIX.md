# Court Change Availability Fix

## Issue
When an admin changed the court of a booking via the BookingDetailsDialog, the availability check in the NewBookingDialog would still show the old time slot as "Booked" for the original court. This created a mismatch between the actual booking status and what was displayed to users trying to book new time slots.

## Root Cause
The system maintains booking information in two tables:
1. `cart_items` - Contains booking details as cart items (new system)
2. `bookings` - Contains confirmed booking records (linked to cart transactions)

When a court was changed via the `updateCartItem` API endpoint:
- The `cart_items` table was updated with the new court_id
- The corresponding `Booking` record(s) in the `bookings` table were NOT updated
- The `availableSlots` API endpoint checks BOTH tables for conflicts
- Result: The old court still showed as booked because the booking record pointed to it

## Solution

### Backend Fix (CartController.php)
Updated the `updateCartItem` method to also update any related booking records when a cart item's court is changed:

```php
// Update the cart item
$cartItem->update([
    'court_id' => $request->court_id
]);

// Also update any related booking records with the same cart_transaction_id and time slot
// This ensures availability checks show the correct status
if ($cartItem->cart_transaction_id) {
    Booking::where('cart_transaction_id', $cartItem->cart_transaction_id)
        ->where('start_time', $bookingDate . ' ' . $cartItem->start_time)
        ->where('end_time', $endDateTime)
        ->update([
            'court_id' => $request->court_id
        ]);
}
```

**Location:** `/app/Http/Controllers/Api/CartController.php` (lines 1118-1127)

### Frontend Fix (NewBookingDialog.vue)
Added event listeners to automatically refresh availability when bookings are updated:

```javascript
// Add event listener for booking updates to refresh availability
const handleBookingUpdated = () => {
  // Only reload if we're on step 2 (time slot selection) and have a selected date
  if (step.value === 2 && selectedDate.value) {
    loadTimeSlotsForAllCourts()
  }
}

onMounted(async () => {
  // ... existing code ...

  // Listen for booking updates to refresh availability
  window.addEventListener('booking-updated', handleBookingUpdated)
  window.addEventListener('booking-created', handleBookingUpdated)
})

// Cleanup event listeners when component is unmounted
onUnmounted(() => {
  window.removeEventListener('booking-updated', handleBookingUpdated)
  window.removeEventListener('booking-created', handleBookingUpdated)
})
```

**Location:** `/src/components/NewBookingDialog.vue` (lines 759, 2062-2109)

## How It Works

### Flow When Court is Changed:
1. Admin changes a booking's court in BookingDetailsDialog
2. `updateCartItem` API is called with the new court_id
3. Backend updates:
   - The cart_item record's court_id
   - Any related booking record's court_id (matching by cart_transaction_id and time slot)
4. BookingDetailsDialog dispatches 'booking-updated' event
5. NewBookingDialog (if open) receives the event and refreshes time slots
6. Availability now correctly shows:
   - Old court slot as available
   - New court slot as booked

### Data Consistency:
- Both cart_items and bookings tables now reflect the correct court
- availableSlots API checks both tables and gets consistent results
- Real-time updates via event listeners ensure UI stays synchronized

## Testing

### Test Case 1: Change Court and Check Availability
1. Create a booking for Court A at 10:00-11:00
2. Open booking details and change it to Court B
3. Open New Booking dialog
4. Select Court A for 10:00-11:00
5. **Expected:** Time slot shows as available (not booked)
6. Select Court B for 10:00-11:00
7. **Expected:** Time slot shows as booked

### Test Case 2: Real-time Update
1. Have two browser windows open (both logged in as admin)
2. In Window 1: Create a booking for Court A at 14:00-15:00
3. In Window 2: Open New Booking dialog and verify Court A at 14:00-15:00 is booked
4. In Window 1: Change the booking to Court B
5. In Window 2: Without closing dialog, observe that:
   - Court A at 14:00-15:00 now shows as available
   - Court B at 14:00-15:00 now shows as booked

### Test Case 3: Database Verification
```sql
-- Before court change
SELECT id, court_id, start_time FROM cart_items WHERE id = X;
SELECT id, court_id, start_time FROM bookings WHERE cart_transaction_id = Y;

-- After court change (both should show new court_id)
SELECT id, court_id, start_time FROM cart_items WHERE id = X;
SELECT id, court_id, start_time FROM bookings WHERE cart_transaction_id = Y;
```

## Related Components
- `BookingDetailsDialog.vue` - Triggers the court change
- `NewBookingDialog.vue` - Shows availability (now refreshes on updates)
- `CartController.php` - Handles court change logic
- `BookingController.php` - Provides availability data via `availableSlots` endpoint

## Benefits
1. **Data Consistency:** Both cart items and bookings reflect the correct court
2. **Accurate Availability:** Users see correct availability status in real-time
3. **No Ghost Bookings:** Old courts don't show phantom bookings after changes
4. **Real-time Updates:** UI automatically refreshes when changes occur
5. **Admin Confidence:** Admins can change courts knowing the system will update correctly

## Date
October 21, 2025
