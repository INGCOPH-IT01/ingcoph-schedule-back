<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Booking;
use App\Models\CartTransaction;
use App\Models\CartItem;
use Carbon\Carbon;

class AnalyzeOctober22Data extends Command
{
    protected $signature = 'analyze:oct22';
    protected $description = 'Analyze all bookings and cart transactions booked on October 22, 2025 and report data inconsistencies';

    protected $inconsistencies = [];
    protected $stats = [];

    public function handle()
    {
        $this->info('===========================================');
        $this->info('October 22, 2025 - Booking Analysis Report');
        $this->info('===========================================');
        $this->newLine();

        // Define the date to analyze
        $targetDate = '2025-10-22';
        $startOfDay = Carbon::parse($targetDate)->startOfDay();
        $endOfDay = Carbon::parse($targetDate)->endOfDay();

        // Get all bookings created on Oct 22
        $bookingsCreatedOnDate = Booking::whereBetween('created_at', [$startOfDay, $endOfDay])
            ->with(['user', 'bookingForUser', 'court', 'sport', 'cartTransaction'])
            ->orderBy('created_at')
            ->get();

        // Get all bookings scheduled for Oct 22
        $bookingsScheduledOnDate = Booking::whereDate('start_time', $targetDate)
            ->with(['user', 'bookingForUser', 'court', 'sport', 'cartTransaction'])
            ->orderBy('start_time')
            ->get();

        // Get all cart transactions created on Oct 22
        $cartTransactionsCreatedOnDate = CartTransaction::whereBetween('created_at', [$startOfDay, $endOfDay])
            ->with(['user', 'bookingForUser', 'cartItems', 'bookings'])
            ->orderBy('created_at')
            ->get();

        // Get all cart items with booking date of Oct 22
        $cartItemsForDate = CartItem::where('booking_date', $targetDate)
            ->with(['user', 'bookingForUser', 'court', 'sport', 'cartTransaction'])
            ->orderBy('created_at')
            ->get();

        // Display stats
        $this->info('📊 OVERVIEW STATISTICS:');
        $this->info('------------------------');
        $this->line("Bookings CREATED on Oct 22: {$bookingsCreatedOnDate->count()}");
        $this->line("Bookings SCHEDULED FOR Oct 22: {$bookingsScheduledOnDate->count()}");
        $this->line("Cart Transactions CREATED on Oct 22: {$cartTransactionsCreatedOnDate->count()}");
        $this->line("Cart Items for Oct 22 bookings: {$cartItemsForDate->count()}");
        $this->newLine();

        // Store stats
        $this->stats = [
            'bookings_created_on_date' => $bookingsCreatedOnDate->count(),
            'bookings_scheduled_on_date' => $bookingsScheduledOnDate->count(),
            'cart_transactions_created_on_date' => $cartTransactionsCreatedOnDate->count(),
            'cart_items_for_date' => $cartItemsForDate->count(),
        ];

        // ANALYZE CART TRANSACTIONS
        $this->info('🔍 ANALYZING CART TRANSACTIONS:');
        $this->info('--------------------------------');
        foreach ($cartTransactionsCreatedOnDate as $transaction) {
            $this->analyzeCartTransaction($transaction, $targetDate);
        }
        $this->newLine();

        // ANALYZE CART ITEMS
        $this->info('🔍 ANALYZING CART ITEMS FOR OCT 22:');
        $this->info('------------------------------------');
        foreach ($cartItemsForDate as $item) {
            $this->analyzeCartItem($item);
        }
        $this->newLine();

        // ANALYZE BOOKINGS CREATED ON OCT 22
        $this->info('🔍 ANALYZING BOOKINGS CREATED ON OCT 22:');
        $this->info('-----------------------------------------');
        foreach ($bookingsCreatedOnDate as $booking) {
            $this->analyzeBooking($booking, 'created');
        }
        $this->newLine();

        // ANALYZE BOOKINGS SCHEDULED FOR OCT 22
        $this->info('🔍 ANALYZING BOOKINGS SCHEDULED FOR OCT 22:');
        $this->info('--------------------------------------------');
        foreach ($bookingsScheduledOnDate as $booking) {
            $this->analyzeBooking($booking, 'scheduled');
        }
        $this->newLine();

        // CROSS-REFERENCE ANALYSIS
        $this->info('🔍 CROSS-REFERENCE ANALYSIS:');
        $this->info('-----------------------------');
        $this->crossReferenceData($bookingsCreatedOnDate, $bookingsScheduledOnDate, $cartTransactionsCreatedOnDate, $cartItemsForDate);
        $this->newLine();

        // REPORT INCONSISTENCIES
        $this->reportInconsistencies();

        return 0;
    }

