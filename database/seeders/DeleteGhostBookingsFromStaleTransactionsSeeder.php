<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Booking;
use App\Models\CartTransaction;

class DeleteGhostBookingsFromStaleTransactionsSeeder extends Seeder
{
    /**
     * Deletes ghost booking records that were incorrectly created by
     * CreateBookingsForCartTransactionsSeeder from stale/abandoned cart transactions.
     *
     * A "ghost" booking is one that:
     *  - Has status = 'pending' and payment_status = 'unpaid'  (never paid)
     *  - Is linked to a cart transaction that is also stale:
     *      a) transaction status = 'expired' or 'cancelled', OR
     *      b) transaction is pending + unpaid + unapproved (abandoned cart, never checked out)
     *
     * These records block time slots on the booking calendar without representing
     * a real committed booking. They should never have been created.
     *
     * Safe to re-run — only targets the specific stale combination above and
     * will report 0 deletions if already clean.
     */
    public function run(): void
    {
        $this->command->info('Scanning for ghost bookings from stale cart transactions...');
        $this->command->newLine();

        // --- Category A: linked to expired or cancelled transactions ---
        $categoryA = Booking::whereHas('cartTransaction', function ($q) {
                $q->whereIn('status', ['expired', 'cancelled']);
            })
            ->where('status', 'pending')
            ->where('payment_status', 'unpaid');

        $countA = $categoryA->count();
        $this->command->line("  [A] Ghost bookings from expired/cancelled transactions: <fg=yellow>{$countA}</>");

        // --- Category B: linked to pending+unpaid+unapproved (abandoned) transactions ---
        $categoryB = Booking::whereHas('cartTransaction', function ($q) {
                $q->where('status', 'pending')
                  ->where('payment_status', 'unpaid')
                  ->where('approval_status', 'pending');
            })
            ->where('status', 'pending')
            ->where('payment_status', 'unpaid');

        $countB = $categoryB->count();
        $this->command->line("  [B] Ghost bookings from abandoned (pending+unpaid) transactions: <fg=yellow>{$countB}</>");

        $total = $countA + $countB;

        if ($total === 0) {
            $this->command->newLine();
            $this->command->info('No ghost bookings found. Database is already clean.');
            return;
        }

        $this->command->newLine();
        $this->command->warn("Total ghost bookings to delete: {$total}");

        if ($this->command->confirm('Proceed with deletion?', true)) {
            DB::beginTransaction();
            try {
                // Show a sample before deleting
                $samples = Booking::with(['cartTransaction', 'user'])
                    ->whereHas('cartTransaction', function ($q) {
                        $q->where(function ($q2) {
                            $q2->whereIn('status', ['expired', 'cancelled'])
                               ->orWhere(function ($q3) {
                                   $q3->where('status', 'pending')
                                      ->where('payment_status', 'unpaid')
                                      ->where('approval_status', 'pending');
                               });
                        });
                    })
                    ->where('status', 'pending')
                    ->where('payment_status', 'unpaid')
                    ->orderBy('created_at')
                    ->limit(20)
                    ->get();

                $tableRows = $samples->map(function ($b) {
                    $txn = $b->cartTransaction;
                    return [
                        $b->id,
                        $txn->id ?? 'N/A',
                        $txn->status ?? 'N/A',
                        $txn->payment_status ?? 'N/A',
                        $b->court_id,
                        $b->start_time,
                        $b->user->name ?? 'N/A',
                    ];
                })->toArray();

                $this->command->table(
                    ['Booking ID', 'Txn ID', 'Txn Status', 'Txn Payment', 'Court', 'Start Time', 'User'],
                    $tableRows
                );

                if ($samples->count() < $total) {
                    $this->command->line("  ... and " . ($total - $samples->count()) . " more");
                }

                $this->command->newLine();

                // Delete Category A
                $deletedA = Booking::whereHas('cartTransaction', function ($q) {
                        $q->whereIn('status', ['expired', 'cancelled']);
                    })
                    ->where('status', 'pending')
                    ->where('payment_status', 'unpaid')
                    ->delete();

                // Delete Category B
                $deletedB = Booking::whereHas('cartTransaction', function ($q) {
                        $q->where('status', 'pending')
                          ->where('payment_status', 'unpaid')
                          ->where('approval_status', 'pending');
                    })
                    ->where('status', 'pending')
                    ->where('payment_status', 'unpaid')
                    ->delete();

                DB::commit();

                $this->command->newLine();
                $this->command->info('=========================================');
                $this->command->info('Cleanup Complete!');
                $this->command->info('=========================================');
                $this->command->info("  [A] Deleted from expired/cancelled txns : {$deletedA}");
                $this->command->info("  [B] Deleted from abandoned txns         : {$deletedB}");
                $this->command->info("  Total deleted                            : " . ($deletedA + $deletedB));
                $this->command->newLine();

            } catch (\Exception $e) {
                DB::rollBack();
                $this->command->error('Deletion failed, rolled back: ' . $e->getMessage());
            }
        } else {
            $this->command->warn('Aborted. No records were deleted.');
        }
    }
}
