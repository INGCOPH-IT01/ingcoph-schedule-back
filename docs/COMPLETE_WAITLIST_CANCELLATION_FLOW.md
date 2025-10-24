# Complete Waitlist Cancellation Flow

## Overview
When a parent booking is approved, the system now cancels **ALL** related records including:
1. ✅ Original pending cart transaction (from when user joined waitlist)
2. ✅ Original cart items (from when user joined waitlist)
3. ✅ Auto-created bookings (if parent was rejected then approved)
4. ✅ Auto-created cart transactions (if user uploaded payment)
5. ✅ The waitlist entry itself

## Complete Data Flow

### Scenario: User B Joins Waitlist, Parent Rejected, Then Parent Approved

```
STEP 1: User A Creates Booking
================================
┌─────────────────────────────┐
│ Booking #100 (User A)       │
│ Status: pending             │
│ Court: Court 1              │
│ Time: 10am-11am             │
└─────────────────────────────┘

STEP 2: User B Tries to Book Same Slot → Joins Waitlist
=========================================================
User B creates cart transaction but slot is taken, so waitlist is created:

┌─────────────────────────────┐
│ Cart Transaction #200       │ ← User B's ORIGINAL transaction
│ User: B                     │
│ Status: pending             │
│ Payment: unpaid             │
└──────────┬──────────────────┘
           │
           ├─ Cart Item #201
           │  Status: pending
           │
           └─ Creates Waitlist:
              ┌────────────────────────────┐
              │ Waitlist Entry #300        │
              │ User: B                    │
              │ pending_booking_id: 100    │ ← Links to User A's booking
              │ pending_cart_transaction_id: 200 │ ← Links to User B's cart!
              │ Status: pending            │
              └────────────────────────────┘

STEP 3: Admin Rejects User A's Booking (Optional)
==================================================
System auto-creates booking for User B:

┌─────────────────────────────┐
│ Booking #101 (User B)       │ ← AUTO-CREATED
│ Status: pending             │
│ Notes: "Auto-created from   │
│        waitlist position #1"│
│ cart_transaction_id: NULL   │
└─────────────────────────────┘

Waitlist updated:
┌────────────────────────────┐
│ Waitlist Entry #300        │
│ Status: notified           │ ← Changed from pending
│ expires_at: 9am tomorrow   │
└────────────────────────────┘

STEP 4: User B Uploads Payment (Optional)
==========================================
User B creates NEW cart transaction with payment:

┌─────────────────────────────┐
│ Cart Transaction #202       │ ← NEW transaction for payment
│ User: B                     │
│ Status: pending             │
│ Payment: paid               │
└──────────┬──────────────────┘
           │
           └─ Links to Booking #101
              cart_transaction_id: 202

STEP 5: Admin Approves User A's Booking (Reverses Decision)
============================================================
CASCADE CANCELLATION TRIGGERS:

1️⃣ CANCEL ORIGINAL CART TRANSACTION (from Step 2)
   Cart Transaction #200
   - approval_status: pending → rejected ✓
   - rejection_reason: "Parent booking was approved - waitlist cancelled"

   Cart Item #201
   - status: pending → cancelled ✓
   - notes: "Cancelled: Parent booking approved, waitlist cancelled"

2️⃣ REJECT AUTO-CREATED BOOKING (from Step 3)
   Booking #101
   - status: pending → rejected ✓
   - notes: "Auto-rejected: Parent booking was approved"

3️⃣ REJECT PAYMENT TRANSACTION (from Step 4)
   Cart Transaction #202
   - approval_status: pending → rejected ✓
   - rejection_reason: "Parent booking was approved - waitlist cancelled"

   Related Cart Items
   - status: completed → cancelled ✓

4️⃣ CANCEL WAITLIST ENTRY
   Waitlist Entry #300
   - status: notified → cancelled ✓

5️⃣ SEND EMAIL NOTIFICATION
   To: User B
   Subject: "Waitlist Booking Cancelled"
   Body: Explains everything is cancelled

6️⃣ BROADCAST REAL-TIME UPDATES
   Event: BookingStatusChanged
   Updates all connected clients
```

## Database Tables Affected

### 1. `booking_waitlists`
```sql
-- Find waitlist
WHERE pending_booking_id = [Parent Booking ID]

-- Update
SET status = 'cancelled'
```

### 2. `cart_transactions` (ORIGINAL - from waitlist creation)
```sql
-- Find using waitlist
WHERE id = booking_waitlists.pending_cart_transaction_id

-- Update
SET approval_status = 'rejected',
    rejection_reason = 'Parent booking was approved - waitlist cancelled'
```

