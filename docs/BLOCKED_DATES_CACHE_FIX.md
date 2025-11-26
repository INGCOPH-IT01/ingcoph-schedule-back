# Blocked Booking Dates Cache Fix

## Issue
After deleting a blocked booking date in company settings, regular users were still unable to book in the previously blocked date range due to caching issues.

## Root Cause
When blocked booking dates were updated or deleted:
1. **Backend**: No cache invalidation was happening in `CompanySettingController`
2. **Frontend**: While the admin's browser cache was cleared, regular users retained cached settings for up to 5 minutes (cache TTL)

## Solution Implemented

### Backend Fix (CompanySettingController.php)
Added cache invalidation when `blocked_booking_dates` are updated:

```php
if ($request->has('blocked_booking_dates')) {
    // Validate and sanitize the blocked dates
    $blockedDates = is_string($request->blocked_booking_dates)
        ? json_decode($request->blocked_booking_dates, true)
        : $request->blocked_booking_dates;

    // Store as JSON string
    CompanySetting::set('blocked_booking_dates', json_encode($blockedDates ?: []));

    // Clear backend cache for blocked_booking_dates
    \App\Helpers\CachedSettings::flush('blocked_booking_dates');
}
```

**File**: `app/Http/Controllers/Api/CompanySettingController.php`
**Line**: ~324

### Frontend Fix (companySettingService.js)

#### 1. Always Fetch Fresh Blocked Dates
Modified `getBlockedBookingDates()` to always bypass cache:

```javascript
async getBlockedBookingDates() {
  try {
    // Always fetch fresh data (bypass cache) to avoid showing outdated blocked dates
    const settings = await this.getSettings(false)
    return settings?.blocked_booking_dates || []
  } catch (e) {
    console.warn('Error fetching blocked booking dates:', e)
    return []
  }
}
```

**Rationale**: Blocked dates are critical for booking validation. Showing outdated blocked dates could:
- Prevent users from booking available dates
- Allow bookings on dates that should be blocked

#### 2. Clear Cache After Successful Update
Modified `updateSettings()` to clear cache both before AND after update:

```javascript
async updateSettings(settingsData) {
  try {
    // Clear cache before updating
    this.clearSettingsCache()

    // ... perform update ...

    // Clear cache again after successful update to ensure fresh data on next fetch
    this.clearSettingsCache()

    return response.data.data
  } catch (error) {
    throw new Error(error.response?.data?.message || 'Failed to update company settings')
  }
}
```

**File**: `src/services/companySettingService.js`

## Testing the Fix

### Test Case 1: Delete Blocked Date
1. Login as **Admin**
2. Go to **Company Settings**
3. Add a blocked date range (e.g., Dec 1-31, 2024)
4. Click **Save Settings**
5. Logout and login as **Regular User**
6. Try to book a date in the blocked range → Should be **blocked** ✅
7. Logout and login as **Admin** again
8. Delete the blocked date range
9. Click **Save Settings**
10. Logout and login as **Regular User**
11. Try to book the same date → Should **succeed immediately** ✅

### Test Case 2: Update Blocked Date Range
1. Login as **Admin**
2. Add blocked date: Jan 1-15, 2025
3. Save settings
4. Login as **Regular User**
5. Try to book Jan 10 → Should be **blocked** ✅
6. Login as **Admin**
7. Remove the Jan 1-15 block
8. Add new block: Jan 20-31, 2025
9. Save settings
10. Login as **Regular User**
11. Try to book Jan 10 → Should **succeed** ✅
12. Try to book Jan 25 → Should be **blocked** ✅

### Expected Behavior
- Changes to blocked dates should take effect **immediately** for all users
- No need to wait for cache expiration (previously up to 5 minutes)
- No need for users to refresh their browser

## Technical Details

### Cache Layers Involved
1. **Backend Cache (Laravel)**: Uses `CachedSettings` helper with 1-hour TTL
2. **Frontend Cache (Browser)**: Uses `apiCache` utility with 5-minute TTL

### Why Both Layers Need Clearing
- **Backend cache**: Ensures the API returns fresh data
- **Frontend cache**: Ensures the browser fetches fresh data from API

### Performance Considerations
- `getBlockedBookingDates()` now makes an API call every time (bypasses cache)
- This is acceptable because:
  - Blocked dates checking doesn't happen frequently (only during booking flow)
  - The API call is fast
  - Data accuracy is critical for booking validation

## Related Files
- Backend:
  - `app/Http/Controllers/Api/CompanySettingController.php`
  - `app/Helpers/CachedSettings.php`
- Frontend:
  - `src/services/companySettingService.js`
  - `src/views/CompanySettings.vue`
- Validation Points:
  - `app/Http/Controllers/Api/BookingController.php` (line 121)
  - `app/Http/Controllers/Api/CartController.php` (line 148)
  - `app/Http/Controllers/Api/RecurringScheduleController.php` (line 95)

## Date Fixed
November 26, 2025
