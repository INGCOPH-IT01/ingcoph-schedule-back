# Blocked Booking Dates - Troubleshooting Guide

## Issue: I set blocked dates but users can still book

### Fix Applied
**Frontend validation was missing!** The alert was showing but not preventing submission.

**Fixed in this update:**
- ✅ Added validation to `addToCart()` - Prevents adding blocked dates to cart
- ✅ Added validation to `submitBookingWithGCash()` - Prevents GCash bookings
- ✅ Added validation to `submitWaitlistBooking()` - Prevents waitlist bookings
- ✅ Added validation to `submitBookingWithoutPayment()` - Prevents direct bookings
- ✅ Added validation to `submitBooking()` - General booking prevention
- ✅ Added validation to GlobalBookingDialog `handleSubmit()` - Admin booking dialog

### Testing the Fix

#### Step 1: Clear Browser Cache
```bash
# Hard refresh in browser
- Chrome/Edge: Ctrl+Shift+R (Windows) or Cmd+Shift+R (Mac)
- Firefox: Ctrl+F5 (Windows) or Cmd+Shift+R (Mac)
```

#### Step 2: Verify Your Settings
1. Login as **Admin**
2. Go to **Company Settings**
3. Scroll to **"Blocked Booking Dates"**
4. Verify your blocked date is there:
   - Start Date: `2025-12-01`
   - End Date: (empty for "onwards")
   - Should show: "Dec 1, 2025 → Onwards"

#### Step 3: Test as Regular User (Critical!)
**⚠️ IMPORTANT: Admin and Staff bypass blocked dates!**

To properly test:
1. **Logout** from your Admin account
2. **Login as a regular User** (role = 'user')
3. Try to create a booking for December 1, 2025
4. You should see:
   - Red alert: "Date Not Available"
   - If you try to proceed, you'll get a popup error
   - Booking should be **blocked**

#### Step 4: Test as Admin (Should Work)
1. Login as **Admin** or **Staff**
2. Try to book December 1, 2025
3. Should **succeed** (admins bypass restrictions)

## Common Issues

### Issue 1: "I'm testing but it still works!"

**Most Common Cause:** You're logged in as Admin/Staff!

**Solution:**
```javascript
// Check your role in browser console
console.log(JSON.parse(localStorage.getItem('user')))
// Look for: { "role": "user" } ✓ Will be blocked
//          { "role": "admin" } ✗ Not blocked (by design)
//          { "role": "staff" } ✗ Not blocked (by design)
```

### Issue 2: Blocked dates not loading

**Cause:** Cache or settings not saved properly

**Solution:**
1. Open browser console (F12)
2. Run:
```javascript
// Clear settings cache
localStorage.removeItem('api_cache_company_settings')
// Reload page
location.reload()
```

### Issue 3: Date shows alert but still allows booking

**Cause:** Old cached JavaScript files

**Solution:**
1. Clear browser cache completely
2. Or use incognito/private browsing mode
3. Or force rebuild:
```bash
cd /Users/karloalfonso/Documents/GitHub/schedule/ingcoph-schedule-front
npm run build
```

### Issue 4: Backend not blocking the request

**Verify backend is running:**
```bash
cd /Users/karloalfonso/Documents/GitHub/schedule/ingcoph-schedule-back
php artisan serve
```

**Check blocked dates in database:**
```bash
php artisan tinker
```
```php
// In tinker:
$setting = \App\Models\CompanySetting::where('key', 'blocked_booking_dates')->first();
echo $setting->value;
// Should show: [{"start_date":"2025-12-01","end_date":"","reason":"..."}]
```

**Test backend directly:**
```bash
# Using curl (replace TOKEN with your auth token)
curl -X POST http://localhost:8000/api/bookings \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Content-Type: application/json" \
  -d '{
    "court_id": 1,
    "start_time": "2025-12-01 09:00:00",
    "end_time": "2025-12-01 10:00:00"
  }'

# Expected response for regular user:
# {"success":false,"message":"No advance bookings from December onwards","errors":{...}}

# Expected for admin: Success (booking created)
```

## Verification Checklist

Before reporting an issue, verify:

- [ ] I cleared my browser cache (Ctrl+Shift+R / Cmd+Shift+R)
- [ ] I'm testing with a **regular user account** (role = 'user')
- [ ] The blocked date is saved in Company Settings
- [ ] The blocked date shows "→ Onwards" indicator (for indefinite blocks)
- [ ] I checked browser console for errors (F12)
- [ ] Backend server is running (php artisan serve)

## Expected Behavior

### For Regular Users (role = 'user')
1. Select December 1, 2025 in booking dialog
2. See red alert: "🚫 Date Not Available"
3. Try to click "Add to Cart" or "Book Now"
4. See error popup with reason
5. Booking **prevented** ✅

### For Admin/Staff (role = 'admin' or 'staff')
1. Select December 1, 2025 in booking dialog
2. **No alert shown** (admins bypass)
3. Click "Book Now"
4. Booking **succeeds** ✅

## Debug Commands

### Frontend Debug
```javascript
// In browser console (F12):

// 1. Check current user role
console.log('Role:', JSON.parse(localStorage.getItem('user')).role)

// 2. Check blocked dates loaded
companySettingService.getBlockedBookingDates().then(dates => {
  console.log('Blocked dates:', dates)
})

// 3. Check if specific date is blocked
companySettingService.isDateBlocked('2025-12-01', 'user').then(result => {
  console.log('Is blocked?', result)
})
```

### Backend Debug
```bash
# Check logs
tail -f storage/logs/laravel.log

# Check database
php artisan tinker
```
```php
// In tinker:
// Get blocked dates setting
$dates = \App\Models\CompanySetting::get('blocked_booking_dates');
print_r(json_decode($dates, true));

// Test date blocking logic manually
$user = \App\Models\User::where('role', 'user')->first();
$bookingDate = \Carbon\Carbon::parse('2025-12-01');
// Test your validation logic here
```

## Still Not Working?

If you've followed all steps and it's still not working:

1. **Check browser console for JavaScript errors** (F12 → Console tab)
2. **Check backend logs** for validation errors
3. **Verify the blocked date format** in database:
   ```json
   {
     "start_date": "2025-12-01",
     "end_date": "",
     "reason": "No advance bookings from December onwards"
   }
   ```
4. **Test with a brand new incognito/private window**
5. **Restart both frontend and backend servers**

## Quick Reset

If everything is broken, reset and try again:

```bash
# Backend
cd ingcoph-schedule-back
php artisan cache:clear
php artisan config:clear

# Frontend
cd ingcoph-schedule-front
# Clear browser cache (Ctrl+Shift+R)
# Or rebuild
npm run build

# Then:
# 1. Login as Admin
# 2. Go to Company Settings
# 3. Remove all blocked dates
# 4. Add December 1, 2025 onwards again
# 5. Save
# 6. Logout
# 7. Login as regular user
# 8. Try booking December 1, 2025
```

## Contact/Support

If still having issues after following this guide:
1. Check that you're using the latest code (git pull)
2. Verify role in localStorage (must be 'user' to test blocking)
3. Check browser console for errors
4. Check backend logs for validation errors