### 3. `cart_items` (ORIGINAL - from waitlist creation)
```sql
-- Find using original transaction
WHERE cart_transaction_id = [Original Transaction ID]
AND status != 'cancelled'

-- Update
SET status = 'cancelled',
    notes = 'Cancelled: Parent booking approved, waitlist cancelled'
```

### 4. `bookings` (AUTO-CREATED - if parent was rejected)
```sql
-- Find auto-created bookings
WHERE user_id = waitlist.user_id
AND court_id = waitlist.court_id
AND start_time = waitlist.start_time
AND end_time = waitlist.end_time
AND notes LIKE '%Auto-created from waitlist%'

-- Update
SET status = 'rejected',
    notes = CONCAT(notes, '\n\nAuto-rejected: Parent booking was approved.')
```

### 5. `cart_transactions` (AUTO-CREATED - if user uploaded payment)
```sql
-- Find using auto-created booking
WHERE id = bookings.cart_transaction_id

-- Update
SET approval_status = 'rejected',
    rejection_reason = 'Parent booking was approved - waitlist cancelled'
```

### 6. `cart_items` (AUTO-CREATED - if user uploaded payment)
```sql
-- Find using auto-created transaction
WHERE cart_transaction_id = [Auto-Created Transaction ID]

-- Update
SET status = 'cancelled',
    notes = 'Cancelled: Parent booking approved'
```

## Code Implementation

### Key Logic in Controllers

```php
// 1. Cancel ORIGINAL cart transaction (from waitlist creation)
if ($waitlistEntry->pending_cart_transaction_id) {
    $pendingCartTransaction = CartTransaction::find($waitlistEntry->pending_cart_transaction_id);
    if ($pendingCartTransaction && $pendingCartTransaction->approval_status !== 'rejected') {
        $pendingCartTransaction->update([
            'approval_status' => 'rejected',
            'rejection_reason' => 'Parent booking was approved - waitlist cancelled'
        ]);

        // Cancel original cart items
        CartItem::where('cart_transaction_id', $pendingCartTransaction->id)
            ->where('status', '!=', 'cancelled')
            ->update([
                'status' => 'cancelled',
                'notes' => 'Cancelled: Parent booking approved, waitlist cancelled'
            ]);
    }
}

// 2. Reject AUTO-CREATED bookings (if parent was rejected)
$autoCreatedBookings = Booking::where('user_id', $waitlistEntry->user_id)
    ->where('court_id', $waitlistEntry->court_id)
    ->where('start_time', $waitlistEntry->start_time)
    ->where('end_time', $waitlistEntry->end_time)
    ->whereIn('status', ['pending', 'approved'])
    ->where('notes', 'like', '%Auto-created from waitlist%')
    ->get();

foreach ($autoCreatedBookings as $booking) {
    $booking->update([
        'status' => 'rejected',
        'notes' => $booking->notes . "\n\nAuto-rejected: Parent booking was approved."
    ]);

    // 3. Reject AUTO-CREATED cart transaction (if user uploaded payment)
    if ($booking->cart_transaction_id) {
        $cartTransaction = CartTransaction::find($booking->cart_transaction_id);
        if ($cartTransaction && $cartTransaction->approval_status !== 'rejected') {
            $cartTransaction->update([
                'approval_status' => 'rejected',
                'rejection_reason' => 'Parent booking was approved - waitlist cancelled'
            ]);

            // Cancel cart items in auto-created transaction
            CartItem::where('cart_transaction_id', $cartTransaction->id)
                ->where('status', '!=', 'cancelled')
                ->update([
                    'status' => 'cancelled',
                    'notes' => 'Cancelled: Parent booking approved'
                ]);
        }
    }
}

// 4. Cancel waitlist entry
$waitlistEntry->cancel();
```

## Verification Queries

### Complete Verification for a Waitlist Entry
```sql
SET @waitlist_id = 300; -- Your waitlist ID

-- 1. Check waitlist status
SELECT 'Waitlist' as type, id, status, pending_cart_transaction_id, updated_at
FROM booking_waitlists
WHERE id = @waitlist_id;

-- 2. Check ORIGINAL cart transaction (from waitlist creation)
SELECT 'Original Cart Transaction' as type,
       ct.id,
       ct.approval_status,
       ct.rejection_reason,
       ct.updated_at
FROM cart_transactions ct
WHERE ct.id = (
    SELECT pending_cart_transaction_id
    FROM booking_waitlists
    WHERE id = @waitlist_id
);

-- 3. Check ORIGINAL cart items
SELECT 'Original Cart Items' as type,
       ci.id,
       ci.status,
       ci.notes,
       ci.updated_at
FROM cart_items ci
WHERE ci.cart_transaction_id = (
    SELECT pending_cart_transaction_id
    FROM booking_waitlists
    WHERE id = @waitlist_id
);

-- 4. Check AUTO-CREATED bookings
SELECT 'Auto-Created Booking' as type,
       b.id,
       b.status,
       b.cart_transaction_id,
       b.updated_at
FROM bookings b
WHERE b.user_id = (SELECT user_id FROM booking_waitlists WHERE id = @waitlist_id)
AND b.notes LIKE '%Auto-created from waitlist%';

-- 5. Check AUTO-CREATED cart transactions (if exists)
SELECT 'Auto-Created Cart Transaction' as type,
       ct.id,
       ct.approval_status,
       ct.rejection_reason,
       ct.updated_at
FROM cart_transactions ct
WHERE ct.id IN (
    SELECT cart_transaction_id
    FROM bookings
    WHERE user_id = (SELECT user_id FROM booking_waitlists WHERE id = @waitlist_id)
    AND notes LIKE '%Auto-created from waitlist%'
    AND cart_transaction_id IS NOT NULL
);
```

