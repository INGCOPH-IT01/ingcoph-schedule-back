# Proper Foreign Key Solution for Waitlist Cancellation

## The Problem with Field Matching

**Previous approach was fragile:**
- Matching by `user_id`, `court_id`, `booking_date`, `start_time`, `end_time`
- Risk of matching wrong records if multiple users book same slot (data inconsistency)
- No proper database relationship
- Relies on string/time matching which can break

## The Correct Solution: Foreign Key Relationship

**Add `booking_waitlist_id` to `cart_items` table:**
- Proper foreign key constraint
- Direct link from cart item to waitlist entry
- Guaranteed uniqueness - one cart item = one waitlist entry
- Uses database relationships, not field matching

## Implementation

### 1. Database Migration

**File:** `database/migrations/2024_10_24_000001_add_booking_waitlist_id_to_cart_items.php`

```php
Schema::table('cart_items', function (Blueprint $table) {
    $table->unsignedBigInteger('booking_waitlist_id')->nullable()->after('cart_transaction_id');

    $table->foreign('booking_waitlist_id')
        ->references('id')
        ->on('booking_waitlists')
        ->onDelete('set null');

    $table->index('booking_waitlist_id');
});
```

**Run the migration:**
```bash
php artisan migrate
```

### 2. Model Update

**File:** `app/Models/CartItem.php`

Added to `$fillable`:
```php
'booking_waitlist_id',
```

### 3. Cart Creation Update

**File:** `app/Http/Controllers/Api/CartController.php` (lines 269-312)

**Changed order:**
1. Create waitlist entry FIRST
2. Then create cart item with `booking_waitlist_id` link

```php
// Create waitlist entry FIRST so we have the ID
$waitlistEntry = BookingWaitlist::create([
    'user_id' => $userId,
    'pending_booking_id' => $pendingBookingId,
    'pending_cart_transaction_id' => $pendingCartTransactionId,
    'court_id' => $item['court_id'],
    'sport_id' => $item['sport_id'],
    'start_time' => $startDateTime,
    'end_time' => $endDateTime,
    'price' => $item['price'],
    'number_of_players' => $item['number_of_players'] ?? 1,
    'position' => $nextPosition,
    'status' => BookingWaitlist::STATUS_PENDING
]);

// Create cart item with waitlist ID link
$cartItem = CartItem::create([
    'user_id' => $userId,
    'cart_transaction_id' => $cartTransaction->id,
    'booking_waitlist_id' => $waitlistEntry->id, // ✅ Direct link!
    'court_id' => $item['court_id'],
    'sport_id' => $item['sport_id'],
    // ... other fields
]);
```

### 4. Cancellation Update

**Files:**
- `app/Http/Controllers/Api/BookingController.php` (line 1171)
- `app/Http/Controllers/Api/CartTransactionController.php` (line 373)

**Before (fragile):**
```php
$waitlistCartItems = CartItem::where('user_id', $waitlistEntry->user_id)
    ->where('court_id', $waitlistEntry->court_id)
    ->where('booking_date', $waitlistEntry->start_time->format('Y-m-d'))
    ->where('start_time', $waitlistEntry->start_time->format('H:i:s'))
    ->where('end_time', $waitlistEntry->end_time->format('H:i:s'))
    ->where('status', '!=', 'cancelled')
    ->get();
```

**After (proper foreign key):**
```php
$waitlistCartItems = CartItem::where('booking_waitlist_id', $waitlistEntry->id)
    ->where('status', '!=', 'cancelled')
    ->get();
```

## Benefits

### ✅ Reliability
- Uses proper foreign key relationship
- Guaranteed to find the correct cart items
- No risk of matching wrong records

### ✅ Performance
- Simple indexed lookup by ID
- No complex WHERE conditions with multiple fields
- Faster query execution

### ✅ Data Integrity
- Foreign key constraint ensures referential integrity
- `onDelete('set null')` handles cleanup automatically
- No orphaned records

### ✅ Simplicity
- One line query instead of five conditions
- Easier to understand and maintain
- Less prone to bugs

## Database Schema

```
cart_items
├─ id (primary key)
├─ cart_transaction_id → cart_transactions.id
├─ booking_waitlist_id → booking_waitlists.id ✅ NEW!
├─ user_id
├─ court_id
├─ booking_date
├─ start_time
├─ end_time
└─ status

booking_waitlists
├─ id (primary key)
├─ user_id
├─ pending_booking_id
├─ pending_cart_transaction_id
├─ court_id
├─ start_time
├─ end_time
└─ status
```

## Data Flow

```
User B joins waitlist:
1. Create Waitlist Entry (ID: 300)
2. Create Cart Item with booking_waitlist_id: 300 ✅
   └─ Linked via foreign key!

Parent booking approved:
1. Find Cart Items: WHERE booking_waitlist_id = 300 ✅
2. Cancel cart items
3. Reject cart transactions
4. Reject bookings
5. Cancel waitlist entry
```

## Testing

### After Migration

```bash
php artisan migrate
```

### Create a Waitlist