    protected function analyzeCartTransaction($transaction, $targetDate)
    {
        $userName = $transaction->user->name ?? 'N/A';
        $userEmail = $transaction->user->email ?? 'N/A';
        $this->line("Transaction #{$transaction->id} - User: {$userName} ({$userEmail})");
        $this->line("  Created: {$transaction->created_at}");
        $this->line("  Status: {$transaction->status} | Approval: {$transaction->approval_status} | Payment: {$transaction->payment_status}");
        $this->line("  Total Price: ₱{$transaction->total_price}");

        // Check for inconsistencies
        if ($transaction->approval_status === 'approved' && $transaction->payment_status === 'unpaid') {
            $this->addInconsistency('cart_transaction', $transaction->id,
                "Approved but unpaid - Transaction approved but payment status is still 'unpaid'");
        }

        if ($transaction->payment_status === 'paid' && !$transaction->proof_of_payment) {
            $this->addInconsistency('cart_transaction', $transaction->id,
                "Paid without proof - Transaction marked as paid but no proof of payment");
        }

        if ($transaction->approval_status === 'approved' && !$transaction->approved_at) {
            $this->addInconsistency('cart_transaction', $transaction->id,
                "Missing approval timestamp - Transaction approved but approved_at is null");
        }

        // Check cart items count
        $itemsCount = $transaction->cartItems->where('status', '!=', 'cancelled')->count();
        $this->line("  Cart Items: {$itemsCount}");

        if ($itemsCount === 0) {
            $this->addInconsistency('cart_transaction', $transaction->id,
                "No cart items - Transaction has no non-cancelled cart items");
        }

        // Check price consistency
        $calculatedTotal = $transaction->cartItems->where('status', '!=', 'cancelled')->sum('price');
        if (abs($calculatedTotal - $transaction->total_price) > 0.01) {
            $this->addInconsistency('cart_transaction', $transaction->id,
                "Price mismatch - Transaction total (₱{$transaction->total_price}) doesn't match sum of cart items (₱{$calculatedTotal})");
        }

        // Check bookings relationship
        $bookingsCount = $transaction->bookings->count();
        $this->line("  Associated Bookings: {$bookingsCount}");

        if ($transaction->status === 'completed' && $bookingsCount === 0) {
            $this->addInconsistency('cart_transaction', $transaction->id,
                "Completed without bookings - Transaction is completed but has no associated bookings");
        }

        // Check if bookings status matches transaction approval status
        foreach ($transaction->bookings as $booking) {
            if ($transaction->approval_status === 'approved' && $booking->status !== 'approved' && $booking->status !== 'completed' && $booking->status !== 'checked_in') {
                $this->addInconsistency('cart_transaction', $transaction->id,
                    "Status mismatch - Transaction approved but booking #{$booking->id} is '{$booking->status}'");
            }
        }

        $this->newLine();
    }

