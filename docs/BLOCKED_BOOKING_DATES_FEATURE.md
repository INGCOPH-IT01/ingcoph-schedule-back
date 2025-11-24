# Blocked Booking Dates Feature

## Overview
This feature allows Administrators to block specific date ranges from being booked by regular users (User role). Admin and Staff roles are not affected by these restrictions and can still create bookings for blocked dates.

## Use Cases
1. **Specific Date Range Blocking**: Block a defined period (e.g., "December 1-31, 2024")
2. **Indefinite Forward Blocking**: Block from a date onwards (e.g., "No advance bookings from December 2024 onwards")
3. **Maintenance Periods**: Block dates during facility maintenance
4. **Special Events**: Block dates for special events or facility closures
5. **Policy Changes**: Implement booking policies (e.g., "No bookings more than 3 months in advance")

## Architecture

### Backend Implementation

#### 1. Database Storage
- Blocked dates are stored in the `company_settings` table with key `blocked_booking_dates`
- Format: JSON array of objects
- Supports two blocking modes:
  - **Specific Range**: With both `start_date` and `end_date`
  - **Indefinite/Onwards**: With `start_date` only (empty `end_date`)

```json
[
  {
    "start_date": "2024-12-01",
    "end_date": "2024-12-31",
    "reason": "Holiday season closure"
  },
  {
    "start_date": "2025-01-15",
    "end_date": "2025-01-20",
    "reason": "Facility maintenance"
  },
  {
    "start_date": "2025-03-01",
    "end_date": "",
    "reason": "No advance bookings from March 2025 onwards"
  }
]
```

**Note**: When `end_date` is empty or null, it means "block from start_date onwards indefinitely".

#### 2. Blocking Modes

**Mode 1: Specific Date Range**
- Requires both `start_date` and `end_date`
- Blocks only the dates within the specified range
- Example: Block December 1-31, 2024

```json
{
  "start_date": "2024-12-01",
  "end_date": "2024-12-31",
  "reason": "Holiday season closure"
}
```

**Mode 2: Indefinite/Onwards Blocking**
- Requires only `start_date` (end_date is empty/null)
- Blocks all dates from start_date onwards
- Example: No advance bookings from March 2025 onwards

```json
{
  "start_date": "2025-03-01",
  "end_date": "",
  "reason": "No advance bookings from March onwards"
}
```

**Validation Logic**:
```php
// Backend checking logic
$isIndefinite = empty($blockedRange['end_date']);

if ($isIndefinite) {
    // Block if booking date >= start_date
    $isBlocked = $bookingDate->greaterThanOrEqualTo($blockStart);
} else {
    // Block if booking date is within range
    $isBlocked = $bookingDate->between($blockStart, $blockEnd);
}
```

#### 4. Validation Points
Blocked date validation has been added to all booking creation endpoints:

**BookingController.php**
- Validates single bookings created via direct booking API
- Checks if the booking start_time falls within a blocked date range
- Only applies to users with role 'user'

**CartController.php**
- Validates cart items before adding to cart
- Checks all booking dates in the cart items array
- Prevents users from adding blocked dates to their cart
- Supports both specific ranges and indefinite blocking

**RecurringScheduleController.php**
- Validates recurring schedule start dates
- Prevents recurring schedules from starting in blocked date ranges
- Supports both specific ranges and indefinite blocking
- Note: Once a recurring schedule is created, individual bookings will be checked separately

#### 5. API Response
When a blocked date is detected, the API returns:
```json
{
  "success": false,
  "message": "Holiday season - No advance bookings",
  "errors": {
    "start_time": ["Bookings are not allowed for this date"]
  }
}
```
HTTP Status: 422 (Unprocessable Entity)

#### 6. Company Settings API
**CompanySettingController.php**
- Added `blocked_booking_dates` to the settings index response
- Added validation rule: `'blocked_booking_dates' => 'nullable|json'`
- Properly handles JSON encoding/decoding
- Returns empty array `[]` if no blocked dates are configured

### Frontend Implementation

#### 1. Admin UI - Company Settings Page
**Location**: `src/views/CompanySettings.vue`

