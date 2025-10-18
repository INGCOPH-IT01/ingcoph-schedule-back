# Operating Hours Implementation

## Overview
The booking system now uses operating hours from company settings to generate available time slots dynamically.

## Changes Made

### Backend Changes
**File:** `app/Http/Controllers/Api/BookingController.php`

The `availableSlots()` method has been updated to:

1. **Fetch Day-Specific Operating Hours**
   - Retrieves opening and closing times for the specific day of the week
   - Example keys: `operating_hours_monday_open`, `operating_hours_monday_close`
   - Default fallback: 08:00 - 22:00

2. **Check Operational Status**
   - Verifies if the facility is operational on the selected day
   - Returns empty array with message if closed
   - Example key: `operating_hours_monday_operational`

3. **Generate Time Slots**
   - Generates 1-hour time slots from opening to closing time
   - Maintains existing conflict checking with bookings and cart items
   - Respects the operating hours boundaries

## How It Works

### Request Flow
1. User selects a date in the booking dialog
2. Frontend calls `/api/courts/{courtId}/available-slots?date={date}`
3. Backend determines the day of week (e.g., "monday")
4. Fetches operating hours from `company_settings` table:
   - `operating_hours_monday_open` (e.g., "09:00")
   - `operating_hours_monday_close` (e.g., "21:00")
   - `operating_hours_monday_operational` (e.g., "1" for open, "0" for closed)
5. If operational:
   - Generates 1-hour slots from opening to closing time
   - Checks for conflicts with existing bookings
   - Returns available slots
6. If closed:
   - Returns empty array with message "Facility is closed on this day"

### Example Response (Open Day)
```json
{
  "success": true,
  "data": [
    {
      "start": "09:00",
      "end": "10:00",
      "available": true,
      "price": 500
    },
    {
      "start": "10:00",
      "end": "11:00",
      "available": true,
      "price": 500
    }
    // ... more slots until closing time
  ]
}
```

### Example Response (Closed Day)
```json
{
  "success": true,
  "data": [],
  "message": "Facility is closed on this day"
}
```

## Configuration

### Setting Operating Hours
Operating hours are managed through the Company Settings page in the admin panel:

1. Navigate to Company Settings
2. Go to Operating Hours section
3. Set hours for each day of the week
4. Toggle operational status for each day
5. Save changes

### Special Case: Closing Time of 00:00
If you set the closing time to `00:00`, the system interprets this as "open until midnight" (24:00):
- Example: Opening at 07:00, Closing at 00:00
- This generates slots: 07:00-08:00, 08:00-09:00, ..., 22:00-23:00, 23:00-00:00
- The last bookable slot is 23:00-00:00 (11 PM to midnight)

If you want to close earlier (e.g., 10 PM), set the closing time to `22:00` instead.

### Database Structure
Operating hours are stored in the `company_settings` table with the following keys:

**For each day (monday-sunday):**
- `operating_hours_{day}_open` - Opening time (e.g., "08:00")
- `operating_hours_{day}_close` - Closing time (e.g., "22:00")
- `operating_hours_{day}_operational` - Status ("1" = open, "0" = closed)

## Benefits

1. **Flexibility** - Different hours for different days of the week
2. **Closed Days** - Ability to mark days as non-operational
3. **Dynamic** - No need to redeploy code to change operating hours
4. **Consistent** - All booking operations respect the same operating hours
5. **User-Friendly** - Clear message when facility is closed

## Frontend Behavior

The frontend automatically:
- Loads available time slots based on the selected date
- Displays "No time slots available" when the day is closed
- Shows all available 1-hour slots within operating hours
- Highlights unavailable slots (already booked)

## Testing

To test the implementation:

1. Set operating hours in Company Settings
2. Try booking on different days of the week
3. Verify slots match the configured hours
4. Set a day as non-operational and verify no slots appear
5. Create bookings and verify they appear in the correct time ranges

## Backward Compatibility

The system includes default fallback values:
- Default opening time: 08:00
- Default closing time: 22:00
- Default operational status: Open (1)

This ensures the system works even if operating hours are not configured.
