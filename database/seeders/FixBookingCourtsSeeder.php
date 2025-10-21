<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FixBookingCourtsSeeder extends Seeder
{
    /**
     * Fix booking court_id mismatches using efficient SQL update.
     *
     * This seeder uses raw SQL to update bookings in bulk where the court_id
     * doesn't match the corresponding cart item's court_id.
     *
     * This is more efficient than the eloquent version for large datasets.
     *
     * @return void
     */
    public function run()
    {
        $this->command->info('Starting to fix booking court mismatches...');

        // First, let's check how many bookings have mismatches
        $mismatchCount = DB::select("
            SELECT COUNT(*) as count
            FROM bookings b
            INNER JOIN cart_items ci ON
                b.cart_transaction_id = ci.cart_transaction_id
                AND DATE(b.start_time) = ci.booking_date
                AND TIME(b.start_time) = ci.start_time
                AND TIME(b.end_time) = ci.end_time
            WHERE b.cart_transaction_id IS NOT NULL
                AND b.status IN ('pending', 'approved', 'completed')
                AND b.court_id != ci.court_id
        ");

        $totalMismatches = $mismatchCount[0]->count ?? 0;

        if ($totalMismatches == 0) {
            $this->command->info('No mismatches found! All bookings are in sync.');
            return;
        }

        $this->command->warn("Found {$totalMismatches} booking(s) with court mismatches");

        // Show which bookings will be affected
        $this->command->info('Bookings to be updated:');
        $affectedBookings = DB::select("
            SELECT
                b.id as booking_id,
                b.court_id as old_court_id,
                ci.court_id as new_court_id,
                b.start_time,
                b.end_time,
                u.name as user_name,
                c_old.name as old_court_name,
                c_new.name as new_court_name
            FROM bookings b
            INNER JOIN cart_items ci ON
                b.cart_transaction_id = ci.cart_transaction_id
                AND DATE(b.start_time) = ci.booking_date
                AND TIME(b.start_time) = ci.start_time
                AND TIME(b.end_time) = ci.end_time
            LEFT JOIN users u ON b.user_id = u.id
            LEFT JOIN courts c_old ON b.court_id = c_old.id
            LEFT JOIN courts c_new ON ci.court_id = c_new.id
            WHERE b.cart_transaction_id IS NOT NULL
                AND b.status IN ('pending', 'approved', 'completed')
                AND b.court_id != ci.court_id
            ORDER BY b.start_time DESC
            LIMIT 20
        ");

        if (!empty($affectedBookings)) {
            $this->command->table(
                ['Booking ID', 'Old Court', 'New Court', 'Start Time', 'User'],
                array_map(function($booking) {
                    return [
                        $booking->booking_id,
                        "{$booking->old_court_name} (ID: {$booking->old_court_id})",
                        "{$booking->new_court_name} (ID: {$booking->new_court_id})",
                        $booking->start_time,
                        $booking->user_name,
                    ];
                }, $affectedBookings)
            );

            if ($totalMismatches > 20) {
                $this->command->info("... and " . ($totalMismatches - 20) . " more bookings");
            }
        }

        // Ask for confirmation
        if (!$this->command->confirm('Do you want to proceed with the update?', true)) {
            $this->command->warn('Update cancelled.');
            return;
        }

        $this->command->newLine();
        $this->command->info('Updating bookings...');

        // Perform the update using SQL
        $updatedRows = DB::update("
            UPDATE bookings b
            INNER JOIN cart_items ci ON
                b.cart_transaction_id = ci.cart_transaction_id
                AND DATE(b.start_time) = ci.booking_date
                AND TIME(b.start_time) = ci.start_time
                AND TIME(b.end_time) = ci.end_time
            SET b.court_id = ci.court_id
            WHERE b.cart_transaction_id IS NOT NULL
                AND b.status IN ('pending', 'approved', 'completed')
                AND b.court_id != ci.court_id
        ");

        $this->command->newLine();
        $this->command->info('=================================');
        $this->command->info('Update Complete!');
        $this->command->info('=================================');
        $this->command->info("Bookings updated: {$updatedRows}");

        // Verify the fix
        $remainingMismatches = DB::select("
            SELECT COUNT(*) as count
            FROM bookings b
            INNER JOIN cart_items ci ON
                b.cart_transaction_id = ci.cart_transaction_id
                AND DATE(b.start_time) = ci.booking_date
                AND TIME(b.start_time) = ci.start_time
                AND TIME(b.end_time) = ci.end_time
            WHERE b.cart_transaction_id IS NOT NULL
                AND b.status IN ('pending', 'approved', 'completed')
                AND b.court_id != ci.court_id
        ");

        $remaining = $remainingMismatches[0]->count ?? 0;

        if ($remaining == 0) {
            $this->command->info('✓ All bookings are now in sync with their cart items!');
        } else {
            $this->command->warn("Warning: {$remaining} mismatches still remain. Manual review may be needed.");
        }
    }
}