    protected function analyzeCartItem($item)
    {
        $courtName = $item->court->name ?? 'N/A';
        $userName = $item->user->name ?? 'N/A';
        $this->line("Cart Item #{$item->id} - Court: {$courtName}");
        $this->line("  Booking Date: {$item->booking_date} | Time: {$item->start_time} - {$item->end_time}");
        $this->line("  Price: ₱{$item->price} | Status: {$item->status}");
        $this->line("  User: {$userName}");

        if ($item->booking_for_user_id) {
            $bookingForName = $item->booking_for_user_name ?? ($item->bookingForUser->name ?? 'N/A');
            $this->line("  Booking For: {$bookingForName}");
        }

        // Check for inconsistencies
        if (!$item->cart_transaction_id) {
            $this->addInconsistency('cart_item', $item->id,
                "Missing transaction - Cart item has no associated cart transaction");
        }

        if (!$item->court_id) {
            $this->addInconsistency('cart_item', $item->id,
                "Missing court - Cart item has no court assigned");
        }

        if ($item->price <= 0) {
            $this->addInconsistency('cart_item', $item->id,
                "Invalid price - Cart item has price of ₱{$item->price}");
        }

        // Check if start_time is before end_time
        $bookingDate = Carbon::parse($item->booking_date)->format('Y-m-d');
        $start = Carbon::parse($bookingDate . ' ' . $item->start_time);
        $end = Carbon::parse($bookingDate . ' ' . $item->end_time);

        // Handle midnight crossing (e.g., 23:00:00 - 00:00:00)
        if ($end->lte($start)) {
            // If end time is 00:00:00 or earlier than start, it's likely a midnight crossing
            if ($item->end_time === '00:00:00') {
                // This is valid - booking goes to midnight
                $this->line("  Note: Booking crosses midnight (valid)");
            } else {
                $this->addInconsistency('cart_item', $item->id,
                    "Invalid time range - End time ({$item->end_time}) is not after start time ({$item->start_time})");
            }
        }

        // Check if cart item has a transaction and if statuses match
        if ($item->cartTransaction) {
            if ($item->status === 'completed' && $item->cartTransaction->status !== 'completed') {
                $this->addInconsistency('cart_item', $item->id,
                    "Status mismatch - Cart item is 'completed' but transaction is '{$item->cartTransaction->status}'");
            }
        }

        $this->newLine();
    }

    protected function analyzeBooking($booking, $type)
    {
        $courtName = $booking->court->name ?? 'N/A';
        $userName = $booking->user->name ?? 'N/A';
        $this->line("Booking #{$booking->id} ({$type} on Oct 22) - Court: {$courtName}");
        $this->line("  Start: {$booking->start_time} | End: {$booking->end_time}");
        $this->line("  Status: {$booking->status} | Payment: {$booking->payment_status}");
        $this->line("  Price: ₱{$booking->total_price}");
        $this->line("  User: {$userName}");

        if ($booking->booking_for_user_id) {
            $bookingForName = $booking->booking_for_user_name ?? ($booking->bookingForUser->name ?? 'N/A');
            $this->line("  Booking For: {$bookingForName}");
        }

        // Check for inconsistencies
        if ($booking->status === 'approved' && $booking->payment_status === 'unpaid') {
            $this->addInconsistency('booking', $booking->id,
                "Approved but unpaid - Booking approved but payment status is still 'unpaid'");
        }

        if ($booking->payment_status === 'paid' && !$booking->proof_of_payment) {
            $this->addInconsistency('booking', $booking->id,
                "Paid without proof - Booking marked as paid but no proof of payment");
        }

        if ($booking->status === 'approved' && !$booking->qr_code) {
            $this->addInconsistency('booking', $booking->id,
                "Approved without QR - Booking approved but has no QR code");
        }

        if ($booking->total_price <= 0 && $booking->status !== 'recurring_schedule') {
            $this->addInconsistency('booking', $booking->id,
                "Invalid price - Booking has price of ₱{$booking->total_price}");
        }

        // Check cart transaction relationship
        if ($booking->cart_transaction_id) {
            $this->line("  Cart Transaction: #{$booking->cart_transaction_id}");

            if ($booking->cartTransaction) {
                if ($booking->status === 'approved' && $booking->cartTransaction->approval_status !== 'approved') {
                    $this->addInconsistency('booking', $booking->id,
                        "Status mismatch - Booking approved but cart transaction is '{$booking->cartTransaction->approval_status}'");
                }

                if ($booking->payment_status !== $booking->cartTransaction->payment_status) {
                    $this->addInconsistency('booking', $booking->id,
                        "Payment status mismatch - Booking payment is '{$booking->payment_status}' but transaction is '{$booking->cartTransaction->payment_status}'");
                }
            } else {
                $this->addInconsistency('booking', $booking->id,
                    "Orphaned reference - Has cart_transaction_id #{$booking->cart_transaction_id} but transaction doesn't exist");
            }
        } else {
            $this->line("  Cart Transaction: None (direct booking)");
        }

        // Check time overlap with other bookings
        $overlapping = Booking::where('court_id', $booking->court_id)
            ->where('id', '!=', $booking->id)
            ->whereIn('status', ['pending', 'approved', 'completed', 'checked_in'])
            ->where(function($query) use ($booking) {
                $query->where(function($q) use ($booking) {
                    $q->where('start_time', '<', $booking->end_time)
                      ->where('end_time', '>', $booking->start_time);
                });
            })
            ->count();

        if ($overlapping > 0) {
            $this->addInconsistency('booking', $booking->id,
                "Time overlap - Booking has {$overlapping} overlapping booking(s) on the same court");
        }

        $this->newLine();
    }