**New Section**: "Blocked Booking Dates" (added after Booking Rules section)

**Features**:
- Display list of currently blocked date ranges with reason
- Visual indicators for different blocking modes:
  - Specific ranges: "Dec 1, 2024 - Dec 31, 2024"
  - Indefinite ranges: "Mar 1, 2025 → Onwards"
- Add new blocked date ranges with:
  - Start date picker (required)
  - End date picker (optional based on mode)
  - **"Block onwards" toggle** - When enabled:
    - Blocks from start date indefinitely
    - End date field is disabled
    - Shows infinity icon (∞)
    - Displays helpful info alert
  - Optional reason/message field
- Delete existing blocked date ranges
- Save all blocked dates with company settings

**State Management**:
```javascript
const blockedBookingDates = ref([])
const newBlockedDate = ref({
  start_date: '',
  end_date: '',
  reason: '',
  block_onwards: false  // Toggle for indefinite blocking
})

// Watcher to clear end_date when block_onwards is enabled
watch(() => newBlockedDate.value.block_onwards, (blockOnwards) => {
  if (blockOnwards) {
    newBlockedDate.value.end_date = ''
  }
})
```

#### 2. Date Picker Updates
**Modified Files**:
- `src/components/NewBookingDialog.vue`
- `src/components/GlobalBookingDialog.vue`

**Implementation**:
- Load blocked dates when dialog opens
- Watch for selected date changes
- Check if selected date is blocked using `companySettingService.isDateBlocked()`
- Display prominent error alert when a blocked date is selected:
  - Red alert with calendar-remove icon
  - Shows "Date Not Available" heading
  - Displays the reason configured by admin

**Visual Feedback**:
```vue
<v-alert
  v-if="selectedDateBlockInfo.isBlocked"
  type="error"
  variant="tonal"
  prominent
>
  <div class="d-flex align-center">
    <v-icon class="mr-3" size="large">mdi-calendar-remove</v-icon>
    <div>
      <div class="font-weight-bold text-h6 mb-1">Date Not Available</div>
      <div>{{ selectedDateBlockInfo.reason }}</div>
    </div>
  </div>
</v-alert>
```

#### 3. Service Layer
**File**: `src/services/companySettingService.js`

**New Methods**:

`getBlockedBookingDates()`
- Fetches blocked dates from settings
- Returns empty array on error (fail-safe)

`isDateBlocked(date, userRole)`
- Checks if a specific date is blocked
- Parameters:
  - `date`: Date string (YYYY-MM-DD) or Date object
  - `userRole`: User's role ('user', 'admin', 'staff')
- Returns: `{ isBlocked: boolean, reason: string }`
- Admin/Staff always return `isBlocked: false`
- Compares date against all blocked ranges

**Implementation Details**:
```javascript
// Handles both specific ranges and indefinite blocking
const isIndefinite = !range.end_date || range.end_date === ''

if (isIndefinite) {
  // Block from start_date onwards (indefinitely)
  isBlocked = dateToCheck >= startDate
} else {
  // Block specific date range
  isBlocked = dateToCheck >= startDate && dateToCheck <= endDate
}
```

**Usage Example**:
```javascript
const result = await companySettingService.isDateBlocked('2024-12-15', 'user')
if (result.isBlocked) {
  console.log(`Date is blocked: ${result.reason}`)
}

// Examples:
// If blocked range is { start_date: '2024-12-01', end_date: '2024-12-31' }
// - '2024-12-15' → blocked (within range)
// - '2025-01-15' → not blocked (outside range)

// If blocked range is { start_date: '2025-03-01', end_date: '' }
// - '2025-03-15' → blocked (onwards)
// - '2025-12-31' → blocked (onwards)
// - '2026-06-01' → blocked (onwards, no end date)
```

## User Experience Flow

### For Regular Users (Role: 'user')

1. **Opening Booking Dialog**:
   - User selects a sport and opens the booking form
   - Blocked dates are loaded in the background

2. **Selecting a Date**:
   - User picks a date from the date picker
   - If the date is blocked:
     - A prominent red alert appears immediately
     - Shows the reason configured by admin
     - Time slots may still appear but booking will fail
   - If the date is not blocked:
     - Normal booking flow continues

