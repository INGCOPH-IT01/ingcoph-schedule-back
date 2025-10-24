# Waitlist Direct Booking Fix

## Problem

Waitlist entries were **not being saved to the `booking_waitlists` table** when users created bookings directly through the booking dialog (AdminDashboard or user booking form).

### Root Cause

Waitlist logic was only implemented in the **cart flow** (`CartController@store`), but not in the **direct booking flow** (`BookingController@store`).

When users tried to book a time slot that was already taken:
- ❌ Through cart: Waitlist entry created ✅
- ❌ Through direct booking: Booking rejected with 409 error ❌

## Solution

Added waitlist creation logic to `BookingController@store` to handle conflicts consistently across both flows.

## Changes Made

### Backend Changes

#### 1. BookingController.php (Direct Booking Flow)

**Import Added:**
```php
use App\Models\BookingWaitlist;
```

**Conflict Handling Updated (Lines 150-200):**

Instead of immediately rejecting conflicting bookings, the system now:

1. **Detects the conflict** with existing bookings
2. **Creates a waitlist entry** for ALL users (including admins and staff)
3. **Returns waitlist information** to the frontend
4. **Ensures fairness** - no one can skip the waitlist queue

```php
if ($conflictingBooking) {
    // ALL users (including admins and staff) must go through waitlist queue
    // This ensures fairness - no one can skip the line
    try {
        DB::beginTransaction();

        // Get the next position in waitlist
        $nextPosition = BookingWaitlist::where('court_id', $court->id)
            ->where('start_time', $startTime)
            ->where('end_time', $endTime)
            ->where('status', BookingWaitlist::STATUS_PENDING)
            ->count() + 1;

        // Use time-based pricing calculation
        $totalPrice = $court->sport->calculatePriceForRange($startTime, $endTime);

        // Create waitlist entry for ANY user (regular, staff, or admin)
        $waitlistEntry = BookingWaitlist::create([
            'user_id' => $request->user()->id,
            'pending_booking_id' => $conflictingBooking->id,
            'pending_cart_transaction_id' => $conflictingBooking->cart_transaction_id,
            'court_id' => $court->id,
            'sport_id' => $request->sport_id ?? $court->sport_id,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'price' => $totalPrice,
            'number_of_players' => $request->number_of_players ?? 1,
            'position' => $nextPosition,
            'status' => BookingWaitlist::STATUS_PENDING,
            'notes' => $request->notes
        ]);

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'This time slot is currently pending approval for another user. You have been added to the waitlist.',
            'waitlisted' => true,
            'waitlist_entry' => $waitlistEntry->load(['court', 'sport', 'user']),
            'position' => $nextPosition
        ], 200);

    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json([
            'success' => false,
            'message' => 'Failed to add to waitlist: ' . $e->getMessage()
        ], 500);
    }
}
```

#### 2. CartController.php (Cart Flow)

**Role Check Removed (Line 265-267):**

Previously, only regular users were added to waitlist when using the cart flow:
```php
// OLD CODE - Only regular users go to waitlist
if ($request->user()->role === 'user' && $isPendingApprovalBooking) {
    // Create waitlist entry
}
```

Now, ALL users (including admins and staff) are added to waitlist:
```php
// NEW CODE - ALL users go to waitlist
if ($isPendingApprovalBooking) {
    // Create waitlist entry for ANY user
}
```

This ensures consistent behavior: **no one can skip the waitlist queue**, regardless of their role.

### Frontend Changes

#### 3. courtService.js

**Updated `createBooking` method:**
```javascript
async createBooking(bookingData) {
  try {
    const response = await api.post('/bookings', bookingData)

    // Check if the response indicates a waitlist entry was created
    if (response.data.waitlisted) {
      return {
        ...response.data,
        isWaitlisted: true
      }
    }

    return response.data.data
  } catch (error) {
    throw new Error(error.response?.data?.message || 'Failed to create booking')
  }
}
```