    protected function crossReferenceData($bookingsCreated, $bookingsScheduled, $cartTransactions, $cartItems)
    {
        // Check for bookings with cart_transaction_id that don't exist in cart_transactions
        $orphanedBookings = 0;
        $transactionIds = $cartTransactions->pluck('id')->toArray();

        foreach ($bookingsCreated->merge($bookingsScheduled)->unique('id') as $booking) {
            if ($booking->cart_transaction_id && !in_array($booking->cart_transaction_id, $transactionIds)) {
                $orphanedBookings++;
            }
        }

        if ($orphanedBookings > 0) {
            $this->line("⚠️  Found {$orphanedBookings} booking(s) with non-existent cart transaction references");
        }

        // Check for cart items without transactions
        $orphanedCartItems = 0;
        foreach ($cartItems as $item) {
            if (!$item->cart_transaction_id || !$item->cartTransaction) {
                $orphanedCartItems++;
            }
        }

        if ($orphanedCartItems > 0) {
            $this->line("⚠️  Found {$orphanedCartItems} cart item(s) without valid transaction references");
        }

        // Check for transactions without cart items
        $emptyTransactions = 0;
        foreach ($cartTransactions as $transaction) {
            if ($transaction->cartItems->where('status', '!=', 'cancelled')->count() === 0) {
                $emptyTransactions++;
            }
        }

        if ($emptyTransactions > 0) {
            $this->line("⚠️  Found {$emptyTransactions} transaction(s) without any non-cancelled cart items");
        }

        $this->newLine();
    }

    protected function addInconsistency($type, $id, $message)
    {
        $this->inconsistencies[] = [
            'type' => $type,
            'id' => $id,
            'message' => $message
        ];
    }

    protected function reportInconsistencies()
    {
        $this->info('=======================================');
        $this->info('📋 INCONSISTENCY REPORT');
        $this->info('=======================================');
        $this->newLine();

        if (empty($this->inconsistencies)) {
            $this->line('✅ No data inconsistencies found! All data appears to be consistent.');
            return;
        }

        $this->error("❌ Found " . count($this->inconsistencies) . " inconsistenc(ies):");
        $this->newLine();

        // Group by type
        $grouped = collect($this->inconsistencies)->groupBy('type');

        foreach ($grouped as $type => $issues) {
            $this->line("🔴 " . strtoupper($type) . " ISSUES ({$issues->count()}):");
            foreach ($issues as $issue) {
                $this->line("   #{$issue['id']}: {$issue['message']}");
            }
            $this->newLine();
        }

        // Summary
        $this->info('=======================================');
        $this->info('SUMMARY:');
        $this->line("Total Inconsistencies: " . count($this->inconsistencies));
        foreach ($grouped as $type => $issues) {
            $this->line("  - {$type}: {$issues->count()}");
        }
    }
}
