# FINAL CORRECT Waitlist Cancellation Logic

## The Issue You Identified ✅

**`pending_cart_transaction_id` does NOT link to the waitlist user's cart transaction!**

Looking at the code in `CartController.php` line 302:
```php
'pending_cart_transaction_id' => $pendingCartTransactionId
```

This `$pendingCartTransactionId` is the **PARENT's** cart transaction ID (User A's), NOT the waitlist user's (User B's)!

## The Correct Data Flow

When User B joins a waitlist:

```
1. User B tries to book a taken slot
2. System creates:
   ├─ Cart Item (User B's) ← Created at line 270-284
   │  user_id: User B
   │  court_id: Court 1
   │  cart_transaction_id: [User B's transaction] ← THIS is what we need!
   │  booking_date: 2024-10-24
   │  start_time: 10:00:00
   │  end_time: 11:00:00
   │
   └─ Waitlist Entry
      user_id: User B
      pending_booking_id: [User A's booking] ← Points to parent
      pending_cart_transaction_id: [User A's transaction] ← WRONG! Not User B's!
      court_id: Court 1
      start_time: 2024-10-24 10:00:00
      end_time: 2024-10-24 11:00:00
```

## The Correct Solution

**Find cart items by matching the waitlist criteria:**

```php
// Find User B's cart items by matching court, time, and user
$waitlistCartItems = CartItem::where('user_id', $waitlistEntry->user_id)
    ->where('court_id', $waitlistEntry->court_id)
    ->where('booking_date', $waitlistEntry->start_time->format('Y-m-d'))
    ->where('start_time', $waitlistEntry->start_time->format('H:i:s'))
    ->where('end_time', $waitlistEntry->end_time->format('H:i:s'))
    ->where('status', '!=', 'cancelled')
    ->get();
```

This finds:
- ✅ Cart items belonging to **waitlist user** (User B)
- ✅ For the **same court and time** as the waitlist
- ✅ That are **not already cancelled**

Then from the cart items, we can:
1. Cancel the cart items
2. Find their cart transactions via `cart_transaction_id`
3. Reject those transactions
4. Find and reject any bookings linked to those transactions

## Complete Implementation

### Step 1: Find Waitlist User's Cart Items
```php
$waitlistCartItems = CartItem::where('user_id', $waitlistEntry->user_id)
    ->where('court_id', $waitlistEntry->court_id)
    ->where('booking_date', $waitlistEntry->start_time->format('Y-m-d'))
    ->where('start_time', $waitlistEntry->start_time->format('H:i:s'))
    ->where('end_time', $waitlistEntry->end_time->format('H:i:s'))
    ->where('status', '!=', 'cancelled')
    ->get();
```

### Step 2: Cancel Each Cart Item
```php
foreach ($waitlistCartItems as $cartItem) {
    $cartItem->update([
        'status' => 'cancelled',
        'notes' => 'Cancelled: Parent booking was approved, waitlist cancelled'
    ]);
}
```

### Step 3: Reject Cart Transactions
```php
foreach ($waitlistCartItems as $cartItem) {
    if ($cartItem->cart_transaction_id) {
        $cartTransaction = CartTransaction::find($cartItem->cart_transaction_id);
        if ($cartTransaction && $cartTransaction->approval_status !== 'rejected') {
            $cartTransaction->update([
                'approval_status' => 'rejected',
                'rejection_reason' => 'Parent booking was approved - waitlist cancelled'
            ]);
        }
    }
}
```

### Step 4: Reject Bookings
```php
$transactionBookings = Booking::where('cart_transaction_id', $cartTransaction->id)
    ->where('id', '!=', $approvedBooking->id) // Don't touch parent!
    ->where('user_id', $waitlistEntry->user_id) // Only waitlist user
    ->whereIn('status', ['pending', 'approved'])
    ->get();

foreach ($transactionBookings as $booking) {
    $booking->update([
        'status' => 'rejected',
        'notes' => ($booking->notes ?? '') . "\n\nAuto-rejected: Parent booking was approved."
    ]);
}
```

### Step 5: Cancel Waitlist Entry
```php
$waitlistEntry->cancel(); // status → 'cancelled'
```

## Why This Works

### ✅ Correct Approach:
1. Finds cart items by **matching waitlist criteria** (user, court, time)
2. These cart items were created when User B joined the waitlist
3. From cart items → find cart transactions
4. From cart transactions → find bookings
5. Everything is properly linked and cancelled

### ❌ Wrong Approach (before):
1. Used `pending_cart_transaction_id` which points to **Parent's transaction**
2. Would find **Parent's bookings** instead of waitlist user's
3. Could accidentally cancel parent booking!

## Database Relationships

