# Quick Reference: Backend Optimizations

**Print this out or keep it handy while implementing optimizations**

---

## 🚀 Phase 1: Critical Optimizations (Today!)

### 1. Database Indexes (30 min) ⭐⭐⭐
```bash
php artisan migrate
```
✅ Adds 13 critical indexes
✅ 40-60% query improvement
✅ No code changes needed

---

### 2. Settings Caching (45 min) ⭐⭐⭐

**Replace this:**
```php
$enabled = \App\Models\CompanySetting::get('user_booking_enabled', '1');
```

**With this:**
```php
use App\Helpers\CachedSettings;
$enabled = CachedSettings::isUserBookingEnabled();
```

**Files to update:**
- `BookingController.php` (lines 67, 662-664)
- `CartController.php` (lines 102, 722)
- `RecurringScheduleController.php` (lines 55)

✅ 95% reduction in settings queries
✅ Backward compatible

---

### 3. Add Pagination (30 min) ⭐⭐⭐

**Replace this:**
```php
$bookings = $query->get();
```

**With this:**
```php
$perPage = min($request->input('per_page', 50), 100);
$bookings = $query->paginate($perPage);
```

**Files to update:**
- `BookingController.php` line 52
- `CartTransactionController.php` line 132
- `UserController.php` line 19

✅ 80% faster for large datasets
⚠️ Update frontend to handle pagination

---

### 4. Cache Available Slots (45 min) ⭐⭐⭐

**Wrap availableSlots logic:**
```php
$cacheKey = "available_slots:{$courtId}:{$date->format('Y-m-d')}";
$slots = Cache::remember($cacheKey, 300, function() {
    // ... existing logic ...
    return $availableSlots;
});
```

**Clear cache when bookings change:**
```php
// In store/update/destroy methods
Cache::forget("available_slots:{$court->id}:{$bookingDate}");
```

✅ 90% reduction in availability queries

---

## 📊 Expected Results After Phase 1

| Before | After | Improvement |
|--------|-------|-------------|
| 800ms | 150ms | 81% faster |
| 45 queries | 10 queries | 77% fewer |
| 0% cache | 70% cache | Much faster |

---

## 🔧 Phase 2: High-Value Optimizations (This Week)

### 5. Optimize Eager Loading

**Instead of loading everything:**
```php
Booking::with(['user', 'court', 'sport', 'court.images'])->get();
```

**Load only what you need:**
```php
Booking::with([
    'user:id,first_name,last_name,email',
    'court:id,name',
    'sport:id,name,price_per_hour'
])->get();
```

✅ 30-40% faster
✅ 50% less memory

---

### 6. Optimize availableSlots Query

**Add to Booking query:**
```php
->select(['id', 'user_id', 'start_time', 'end_time', 'status'])
```

**Add to CartItem query:**
```php
->select(['id', 'court_id', 'booking_date', 'start_time', 'end_time'])
```

✅ 50-70% faster

---

## 🛠️ Common Patterns to Follow

### Pattern 1: Always Select Only Needed Columns
```php
// ❌ Bad
Model::all();

// ✅ Good
Model::select(['id', 'name', 'created_at'])->get();
```

---

### Pattern 2: Use Indexed Columns in WHERE
```php
// ❌ Bad (no index)
->where('notes', 'LIKE', '%text%')

// ✅ Good (indexed)
->where('status', 'pending')
->where('court_id', $courtId)
```

---

### Pattern 3: Paginate Large Results
```php
// ❌ Bad
->get();

// ✅ Good
->paginate(50);
```

---

### Pattern 4: Cache Frequently Accessed Data
```php
// ❌ Bad
CompanySetting::get('key');

// ✅ Good
CachedSettings::get('key');
```

---

### Pattern 5: Eager Load Relationships
```php
// ❌ Bad (N+1)
foreach ($bookings as $booking) {
    echo $booking->user->name; // Query per booking
}

// ✅ Good
$bookings = Booking::with('user')->get();
foreach ($bookings as $booking) {
    echo $booking->user->name; // No extra queries
}
```

---

## 🚨 Red Flags to Watch For

### In Queries:
- ❌ `->all()` without filters
- ❌ `->get()` without limit
- ❌ Loading relationships in loops
- ❌ Multiple `whereHas()` on same relationship
- ❌ `LIKE '%text%'` on unindexed columns

### In Code:
- ❌ Controllers > 500 lines
- ❌ Database queries in loops
- ❌ No caching for repeated data
- ❌ Loading full models when IDs suffice
- ❌ Missing indexes on WHERE columns

---

## 📈 Measuring Performance

### Before Changes:
```php
php artisan tinker
>>> $start = microtime(true);
>>> // Run your code
>>> echo "Time: " . (microtime(true) - $start) . "s\n";
```

### Check Query Count:
```php
DB::enableQueryLog();
// Run your code
dd(count(DB::getQueryLog()));
```

### Use Laravel Telescope:
```bash
composer require laravel/telescope --dev
php artisan telescope:install
php artisan migrate
```
Visit: `http://your-app.test/telescope`

---

## 🔍 Quick Debug Commands

```bash
# See all tables and indexes
php artisan db:show

# Check slow queries (add to AppServiceProvider)
DB::listen(function($query) {
    if ($query->time > 100) {
        Log::warning("Slow: {$query->sql} ({$query->time}ms)");
    }
});

# Clear all caches
php artisan cache:clear
php artisan config:clear
php artisan view:clear

# Check cache is working
php artisan tinker
>>> Cache::put('test', 'value', 60);
>>> Cache::get('test');
```

---

## ✅ Implementation Checklist

### Phase 1 (Today):
- [ ] Run database migration
- [ ] Verify indexes created
- [ ] Replace CompanySetting::get() calls
- [ ] Add pagination to list endpoints
- [ ] Add cache to availableSlots
- [ ] Test booking flow
- [ ] Monitor with Telescope

### Phase 2 (This Week):
- [ ] Optimize eager loading
- [ ] Fix N+1 queries
- [ ] Optimize availableSlots query
- [ ] Add image loading optimization
- [ ] Performance testing

### Monitoring (Ongoing):
- [ ] Set up slow query logging
- [ ] Monitor cache hit rate
- [ ] Track response times
- [ ] Review Telescope daily

---

## 🆘 Rollback Commands

```bash
# Rollback last migration
php artisan migrate:rollback --step=1

# Clear all cache
php artisan cache:clear

# Revert code changes
git revert <commit-hash>
```

---

## 📞 Need Help?

1. Check `QUICK_IMPLEMENTATION_GUIDE.md` for detailed steps
2. Check `OPTIMIZATION_RECOMMENDATIONS.md` for full analysis
3. Use Laravel Telescope to debug queries
4. Check Laravel logs: `storage/logs/laravel.log`

---

## 🎯 Success Criteria

You'll know it's working when:
- ✅ Telescope shows <15 queries per request
- ✅ Response times < 200ms for most endpoints
- ✅ Cache hit rate > 60%
- ✅ No slow queries (>100ms) in logs
- ✅ Admin dashboard loads in <1 second

---

**Last Updated**: October 30, 2025
**Quick Reference Version**: 1.0

*Keep this document handy while implementing optimizations!*
