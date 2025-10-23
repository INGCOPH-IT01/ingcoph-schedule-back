# Step-by-Step Tinker Investigation

Open tinker:
```bash
php artisan tinker
```

## STEP 1: Find your recent bookings

```php
// Get latest bookings
$bookings = App\Models\Booking::with(['user', 'court'])->orderBy('id', 'desc')->take(5)->get();
$bookings->map(function($b) {
    return [
        'id' => $b->id,
        'user' => $b->user->name,
        'court' => $b->court->name,
        'status' => $b->status,
        'time' => $b->start_time,
        'notes' => substr($b->notes ?? '', 0, 40)
    ];
});
```

**👉 Copy the output and identify your two booking IDs**

---

## STEP 2: Check waitlist entries

```php
// Get latest waitlists
$waitlists = App\Models\BookingWaitlist::with(['user'])->orderBy('id', 'desc')->take(5)->get();
$waitlists->map(function($w) {
    return [
        'id' => $w->id,
        'user' => $w->user->name,
        'pending_booking_id' => $w->pending_booking_id,
        'status' => $w->status,
        'updated' => $w->updated_at
    ];
});
```

**👉 Note which waitlist is linked to which booking**

---

## STEP 3: Investigate specific parent booking

```php
// REPLACE 123 with your parent booking ID
$parentId = 123;
$parent = App\Models\Booking::with(['user', 'court'])->find($parentId);

// Show details
[
    'id' => $parent->id,
    'user' => $parent->user->name,
    'status' => $parent->status,
    'court' => $parent->court->name,
    'time' => $parent->start_time,
    'updated' => $parent->updated_at
];
```

---

## STEP 4: Find waitlists linked to parent booking

```php
// Find waitlists for this booking
$waitlistsForParent = App\Models\BookingWaitlist::with(['user'])
    ->where('pending_booking_id', $parentId)
    ->get();

echo "Found: " . $waitlistsForParent->count() . " waitlists\n";

$waitlistsForParent->map(function($w) {
    return [
        'waitlist_id' => $w->id,
        'user' => $w->user->name,
        'status' => $w->status,
        'updated' => $w->updated_at->toDateTimeString()
    ];
});
```

**👉 If count is 0, that's the problem! Waitlist not linked properly.**

---

## STEP 5: Find auto-created bookings

```php
// For each waitlist, find auto-created bookings
foreach ($waitlistsForParent as $wl) {
    $autoBookings = App\Models\Booking::where('user_id', $wl->user_id)
        ->where('court_id', $wl->court_id)
        ->where('start_time', $wl->start_time)
        ->where('end_time', $wl->end_time)
        ->where('notes', 'like', '%Auto-created from waitlist%')
        ->get();

    echo "\nUser: {$wl->user->name}\n";
    echo "Found: " . $autoBookings->count() . " auto-created bookings\n";

    foreach ($autoBookings as $ab) {
        echo "  Booking ID: {$ab->id}\n";
        echo "  Status: {$ab->status}\n";
        echo "  Cart Transaction: " . ($ab->cart_transaction_id ?? 'NULL') . "\n";
        echo "  Updated: {$ab->updated_at}\n";
    }
}
```

**👉 Check if status is 'rejected' or still 'pending'**

---

## STEP 6: Test the cancel method

```php
// Get first waitlist entry
$testWaitlist = $waitlistsForParent->first();

echo "Before: {$testWaitlist->status}\n";

// Try to cancel it
$testWaitlist->cancel();
$testWaitlist->refresh();

echo "After: {$testWaitlist->status}\n";

// Double check in database
$dbCheck = App\Models\BookingWaitlist::find($testWaitlist->id);
echo "In DB: {$dbCheck->status}\n";
```

**👉 If status doesn't change to 'cancelled', there's an issue with the model**

---

## STEP 7: Check cart transactions

```php
// Get auto-created bookings with transactions
$bookingsWithTrans = App\Models\Booking::whereIn('user_id', $waitlistsForParent->pluck('user_id'))
    ->where('notes', 'like', '%Auto-created from waitlist%')
    ->whereNotNull('cart_transaction_id')
    ->get();

foreach ($bookingsWithTrans as $b) {
    $ct = App\Models\CartTransaction::find($b->cart_transaction_id);
    if ($ct) {
        echo "\nTransaction ID: {$ct->id}\n";
        echo "Approval Status: {$ct->approval_status}\n";
        echo "Rejection Reason: " . ($ct->rejection_reason ?? 'NULL') . "\n";
        echo "Updated: {$ct->updated_at}\n";

        // Check cart items
        $items = App\Models\CartItem::where('cart_transaction_id', $ct->id)->get();
        echo "Cart Items:\n";
        foreach ($items as $item) {
            echo "  Item {$item->id}: {$item->status}\n";
        }
    }
}
```

