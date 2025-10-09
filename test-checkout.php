<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use App\Models\CartItem;
use App\Models\Booking;
use Illuminate\Support\Facades\DB;

echo "=== Testing Checkout Process ===\n\n";

// Get first user
$user = User::first();
if (!$user) {
    echo "ERROR: No users found!\n";
    exit(1);
}
echo "User: {$user->name} (ID: {$user->id})\n\n";

// Get cart items
$cartItems = CartItem::with('court')
    ->where('user_id', $user->id)
    ->whereNull('booking_id')
    ->orderBy('court_id')
    ->orderBy('booking_date')
    ->orderBy('start_time')
    ->get();

echo "Cart Items: {$cartItems->count()}\n";
if ($cartItems->isEmpty()) {
    echo "ERROR: No cart items found!\n";
    exit(1);
}

// Show cart items
foreach ($cartItems as $item) {
    echo "  - Court {$item->court_id} on {$item->booking_date} from {$item->start_time} to {$item->end_time} (₱{$item->price})\n";
}
echo "\n";

// Group items
$groupedBookings = [];
$currentGroup = null;

foreach ($cartItems as $item) {
    $bookingDate = $item->booking_date instanceof \Carbon\Carbon ? $item->booking_date->format('Y-m-d') : $item->booking_date;
    $groupKey = $item->court_id . '_' . $bookingDate;

    if (!$currentGroup || $currentGroup['key'] !== $groupKey || 
        $currentGroup['end_time'] !== $item->start_time) {
        // Start new group
        if ($currentGroup) {
            $groupedBookings[] = $currentGroup;
        }
        $currentGroup = [
            'key' => $groupKey,
            'court_id' => $item->court_id,
            'booking_date' => $bookingDate,
            'start_time' => $item->start_time,
            'end_time' => $item->end_time,
            'price' => $item->price,
            'items' => [$item->id]
        ];
    } else {
        // Extend current group
        $currentGroup['end_time'] = $item->end_time;
        $currentGroup['price'] += $item->price;
        $currentGroup['items'][] = $item->id;
    }
}

// Add last group
if ($currentGroup) {
    $groupedBookings[] = $currentGroup;
}

echo "Grouped into " . count($groupedBookings) . " booking(s):\n";
foreach ($groupedBookings as $i => $group) {
    echo "  Group " . ($i + 1) . ": Court {$group['court_id']} on {$group['booking_date']} from {$group['start_time']} to {$group['end_time']} (₱{$group['price']}) - " . count($group['items']) . " items\n";
}
echo "\n";

// Try to create bookings
DB::beginTransaction();
try {
    $createdBookings = [];
    
    foreach ($groupedBookings as $group) {
        echo "Creating booking for group...\n";
        
        $bookingData = [
            'user_id' => $user->id,
            'court_id' => $group['court_id'],
            'start_time' => $group['booking_date'] . ' ' . $group['start_time'],
            'end_time' => $group['booking_date'] . ' ' . $group['end_time'],
            'total_price' => $group['price'],
            'status' => 'pending',
            'payment_method' => 'gcash',
            'payment_status' => 'paid',
            'gcash_reference' => 'TEST123',
            'proof_of_payment' => 'test_proof',
            'paid_at' => now()
        ];
        
        echo "  Data: " . json_encode($bookingData, JSON_PRETTY_PRINT) . "\n";
        
        $booking = Booking::create($bookingData);
        echo "  ✓ Booking created with ID: {$booking->id}\n";
        
        // Link cart items
        $updated = CartItem::whereIn('id', $group['items'])->update(['booking_id' => $booking->id]);
        echo "  ✓ Linked {$updated} cart items to booking\n\n";
        
        $createdBookings[] = $booking;
    }
    
    DB::commit();
    echo "✓ Transaction committed successfully!\n\n";
    
    echo "=== Results ===\n";
    echo "Created bookings: " . count($createdBookings) . "\n";
    echo "Total bookings in DB: " . Booking::count() . "\n";
    echo "Active cart items: " . CartItem::whereNull('booking_id')->count() . "\n";
    echo "Checked out cart items: " . CartItem::whereNotNull('booking_id')->count() . "\n";
    
} catch (\Exception $e) {
    DB::rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}