3. **Attempting to Book**:
   - Frontend validation prevents proceeding with blocked dates
   - Backend validation provides additional security
   - User receives clear error message with reason

### For Admin/Staff

1. **Managing Blocked Dates**:
   - Navigate to Company Settings page
   - Scroll to "Blocked Booking Dates" section
   - Add new date ranges:
     - **Specific Range**: Select start and end dates
     - **Indefinite/Onwards**: Enable "Block onwards" toggle, select only start date
   - Add optional reason/message
   - View all blocked dates with clear indicators
   - Remove existing blocked dates
   - Save changes

2. **Examples of Blocking Scenarios**:
   - Holiday closure: Dec 24-26, 2024 (specific range)
   - No advance bookings: From March 2025 onwards (indefinite)
   - Maintenance: Jan 15-20, 2025 (specific range)
   - Policy change: No bookings beyond June 2025 (indefinite from June 1)

3. **Creating Bookings**:
   - Admin and Staff are NOT restricted by blocked dates
   - Can create bookings for any date
   - Useful for emergency bookings or special cases

## Blocking Modes Comparison

| Feature | Specific Date Range | Indefinite/Onwards |
|---------|-------------------|-------------------|
| **Start Date** | Required | Required |
| **End Date** | Required | Not required (empty) |
| **Use Case** | Known period (holidays, maintenance) | Policy enforcement, open-ended blocks |
| **Example** | Dec 24-26, 2024 | No bookings from March 2025 onwards |
| **UI Toggle** | "Block onwards" OFF | "Block onwards" ON |
| **Display** | "Dec 24, 2024 - Dec 26, 2024" | "Mar 1, 2025 → Onwards" |
| **Backend Check** | `date >= start && date <= end` | `date >= start` |
| **Removal** | Automatically inactive after end_date | Must be manually removed |

### Best Practices

**When to use Specific Date Ranges:**
- Known holiday periods
- Scheduled maintenance with confirmed dates
- Short-term events or closures
- When you know exactly when booking should resume

**When to use Indefinite/Onwards Blocking:**
- Policy changes (e.g., "no bookings more than X months in advance")
- Indefinite closure or suspension
- Future planning where end date is uncertain
- Preventing far-future bookings

**Tips:**
1. Use clear, descriptive reasons so users understand why dates are blocked
2. Regularly review indefinite blocks to ensure they're still needed
3. Combine both modes for complex scenarios (e.g., holiday + policy enforcement)
4. Remember: Admins can always override blocks for exceptions

## Technical Notes

### Date Comparison Logic
- All date comparisons use `Carbon` (backend) and native `Date` objects (frontend)
- Times are normalized to start/end of day for accurate range checking:
  - `startOfDay()` - 00:00:00
  - `endOfDay()` - 23:59:59

### Role-Based Access
- Blocked dates only affect users with role `'user'`
- Roles `'admin'` and `'staff'` bypass all blocked date restrictions
- Backend validates roles from authenticated user token
- Frontend validates roles from stored user data

### Error Handling
- Frontend: Fails open (allows booking if check fails, backend will validate)
- Backend: Fails closed (blocks booking if validation fails)
- This ensures security while maintaining user experience

### Caching Considerations
- Company settings are cached for 5 minutes in frontend
- Cache is cleared when settings are updated
- Event `company-settings-updated` triggers cache refresh
- Booking dialogs reload settings when opened

## Testing Checklist

### Backend Testing - Specific Date Ranges
- [ ] Create booking with date in blocked range as User → Should fail
- [ ] Create booking with date in blocked range as Admin → Should succeed
- [ ] Add cart items with blocked dates as User → Should fail
- [ ] Create recurring schedule starting in blocked range as User → Should fail
- [ ] Book date before blocked range starts → Should succeed
- [ ] Book date after blocked range ends → Should succeed

### Backend Testing - Indefinite/Onwards Blocking
- [ ] Create booking on start_date with indefinite block as User → Should fail
- [ ] Create booking 1 year after start_date with indefinite block as User → Should fail
- [ ] Create booking before indefinite block start_date as User → Should succeed
- [ ] Create booking in indefinite blocked range as Admin → Should succeed

