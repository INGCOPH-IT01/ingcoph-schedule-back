# Blocked Booking Dates Cache Fix

## Issue
After deleting a blocked booking date in company settings, regular users were still unable to book in the previously blocked date range due to caching issues.

## Root Cause
When blocked booking dates were updated or deleted:
1. **Backend**: No cache invalidation was happening in `CompanySettingController`
2. **Frontend API Cache**: While the admin's browser cache was cleared, regular users retained cached settings for up to 5 minutes (cache TTL)
3. **Frontend Components**: Booking dialogs were calling `getSettings()` without bypassing cache, so they always loaded stale data
4. **HTTP Caching**: No cache-control headers were set, allowing browsers to cache the API response
5. **UI State**: Even after cache clearing, users with the booking dialog already open wouldn't see updates until they changed the selected date

## Solution Implemented

### Backend Fix (CompanySettingController.php)
Added comprehensive cache invalidation when `blocked_booking_dates` are updated:

```php
if ($request->has('blocked_booking_dates')) {
    // Validate and sanitize the blocked dates
    $blockedDates = is_string($request->blocked_booking_dates)
        ? json_decode($request->blocked_booking_dates, true)
        : $request->blocked_booking_dates;

    // Store as JSON string
    CompanySetting::set('blocked_booking_dates', json_encode($blockedDates ?: []));

    // Clear all possible caches for company settings to ensure fresh data everywhere
    \App\Helpers\CachedSettings::flush('blocked_booking_dates');
    \Illuminate\Support\Facades\Cache::forget('company_setting:blocked_booking_dates');

    // Clear tagged cache if supported by the cache driver
    try {
        if (method_exists(\Illuminate\Support\Facades\Cache::getStore(), 'tags')) {
            \Illuminate\Support\Facades\Cache::tags(['company_settings'])->flush();
        }
    } catch (\Exception $e) {
        // Cache tags not supported, ignore silently
    }
}
```

**Changes Made**:
1. Flush CachedSettings helper cache
2. Forget direct cache key
3. Flush tagged cache (if supported by cache driver)

**File**: `app/Http/Controllers/Api/CompanySettingController.php`
**Line**: ~324

#### 2. Prevent HTTP Caching
Added HTTP cache-control headers to the `index()` method to prevent browser caching:

```php
return response()->json([
    'success' => true,
    'data' => $settings
])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
  ->header('Pragma', 'no-cache')
  ->header('Expires', '0');
```

**Rationale**: Without these headers, browsers could cache the API response, causing users to see stale data even when the application layer cache is cleared.

**File**: `app/Http/Controllers/Api/CompanySettingController.php`
**Line**: ~160

### Frontend Fixes

#### 1. companySettingService.js - Always Fetch Fresh Blocked Dates
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

**File**: `src/services/companySettingService.js`

#### 2. companySettingService.js - Clear Cache After Update
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

#### 3. NewBookingDialog.vue - Bypass Cache When Loading
**CRITICAL FIX**: Changed all `getSettings()` calls to `getSettings(false)`:

```javascript
// Before (WRONG - uses cache):
const settings = await companySettingService.getSettings()

// After (CORRECT - bypasses cache):
const settings = await companySettingService.getSettings(false)
```

**Locations fixed**:
- `fetchWaitlistConfig()` function
- Dialog opening (watch for `isOpen` prop)
- Dialog mounting (onMounted hook)

**File**: `src/components/NewBookingDialog.vue`

#### 4. GlobalBookingDialog.vue - Bypass Cache When Loading
Same fix as NewBookingDialog - changed `getSettings()` to `getSettings(false)` when loading blocked dates.

**File**: `src/components/GlobalBookingDialog.vue`

#### 5. CompanySettings.vue - Bypass Cache When Loading
Changed `loadSettings()` to bypass cache:

```javascript
const loadSettings = async () => {
  try {
    loading.value = true
    // Always fetch fresh settings (bypass cache) when loading the settings page
    const settings = await companySettingService.getSettings(false)
    // ...
  }
}
```

**File**: `src/views/CompanySettings.vue`

#### 6. Real-time Updates in Booking Dialogs
Added event listeners to re-check blocked dates when settings are updated:

