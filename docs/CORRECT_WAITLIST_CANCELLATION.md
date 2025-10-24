# CORRECT Waitlist Cancellation - Using Proper Relationships

## The Problem I Had
I was incorrectly searching for bookings by user_id, court_id, and time, which could accidentally match the PARENT booking instead of the WAITLIST USER's bookings.

## The Correct Solution
Use the `pending_cart_transaction_id` from the `booking_waitlists` table to find the correct cart transaction, then find all bookings linked to that transaction via `cart_transaction_id`.

## Correct Data Flow

### Understanding the Relationships

```
Parent Booking (User A):
┌─────────────────────────────┐
│ Booking #100                │
│ user_id: User A             │
│ cart_transaction_id: 50     │ ← User A's transaction
│ status: approved            │
└─────────────────────────────┘
        ↓ (referenced by)
┌─────────────────────────────┐
│ booking_waitlists           │
│ pending_booking_id: 100     │ ← Points to parent booking (User A)
│ pending_cart_transaction_id: 200 │ ← Points to waitlist user's transaction (User B)
│ user_id: User B             │
└─────────────────────────────┘
        ↓ (links to)
┌─────────────────────────────┐
│ Cart Transaction #200       │ ← WAITLIST USER's transaction (User B)
│ user_id: User B             │
│ approval_status: pending    │
└──────────┬──────────────────┘
           │ (has many)
           ├─ Cart Item #201
           │  cart_transaction_id: 200
           │  status: pending
           │
           └─ Booking #101 (if created)
              cart_transaction_id: 200
              user_id: User B
              status: pending
```

## Key Fields in booking_waitlists

| Field | Points To | Purpose |
|-------|-----------|---------|
| `pending_booking_id` | Parent booking (User A) | The booking that's blocking this slot |
| `pending_cart_transaction_id` | Waitlist user's transaction (User B) | The transaction created when User B joined waitlist |
| `user_id` | Waitlist user (User B) | The user on the waitlist |

## Correct Cancellation Logic

### WRONG ❌ (What I did before)
```php
// DON'T DO THIS - Could match parent booking!
$bookings = Booking::where('user_id', $waitlistEntry->user_id)
    ->where('court_id', $waitlistEntry->court_id)
    ->where('start_time', $waitlistEntry->start_time)
    ->where('end_time', $waitlistEntry->end_time)
    ->get();
```

### CORRECT ✅ (What I do now)
```php
// Step 1: Get the cart transaction using pending_cart_transaction_id
$pendingCartTransaction = CartTransaction::find($waitlistEntry->pending_cart_transaction_id);

// Step 2: Find bookings linked to THIS cart transaction
$waitlistBookings = Booking::where('cart_transaction_id', $pendingCartTransaction->id)
    ->whereIn('status', ['pending', 'approved'])
    ->get();

// Step 3: Reject the bookings
foreach ($waitlistBookings as $booking) {
    $booking->update(['status' => 'rejected']);
}

// Step 4: Reject the cart transaction
$pendingCartTransaction->update(['approval_status' => 'rejected']);

// Step 5: Cancel cart items
CartItem::where('cart_transaction_id', $pendingCartTransaction->id)
    ->update(['status' => 'cancelled']);
```

## Why This Is Correct

### Using cart_transaction_id as the link:
1. ✅ **Guaranteed to find ONLY waitlist user's bookings** - They're linked via cart_transaction_id
2. ✅ **Won't touch parent booking** - Parent has different cart_transaction_id
3. ✅ **Handles multiple bookings** - If waitlist user has multiple slots in same transaction
4. ✅ **Follows proper relationships** - Uses foreign keys, not field matching

### Why field matching was wrong:
1. ❌ Could match parent booking if user_id/court/time happen to match
2. ❌ Relies on string matching ("Auto-created from waitlist")
3. ❌ Fragile - breaks if notes text changes
4. ❌ Doesn't follow database relationships

## Complete Implementation

```php
// In both BookingController and CartTransactionController

// Get waitlist entry
$waitlistEntry = BookingWaitlist::where('pending_booking_id', $approvedBooking->id)
    ->whereIn('status', ['pending', 'notified'])
    ->first();

// Cancel everything linked to pending_cart_transaction_id
if ($waitlistEntry->pending_cart_transaction_id) {
    $pendingCartTransaction = CartTransaction::find($waitlistEntry->pending_cart_transaction_id);

    if ($pendingCartTransaction) {
        // 1. Find and reject bookings
        $waitlistBookings = Booking::where('cart_transaction_id', $pendingCartTransaction->id)
            ->whereIn('status', ['pending', 'approved'])
            ->get();

        foreach ($waitlistBookings as $booking) {
            $booking->update([
                'status' => 'rejected',
                'notes' => ($booking->notes ?? '') . "\n\nAuto-rejected: Parent booking was approved."
            ]);

            // Broadcast change
            broadcast(new BookingStatusChanged($booking, $oldStatus, 'rejected'))->toOthers();
        }

        // 2. Reject cart transaction
        $pendingCartTransaction->update([
            'approval_status' => 'rejected',
            'rejection_reason' => 'Parent booking was approved - waitlist cancelled'
        ]);

        // 3. Cancel cart items
        CartItem::where('cart_transaction_id', $pendingCartTransaction->id)
            ->where('status', '!=', 'cancelled')
            ->update([
                'status' => 'cancelled',
                'notes' => 'Cancelled: Parent booking approved, waitlist cancelled'
            ]);
    }
}

// 4. Cancel waitlist
$waitlistEntry->cancel();

// 5. Send email
Mail::to($waitlistEntry->user->email)->send(new WaitlistCancelledMail($waitlistEntry));
```

