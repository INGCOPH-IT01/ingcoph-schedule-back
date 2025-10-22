# Time Slot Hourly Display Update

## Overview
Updated the available slots API to always show 1-hour increment time slots from the start to the end of operating hours. When a booking covers multiple time slots (e.g., 3 hours), each individual 1-hour slot now displays the booking name.

## Changes Made

### Backend Changes

**File:** `app/Http/Controllers/Api/BookingController.php`

#### Key Modifications:

1. **Changed slot generation to always use 1-hour increments**
   - Modified from: `$slotEnd = $currentTime->copy()->addHours($duration)`
   - Modified to: `$slotEnd = $currentTime->copy()->addHour()`
   - This ensures all slots are exactly 1 hour regardless of booking duration

2. **Removed duplicate prevention for multi-hour bookings**
   - Removed: `$addedBookingIds` and `$addedCartItemIds` tracking arrays
   - Removed: Conditional checks that prevented showing multiple slots for same booking
   - Result: Each 1-hour slot within a multi-hour booking now shows separately with the booking name

3. **Updated slot data to reflect current 1-hour segment**
   - Changed slot times from full booking range to current 1-hour segment
   - Updated pricing calculation to reflect 1-hour slot price instead of full booking price
   - For Cart Items:
     ```php
     'start' => $currentTime->format('H:i'),
     'end' => $slotEnd->format('H:i'),
     'duration_hours' => 1,
     'price' => $court->sport->calculatePriceForRange($currentTime, $slotEnd)
     ```
   - For Old Bookings:
     ```php
     'start' => $currentTime->format('H:i'),
     'end' => $slotEnd->format('H:i'),
     'duration_hours' => 1,
     'price' => $court->sport->calculatePriceForRange($currentTime, $slotEnd)
     ```

4. **Added full booking information to each slot**
   - New fields added to preserve original booking context:
     - `full_booking_start`: Original booking start time
     - `full_booking_end`: Original booking end time
     - `full_booking_duration`: Total duration of the booking
   - This allows the frontend to show individual slots while maintaining context of the full booking

5. **Updated loop increment**
   - Changed from: `$currentTime->addHours($duration)`
   - Changed to: `$currentTime->addHour()`
   - Ensures consistent 1-hour increments through all operating hours

## Example Output

### Before (3-hour booking from 10:00-13:00):
```json
{
  "start": "10:00",
  "end": "13:00",
  "duration_hours": 3,
  "display_name": "John Doe",
  "is_booked": true
}
```

### After (3-hour booking from 10:00-13:00):
```json
[
  {
    "start": "10:00",
    "end": "11:00",
    "duration_hours": 1,
    "display_name": "John Doe",
    "is_booked": true,
    "full_booking_start": "10:00",
    "full_booking_end": "13:00",
    "full_booking_duration": 3
  },
  {
    "start": "11:00",
    "end": "12:00",
    "duration_hours": 1,
    "display_name": "John Doe",
    "is_booked": true,
    "full_booking_start": "10:00",
    "full_booking_end": "13:00",
    "full_booking_duration": 3
  },
  {
    "start": "12:00",
    "end": "13:00",
    "duration_hours": 1,
    "display_name": "John Doe",
    "is_booked": true,
    "full_booking_start": "10:00",
    "full_booking_end": "13:00",
    "full_booking_duration": 3
  }
]
```

## Benefits

1. **Clearer Timeline View**: Users can see the complete schedule with all hourly slots
2. **Better Context**: Each slot shows which bookings occupy each hour
3. **Consistent Display**: All time slots are shown in uniform 1-hour increments
4. **Preserved Information**: Full booking details are still available via `full_booking_*` fields
5. **Accurate Pricing**: Each slot shows the correct price for that specific hour (respects time-based pricing)

## Frontend Compatibility

The existing frontend components (`NewBookingDialog.vue`, `CalendarView.vue`, etc.) already handle this data structure correctly:
- They display the `display_name` field which contains the booking name
- They respect the `is_booked`, `is_pending_approval`, and `is_waitlist_available` flags
- They show the time range using `start` and `end` fields
- No frontend changes required

## Testing Recommendations

1. Create a multi-hour booking (e.g., 3 hours)
2. View the booking calendar/time slots
3. Verify each 1-hour slot shows the booking name
4. Verify all slots from opening to closing time are displayed
5. Check that pricing is calculated correctly for each hour
6. Test with time-based pricing rules to ensure correct hourly rates

## Notes

- Operating hours are still respected (slots only generated within configured hours)
- Time-based pricing is calculated per 1-hour slot
- Booking status and payment status are preserved on each slot
- Both cart items and old booking records are handled consistently
