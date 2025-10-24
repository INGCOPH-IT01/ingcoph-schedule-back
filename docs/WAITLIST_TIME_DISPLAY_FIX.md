# Waitlist Time Display Fix

## Issue
The waitlist entries were displaying incorrect time slots that didn't match the parent booking's time slot. For example:
- **Parent Booking**: Tue, Oct 28, 2025, 6:00 PM - 7:00 PM
- **Waitlist Display**: 02:00 AM - 03:00 AM ❌

## Root Cause
When creating waitlist entries, the backend was using the **incoming booking request's times** instead of the **parent (conflicting) booking's times**. This caused the waitlist to show whatever time the user tried to book, rather than the actual time slot they're waiting for.

### Example Scenario
1. User A books Court 1 from 6:00 PM - 7:00 PM (status: pending)
2. User B tries to book Court 1 from 2:00 AM - 3:00 AM but there's a conflict
3. User B gets waitlisted, but the waitlist shows 2:00 AM - 3:00 AM instead of 6:00 PM - 7:00 PM

## Solution
Updated both `CartController` and `BookingController` to use the **parent booking's start_time and end_time** when creating waitlist entries.

### Files Changed

#### 1. CartController.php (Lines 232-320)
**Before:**
```php
// Created waitlist with incoming request's times
$waitlistEntry = BookingWaitlist::create([
    'start_time' => $startDateTime,  // From incoming item
    'end_time' => $endDateTime,      // From incoming item
    ...
]);
```

**After:**
```php
// Track parent booking's times
$parentStartTime = $conflictingBooking->start_time;
$parentEndTime = $conflictingBooking->end_time;

// Use parent's times for waitlist
$waitlistStartTime = $parentStartTime ?? $startDateTime;
$waitlistEndTime = $parentEndTime ?? $endDateTime;

$waitlistEntry = BookingWaitlist::create([
    'start_time' => $waitlistStartTime,  // From parent booking
    'end_time' => $waitlistEndTime,      // From parent booking
    ...
]);
```

#### 2. BookingController.php (Lines 150-188)
**Before:**
```php
// Created waitlist with incoming request's times
$waitlistEntry = BookingWaitlist::create([
    'start_time' => $startTime,  // From request
    'end_time' => $endTime,      // From request
    ...
]);
```

**After:**
```php
// Use conflicting booking's times
$waitlistStartTime = $conflictingBooking->start_time;
$waitlistEndTime = $conflictingBooking->end_time;

$waitlistEntry = BookingWaitlist::create([
    'start_time' => $waitlistStartTime,  // From parent booking
    'end_time' => $waitlistEndTime,      // From parent booking
    ...
]);
```

## Impact

### ✅ Benefits
1. **Accurate Time Display**: Waitlist now shows the correct time slot (matching parent booking)
2. **Consistent Queue**: All waitlist entries for the same booking show the same time slot
3. **Better UX**: Users can clearly see what time slot they're waiting for
4. **Correct Notifications**: Email notifications will show the accurate time slot

### 🔍 Testing Recommendations
1. Create a pending booking for a specific time slot (e.g., 6:00 PM - 7:00 PM)
2. Try to book the same or overlapping time slot as another user
3. Verify the waitlist entry shows the parent booking's time (6:00 PM - 7:00 PM)
4. Check both frontend display and email notifications

## Related Components
- **Frontend**: `BookingDetailsDialog.vue` displays waitlist times (lines 641-647)
- **Backend**: `CartController.php` and `BookingController.php` create waitlist entries
- **Model**: `BookingWaitlist.php` defines the waitlist structure
- **Emails**: Waitlist notification emails use the stored times

## Future Considerations
The waitlist entry's `start_time` and `end_time` fields now accurately reflect the parent booking's time slot. This ensures:
- Consistent display across all interfaces (admin dashboard, user bookings, emails)
- Accurate position tracking in the waitlist queue
- Proper notification when the time slot becomes available

## Frontend Fix (For Existing Data)

For existing waitlist entries with incorrect times, we also needed to:

### 1. Fix Database Records
Created a command to update existing waitlist entries:
```bash
php artisan waitlist:fix-times
```

This command:
- Reads all waitlist entries
- Finds their parent booking times
- Updates waitlist start_time/end_time to match parent booking

### 2. Fix Frontend Display
**File**: `BookingDetailsDialog.vue` (Lines 641-646, 1608-1615)

**Problem**: Frontend was using `new Date().toLocaleTimeString()` which caused timezone conversion issues.

**Before** (Line 644-645):
```javascript
{{ new Date(entry.start_time).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }}
```
This would convert "2025-10-28 18:00:00" through JavaScript's Date parser, causing timezone issues.

**After**:
```javascript
{{ formatWaitlistTime(entry.start_time) }}
```

Added `formatWaitlistTime` function:
```javascript
const formatWaitlistTime = (dateTime) => {
  if (!dateTime) return ''
  // Handle ISO 8601 format from API: "2025-10-28T18:00:00.000000Z"
  const dateTimeStr = dateTime.toString()

  let timePart = ''
  if (dateTimeStr.includes('T')) {
    // ISO format: Split by 'T' and get time portion
    const parts = dateTimeStr.split('T')
    if (parts[1]) {
      timePart = parts[1].split('.')[0] // Remove milliseconds
    }
  } else if (dateTimeStr.includes(' ')) {
    // Space-separated format fallback
    const parts = dateTimeStr.split(' ')
    if (parts[1]) {
      timePart = parts[1]
    }
  }

  // Extract HH:MM and format to 12-hour with AM/PM
  const timeHHMM = timePart.substring(0, 5)
  return formatTimeSlot(timeHHMM)
}
```

This function:
- Handles ISO 8601 format from the API (`2025-10-28T18:00:00.000000Z`)
- Extracts the time portion directly without timezone conversion
- Uses the same `formatTimeSlot()` function that parent bookings use
- Result: "18:00:00" → "6:00 PM" ✅

## Date
October 24, 2025
