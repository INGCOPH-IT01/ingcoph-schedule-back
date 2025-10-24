# Debug: Why Parent Booking Was Being Updated

## The Issue
The parent booking was being updated/rejected when it should only be the waitlist user's bookings.

## Root Causes Fixed

### Fix 1: Explicit Exclusion by ID
```php
->where('id', '!=', $approvedBooking->id) // Don't touch parent booking!
```

### Fix 2: Filter by Waitlist User
```php
->where('user_id', $waitlistEntry->user_id) // Only waitlist user's bookings
```

## Complete Query Now

```php
$waitlistBookings = Booking::where('cart_transaction_id', $pendingCartTransaction->id)
    ->where('id', '!=', $approvedBooking->id) // Exclude parent
    ->where('user_id', $waitlistEntry->user_id) // Only waitlist user
    ->whereIn('status', ['pending', 'approved'])
    ->get();
```

## Debug Logging Added

The code now logs exactly what it finds:

```json
{
  "waitlist_id": 300,
  "pending_cart_transaction_id": 200,
  "parent_booking_id": 100,
  "waitlist_user_id": 5,
  "bookings_found": 1,
  "booking_ids": [101]
}
```

## How to Verify It's Fixed

### Step 1: Check the logs when approving

```bash
tail -f storage/logs/laravel.log | grep "Found bookings to reject"
```

You should see:
- `parent_booking_id`: The ID of the booking you're approving
- `booking_ids`: Should NOT include the parent booking ID

### Step 2: Run in Tinker BEFORE approval

```php
php artisan tinker
```

```php
// Set your IDs
$parentId = 100; // Parent booking being approved
$waitlistId = 300; // Waitlist entry

// Get data
$parent = App\Models\Booking::find($parentId);
$waitlist = App\Models\BookingWaitlist::find($waitlistId);
$cart = App\Models\CartTransaction::find($waitlist->pending_cart_transaction_id);

echo "=== BEFORE APPROVAL ===\n";
echo "Parent Booking ID: {$parent->id}\n";
echo "Parent User: {$parent->user_id}\n";
echo "Parent Status: {$parent->status}\n";
echo "Parent Cart Transaction: {$parent->cart_transaction_id}\n\n";

echo "Waitlist User: {$waitlist->user_id}\n";
echo "Waitlist Cart Transaction: {$cart->id}\n\n";

// Check what bookings will be found
$bookingsToReject = App\Models\Booking::where('cart_transaction_id', $cart->id)
    ->where('id', '!=', $parent->id) // Exclude parent
    ->where('user_id', $waitlist->user_id) // Only waitlist user
    ->whereIn('status', ['pending', 'approved'])
    ->get();

echo "Bookings that WILL be rejected:\n";
foreach ($bookingsToReject as $b) {
    echo "  Booking {$b->id} (User: {$b->user_id})\n";
}

// Verify parent is NOT in the list
$parentInList = $bookingsToReject->contains('id', $parent->id);
echo "\nIs parent in reject list? " . ($parentInList ? 'YES - ERROR!' : 'NO - GOOD!') . "\n";
```

### Step 3: Approve the booking via UI

### Step 4: Run in Tinker AFTER approval

```php
// Refresh data
$parent->refresh();
$waitlist->refresh();

echo "\n=== AFTER APPROVAL ===\n";
echo "Parent Status: {$parent->status} (should be 'approved')\n";
echo "Waitlist Status: {$waitlist->status} (should be 'cancelled')\n";

// Check rejected bookings
$rejectedBookings = App\Models\Booking::where('cart_transaction_id', $cart->id)
    ->where('status', 'rejected')
    ->get();

echo "\nBookings that WERE rejected:\n";
foreach ($rejectedBookings as $b) {
    echo "  Booking {$b->id} (User: {$b->user_id})\n";
}

// Final verification
echo "\nParent booking ID: {$parent->id}\n";
echo "Parent still approved? " . ($parent->status === 'approved' ? 'YES - GOOD!' : 'NO - ERROR!') . "\n";
```

## Expected Outcome

### ✅ Correct Behavior:
```
Parent Booking #100 (User A):
- Status: approved ← STAYS APPROVED
- User: User A
- Cart Transaction: 50

Waitlist User's Booking #101 (User B):
- Status: rejected ← GETS REJECTED
- User: User B
- Cart Transaction: 200

Waitlist Entry:
- Status: cancelled ← GETS CANCELLED
```

### ❌ Wrong Behavior (Should NOT happen now):
```
Parent Booking #100:
- Status: rejected ← WRONG!
```

## Why This Could Happen

### Scenario 1: Same Cart Transaction ID
If somehow the parent booking has the same `cart_transaction_id` as the `pending_cart_transaction_id`, it would get selected. Now explicitly excluded by:
```php
->where('id', '!=', $approvedBooking->id)
```