#### 4. GlobalBookingDialog.vue

**Updated booking submission handler (Lines 1375-1423):**

Added logic to detect and handle waitlist responses:

```javascript
result = await courtService.createBooking(bookingData)

// Check if the user was added to waitlist
if (result.isWaitlisted) {
  // Show waitlist notification
  Swal.fire({
    icon: 'info',
    title: 'Added to Waitlist',
    html: `
      <p>${result.message}</p>
      <p class="mt-3"><strong>Your waitlist position: #${result.position}</strong></p>
      <p class="text-sm mt-2">You'll be notified if this slot becomes available.</p>
    `,
    confirmButtonColor: '#1976d2',
    confirmButtonText: 'Understood',
    timer: 6000,
    timerProgressBar: true
  })

  showSnackbar(`Added to waitlist (Position #${result.position})`, 'info')
} else {
  // Regular booking created
  // ... handle normal booking flow
}
```

## How It Works Now

### User Flow

1. **User tries to book a slot**
   - Calls `POST /api/bookings` via `courtService.createBooking()`

2. **Backend detects conflict**
   - Finds existing booking with conflicting time

3. **Waitlist entry created**
   - Saves to `booking_waitlists` table
   - Calculates waitlist position
   - Links to pending booking

4. **Frontend shows notification**
   - Sweet Alert with waitlist position
   - User understands they're in queue
   - Can track status in their bookings

### Admin/Staff Flow

**Admins and staff users are treated the same as regular users:**
- Cannot bypass the waitlist queue
- Must wait their turn like everyone else
- Ensures fair queue management for all users

## Benefits

✅ **Consistent behavior** - Waitlist works for both cart and direct booking flows
✅ **Database integrity** - All waitlist bookings are properly tracked
✅ **User experience** - Clear feedback when added to waitlist
✅ **Queue management** - Positions are calculated correctly
✅ **Notification support** - Users can be notified when slots become available
✅ **Fairness & Equality** - ALL users (including admins/staff) must wait in queue - no special privileges

## Testing

Test the following scenarios:

1. ✅ Regular user books an already-taken slot → Added to waitlist
2. ✅ Admin/Staff books an already-taken slot → Also added to waitlist (no bypass)
3. ✅ Multiple users (mixed roles) join waitlist → Positions calculated correctly in order
4. ✅ Admin tries to book after regular user → Admin gets position #2 (waits turn)
5. ✅ Blocking booking rejected → All waitlisted users notified (regardless of role)
6. ✅ Waitlist entries visible in `booking_waitlists` table with correct user roles

## Database Entries

When a waitlist entry is created, the following is saved to `booking_waitlists`:

```sql
INSERT INTO booking_waitlists (
  user_id,                      -- User requesting the slot
  pending_booking_id,           -- ID of blocking booking
  pending_cart_transaction_id,  -- Cart transaction of blocking booking
  court_id,                     -- Court being requested
  sport_id,                     -- Sport for the court
  start_time,                   -- Requested start time
  end_time,                     -- Requested end time
  price,                        -- Calculated price
  number_of_players,            -- Number of players
  position,                     -- Queue position
  status,                       -- 'pending'
  notes,                        -- Optional notes
  created_at,
  updated_at
)
```

## Related Files

**Backend:**
- `/app/Http/Controllers/Api/BookingController.php` - Direct booking flow
- `/app/Http/Controllers/Api/CartController.php` - Cart flow
- `/app/Models/BookingWaitlist.php` - Waitlist model

**Frontend:**
- `/src/services/courtService.js` - API service
- `/src/components/GlobalBookingDialog.vue` - Booking dialog component

## Related Documentation

- `WAITLIST_COMPLETE_SOLUTION.md`
- `WAITLIST_AUTO_BOOKING_CREATION.md`
- `WAITLIST_CART_ITEMS_FIX.md`
- `WAITLIST_APPROVAL_STATUS.md`