## Verification Query

```sql
-- Replace @waitlist_id with your waitlist entry ID
SET @waitlist_id = 123;

-- Get the waitlist info
SELECT
    'Waitlist Entry' as record_type,
    w.id,
    w.user_id,
    w.pending_booking_id,
    w.pending_cart_transaction_id,
    w.status
FROM booking_waitlists w
WHERE w.id = @waitlist_id;

-- Get the PARENT booking (should NOT be touched)
SELECT
    'Parent Booking (DO NOT TOUCH)' as record_type,
    b.id,
    b.user_id,
    b.status,
    b.cart_transaction_id
FROM bookings b
WHERE b.id = (SELECT pending_booking_id FROM booking_waitlists WHERE id = @waitlist_id);

-- Get the WAITLIST USER's cart transaction (SHOULD be rejected)
SELECT
    'Waitlist Cart Transaction (SHOULD REJECT)' as record_type,
    ct.id,
    ct.user_id,
    ct.approval_status,
    ct.rejection_reason
FROM cart_transactions ct
WHERE ct.id = (SELECT pending_cart_transaction_id FROM booking_waitlists WHERE id = @waitlist_id);

-- Get the WAITLIST USER's bookings (SHOULD be rejected)
SELECT
    'Waitlist User Bookings (SHOULD REJECT)' as record_type,
    b.id,
    b.user_id,
    b.status,
    b.notes
FROM bookings b
WHERE b.cart_transaction_id = (
    SELECT pending_cart_transaction_id
    FROM booking_waitlists
    WHERE id = @waitlist_id
);

-- Get the cart items (SHOULD be cancelled)
SELECT
    'Cart Items (SHOULD CANCEL)' as record_type,
    ci.id,
    ci.status,
    ci.notes
FROM cart_items ci
WHERE ci.cart_transaction_id = (
    SELECT pending_cart_transaction_id
    FROM booking_waitlists
    WHERE id = @waitlist_id
);
```

## Expected Results

After parent booking is approved:

| Record | Status | Notes |
|--------|--------|-------|
| Parent Booking (User A) | `approved` | ✅ NOT touched |
| Waitlist Entry | `cancelled` | ✅ Updated |
| Waitlist Cart Transaction | `rejected` | ✅ Updated |
| Waitlist User Bookings | `rejected` | ✅ Updated |
| Cart Items | `cancelled` | ✅ Updated |

## Testing in Tinker

```php
php artisan tinker

// Get a waitlist entry
$w = App\Models\BookingWaitlist::find(YOUR_WAITLIST_ID);

echo "=== BEFORE ===\n";
echo "Parent Booking ID: {$w->pending_booking_id}\n";
echo "Waitlist Cart Transaction ID: {$w->pending_cart_transaction_id}\n";

$parent = App\Models\Booking::find($w->pending_booking_id);
echo "Parent Status: {$parent->status}\n";

$cart = App\Models\CartTransaction::find($w->pending_cart_transaction_id);
echo "Waitlist Cart Status: {$cart->approval_status}\n";

$bookings = App\Models\Booking::where('cart_transaction_id', $cart->id)->get();
echo "Waitlist Bookings Count: {$bookings->count()}\n";
foreach ($bookings as $b) {
    echo "  Booking {$b->id}: {$b->status}\n";
}

// NOW APPROVE THE PARENT BOOKING VIA UI

echo "\n=== AFTER ===\n";
$parent->refresh();
echo "Parent Status: {$parent->status} (should still be 'approved')\n";

$cart->refresh();
echo "Waitlist Cart Status: {$cart->approval_status} (should be 'rejected')\n";

$bookings->each->refresh();
foreach ($bookings as $b) {
    echo "  Booking {$b->id}: {$b->status} (should be 'rejected')\n";
}

$items = App\Models\CartItem::where('cart_transaction_id', $cart->id)->get();
foreach ($items as $i) {
    echo "  Item {$i->id}: {$i->status} (should be 'cancelled')\n";
}
```

## What Was Fixed

### Before (WRONG):
- Searched for bookings by matching user_id, court_id, start_time, end_time
- Could accidentally match parent booking
- Relied on notes text pattern

### After (CORRECT):
- Uses `pending_cart_transaction_id` to find cart transaction
- Finds bookings via `cart_transaction_id` foreign key
- Guaranteed to only affect waitlist user's records
- Follows proper database relationships

## Files Modified
- `app/Http/Controllers/Api/BookingController.php` (lines 1169-1215)
- `app/Http/Controllers/Api/CartTransactionController.php` (lines 371-417)

This is now the CORRECT implementation! ✅
