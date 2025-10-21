# Business Hours Expiration System

## Overview
The booking system implements a business hours-based expiration logic for cart transactions and bookings. This ensures that expiration timers respect business hours (8am-5pm, Monday-Saturday) and holidays.

## Business Rules

### Expiration Timer Rules

1. **During Business Hours (8am-5pm, Mon-Sat)**
   - Timer starts immediately upon booking creation
   - Booking expires 1 hour after creation
   - Example: Booking created at 10:30am → Expires at 11:30am

2. **After Business Hours (After 5pm)**
   - Timer starts at 8am the next business day
   - Booking expires at 9am the next business day
   - Example: Booking created Tuesday 6pm → Timer starts Wednesday 8am → Expires Wednesday 9am

3. **Before Business Hours (Before 8am)**
   - Timer starts at 8am same day
   - Booking expires at 9am same day
   - Example: Booking created at 7am → Timer starts 8am → Expires 9am

4. **On Sundays**
   - Timer starts at 8am the next business day (Monday or next non-holiday)
   - Example: Booking created Sunday 3pm → Timer starts Monday 8am → Expires Monday 9am

5. **On Holidays**
   - Timer starts at 8am the next business day
   - Skips consecutive holidays
   - Example: Booking created on holiday → Timer starts next working day 8am

### Admin User Exception

**Admin bookings NEVER expire automatically** - they must be manually approved or cancelled.

This allows admins to:
- Accept walk-in customers paying cash
- Handle special payment arrangements
- Manage VIP bookings without time pressure

## Implementation

### Backend Components

#### 1. Business Hours Helper
**File:** `app/Helpers/BusinessHoursHelper.php`

Key methods:
- `isWithinBusinessHours()` - Check if time is within 8am-5pm
- `isWorkingDay()` - Check if date is Mon-Sat and not a holiday
- `getNextWorkingDay()` - Get next available working day
- `calculateExpirationTime()` - Calculate expiration based on business hours
- `isExpired()` - Check if a booking has expired
- `getTimeRemainingFormatted()` - Get human-readable time remaining

Constants:
- `BUSINESS_START_HOUR = 8` (8:00 AM)
- `BUSINESS_END_HOUR = 17` (5:00 PM)

#### 2. Holiday Model
**File:** `app/Models/Holiday.php`

Features:
- Store holiday dates
- Support recurring holidays (e.g., Christmas, New Year)
- Check if a date is a holiday
- Get all holidays for a specific year

Fields:
- `date` - Holiday date
- `name` - Holiday name
- `description` - Optional description
- `is_recurring` - Whether holiday repeats yearly

#### 3. Expiration Commands
**File:** `app/Console/Commands/CancelExpiredCartItems.php`

Updates:
- Uses `BusinessHoursHelper::isExpired()` to check expiration
- Skips admin bookings from automatic expiration
- Logs all expired transactions
- Reports count of skipped admin transactions

Schedule: Runs automatically via Laravel scheduler (typically every minute)

#### 4. Cart Controller
**File:** `app/Http/Controllers/Api/CartController.php`

Methods updated:
- `checkAndExpireCartItems()` - Checks expiration using business hours logic
- `getExpirationInfo()` - API endpoint providing expiration details to frontend

New endpoint: `GET /api/cart/expiration-info`

Response:
```json
{
  "success": true,
  "has_transaction": true,
  "is_admin": false,
  "created_at": "2025-10-21T14:30:00Z",
  "expires_at": "2025-10-21T15:30:00Z",
  "time_remaining_seconds": 1800,
  "time_remaining_formatted": "30m 0s",
  "is_expired": false
}
```

### Frontend Components

#### 1. Booking Cart Component
**File:** `src/components/BookingCart.vue`

Updates:
- Calls `/api/cart/expiration-info` endpoint every second
- Displays formatted time remaining from backend
- Shows "Pending next business day" when timer hasn't started
- Shows "No expiration (Admin)" for admin users
- Shows warning when less than 10 minutes remain

#### 2. Bookings View
**File:** `src/views/Bookings.vue`

Updates:
- Added `calculateBusinessHoursExpiration()` helper function
- Updated `isBookingExpired()` to use business hours logic
- Client-side calculation for display purposes

#### 3. Holiday Management UI
**File:** `src/views/HolidayManagement.vue`

Features:
- View all configured holidays
- Add new holidays (one-time or recurring)
- Edit existing holidays
- Delete holidays
- Info alert explaining impact on expiration

Navigation: Admin menu → Holiday Management

### Holiday Management API

#### Endpoints

**List all holidays**
```
GET /api/admin/holidays
```

**Get holidays for specific year**
```
GET /api/admin/holidays/year/{year}
```

**Create holiday**
```
POST /api/admin/holidays
Body: {
  "date": "2025-12-25",
  "name": "Christmas Day",
  "description": "Optional",
  "is_recurring": true
}
```