### Backend Testing - Settings
- [ ] Update settings with blocked dates (specific range) → Should save
- [ ] Update settings with indefinite block (empty end_date) → Should save
- [ ] API returns blocked dates with proper format in settings endpoint
- [ ] Mix of specific and indefinite ranges in same settings → Works correctly

### Frontend Testing - UI
- [ ] Add specific date range in Company Settings → Appears as "Start - End"
- [ ] Add indefinite range with "Block onwards" toggle → Appears as "Start → Onwards"
- [ ] Toggle "Block onwards" on → End date field disables and clears
- [ ] Toggle "Block onwards" off → End date field re-enables
- [ ] Remove blocked date range → Removed from list
- [ ] Save settings with mixed blocking modes → Persists correctly

### Frontend Testing - Booking Dialogs
- [ ] Open NewBookingDialog, select date in specific blocked range → Shows alert with reason
- [ ] Open NewBookingDialog, select date in indefinite blocked range → Shows alert with reason
- [ ] Open GlobalBookingDialog, select date in blocked range → Shows alert with reason
- [ ] Select non-blocked date → No alert shown
- [ ] Admin selects blocked date → No alert shown (admins bypass)
- [ ] Date format displays correctly for both blocking modes

## Future Enhancements

Possible improvements for future versions:

1. **Day-of-Week Blocking**: Block specific days of the week (e.g., "No Mondays")
2. **Time-Range Blocking**: Block specific time ranges on specific dates
3. **Court-Specific Blocking**: Block dates for specific courts only
4. **Recurring Blocked Dates**: Annual recurring blocked dates (e.g., holidays)
5. **Bulk Import/Export**: CSV import/export for managing many blocked dates
6. **Calendar View**: Visual calendar showing blocked dates in admin interface
7. **Notification System**: Alert users when their preferred dates become blocked

## Security Considerations

1. **Authorization**: Only Admin role can modify blocked dates
2. **Validation**: Both frontend and backend validate blocked dates
3. **Data Integrity**: JSON validation ensures data format is correct
4. **Role Bypass**: Admin/Staff bypass is intentional for emergency bookings
5. **Error Messages**: User-friendly messages don't expose system internals

## Support and Maintenance

### Common Issues

**Issue**: User reports they can't book a date
- **Check**: Verify if date is in blocked_booking_dates setting
- **Fix**: Remove the blocked date range or adjust dates

**Issue**: Admin can't save blocked dates
- **Check**: Browser console for errors
- **Check**: Backend logs for validation errors
- **Fix**: Ensure date format is YYYY-MM-DD and end_date >= start_date

**Issue**: Blocked dates not showing in UI
- **Check**: Clear browser cache and reload
- **Check**: Verify settings API returns blocked_booking_dates
- **Fix**: Clear cache using companySettingService.clearSettingsCache()

### Database Query
To view current blocked dates in database:
```sql
SELECT value FROM company_settings WHERE key = 'blocked_booking_dates';
```

To manually update (not recommended, use UI instead):

**Specific date range:**
```sql
UPDATE company_settings
SET value = '[{"start_date":"2024-12-01","end_date":"2024-12-31","reason":"Holiday season"}]'
WHERE key = 'blocked_booking_dates';
```

**Indefinite/onwards blocking:**
```sql
UPDATE company_settings
SET value = '[{"start_date":"2025-03-01","end_date":"","reason":"No advance bookings from March onwards"}]'
WHERE key = 'blocked_booking_dates';
```

**Mixed blocking modes:**
```sql
UPDATE company_settings
SET value = '[
  {"start_date":"2024-12-24","end_date":"2024-12-26","reason":"Holiday closure"},
  {"start_date":"2025-03-01","end_date":"","reason":"No bookings from March onwards"}
]'
WHERE key = 'blocked_booking_dates';
```

## Conclusion

This feature provides flexible control over booking availability while maintaining a clear separation between user restrictions and admin capabilities. The implementation follows best practices for security, user experience, and maintainability.
