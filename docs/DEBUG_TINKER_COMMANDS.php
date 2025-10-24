<?php
/**
 * Debug Tinker Commands
 * Run these in: php artisan tinker
 * Copy and paste each section one at a time
 */

// ============================================
// STEP 1: Get the latest bookings
// ============================================
echo "=== LATEST BOOKINGS ===\n";
$bookings = App\Models\Booking::with(['user', 'court'])
    ->orderBy('id', 'desc')
    ->limit(5)
    ->get();

foreach ($bookings as $booking) {
    echo sprintf(
        "ID: %d | User: %s | Court: %s | Status: %s | Time: %s | Notes: %s\n",
        $booking->id,
        $booking->user->name ?? 'N/A',
        $booking->court->name ?? 'N/A',
        $booking->status,
        $booking->start_time,
        substr($booking->notes ?? '', 0, 50)
    );
}

// ============================================
// STEP 2: Get latest waitlist entries
// ============================================
echo "\n=== LATEST WAITLIST ENTRIES ===\n";
$waitlists = App\Models\BookingWaitlist::with(['user', 'court'])
    ->orderBy('id', 'desc')
    ->limit(5)
    ->get();

foreach ($waitlists as $waitlist) {
    echo sprintf(
        "ID: %d | User: %s | PendingBooking: %s | Status: %s | Court: %s | Time: %s\n",
        $waitlist->id,
        $waitlist->user->name ?? 'N/A',
        $waitlist->pending_booking_id ?? 'NULL',
        $waitlist->status,
        $waitlist->court->name ?? 'N/A',
        $waitlist->start_time
    );
}

// ============================================
// STEP 3: Check specific parent booking
// Replace [PARENT_BOOKING_ID] with actual ID
// ============================================
echo "\n=== CHECKING PARENT BOOKING ===\n";
$parentBookingId = [PARENT_BOOKING_ID]; // REPLACE THIS
$parentBooking = App\Models\Booking::with(['user', 'court'])->find($parentBookingId);

if ($parentBooking) {
    echo "Parent Booking ID: {$parentBooking->id}\n";
    echo "User: {$parentBooking->user->name}\n";
    echo "Court: {$parentBooking->court->name}\n";
    echo "Status: {$parentBooking->status}\n";
    echo "Start Time: {$parentBooking->start_time}\n";
    echo "End Time: {$parentBooking->end_time}\n";
    echo "Updated At: {$parentBooking->updated_at}\n";
} else {
    echo "Booking not found!\n";
}

// ============================================
// STEP 4: Find waitlist entries linked to this parent booking
// ============================================
echo "\n=== WAITLIST ENTRIES FOR THIS BOOKING ===\n";
$waitlistsForParent = App\Models\BookingWaitlist::with(['user'])
    ->where('pending_booking_id', $parentBookingId)
    ->get();

echo "Found: " . $waitlistsForParent->count() . " waitlist entries\n";
foreach ($waitlistsForParent as $wl) {
    echo sprintf(
        "  Waitlist ID: %d | User: %s | Status: %s | Updated: %s\n",
        $wl->id,
        $wl->user->name,
        $wl->status,
        $wl->updated_at
    );
}

// ============================================
// STEP 5: Find auto-created bookings for these waitlist users
// ============================================
echo "\n=== AUTO-CREATED BOOKINGS ===\n";
foreach ($waitlistsForParent as $wl) {
    $autoBookings = App\Models\Booking::where('user_id', $wl->user_id)
        ->where('court_id', $wl->court_id)
        ->where('start_time', $wl->start_time)
        ->where('end_time', $wl->end_time)
        ->where('notes', 'like', '%Auto-created from waitlist%')
        ->get();

    echo "User: {$wl->user->name} ({$wl->user_id})\n";
    echo "  Found: " . $autoBookings->count() . " auto-created bookings\n";
    foreach ($autoBookings as $ab) {
        echo sprintf(
            "    Booking ID: %d | Status: %s | Cart Trans: %s | Updated: %s\n",
            $ab->id,
            $ab->status,
            $ab->cart_transaction_id ?? 'NULL',
            $ab->updated_at
        );
        echo "    Notes: " . substr($ab->notes, 0, 100) . "\n";
    }
}

// ============================================
// STEP 6: Check if cancellation method is working
// Test the cancel method manually
// ============================================
echo "\n=== TESTING CANCEL METHOD ===\n";
if ($waitlistsForParent->count() > 0) {
    $testWaitlist = $waitlistsForParent->first();
    echo "Testing waitlist ID: {$testWaitlist->id}\n";
    echo "Status before: {$testWaitlist->status}\n";

    // Try calling cancel
    $testWaitlist->cancel();
    $testWaitlist->refresh();

    echo "Status after cancel(): {$testWaitlist->status}\n";
    echo "Updated at: {$testWaitlist->updated_at}\n";

    // Check in database
    $dbCheck = App\Models\BookingWaitlist::find($testWaitlist->id);
    echo "Status in DB: {$dbCheck->status}\n";
}