### Expected Results After Cancellation
```
✅ Waitlist: status = 'cancelled'
✅ Original Cart Transaction: approval_status = 'rejected'
✅ Original Cart Items: status = 'cancelled'
✅ Auto-Created Booking: status = 'rejected' (if exists)
✅ Auto-Created Cart Transaction: approval_status = 'rejected' (if exists)
✅ Auto-Created Cart Items: status = 'cancelled' (if exists)
```

## Tinker Testing

```php
php artisan tinker
```

```php
// Set your waitlist ID
$waitlistId = 300;
$waitlist = App\Models\BookingWaitlist::find($waitlistId);

echo "=== BEFORE CANCELLATION ===\n";

// Check original transaction
$originalTrans = App\Models\CartTransaction::find($waitlist->pending_cart_transaction_id);
echo "Original Transaction {$originalTrans->id}: {$originalTrans->approval_status}\n";

$originalItems = App\Models\CartItem::where('cart_transaction_id', $originalTrans->id)->get();
echo "Original Items: " . $originalItems->pluck('status')->join(', ') . "\n";

// Check auto-created bookings
$autoBookings = App\Models\Booking::where('user_id', $waitlist->user_id)
    ->where('notes', 'like', '%Auto-created from waitlist%')
    ->get();
echo "Auto-Created Bookings: " . $autoBookings->count() . "\n";
foreach ($autoBookings as $ab) {
    echo "  Booking {$ab->id}: {$ab->status}\n";
    if ($ab->cart_transaction_id) {
        $autoTrans = App\Models\CartTransaction::find($ab->cart_transaction_id);
        echo "    Transaction {$autoTrans->id}: {$autoTrans->approval_status}\n";
    }
}

// Now test the cancellation
echo "\n=== RUNNING CANCELLATION ===\n";

// Trigger the cancellation manually
// (Or approve the parent booking through UI)

echo "\n=== AFTER CANCELLATION ===\n";
// Re-check everything...
```

## Log Output

### Successful Complete Cancellation
```json
{
  "message": "Cancelled pending cart transaction from waitlist",
  "waitlist_id": 300,
  "cart_transaction_id": 200
}

{
  "message": "Waitlist cancelled due to parent booking approval",
  "waitlist_id": 300,
  "rejected_bookings": [101],
  "rejected_transactions": [200, 202],
  "final_status": "cancelled"
}
```

This tells you:
- Original transaction #200 was rejected
- Auto-created booking #101 was rejected
- Payment transaction #202 was rejected
- Waitlist #300 was cancelled

## Summary

### What Gets Cancelled:
1. ✅ **Original Cart Transaction** (via `pending_cart_transaction_id`)
2. ✅ **Original Cart Items** (from original transaction)
3. ✅ **Auto-Created Booking** (if parent was rejected)
4. ✅ **Auto-Created Cart Transaction** (if user uploaded payment)
5. ✅ **Auto-Created Cart Items** (from payment transaction)
6. ✅ **Waitlist Entry** (status → cancelled)

### Files Modified:
- `app/Http/Controllers/Api/BookingController.php` (lines 1212-1235)
- `app/Http/Controllers/Api/CartTransactionController.php` (lines 414-437)

### Key Change:
Now using `pending_cart_transaction_id` from `booking_waitlists` table to find and cancel the ORIGINAL cart transaction that was created when the user joined the waitlist!

## Testing Checklist

- [ ] Original cart transaction rejected
- [ ] Original cart items cancelled
- [ ] Auto-created booking rejected (if exists)
- [ ] Auto-created cart transaction rejected (if exists)
- [ ] Auto-created cart items cancelled (if exists)
- [ ] Waitlist status updated to 'cancelled'
- [ ] Email sent to user
- [ ] Real-time updates broadcast
- [ ] Logs show all transaction IDs

This is now a **complete** cascade cancellation! 🎉