```
Waitlist Entry (User B)
├─ user_id: User B
├─ pending_booking_id → Booking (User A) ← DON'T USE FOR CANCELLATION
└─ pending_cart_transaction_id → Cart Transaction (User A) ← DON'T USE FOR CANCELLATION

Find by MATCHING:
Cart Items (User B) ← START HERE
├─ user_id: User B
├─ court_id: matches waitlist
├─ booking_date: matches waitlist
├─ start_time: matches waitlist
├─ end_time: matches waitlist
└─ cart_transaction_id → Cart Transaction (User B) ← USER B's transaction!
    └─ bookings → Bookings (User B) ← USER B's bookings!
```

## Testing in Tinker

```php
php artisan tinker

// Get waitlist
$waitlistId = 300;
$waitlist = App\Models\BookingWaitlist::find($waitlistId);

echo "=== WAITLIST INFO ===\n";
echo "User ID: {$waitlist->user_id}\n";
echo "Court ID: {$waitlist->court_id}\n";
echo "Time: {$waitlist->start_time} to {$waitlist->end_time}\n\n";

// Find cart items using the CORRECT method
$cartItems = App\Models\CartItem::where('user_id', $waitlist->user_id)
    ->where('court_id', $waitlist->court_id)
    ->where('booking_date', $waitlist->start_time->format('Y-m-d'))
    ->where('start_time', $waitlist->start_time->format('H:i:s'))
    ->where('end_time', $waitlist->end_time->format('H:i:s'))
    ->get();

echo "=== CART ITEMS FOUND ===\n";
echo "Count: {$cartItems->count()}\n";
foreach ($cartItems as $item) {
    echo "  Item {$item->id}: status={$item->status}, cart_transaction_id={$item->cart_transaction_id}\n";

    if ($item->cart_transaction_id) {
        $ct = App\Models\CartTransaction::find($item->cart_transaction_id);
        echo "    Transaction {$ct->id}: user={$ct->user_id}, status={$ct->approval_status}\n";

        $bookings = App\Models\Booking::where('cart_transaction_id', $ct->id)->get();
        foreach ($bookings as $b) {
            echo "      Booking {$b->id}: user={$b->user_id}, status={$b->status}\n";
        }
    }
}

// Verify parent booking won't be touched
$parent = App\Models\Booking::find($waitlist->pending_booking_id);
echo "\n=== PARENT BOOKING ===\n";
echo "ID: {$parent->id}\n";
echo "User: {$parent->user_id}\n";
echo "Status: {$parent->status}\n";
echo "Cart Transaction: {$parent->cart_transaction_id}\n\n";

echo "Parent will be touched? " . ($parent->user_id == $waitlist->user_id ? "YES - ERROR!" : "NO - Good!") . "\n";
```

## Expected Log Output

```json
{
  "message": "Found cart items to cancel for waitlist",
  "waitlist_id": 300,
  "waitlist_user_id": 5,
  "parent_booking_id": 100,
  "cart_items_found": 1,
  "cart_item_ids": [201],
  "cart_transaction_ids": [200]
}

{
  "message": "Cancelled waitlist cart items and related records",
  "waitlist_id": 300,
  "cancelled_cart_items": [201],
  "rejected_transactions": [200],
  "rejected_bookings": [101]
}
```

This clearly shows:
- Found cart item #201 (User B's)
- From cart transaction #200 (User B's)
- Rejected booking #101 (User B's)
- **Parent booking #100 is NOT in the list!**

## Verification Query

```sql
-- Check waitlist user's cart items
SELECT
    ci.id as cart_item_id,
    ci.user_id,
    ci.cart_transaction_id,
    ci.status as item_status,
    ct.approval_status as transaction_status,
    b.id as booking_id,
    b.status as booking_status
FROM cart_items ci
LEFT JOIN cart_transactions ct ON ci.cart_transaction_id = ct.id
LEFT JOIN bookings b ON b.cart_transaction_id = ct.id
WHERE ci.user_id = [WAITLIST_USER_ID]
AND ci.court_id = [COURT_ID]
AND ci.booking_date = '[DATE]'
AND ci.start_time = '[TIME]'
AND ci.end_time = '[TIME]';
```

After cancellation, should show:
- `item_status` = 'cancelled'
- `transaction_status` = 'rejected'
- `booking_status` = 'rejected' (if exists)

## Summary

### The Key Insight:
**Don't use `pending_cart_transaction_id` for cancellation! It points to the wrong transaction (parent's).**

### The Correct Method:
**Find cart items by matching the waitlist criteria (user, court, date, time).**

This ensures:
- ✅ Only waitlist user's records are affected
- ✅ Parent booking is never touched
- ✅ Proper cancellation chain: Cart Items → Cart Transactions → Bookings
- ✅ Complete cleanup of all related records

## Files Updated
- `app/Http/Controllers/Api/BookingController.php` (lines 1170-1238)
- `app/Http/Controllers/Api/CartTransactionController.php` (lines 372-440)

This is now the FINAL CORRECT implementation! 🎉