// ============================================
// STEP 7: Check cart transactions
// ============================================
echo "\n=== CART TRANSACTIONS ===\n";
foreach ($waitlistsForParent as $wl) {
    $autoBookings = App\Models\Booking::where('user_id', $wl->user_id)
        ->where('notes', 'like', '%Auto-created from waitlist%')
        ->whereNotNull('cart_transaction_id')
        ->get();

    foreach ($autoBookings as $ab) {
        if ($ab->cart_transaction_id) {
            $ct = App\Models\CartTransaction::find($ab->cart_transaction_id);
            if ($ct) {
                echo sprintf(
                    "Transaction ID: %d | Status: %s | Rejection: %s | Updated: %s\n",
                    $ct->id,
                    $ct->approval_status,
                    $ct->rejection_reason ?? 'NULL',
                    $ct->updated_at
                );

                // Check cart items
                $items = App\Models\CartItem::where('cart_transaction_id', $ct->id)->get();
                echo "  Cart Items: " . $items->count() . "\n";
                foreach ($items as $item) {
                    echo "    Item ID: {$item->id} | Status: {$item->status}\n";
                }
            }
        }
    }
}

// ============================================
// STEP 8: Check if the issue is with the query
// Test the exact query used in the controller
// ============================================
echo "\n=== TESTING CONTROLLER QUERY ===\n";
$testWaitlists = App\Models\BookingWaitlist::where('pending_booking_id', $parentBookingId)
    ->whereIn('status', [
        App\Models\BookingWaitlist::STATUS_PENDING,
        App\Models\BookingWaitlist::STATUS_NOTIFIED
    ])
    ->orderBy('position')
    ->orderBy('created_at')
    ->get();

echo "Query found: " . $testWaitlists->count() . " waitlists\n";
foreach ($testWaitlists as $tw) {
    echo "  ID: {$tw->id} | Status: {$tw->status} | User: {$tw->user_id}\n";
}

// ============================================
// STEP 9: Simulate the complete cancellation process
// ============================================
echo "\n=== SIMULATING CANCELLATION PROCESS ===\n";
foreach ($testWaitlists as $waitlistEntry) {
    echo "\nProcessing Waitlist ID: {$waitlistEntry->id}\n";

    // Find auto-created bookings
    $autoCreatedBookings = App\Models\Booking::where('user_id', $waitlistEntry->user_id)
        ->where('court_id', $waitlistEntry->court_id)
        ->where('start_time', $waitlistEntry->start_time)
        ->where('end_time', $waitlistEntry->end_time)
        ->whereIn('status', ['pending', 'approved'])
        ->where('notes', 'like', '%Auto-created from waitlist%')
        ->get();

    echo "  Found {$autoCreatedBookings->count()} auto-created bookings\n";

    foreach ($autoCreatedBookings as $booking) {
        echo "    Booking ID: {$booking->id}\n";
        echo "    Current status: {$booking->status}\n";
        echo "    Cart Transaction ID: " . ($booking->cart_transaction_id ?? 'NULL') . "\n";

        if ($booking->cart_transaction_id) {
            $cartTrans = App\Models\CartTransaction::find($booking->cart_transaction_id);
            if ($cartTrans) {
                echo "    Transaction approval_status: {$cartTrans->approval_status}\n";
            }
        }
    }

    echo "  Waitlist current status: {$waitlistEntry->status}\n";
}

// ============================================
// STEP 10: Check logs for errors
// ============================================
echo "\n=== CHECKING RECENT LOGS ===\n";
echo "Run this in terminal:\n";
echo "tail -100 storage/logs/laravel.log | grep -i 'waitlist\\|booking.*approved'\n";

// ============================================
// STEP 11: Get full picture of a problematic case
// ============================================
echo "\n=== COMPLETE RELATIONSHIP CHECK ===\n";
echo "Parent Booking: {$parentBookingId}\n";
echo "Status: " . App\Models\Booking::find($parentBookingId)->status . "\n";
echo "\nAll related records:\n";

$relatedWaitlists = App\Models\BookingWaitlist::where('pending_booking_id', $parentBookingId)->get();
foreach ($relatedWaitlists as $rw) {
    echo "\n📋 Waitlist #{$rw->id}\n";
    echo "   User: {$rw->user->name} (ID: {$rw->user_id})\n";
    echo "   Status: {$rw->status}\n";
    echo "   Updated: {$rw->updated_at}\n";

    // Find related bookings
    $relatedBookings = App\Models\Booking::where('user_id', $rw->user_id)
        ->where('court_id', $rw->court_id)
        ->where('start_time', $rw->start_time)
        ->where('end_time', $rw->end_time)
        ->get();

    foreach ($relatedBookings as $rb) {
        echo "   📅 Booking #{$rb->id}\n";
        echo "      Status: {$rb->status}\n";
        echo "      Notes: " . substr($rb->notes ?? '', 0, 60) . "\n";

        if ($rb->cart_transaction_id) {
            $ct = App\Models\CartTransaction::find($rb->cart_transaction_id);
            echo "      💳 Transaction #{$ct->id}\n";
            echo "         Approval: {$ct->approval_status}\n";
            echo "         Payment: {$ct->payment_status}\n";
            echo "         Rejection reason: " . ($ct->rejection_reason ?? 'NULL') . "\n";
        }
    }
}

echo "\n=== END OF DIAGNOSTICS ===\n";
echo "\nPlease share the output above!\n";
