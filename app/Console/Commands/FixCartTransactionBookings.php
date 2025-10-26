<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\CartTransaction;
use App\Models\CartItem;
use App\Models\Booking;
use Carbon\Carbon;

class FixCartTransactionBookings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cart:fix-bookings {--check-only : Only check for issues without fixing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check and fix cart transactions that have cart items but no bookings';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $checkOnly = $this->option('check-only');

        $this->info('Checking cart transactions for data integrity issues...');
        $this->newLine();

        // Find all cart transactions that have cart items but no bookings
        $transactions = CartTransaction::with(['cartItems', 'bookings', 'user'])
            ->whereIn('status', ['pending', 'completed'])
            ->get();

        $problematicTransactions = [];
        foreach($transactions as $transaction) {
            $hasNonCancelledItems = $transaction->cartItems->where('status', '!=', 'cancelled')->count() > 0;
            $hasBookings = $transaction->bookings->count() > 0;

            if ($hasNonCancelledItems && !$hasBookings) {
                $problematicTransactions[] = $transaction;
            }
        }

        $this->info('Total transactions checked: ' . $transactions->count());

        if (count($problematicTransactions) === 0) {
            $this->info('✓ No data integrity issues found!');
            return 0;
        }

        $this->warn('Found ' . count($problematicTransactions) . ' transaction(s) with cart items but NO bookings');
        $this->newLine();

        foreach($problematicTransactions as $transaction) {
            $this->line('Transaction ID: ' . $transaction->id);
            $this->line('  User: ' . ($transaction->user->name ?? 'N/A'));
            $this->line('  Status: ' . $transaction->status);
            $this->line('  Approval Status: ' . $transaction->approval_status);
            $this->line('  Created: ' . $transaction->created_at);
            $this->line('  Cart Items: ' . $transaction->cartItems->where('status', '!=', 'cancelled')->count());
            $this->line('  Bookings: ' . $transaction->bookings->count());
            $this->newLine();
        }

        if ($checkOnly) {
            $this->warn('Running in check-only mode. Use without --check-only to fix these issues.');
            return 1;
        }

        if (!$this->confirm('Do you want to create missing bookings for these transactions?')) {
            $this->info('Operation cancelled.');
            return 0;
        }

        // Fix each problematic transaction
        $fixedCount = 0;
        $bookingsCreated = 0;

        foreach($problematicTransactions as $transaction) {
            $this->info('Fixing Transaction ' . $transaction->id . '...');

            try {
                foreach($transaction->cartItems as $item) {
                    if ($item->status !== 'cancelled') {
                        $date = Carbon::parse($item->booking_date)->format('Y-m-d');
                        $startDateTime = $date . ' ' . $item->start_time;

                        // Handle midnight crossing
                        $endTime = Carbon::parse($item->end_time);
                        $startTime = Carbon::parse($item->start_time);
                        if ($endTime->lte($startTime)) {
                            $endDate = Carbon::parse($date)->addDay()->format('Y-m-d');
                            $endDateTime = $endDate . ' ' . $item->end_time;
                        } else {
                            $endDateTime = $date . ' ' . $item->end_time;
                        }

                        $booking = Booking::create([
                            'user_id' => $transaction->user_id,
                            'booking_for_user_id' => $item->booking_for_user_id,
                            'booking_for_user_name' => $item->booking_for_user_name,
                            'cart_transaction_id' => $transaction->id,
                            'court_id' => $item->court_id,
                            'sport_id' => $item->sport_id,
                            'start_time' => $startDateTime,
                            'end_time' => $endDateTime,
                            'total_price' => $item->price,
                            'number_of_players' => $item->number_of_players ?? 1,
                            'status' => $transaction->approval_status ?? 'pending',
                            'payment_status' => $transaction->payment_status ?? 'unpaid',
                            'payment_method' => $transaction->payment_method ?? 'pending',
                            'notes' => $item->notes,
                            'admin_notes' => $item->admin_notes
                        ]);

                        $this->line('  ✓ Created Booking ID: ' . $booking->id . ' (Cart Item ' . $item->id . ')');
                        $bookingsCreated++;
                    }
                }

                $fixedCount++;
            } catch (\Exception $e) {
                $this->error('  ✗ Failed to fix Transaction ' . $transaction->id . ': ' . $e->getMessage());
            }
        }

        $this->newLine();
        $this->info('✓ Fixed ' . $fixedCount . ' transaction(s)');
        $this->info('✓ Created ' . $bookingsCreated . ' booking(s)');

        return 0;
    }
}