**👉 Check if transactions are 'rejected' and items are 'cancelled'**

---

## STEP 8: Test the controller query

```php
// This is the exact query the controller uses
$foundWaitlists = App\Models\BookingWaitlist::where('pending_booking_id', $parentId)
    ->whereIn('status', ['pending', 'notified'])
    ->get();

echo "Controller query found: " . $foundWaitlists->count() . " waitlists\n";

$foundWaitlists->each(function($w) {
    echo "ID: {$w->id} | Status: {$w->status} | User: {$w->user_id}\n";
});
```

**👉 If count is 0, waitlist status might already be 'cancelled' or 'converted'**

---

## STEP 9: Check if approval triggered the method

```php
// Check if the method exists and is callable
$controller = new App\Http\Controllers\Api\BookingController();
echo "Method exists: " . (method_exists($controller, 'cancelWaitlistForApprovedBooking') ? 'YES' : 'NO') . "\n";
```

---

## STEP 10: Manual cancellation test

```php
// Manually run the cancellation logic
foreach ($waitlistsForParent as $waitlistEntry) {
    echo "\nProcessing Waitlist: {$waitlistEntry->id}\n";
    echo "Current status: {$waitlistEntry->status}\n";

    // Find auto-created bookings
    $autoBookings = App\Models\Booking::where('user_id', $waitlistEntry->user_id)
        ->where('court_id', $waitlistEntry->court_id)
        ->where('start_time', $waitlistEntry->start_time)
        ->where('end_time', $waitlistEntry->end_time)
        ->whereIn('status', ['pending', 'approved'])
        ->where('notes', 'like', '%Auto-created from waitlist%')
        ->get();

    echo "Found {$autoBookings->count()} bookings to reject\n";

    foreach ($autoBookings as $booking) {
        echo "  Booking {$booking->id}: {$booking->status} -> rejecting...\n";

        $booking->update([
            'status' => 'rejected',
            'notes' => $booking->notes . "\n\nAuto-rejected: Parent booking was approved."
        ]);

        echo "  Now: {$booking->fresh()->status}\n";

        // If has transaction
        if ($booking->cart_transaction_id) {
            $ct = App\Models\CartTransaction::find($booking->cart_transaction_id);
            echo "  Transaction {$ct->id}: {$ct->approval_status} -> rejecting...\n";

            $ct->update([
                'approval_status' => 'rejected',
                'rejection_reason' => 'Parent booking was approved - waitlist cancelled'
            ]);

            echo "  Now: {$ct->fresh()->approval_status}\n";
        }
    }

    // Cancel waitlist
    echo "  Waitlist status: {$waitlistEntry->status} -> cancelling...\n";
    $waitlistEntry->cancel();
    $waitlistEntry->refresh();
    echo "  Now: {$waitlistEntry->status}\n";
}
```

**👉 This manually runs the entire process. Watch for any errors.**

---

## STEP 11: Final verification

```php
// Check everything after manual cancellation
echo "=== FINAL STATE ===\n";

$parent = App\Models\Booking::find($parentId);
echo "Parent Booking {$parentId}: {$parent->status}\n\n";

$waitlists = App\Models\BookingWaitlist::where('pending_booking_id', $parentId)->get();
foreach ($waitlists as $w) {
    echo "Waitlist {$w->id}: {$w->status}\n";

    $bookings = App\Models\Booking::where('user_id', $w->user_id)
        ->where('court_id', $w->court_id)
        ->where('start_time', $w->start_time)
        ->where('end_time', $w->end_time)
        ->where('notes', 'like', '%Auto-created%')
        ->get();

    foreach ($bookings as $b) {
        echo "  Booking {$b->id}: {$b->status}\n";
        if ($b->cart_transaction_id) {
            $ct = App\Models\CartTransaction::find($b->cart_transaction_id);
            echo "    Transaction {$ct->id}: {$ct->approval_status}\n";
        }
    }
}
```

---

## 🎯 What to Look For

### ✅ Expected Results:
- Waitlist status: `'cancelled'`
- Auto-booking status: `'rejected'`
- Cart transaction approval_status: `'rejected'`
- Cart items status: `'cancelled'`

### ❌ Common Problems:

1. **"Found: 0 waitlists"** → Waitlist not linked with `pending_booking_id`
2. **Status doesn't change after `cancel()`** → Model method issue
3. **Controller query finds 0** → Waitlist already converted or expired
4. **Bookings not found** → Notes pattern doesn't match
5. **Method not called** → Approval process not triggering cancellation

---

## 📤 Share These Results:

After running through these steps, please share:
1. The output from STEP 4 (how many waitlists found)
2. The output from STEP 5 (auto-created bookings status)
3. Any errors from STEP 10 (manual cancellation)
4. The final state from STEP 11

This will help me pinpoint exactly what's wrong! 🔍