**NewBookingDialog.vue**:
```javascript
// Re-check if currently selected date is blocked (called when settings are updated)
const recheckBlockedDates = async () => {
  if (selectedDate.value && currentUser.value) {
    const result = await companySettingService.isDateBlocked(selectedDate.value, currentUser.value.role)
    selectedDateBlockInfo.value = result
    console.log('Rechecked blocked dates for:', selectedDate.value, result)
  }
}

// Handler for company settings updated event
const handleCompanySettingsUpdated = async () => {
  await fetchWaitlistConfig()  // Also refreshes with getSettings(false)
  await recheckBlockedDates()  // Re-validates current date
}

// Listen for event
window.addEventListener('company-settings-updated', handleCompanySettingsUpdated)
```

**GlobalBookingDialog.vue**:
- Added similar functionality for the admin booking dialog
- Listens for 'company-settings-updated' events
- Automatically re-checks blocked dates when settings change

**Rationale**: When an admin updates blocked dates in the Company Settings page, the event is dispatched to all open dialogs, which immediately:
1. Re-fetch fresh settings (bypassing cache)
2. Re-check if the currently selected date is blocked
3. Update the UI to show/hide blocked date alerts

**Files Modified**:
- `src/components/NewBookingDialog.vue`
- `src/components/GlobalBookingDialog.vue`

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

### Test Case 3: Real-time Update (Same Browser)
1. **Open two tabs** in the same browser
2. Tab 1: **Login as Admin** → Open Company Settings
3. Tab 2: **Login as Regular User** → Open New Booking Dialog → Select Jan 10, 2025
4. Tab 1: **Add blocked date** (Jan 1-15, 2025) → Save
5. Tab 2: **Check the booking dialog** → Should show "Date Not Available" alert **immediately** ✅
6. Tab 1: **Delete the blocked date** → Save
7. Tab 2: **Check the booking dialog** → Alert should disappear **immediately** ✅

### Expected Behavior
- Changes to blocked dates should take effect **immediately** for all users
- No need to wait for cache expiration (previously up to 5 minutes)
- No need for users to refresh their browser
- Real-time updates even in already-open booking dialogs

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

## Related Files Modified

### Backend
- `app/Http/Controllers/Api/CompanySettingController.php` - Added comprehensive cache clearing
- `app/Helpers/CachedSettings.php` - Existing cache helper (used in fix)

### Frontend
- `src/services/companySettingService.js` - Always bypass cache for blocked dates, clear cache after update
- `src/components/NewBookingDialog.vue` - Added real-time blocked dates refresh listener
- `src/components/GlobalBookingDialog.vue` - Added real-time blocked dates refresh listener
- `src/views/CompanySettings.vue` - Dispatches 'company-settings-updated' event (already existed)

### Validation Points (Not Modified)
These files check blocked dates but didn't need changes:
- `app/Http/Controllers/Api/BookingController.php` (line 121)
- `app/Http/Controllers/Api/CartController.php` (line 148)
- `app/Http/Controllers/Api/RecurringScheduleController.php` (line 95)

## Summary of Changes

### Backend
1. ✅ Clear all cache layers when blocked dates are updated (CachedSettings, Laravel Cache, Tagged Cache)
2. ✅ Add HTTP cache-control headers to prevent browser-level caching of settings endpoint

### Frontend Service Layer
3. ✅ `getBlockedBookingDates()` always bypasses cache (passes `false` to `getSettings`)
4. ✅ `updateSettings()` clears cache both before and after update

### Frontend Components
5. ✅ **NewBookingDialog.vue**: All `getSettings()` calls changed to `getSettings(false)`
6. ✅ **GlobalBookingDialog.vue**: All `getSettings()` calls changed to `getSettings(false)`
7. ✅ **CompanySettings.vue**: `loadSettings()` changed to use `getSettings(false)`
8. ✅ Both booking dialogs listen for 'company-settings-updated' events and re-check blocked dates in real-time

### Result
- Changes take effect **immediately** for all users
- No browser refresh needed
- Real-time updates even in already-open booking dialogs
- No stale cache at any layer (application, HTTP, or browser)

## Date Fixed
November 26, 2025