**Update holiday**
```
PUT /api/admin/holidays/{id}
Body: {
  "date": "2025-12-25",
  "name": "Christmas Day",
  "description": "Updated description",
  "is_recurring": true
}
```

**Delete holiday**
```
DELETE /api/admin/holidays/{id}
```

**Check if date is holiday**
```
POST /api/admin/holidays/check-date
Body: {
  "date": "2025-12-25"
}
```

## User Experience

### For Regular Users

**Scenario 1: Booking during business hours**
- User creates booking at 2:30pm Monday
- Timer shows: "57m 30s" (counting down)
- Booking expires at 3:30pm Monday

**Scenario 2: Booking after 5pm**
- User creates booking at 6:15pm Tuesday
- Timer shows: "Pending next business day (Wed 8:00 AM)"
- Timer starts Wednesday 8am
- Booking expires Wednesday 9am

**Scenario 3: Booking on Sunday**
- User creates booking at 11am Sunday
- Timer shows: "Pending next business day (Mon 8:00 AM)"
- Timer starts Monday 8am
- Booking expires Monday 9am

**Scenario 4: Booking on holiday**
- User creates booking on Christmas (holiday)
- Timer shows: "Pending next business day (Dec 26 8:00 AM)"
- If Dec 26 is Sunday, moves to Dec 27
- Timer starts at 8am, expires at 9am

### For Admin Users

- Timer always shows: "No expiration (Admin)"
- No countdown displayed
- No automatic expiration
- Must manually approve or cancel bookings
- Full control over booking lifecycle

## Database

### Migrations

**Holidays Table**
- Created: `2025_10_21_065944_create_holidays_table.php`
- Fields: `id`, `date`, `name`, `description`, `is_recurring`, `created_at`, `updated_at`
- Index: `date` field for fast lookups

## Configuration

### Business Hours
Defined in: `app/Helpers/BusinessHoursHelper.php`

To change business hours, update constants:
```php
const BUSINESS_START_HOUR = 8;   // 8:00 AM
const BUSINESS_END_HOUR = 17;    // 5:00 PM (17:00)
```

### Working Days
Currently: Monday - Saturday
Sunday is always non-working
Holidays are configurable via Holiday Management UI

## Testing

### Test Scenarios

1. **Create booking at 10am Monday**
   - Expected: Expires at 11am Monday

2. **Create booking at 6pm Friday**
   - Expected: Timer starts 8am Monday, expires 9am Monday

3. **Create booking at 7am Tuesday**
   - Expected: Timer starts 8am Tuesday, expires 9am Tuesday

4. **Create booking on Sunday**
   - Expected: Timer starts 8am Monday, expires 9am Monday

5. **Create booking on holiday**
   - Expected: Timer starts 8am next working day

6. **Admin creates booking anytime**
   - Expected: Never expires, shows "No expiration (Admin)"

7. **Let timer expire**
   - Expected: Booking status becomes "expired", slots released

## Scheduled Tasks

The expiration check runs via Laravel scheduler. Add to `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    // Check for expired cart items every minute
    $schedule->command('cart:cancel-expired')->everyMinute();
}
```

Ensure cron is running:
```bash
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
```

## Benefits

✅ **Fair for Users** - No expiration outside business hours
✅ **Flexible for Admins** - No pressure to immediately process walk-ins
✅ **Holiday Aware** - Automatically adjusts for non-working days
✅ **Transparent** - Clear communication of when timer starts/expires
✅ **Configurable** - Holidays can be added/removed as needed
✅ **Scalable** - Supports recurring holidays (yearly events)

## Logging

All expiration events are logged:

```
[INFO] Skipped admin cart transaction #123 from expiration
[INFO] Expired cart transaction #456 with 3 items (created: 2025-10-20 18:30:00)
[INFO] Skipped 5 admin cart transactions from expiration.
[INFO] Skipped 12 transactions that have not yet expired.
```

## Migration from Old System

### Differences from Previous Implementation

**Old System:**
- Simple 1-hour countdown from creation
- No business hours consideration
- Expires even if created at night

**New System:**
- Business hours aware (8am-5pm)
- Respects weekends and holidays
- Timer starts at next business day if created after hours

### Backward Compatibility

Existing bookings created before this update will:
- Continue to work normally
- Use new business hours logic for expiration checks
- Not be retroactively expired if they're past old 1-hour limit but within new business hours limit

## Support

For issues or questions:
1. Check logs: `storage/logs/laravel.log`
2. Verify holidays are configured correctly
3. Ensure scheduled tasks are running
4. Test with different time scenarios

## Related Documentation

- [Admin Booking Expiration Fix](./ADMIN_BOOKING_EXPIRATION_FIX.md)
- [Operating Hours Implementation](./operating-hours-implementation.md)
- [Cart System Documentation](./CART_SYSTEM.md)