### Scenario 2: User ID Confusion
If there was any user_id matching issue. Now explicitly filtered by:
```php
->where('user_id', $waitlistEntry->user_id)
```

## SQL Query Being Run

```sql
SELECT * FROM bookings
WHERE cart_transaction_id = [pending_cart_transaction_id from waitlist]
AND id != [parent booking id]  -- NEW: Explicit exclusion
AND user_id = [waitlist user id]  -- NEW: Only waitlist user
AND status IN ('pending', 'approved');
```

This query CANNOT possibly return the parent booking because:
1. Parent booking ID is explicitly excluded
2. Parent booking belongs to different user
3. Parent booking likely has different cart_transaction_id

## Test Case to Verify

Create this scenario and test:

```
User A creates Booking #100
└─ cart_transaction_id: 50
└─ status: pending

User B tries to book same slot → Waitlist #300
└─ pending_booking_id: 100
└─ pending_cart_transaction_id: 200
└─ user_id: User B

Cart Transaction #200 (User B's)
└─ Contains Booking #101 (User B's booking)

Admin approves Booking #100
```

**Expected:**
- Booking #100: pending → approved ✅
- Booking #101: pending → rejected ✅
- Waitlist #300: pending → cancelled ✅

**NOT Expected:**
- Booking #100: pending → rejected ❌ (Should NOT happen)

## Database Check After Approval

```sql
-- Check parent booking (should be approved)
SELECT id, user_id, status, cart_transaction_id
FROM bookings
WHERE id = [PARENT_BOOKING_ID];
-- Expected: status = 'approved'

-- Check waitlist user's bookings (should be rejected)
SELECT b.id, b.user_id, b.status, b.cart_transaction_id
FROM bookings b
JOIN booking_waitlists w ON b.cart_transaction_id = w.pending_cart_transaction_id
WHERE w.id = [WAITLIST_ID];
-- Expected: status = 'rejected'

-- Verify they're different bookings
SELECT
    'Parent' as type,
    id,
    user_id,
    status,
    cart_transaction_id
FROM bookings
WHERE id = [PARENT_BOOKING_ID]

UNION ALL

SELECT
    'Waitlist User' as type,
    b.id,
    b.user_id,
    b.status,
    b.cart_transaction_id
FROM bookings b
JOIN booking_waitlists w ON b.cart_transaction_id = w.pending_cart_transaction_id
WHERE w.pending_booking_id = [PARENT_BOOKING_ID];
```

## If Still Having Issues

Run this diagnostic:

```php
php artisan tinker

$parentId = YOUR_PARENT_BOOKING_ID;
$parent = App\Models\Booking::find($parentId);
$waitlist = App\Models\BookingWaitlist::where('pending_booking_id', $parentId)->first();

if (!$waitlist) {
    echo "ERROR: No waitlist found for this parent booking!\n";
    exit;
}

$cart = App\Models\CartTransaction::find($waitlist->pending_cart_transaction_id);

echo "Parent Booking:\n";
echo "  ID: {$parent->id}\n";
echo "  User: {$parent->user_id}\n";
echo "  Cart Transaction: {$parent->cart_transaction_id}\n\n";

echo "Waitlist:\n";
echo "  ID: {$waitlist->id}\n";
echo "  User: {$waitlist->user_id}\n";
echo "  Pending Cart Transaction: {$cart->id}\n\n";

echo "Are they the same user? " . ($parent->user_id === $waitlist->user_id ? "YES - PROBLEM!" : "NO - Good") . "\n";
echo "Same cart transaction? " . ($parent->cart_transaction_id === $cart->id ? "YES - PROBLEM!" : "NO - Good") . "\n";

// Check what the query will find
$found = App\Models\Booking::where('cart_transaction_id', $cart->id)
    ->where('id', '!=', $parent->id)
    ->where('user_id', $waitlist->user_id)
    ->whereIn('status', ['pending', 'approved'])
    ->get();

echo "\nQuery will find {$found->count()} bookings:\n";
foreach ($found as $b) {
    echo "  Booking {$b->id} (User {$b->user_id})\n";
    echo "    Is parent? " . ($b->id === $parent->id ? "YES - ERROR!" : "NO - Good") . "\n";
}
```

Share the output from this diagnostic if the issue persists.

## Files Modified
- `app/Http/Controllers/Api/BookingController.php` (lines 1176-1180)
- `app/Http/Controllers/Api/CartTransactionController.php` (lines 378-382)

## Summary of Protection

**Two-layer protection:**
1. ✅ Explicit ID exclusion: `->where('id', '!=', $parentId)`
2. ✅ User filtering: `->where('user_id', $waitlistUserId)`

The parent booking literally **cannot** be selected by this query.
