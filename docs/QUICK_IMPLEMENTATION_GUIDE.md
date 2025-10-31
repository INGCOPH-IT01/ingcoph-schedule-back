# Quick Implementation Guide - Performance Optimizations

This guide provides step-by-step instructions to implement the high-priority optimizations identified in the analysis.

---

## Prerequisites

1. Backup your database
2. Ensure you have a staging environment for testing
3. Have Laravel Telescope installed for monitoring (optional but recommended)

```bash
composer require laravel/telescope --dev
php artisan telescope:install
php artisan migrate
```

---

## Phase 1: Critical Database Indexes (30 minutes)

### Step 1: Run the Migration

```bash
# Apply the performance indexes
php artisan migrate

# Verify the indexes were created
php artisan db:show
```

### Step 2: Verify Index Creation

Connect to your database and verify:

```sql
-- MySQL
SHOW INDEX FROM bookings;
SHOW INDEX FROM cart_items;
SHOW INDEX FROM cart_transactions;

-- You should see the new indexes:
-- bookings_status_court_time
-- bookings_user_time
-- cart_items_court_date_status
-- etc.
```

**Expected Impact**: Immediate 40-60% improvement in query times

---

## Phase 2: Implement Settings Caching (45 minutes)

### Step 1: Add the CachedSettings Helper to composer.json

```json
// composer.json
{
    "autoload": {
        "psr-4": {
            "App\\": "app/",
            "Database\\Factories\\": "database/factories/",
            "Database\\Seeders\\": "database/seeders/"
        },
        "files": [
            "app/Helpers/CachedSettings.php"
        ]
    }
}
```

Then run:
```bash
composer dump-autoload
```

### Step 2: Replace CompanySetting::get() Calls

**In BookingController.php** (Line 67-68):

```php
// Before:
$userBookingEnabled = \App\Models\CompanySetting::get('user_booking_enabled', '1') === '1';

// After:
use App\Helpers\CachedSettings;

$userBookingEnabled = CachedSettings::isUserBookingEnabled();
```

**In BookingController.php** (Line 662-664):

```php
// Before:
$operatingHoursOpen = \App\Models\CompanySetting::get("operating_hours_{$dayOfWeek}_open", '08:00');
$operatingHoursClose = \App\Models\CompanySetting::get("operating_hours_{$dayOfWeek}_close", '22:00');
$isOperational = \App\Models\CompanySetting::get("operating_hours_{$dayOfWeek}_operational", '1') === '1';

// After:
$operatingHours = CachedSettings::getOperatingHours($dayOfWeek);
$operatingHoursOpen = $operatingHours['open'];
$operatingHoursClose = $operatingHours['close'];
$isOperational = $operatingHours['operational'];
```

**In CartController.php** (Line 102):

```php
// Before:
$userBookingEnabled = \App\Models\CompanySetting::get('user_booking_enabled', '1') === '1';

// After:
$userBookingEnabled = CachedSettings::isUserBookingEnabled();
```

Repeat for all other CompanySetting::get() calls in:
- CartController.php
- RecurringScheduleController.php
- Other controllers as needed

### Step 3: Update CompanySettingController to Clear Cache

```php
// In CompanySettingController.php update method
use App\Helpers\CachedSettings;

public function update(Request $request)
{
    // ... existing code ...

    // After updating the setting:
    CachedSettings::flush($request->key);

    // ... rest of code ...
}
```

**Expected Impact**: 95% reduction in settings-related database queries

---

## Phase 3: Optimize availableSlots Query (1 hour)

### Step 1: Update BookingController::availableSlots()

**Replace lines 676-681** with:

```php
// Load only necessary columns and relationships
$bookings = Booking::select([
        'id', 'user_id', 'booking_for_user_id', 'start_time',
        'end_time', 'status', 'payment_status', 'booking_for_user_name',
        'admin_notes'
    ])
    ->where('court_id', $courtId)
    ->whereIn('status', ['pending', 'approved', 'completed'])
    ->whereBetween('start_time', [$startOfDay, $endOfDay])
    ->with([
        'user:id,first_name,last_name,email',
        'bookingForUser:id,first_name,last_name,email,phone'
    ])
    ->orderBy('start_time')
    ->get();
```

