# Changelog - Operating Hours Integration

## Date: 2025-10-18

### Feature: Time Slots Based on Operating Hours

#### Summary
Time slots shown when booking are now dynamically generated based on the operating hours schedule configured in company settings. Time slots maintain 1-hour increments.

#### Changes

##### Backend
**File:** `app/Http/Controllers/Api/BookingController.php`
- Modified `availableSlots()` method to fetch day-specific operating hours
- Added check for facility operational status
- Replaced hardcoded start time (6 AM) and end time (10 PM) with dynamic values from settings
- Returns empty array with message when facility is closed

**Database:**
- Uses existing `company_settings` table with keys:
  - `operating_hours_{day}_open` (e.g., "09:00")
  - `operating_hours_{day}_close` (e.g., "21:00")
  - `operating_hours_{day}_operational` ("1" or "0")

##### Frontend
**No changes required!** All views that display time slots use the same API endpoint:
- `NewBookingDialog.vue` - Main booking dialog
- `Courts.vue` - Court listing with availability
- `CourtDetails.vue` - Individual court details
- `CourtDetail.vue` - Court detail page
- `Home.vue` - Home page court display

#### Benefits
1. ✅ Different operating hours for each day of the week
2. ✅ Ability to mark specific days as closed
3. ✅ No code deployment needed to change hours
4. ✅ Consistent across all views
5. ✅ 1-hour slot increments maintained

#### Testing Checklist
- [ ] Set different operating hours for different days
- [ ] Verify time slots match configured hours
- [ ] Set a day as non-operational (closed)
- [ ] Verify no slots appear on closed days
- [ ] Verify "Facility is closed on this day" message
- [ ] Test booking creation within operating hours
- [ ] Test that bookings cannot be created outside operating hours
- [ ] Verify Courts view shows correct availability
- [ ] Verify Home page shows correct availability
- [ ] Verify CourtDetails shows correct time slots

#### Migration Path
1. Deploy backend changes
2. Access Company Settings in admin panel
3. Configure operating hours for each day
4. Save changes
5. Verify time slots update accordingly

#### Backward Compatibility
✅ Fully backward compatible with default fallback values:
- Default opening: 08:00
- Default closing: 22:00
- Default operational status: Open (1)

#### API Response Examples

**When Open:**
```json
{
  "success": true,
  "data": [
    { "start": "09:00", "end": "10:00", "available": true, "price": 500 },
    { "start": "10:00", "end": "11:00", "available": true, "price": 500 }
  ]
}
```

**When Closed:**
```json
{
  "success": true,
  "data": [],
  "message": "Facility is closed on this day"
}
```

#### Performance Impact
✅ Minimal - Only 3 additional database lookups per API call (cached by Laravel)

#### Security Considerations
✅ No security impact - uses existing authenticated endpoints and settings system