```php
php artisan tinker

// Simulate joining a waitlist
$waitlist = App\Models\BookingWaitlist::create([
    'user_id' => 5,
    'pending_booking_id' => 100,
    'pending_cart_transaction_id' => 50,
    'court_id' => 1,
    'sport_id' => 1,
    'start_time' => '2024-10-25 10:00:00',
    'end_time' => '2024-10-25 11:00:00',
    'price' => 500,
    'number_of_players' => 2,
    'position' => 1,
    'status' => 'pending'
]);

// Create cart item linked to waitlist
$cartItem = App\Models\CartItem::create([
    'user_id' => 5,
    'cart_transaction_id' => 200,
    'booking_waitlist_id' => $waitlist->id, // ✅ Link!
    'court_id' => 1,
    'sport_id' => 1,
    'booking_date' => '2024-10-25',
    'start_time' => '10:00:00',
    'end_time' => '11:00:00',
    'price' => 500,
    'number_of_players' => 2,
    'status' => 'pending'
]);

echo "Created waitlist: {$waitlist->id}\n";
echo "Created cart item: {$cartItem->id} linked to waitlist: {$cartItem->booking_waitlist_id}\n";
```

### Verify Foreign Key Works

```php
// Find cart items by waitlist ID
$items = App\Models\CartItem::where('booking_waitlist_id', $waitlist->id)->get();
echo "Found {$items->count()} cart items linked to waitlist {$waitlist->id}\n";

// The OLD fragile way (don't use this anymore!)
$oldWay = App\Models\CartItem::where('user_id', $waitlist->user_id)
    ->where('court_id', $waitlist->court_id)
    ->where('booking_date', $waitlist->start_time->format('Y-m-d'))
    ->where('start_time', $waitlist->start_time->format('H:i:s'))
    ->where('end_time', $waitlist->end_time->format('H:i:s'))
    ->get();
echo "Old way found: {$oldWay->count()} items\n";

// Should be the same count, but new way is more reliable!
```

### Test Cancellation

```php
// Approve parent booking (simulated)
$waitlist = App\Models\BookingWaitlist::find(300);

// Find cart items using NEW method
$cartItems = App\Models\CartItem::where('booking_waitlist_id', $waitlist->id)
    ->where('status', '!=', 'cancelled')
    ->get();

echo "Found {$cartItems->count()} cart items to cancel\n";

// Cancel them
foreach ($cartItems as $item) {
    $item->update(['status' => 'cancelled']);
    echo "Cancelled cart item {$item->id}\n";
}
```

## SQL Verification

```sql
-- Check the foreign key exists
SHOW CREATE TABLE cart_items;
-- Should see: FOREIGN KEY (`booking_waitlist_id`) REFERENCES `booking_waitlists` (`id`)

-- Check cart items linked to waitlist
SELECT
    ci.id as cart_item_id,
    ci.booking_waitlist_id,
    ci.status,
    w.id as waitlist_id,
    w.status as waitlist_status,
    w.user_id
FROM cart_items ci
LEFT JOIN booking_waitlists w ON ci.booking_waitlist_id = w.id
WHERE ci.booking_waitlist_id IS NOT NULL;

-- Find cart items for a specific waitlist (SIMPLE!)
SELECT * FROM cart_items
WHERE booking_waitlist_id = 300;
```

## Migration Notes

### For Existing Data

If you have existing waitlist entries and cart items without the link:

```sql
-- Option 1: Clear old data (if testing/development)
TRUNCATE cart_items;
TRUNCATE booking_waitlists;

-- Option 2: Try to match and update (risky, use with caution)
UPDATE cart_items ci
JOIN booking_waitlists w ON (
    ci.user_id = w.user_id
    AND ci.court_id = w.court_id
    AND ci.booking_date = DATE(w.start_time)
    AND ci.start_time = TIME(w.start_time)
    AND ci.end_time = TIME(w.end_time)
)
SET ci.booking_waitlist_id = w.id
WHERE ci.booking_waitlist_id IS NULL;
```

**Recommendation:** For production, manually review and link records or start fresh after migration.

## Comparison

### OLD Method (Fragile) ❌
```php
// 5 conditions, fragile matching
CartItem::where('user_id', $user)
    ->where('court_id', $court)
    ->where('booking_date', $date)
    ->where('start_time', $startTime)
    ->where('end_time', $endTime)
    ->get();
```

**Problems:**
- Multiple users could match same court/time
- String/time format issues
- No database relationship
- Slow query with multiple conditions

### NEW Method (Proper) ✅
```php
// 1 condition, direct foreign key
CartItem::where('booking_waitlist_id', $waitlistId)
    ->get();
```

**Benefits:**
- Guaranteed unique match
- Indexed lookup (fast)
- Proper database relationship
- Simple and maintainable

## Files Changed

1. ✅ `database/migrations/2024_10_24_000001_add_booking_waitlist_id_to_cart_items.php` - NEW
2. ✅ `app/Models/CartItem.php` - Added to fillable
3. ✅ `app/Http/Controllers/Api/CartController.php` - Save waitlist ID when creating cart items
4. ✅ `app/Http/Controllers/Api/BookingController.php` - Use foreign key for cancellation
5. ✅ `app/Http/Controllers/Api/CartTransactionController.php` - Use foreign key for cancellation

## Next Steps

1. Run the migration:
   ```bash
   php artisan migrate
   ```

2. Test creating a waitlist - cart item should have `booking_waitlist_id` set

3. Test approving parent booking - cart items should be found and cancelled

4. Verify in logs - should show cart items found via foreign key

This is now a **proper database relationship** instead of fragile field matching! 🎉