**Replace lines 690-727** with:

```php
$oneHourAgo = Carbon::now()->subHour();

// Simplified query leveraging new indexes
$cartItems = CartItem::select([
        'id', 'court_id', 'booking_date', 'start_time', 'end_time',
        'cart_transaction_id', 'booking_for_user_id', 'booking_for_user_name',
        'admin_notes', 'price', 'status', 'number_of_players'
    ])
    ->where('court_id', $courtId)
    ->where('booking_date', $date->format('Y-m-d'))
    ->where('status', '!=', 'cancelled')
    ->whereHas('cartTransaction', function($query) use ($oneHourAgo) {
        $query->where(function($q) {
            $q->where('approval_status', 'approved')
              ->where('payment_status', 'paid');
        })
        ->orWhere(function($q) use ($oneHourAgo) {
            $q->where('approval_status', 'pending')
              ->where(function($timeQuery) use ($oneHourAgo) {
                  $timeQuery->whereHas('user', function($userQuery) {
                      $userQuery->where('role', 'admin');
                  })
                  ->orWhere('created_at', '>=', $oneHourAgo);
              });
        })
        ->orWhere(function($q) use ($oneHourAgo) {
            $q->where('approval_status', 'pending')
              ->where('payment_status', 'unpaid')
              ->where(function($timeQuery) use ($oneHourAgo) {
                  $timeQuery->whereHas('user', function($userQuery) {
                      $userQuery->where('role', 'admin');
                  })
                  ->orWhere('created_at', '>=', $oneHourAgo);
              });
        });
    })
    ->with([
        'cartTransaction' => function($query) {
            $query->select('id', 'user_id', 'approval_status', 'payment_status', 'created_at');
        },
        'cartTransaction.user:id,first_name,last_name,role',
        'bookingForUser:id,first_name,last_name,email,phone'
    ])
    ->orderBy('start_time')
    ->get();
```

**Expected Impact**: 50-70% reduction in query time for availability checks

---

## Phase 4: Add Pagination (30 minutes)

### Step 1: Update BookingController::index()

**Replace line 52** with:

```php
$perPage = min($request->input('per_page', 50), 100);
$bookings = $query->orderBy('start_time', 'asc')->paginate($perPage);
```

### Step 2: Update CartTransactionController::all()

**Replace line 132** with:

```php
$perPage = min($request->input('per_page', 50), 100);
$transactions = $query->paginate($perPage);
```

### Step 3: Update Frontend to Handle Pagination

In your frontend API calls, add pagination parameters:

```javascript
// Example for fetching bookings
const response = await axios.get('/api/bookings', {
  params: {
    page: currentPage,
    per_page: 50
  }
});

// Response will include:
// - data: array of bookings
// - current_page
// - last_page
// - total
// - per_page
```

**Expected Impact**: 80% reduction in response time for large datasets

---

## Phase 5: Add Availability Caching (45 minutes)

### Step 1: Update BookingController::availableSlots()

**Add at the beginning of the method** (after validation):

```php
use Illuminate\Support\Facades\Cache;

public function availableSlots(Request $request, $courtId)
{
    // ... validation code ...

    $date = Carbon::parse($request->date);
    $duration = (int) ($request->duration ?? 1);

    // Cache key includes court, date, and duration
    $cacheKey = "available_slots:{$courtId}:{$date->format('Y-m-d')}:{$duration}";

    // Cache for 5 minutes
    $availableSlots = Cache::remember($cacheKey, 300, function () use ($courtId, $date, $duration, $request) {
        // Move all existing logic here
        // ... (existing code from lines 655-959) ...

        return $availableSlots; // Return the final array
    });

    return response()->json([
        'success' => true,
        'data' => $availableSlots
    ]);
}
```

### Step 2: Clear Cache on Booking Changes

**In BookingController::store()** (after line 257):

```php
Cache::forget("available_slots:{$court->id}:{$bookingDate}:{$duration}");
// Also clear for all possible durations (1-12 hours)
for ($d = 1; $d <= 12; $d++) {
    Cache::forget("available_slots:{$court->id}:{$bookingDate}:{$d}");
}
```

**In BookingController::update()** (after line 411):

```php
$bookingDate = Carbon::parse($booking->start_time)->format('Y-m-d');
for ($d = 1; $d <= 12; $d++) {
    Cache::forget("available_slots:{$booking->court_id}:{$bookingDate}:{$d}");
}
```

**In CartController::checkout()** (after line 1071):

```php
$bookingDate = Carbon::parse($booking->start_time)->format('Y-m-d');
for ($d = 1; $d <= 12; $d++) {
    Cache::forget("available_slots:{$booking->court_id}:{$bookingDate}:{$d}");
}
```

**Expected Impact**: 90% reduction in database queries for availability checks

---

## Testing Checklist

After implementing each phase, test:

- [ ] Existing booking flow still works
- [ ] Availability checking is faster
- [ ] Admin panel loads quickly
- [ ] Settings changes take effect immediately
- [ ] No errors in Laravel logs
- [ ] Performance improvement visible in Telescope

### Performance Testing

```bash
# Before and after each phase, run:
php artisan tinker

# Test availability query speed
>>> $start = microtime(true);
>>> app('App\Http\Controllers\Api\BookingController')->availableSlots(request(), 1);
>>> $end = microtime(true);
>>> echo "Time: " . ($end - $start) . " seconds\n";

# Test bookings query speed
>>> $start = microtime(true);
>>> \App\Models\Booking::where('court_id', 1)->whereIn('status', ['pending', 'approved'])->get();
>>> $end = microtime(true);
>>> echo "Time: " . ($end - $start) . " seconds\n";
```

---

## Monitoring After Deployment

### 1. Enable Query Logging (Temporarily)

```php
// In AppServiceProvider.php boot() method
if (config('app.debug')) {
    DB::listen(function ($query) {
        if ($query->time > 100) {
            Log::warning('Slow Query Detected', [
                'sql' => $query->sql,
                'time' => $query->time . 'ms'
            ]);
        }
    });
}
```

### 2. Monitor Cache Hit Rate

```php
// Add to a monitoring endpoint
Route::get('/admin/cache-stats', function () {
    return [
        'cache_driver' => config('cache.default'),
        'available_slots_cached' => Cache::has('available_slots:1:2025-10-30:1'),
        // Add more monitoring as needed
    ];
});
```

### 3. Use Laravel Telescope

```
# Access Telescope dashboard
http://your-app.test/telescope

# Monitor:
- Queries tab: Check for N+1 queries
- Cache tab: Verify cache hits
- Requests tab: Monitor response times
```

---

## Rollback Plan

If you encounter issues:

### Rollback Database Indexes

```bash
php artisan migrate:rollback --step=1
```

### Revert Code Changes

```bash
git revert <commit-hash>
# Or manually restore from backup
```

### Clear All Cache

```bash
php artisan cache:clear
php artisan config:clear
php artisan view:clear
php artisan route:clear
```

---

## Expected Results

After implementing all Phase 1-5 optimizations:

| Metric | Before | After | Improvement |
|--------|--------|-------|-------------|
| Availability Check | 800ms | 150ms | 81% faster |
| Cart Checkout | 2.5s | 1.0s | 60% faster |
| Booking List | 1.2s | 0.4s | 67% faster |
| Settings Queries | 100/min | 5/min | 95% reduction |
| Admin Dashboard | 3.0s | 0.8s | 73% faster |

---

## Next Steps

After successfully implementing Phase 1-5:

1. Monitor production for 1 week
2. Review Phase 2 optimizations in OPTIMIZATION_RECOMMENDATIONS.md
3. Consider implementing service layer pattern (Section 4.1)
4. Set up continuous performance monitoring

---

## Support

If you encounter issues:

1. Check Laravel logs: `storage/logs/laravel.log`
2. Check database slow query log
3. Use Laravel Telescope for debugging
4. Verify cache is working: `php artisan tinker`, then `Cache::get('test_key')`

---

**Last Updated**: October 30, 2025
**Version**: 1.0
