<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Booking;
use App\Models\CartTransaction;
use App\Models\CartItem;
use App\Models\BookingWaitlist;
use App\Models\WaitlistCartTransaction;
use App\Models\WaitlistCartItem;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\Court;
use App\Models\Sport;
use App\Models\User;
use Carbon\Carbon;

class DataConsistencyAnalyzerSeeder extends Seeder
{
    private $report = [];
    private $fixMode = false;
    private $verbose = true;
    private $issuesFixed = 0;
    private $issuesFound = 0;

    /**
     * Run the database seeds.
     *
     * This seeder analyzes and fixes data inconsistencies across:
     * - Bookings
     * - Cart Transactions & Cart Items
     * - Waitlist Entries
     * - POS Sales
     * - Payment & Approval Status
     * - Foreign Key Integrity
     *
     * Run with: php artisan db:seed --class=DataConsistencyAnalyzerSeeder
     *
     * @return void
     */
    public function run()
    {
        $this->info('╔════════════════════════════════════════════════════════════╗');
        $this->info('║     DATA CONSISTENCY ANALYZER & FIXER                      ║');
        $this->info('╚════════════════════════════════════════════════════════════╝');
        $this->newLine();

        // Ask for fix mode
        $this->fixMode = $this->command->confirm('Do you want to automatically fix issues? (Otherwise, only analysis will be performed)', false);
        $this->verbose = $this->command->confirm('Enable verbose output?', true);
        $this->newLine();

        if ($this->fixMode) {
            $this->warn('⚠️  FIX MODE ENABLED - Changes will be made to the database');
            $this->newLine();
        }

        // Run all consistency checks
        $this->checkBookingStatusConsistency();
        $this->checkPaymentConsistency();
        $this->checkWaitlistConsistency();
        $this->checkCartTransactionConsistency();
        $this->checkPosConsistency();
        $this->checkAttendanceConsistency();
        $this->checkForeignKeyIntegrity();
        $this->checkOrphanedRecords();
        $this->checkPriceConsistency();
        $this->checkDuplicateBookings();

        // Display summary report
        $this->displaySummaryReport();
    }

    /**
     * Check 1: Booking Status Consistency
     * Ensures booking status matches cart transaction approval_status
     */
    private function checkBookingStatusConsistency()
    {
        $this->sectionHeader('1. Booking Status Consistency');

        // Approved transactions with pending/rejected bookings
        $approvedTransactions = CartTransaction::where('approval_status', 'approved')
            ->whereHas('bookings', function($query) {
                $query->whereNotIn('status', ['approved', 'checked_in', 'completed']);
            })
            ->with('bookings')
            ->get();

        foreach ($approvedTransactions as $transaction) {
            $inconsistentBookings = $transaction->bookings()
                ->whereNotIn('status', ['approved', 'checked_in', 'completed'])
                ->get();

            foreach ($inconsistentBookings as $booking) {
                $this->issuesFound++;
                $this->log("Booking #{$booking->id}: Status '{$booking->status}' but transaction is 'approved'", 'error');

                if ($this->fixMode) {
                    $booking->update(['status' => Booking::STATUS_APPROVED]);
                    $this->log("  ✓ Fixed: Set booking status to 'approved'", 'success');
                    $this->issuesFixed++;
                }
            }
        }

        // Rejected transactions with non-rejected bookings
        $rejectedTransactions = CartTransaction::where('approval_status', 'rejected')
            ->whereHas('bookings', function($query) {
                $query->where('status', '!=', 'rejected');
            })
            ->with('bookings')
            ->get();

        foreach ($rejectedTransactions as $transaction) {
            $inconsistentBookings = $transaction->bookings()
                ->where('status', '!=', 'rejected')
                ->get();

            foreach ($inconsistentBookings as $booking) {
                $this->issuesFound++;
                $this->log("Booking #{$booking->id}: Status '{$booking->status}' but transaction is 'rejected'", 'error');

                if ($this->fixMode) {
                    $booking->update(['status' => Booking::STATUS_REJECTED]);
                    $this->log("  ✓ Fixed: Set booking status to 'rejected'", 'success');
                    $this->issuesFixed++;
                }
            }
        }

        // Pending transactions with approved bookings
        $pendingTransactions = CartTransaction::where('approval_status', 'pending')
            ->whereHas('bookings', function($query) {
                $query->where('status', 'approved');
            })
            ->with('bookings')
            ->get();

        foreach ($pendingTransactions as $transaction) {
            $inconsistentBookings = $transaction->bookings()
                ->where('status', 'approved')
                ->get();

            foreach ($inconsistentBookings as $booking) {
                $this->issuesFound++;
                $this->log("Booking #{$booking->id}: Status 'approved' but transaction is 'pending'", 'error');

                if ($this->fixMode) {
                    $booking->update(['status' => Booking::STATUS_PENDING]);
                    $this->log("  ✓ Fixed: Set booking status to 'pending'", 'success');
                    $this->issuesFixed++;
                }
            }
        }

        $this->sectionFooter();
    }

    /**
     * Check 2: Payment Consistency
     * Ensures payment status and related fields are consistent
     */
    private function checkPaymentConsistency()
    {
        $this->sectionHeader('2. Payment Status Consistency');

        // Paid transactions with unpaid bookings
        $paidTransactions = CartTransaction::where('payment_status', 'paid')
            ->whereHas('bookings', function($query) {
                $query->where('payment_status', '!=', 'paid');
            })
            ->with('bookings')
            ->get();

        foreach ($paidTransactions as $transaction) {
            $unpaidBookings = $transaction->bookings()
                ->where('payment_status', '!=', 'paid')
                ->get();

            foreach ($unpaidBookings as $booking) {
                $this->issuesFound++;
                $this->log("Booking #{$booking->id}: Payment status '{$booking->payment_status}' but transaction is 'paid'", 'error');

                if ($this->fixMode) {
                    $booking->update([
                        'payment_status' => 'paid',
                        'payment_method' => $transaction->payment_method,
                        'proof_of_payment' => $transaction->proof_of_payment,
                        'paid_at' => $transaction->paid_at
                    ]);
                    $this->log("  ✓ Fixed: Synced payment data from transaction", 'success');
                    $this->issuesFixed++;
                }
            }
        }

        // Bookings with payment_status=paid but no paid_at
        $paidWithoutDate = Booking::where('payment_status', 'paid')
            ->whereNull('paid_at')
            ->get();

        foreach ($paidWithoutDate as $booking) {
            $this->issuesFound++;
            $this->log("Booking #{$booking->id}: Marked as 'paid' but no paid_at timestamp", 'error');

            if ($this->fixMode) {
                $paidAt = $booking->updated_at ?? now();
                $booking->update(['paid_at' => $paidAt]);
                $this->log("  ✓ Fixed: Set paid_at to {$paidAt}", 'success');
                $this->issuesFixed++;
            }
        }

        // Bookings marked as showed_up but payment not paid
        $showedUpUnpaid = Booking::where('attendance_status', 'showed_up')
            ->where('payment_status', '!=', 'paid')
            ->get();

        foreach ($showedUpUnpaid as $booking) {
            $this->issuesFound++;
            $this->log("Booking #{$booking->id}: Attendance 'showed_up' but payment status is '{$booking->payment_status}'", 'error');

            if ($this->fixMode) {
                $booking->update(['attendance_status' => 'not_set']);
                $this->log("  ✓ Fixed: Reset attendance_status (payment required first)", 'success');
                $this->issuesFixed++;
            }
        }

        // Bookings with payment method but no payment status
        $paymentMethodNoStatus = Booking::whereNotNull('payment_method')
            ->whereNull('payment_status')
            ->get();

        foreach ($paymentMethodNoStatus as $booking) {
            $this->issuesFound++;
            $this->log("Booking #{$booking->id}: Has payment_method '{$booking->payment_method}' but no payment_status", 'error');

            if ($this->fixMode) {
                $paymentStatus = $booking->paid_at ? 'paid' : 'unpaid';
                $booking->update(['payment_status' => $paymentStatus]);
                $this->log("  ✓ Fixed: Set payment_status to '{$paymentStatus}'", 'success');
                $this->issuesFixed++;
            }
        }

        $this->sectionFooter();
    }

    /**
     * Check 3: Waitlist Consistency
     * Ensures waitlist entries are properly managed
     */
    private function checkWaitlistConsistency()
    {
        $this->sectionHeader('3. Waitlist Data Consistency');

        // Converted waitlist entries without bookings
        $convertedWithoutBookings = BookingWaitlist::where('status', BookingWaitlist::STATUS_CONVERTED)
            ->whereNotNull('converted_cart_transaction_id')
            ->get()
            ->filter(function($waitlist) {
                return !Booking::where('booking_waitlist_id', $waitlist->id)->exists() &&
                       !Booking::where('cart_transaction_id', $waitlist->converted_cart_transaction_id)->exists();
            });

        foreach ($convertedWithoutBookings as $waitlist) {
            $this->issuesFound++;
            $this->log("Waitlist #{$waitlist->id}: Status 'converted' but no booking found", 'error');

            if ($this->fixMode) {
                $waitlist->update(['status' => BookingWaitlist::STATUS_EXPIRED]);
                $this->log("  ✓ Fixed: Changed status to 'expired'", 'success');
                $this->issuesFixed++;
            }
        }

        // Notified waitlist entries without expires_at
        $notifiedNoExpiry = BookingWaitlist::where('status', BookingWaitlist::STATUS_NOTIFIED)
            ->whereNull('expires_at')
            ->get();

        foreach ($notifiedNoExpiry as $waitlist) {
            $this->issuesFound++;
            $this->log("Waitlist #{$waitlist->id}: Status 'notified' but no expires_at set", 'error');

            if ($this->fixMode) {
                $expiresAt = $waitlist->notified_at ?
                    Carbon::parse($waitlist->notified_at)->addHours(24) :
                    now()->addHours(1);
                $waitlist->update(['expires_at' => $expiresAt]);
                $this->log("  ✓ Fixed: Set expires_at to {$expiresAt}", 'success');
                $this->issuesFixed++;
            }
        }

        // Expired waitlist entries still marked as pending
        $expiredPending = BookingWaitlist::where('status', BookingWaitlist::STATUS_PENDING)
            ->orWhere('status', BookingWaitlist::STATUS_NOTIFIED)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expiredPending as $waitlist) {
            $this->issuesFound++;
            $this->log("Waitlist #{$waitlist->id}: Status '{$waitlist->status}' but expires_at has passed", 'error');

            if ($this->fixMode) {
                $waitlist->update(['status' => BookingWaitlist::STATUS_EXPIRED]);
                $this->log("  ✓ Fixed: Changed status to 'expired'", 'success');
                $this->issuesFixed++;
            }
        }

        // Check for duplicate positions in same time slot
        $duplicatePositions = BookingWaitlist::select('court_id', 'start_time', 'end_time', 'position', DB::raw('COUNT(*) as count'))
            ->whereIn('status', [BookingWaitlist::STATUS_PENDING, BookingWaitlist::STATUS_NOTIFIED])
            ->groupBy('court_id', 'start_time', 'end_time', 'position')
            ->having('count', '>', 1)
            ->get();

        foreach ($duplicatePositions as $duplicate) {
            $this->issuesFound++;
            $this->log("Waitlist: Duplicate position {$duplicate->position} for court {$duplicate->court_id} at {$duplicate->start_time}", 'error');

            if ($this->fixMode) {
                // Reorder positions for this slot
                $waitlistEntries = BookingWaitlist::where('court_id', $duplicate->court_id)
                    ->where('start_time', $duplicate->start_time)
                    ->where('end_time', $duplicate->end_time)
                    ->whereIn('status', [BookingWaitlist::STATUS_PENDING, BookingWaitlist::STATUS_NOTIFIED])
                    ->orderBy('created_at')
                    ->get();

                $position = 1;
                foreach ($waitlistEntries as $entry) {
                    $entry->update(['position' => $position++]);
                }
                $this->log("  ✓ Fixed: Reordered {$waitlistEntries->count()} waitlist positions", 'success');
                $this->issuesFixed++;
            }
        }

        $this->sectionFooter();
    }

    /**
     * Check 4: Cart Transaction & Cart Item Consistency
     */
    private function checkCartTransactionConsistency()
    {
        $this->sectionHeader('4. Cart Transaction & Cart Item Consistency');

        // Cart transactions without bookings
        $transactionsWithoutBookings = CartTransaction::where('approval_status', 'approved')
            ->whereDoesntHave('bookings')
            ->with('cartItems')
            ->get();

        foreach ($transactionsWithoutBookings as $transaction) {
            if ($transaction->cartItems->isEmpty()) {
                continue;
            }

            $this->issuesFound++;
            $this->log("Transaction #{$transaction->id}: Approved but no bookings created ({$transaction->cartItems->count()} cart items)", 'error');

            if ($this->fixMode) {
                $createdCount = 0;
                foreach ($transaction->cartItems as $cartItem) {
                    if (!$cartItem->court_id || !$cartItem->booking_date || !$cartItem->start_time || !$cartItem->end_time) {
                        continue;
                    }

                    $startDateTime = Carbon::parse($cartItem->booking_date)
                        ->setTimeFromTimeString($cartItem->start_time);
                    $endDateTime = Carbon::parse($cartItem->booking_date)
                        ->setTimeFromTimeString($cartItem->end_time);

                    Booking::create([
                        'user_id' => $cartItem->user_id,
                        'booking_for_user_id' => $cartItem->booking_for_user_id,
                        'booking_for_user_name' => $cartItem->booking_for_user_name,
                        'cart_transaction_id' => $transaction->id,
                        'booking_waitlist_id' => $cartItem->booking_waitlist_id,
                        'court_id' => $cartItem->court_id,
                        'sport_id' => $cartItem->sport_id,
                        'start_time' => $startDateTime,
                        'end_time' => $endDateTime,
                        'total_price' => $cartItem->price,
                        'number_of_players' => $cartItem->number_of_players ?? 1,
                        'status' => Booking::STATUS_APPROVED,
                        'notes' => $cartItem->notes,
                        'admin_notes' => $cartItem->admin_notes,
                        'payment_method' => $transaction->payment_method,
                        'payment_status' => $transaction->payment_status,
                        'paid_at' => $transaction->paid_at,
                        'proof_of_payment' => $transaction->proof_of_payment,
                        'attendance_status' => 'not_set',
                        'attendance_scan_count' => 0,
                    ]);
                    $createdCount++;
                }
                $this->log("  ✓ Fixed: Created {$createdCount} booking(s)", 'success');
                $this->issuesFixed++;
            }
        }

        // Cart items with status not matching transaction
        $inconsistentCartItems = CartItem::whereHas('cartTransaction', function($query) {
            $query->whereRaw('cart_items.status != cart_transactions.approval_status');
        })
        ->with('cartTransaction')
        ->get();

        foreach ($inconsistentCartItems as $cartItem) {
            $this->issuesFound++;
            $this->log("Cart Item #{$cartItem->id}: Status '{$cartItem->status}' but transaction status is '{$cartItem->cartTransaction->approval_status}'", 'error');

            if ($this->fixMode) {
                $cartItem->update(['status' => $cartItem->cartTransaction->approval_status]);
                $this->log("  ✓ Fixed: Synced cart item status", 'success');
                $this->issuesFixed++;
            }
        }

        // Same for waitlist cart items
        $inconsistentWaitlistCartItems = WaitlistCartItem::whereHas('waitlistCartTransaction', function($query) {
            $query->whereRaw('waitlist_cart_items.status != waitlist_cart_transactions.approval_status');
        })
        ->with('waitlistCartTransaction')
        ->get();

        foreach ($inconsistentWaitlistCartItems as $cartItem) {
            $this->issuesFound++;
            $this->log("Waitlist Cart Item #{$cartItem->id}: Status mismatch with transaction", 'error');

            if ($this->fixMode) {
                $cartItem->update(['status' => $cartItem->waitlistCartTransaction->approval_status]);
                $this->log("  ✓ Fixed: Synced waitlist cart item status", 'success');
                $this->issuesFixed++;
            }
        }

        $this->sectionFooter();
    }

    /**
     * Check 5: POS Sales Consistency
     */
    private function checkPosConsistency()
    {
        $this->sectionHeader('5. POS Sales Consistency');

        // POS sales with invalid booking references
        $invalidPosSales = PosSale::whereNotNull('booking_id')
            ->whereDoesntHave('booking')
            ->get();

        foreach ($invalidPosSales as $sale) {
            $this->issuesFound++;
            $this->log("POS Sale #{$sale->id}: References non-existent booking/transaction #{$sale->booking_id}", 'error');

            if ($this->fixMode) {
                $sale->update(['booking_id' => null]);
                $this->log("  ✓ Fixed: Removed invalid booking reference", 'success');
                $this->issuesFixed++;
            }
        }

        // POS sales with total amount mismatch
        $salesWithItems = PosSale::with('saleItems')->get();
        foreach ($salesWithItems as $sale) {
            if ($sale->saleItems->isEmpty()) {
                continue;
            }

            $calculatedSubtotal = $sale->saleItems->sum(function($item) {
                return $item->quantity * $item->unit_price;
            });

            $calculatedTotal = $calculatedSubtotal + $sale->tax - $sale->discount;

            if (abs($calculatedTotal - $sale->total_amount) > 0.01) {
                $this->issuesFound++;
                $this->log("POS Sale #{$sale->id}: Total amount mismatch (Calculated: {$calculatedTotal}, Stored: {$sale->total_amount})", 'error');

                if ($this->fixMode) {
                    $sale->update([
                        'subtotal' => $calculatedSubtotal,
                        'total_amount' => $calculatedTotal
                    ]);
                    $this->log("  ✓ Fixed: Recalculated totals", 'success');
                    $this->issuesFixed++;
                }
            }
        }

        $this->sectionFooter();
    }

    /**
     * Check 6: Attendance Consistency
     */
    private function checkAttendanceConsistency()
    {
        $this->sectionHeader('6. Attendance Data Consistency');

        // Attendance scan count exceeds number of players
        $excessiveScans = Booking::whereRaw('attendance_scan_count > number_of_players')
            ->get();

        foreach ($excessiveScans as $booking) {
            $this->issuesFound++;
            $this->log("Booking #{$booking->id}: Scan count ({$booking->attendance_scan_count}) exceeds players ({$booking->number_of_players})", 'error');

            if ($this->fixMode) {
                $booking->update(['attendance_scan_count' => $booking->number_of_players]);
                $this->log("  ✓ Fixed: Capped scan count to number of players", 'success');
                $this->issuesFixed++;
            }
        }

        // Checked-in status without checked_in_at
        $checkedInNoTimestamp = Booking::where('status', Booking::STATUS_CHECKED_IN)
            ->whereNull('checked_in_at')
            ->get();

        foreach ($checkedInNoTimestamp as $booking) {
            $this->issuesFound++;
            $this->log("Booking #{$booking->id}: Status 'checked_in' but no checked_in_at timestamp", 'error');

            if ($this->fixMode) {
                $booking->update(['checked_in_at' => $booking->start_time ?? $booking->updated_at]);
                $this->log("  ✓ Fixed: Set checked_in_at timestamp", 'success');
                $this->issuesFixed++;
            }
        }

        // Attendance scan count > 0 but status not checked_in
        $scansWithoutStatus = Booking::where('attendance_scan_count', '>', 0)
            ->where('status', '!=', Booking::STATUS_CHECKED_IN)
            ->where('status', '!=', Booking::STATUS_COMPLETED)
            ->get();

        foreach ($scansWithoutStatus as $booking) {
            $this->issuesFound++;
            $this->log("Booking #{$booking->id}: Has {$booking->attendance_scan_count} scan(s) but status is '{$booking->status}'", 'error');

            if ($this->fixMode) {
                $booking->update([
                    'status' => Booking::STATUS_CHECKED_IN,
                    'checked_in_at' => $booking->checked_in_at ?? $booking->updated_at,
                    'attendance_status' => 'showed_up'
                ]);
                $this->log("  ✓ Fixed: Updated status to 'checked_in'", 'success');
                $this->issuesFixed++;
            }
        }

        $this->sectionFooter();
    }

    /**
     * Check 7: Foreign Key Integrity
     */
    private function checkForeignKeyIntegrity()
    {
        $this->sectionHeader('7. Foreign Key Integrity');

        // Bookings with invalid user_id
        $invalidUserBookings = Booking::whereNotNull('user_id')
            ->whereDoesntHave('user')
            ->get();

        foreach ($invalidUserBookings as $booking) {
            $this->issuesFound++;
            $this->log("Booking #{$booking->id}: References non-existent user #{$booking->user_id}", 'error');

            if ($this->fixMode) {
                // Try to find an admin user as fallback
                $adminUser = User::where('role', 'admin')->first();
                if ($adminUser) {
                    $booking->update(['user_id' => $adminUser->id]);
                    $this->log("  ✓ Fixed: Assigned to admin user #{$adminUser->id}", 'success');
                    $this->issuesFixed++;
                } else {
                    $this->log("  ✗ Cannot fix: No admin user found", 'warn');
                }
            }
        }

        // Bookings with invalid court_id
        $invalidCourtBookings = Booking::whereNotNull('court_id')
            ->whereDoesntHave('court')
            ->get();

        foreach ($invalidCourtBookings as $booking) {
            $this->issuesFound++;
            $this->log("Booking #{$booking->id}: References non-existent court #{$booking->court_id}", 'error');

            if ($this->fixMode) {
                $this->log("  ⚠ Manual review required: Cannot auto-fix invalid court reference", 'warn');
            }
        }

        // Bookings with invalid sport_id
        $invalidSportBookings = Booking::whereNotNull('sport_id')
            ->whereDoesntHave('sport')
            ->get();

        foreach ($invalidSportBookings as $booking) {
            $this->issuesFound++;
            $this->log("Booking #{$booking->id}: References non-existent sport #{$booking->sport_id}", 'error');

            if ($this->fixMode) {
                // Set to null or find a default sport
                $booking->update(['sport_id' => null]);
                $this->log("  ✓ Fixed: Set sport_id to null", 'success');
                $this->issuesFixed++;
            }
        }

        $this->sectionFooter();
    }

    /**
     * Check 8: Orphaned Records
     */
    private function checkOrphanedRecords()
    {
        $this->sectionHeader('8. Orphaned Records');

        // Bookings pointing to non-existent cart transactions
        $orphanedBookings = Booking::whereNotNull('cart_transaction_id')
            ->whereDoesntHave('cartTransaction')
            ->get();

        foreach ($orphanedBookings as $booking) {
            $this->issuesFound++;
            $this->log("Booking #{$booking->id}: References non-existent cart transaction #{$booking->cart_transaction_id}", 'error');

            if ($this->fixMode) {
                $this->log("  ⚠ Manual review required: Orphaned booking needs investigation", 'warn');
            }
        }

        // Cart items pointing to non-existent cart transactions
        $orphanedCartItems = CartItem::whereNotNull('cart_transaction_id')
            ->whereDoesntHave('cartTransaction')
            ->get();

        foreach ($orphanedCartItems as $cartItem) {
            $this->issuesFound++;
            $this->log("Cart Item #{$cartItem->id}: References non-existent cart transaction #{$cartItem->cart_transaction_id}", 'error');

            if ($this->fixMode) {
                $this->log("  ⚠ Manual review required: Orphaned cart item", 'warn');
            }
        }

        $this->sectionFooter();
    }

    /**
     * Check 9: Price Consistency
     */
    private function checkPriceConsistency()
    {
        $this->sectionHeader('9. Price Consistency');

        // Cart transactions with total_price mismatch
        $transactionsWithMismatch = CartTransaction::with('cartItems')->get()
            ->filter(function($transaction) {
                if ($transaction->cartItems->isEmpty()) {
                    return false;
                }
                $calculatedTotal = $transaction->cartItems->sum('price');
                return abs($calculatedTotal - $transaction->booking_amount) > 0.01;
            });

        foreach ($transactionsWithMismatch as $transaction) {
            $calculatedTotal = $transaction->cartItems->sum('price');
            $this->issuesFound++;
            $this->log("Transaction #{$transaction->id}: Booking amount mismatch (Calculated: {$calculatedTotal}, Stored: {$transaction->booking_amount})", 'error');

            if ($this->fixMode) {
                $transaction->update([
                    'booking_amount' => $calculatedTotal,
                    'total_price' => $calculatedTotal + ($transaction->pos_amount ?? 0)
                ]);
                $this->log("  ✓ Fixed: Recalculated booking and total amounts", 'success');
                $this->issuesFixed++;
            }
        }

        // Bookings with zero or negative prices
        $invalidPrices = Booking::where('total_price', '<=', 0)->get();

        foreach ($invalidPrices as $booking) {
            $this->issuesFound++;
            $this->log("Booking #{$booking->id}: Invalid price ({$booking->total_price})", 'error');

            if ($this->fixMode) {
                $this->log("  ⚠ Manual review required: Cannot determine correct price", 'warn');
            }
        }

        $this->sectionFooter();
    }

    /**
     * Check 10: Duplicate Bookings
     */
    private function checkDuplicateBookings()
    {
        $this->sectionHeader('10. Duplicate Bookings Detection');

        // Find potential duplicate bookings (same court, time, and user)
        $duplicates = Booking::select('court_id', 'start_time', 'end_time', 'user_id', DB::raw('COUNT(*) as count'))
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->groupBy('court_id', 'start_time', 'end_time', 'user_id')
            ->having('count', '>', 1)
            ->get();

        foreach ($duplicates as $duplicate) {
            $this->issuesFound++;
            $this->log("Duplicate bookings found: Court {$duplicate->court_id}, User {$duplicate->user_id}, Time {$duplicate->start_time} ({$duplicate->count} bookings)", 'error');

            if ($this->fixMode) {
                $bookings = Booking::where('court_id', $duplicate->court_id)
                    ->where('start_time', $duplicate->start_time)
                    ->where('end_time', $duplicate->end_time)
                    ->where('user_id', $duplicate->user_id)
                    ->whereNotIn('status', ['cancelled', 'rejected'])
                    ->orderBy('id')
                    ->get();

                // Keep the first one, cancel the rest
                $kept = $bookings->first();
                $cancelled = 0;
                foreach ($bookings->skip(1) as $booking) {
                    $booking->update([
                        'status' => Booking::STATUS_CANCELLED,
                        'admin_notes' => ($booking->admin_notes ?? '') . "\n[Auto-cancelled: Duplicate booking detected]"
                    ]);
                    $cancelled++;
                }

                $this->log("  ✓ Fixed: Kept booking #{$kept->id}, cancelled {$cancelled} duplicate(s)", 'success');
                $this->issuesFixed++;
            }
        }

        $this->sectionFooter();
    }

    /**
     * Display final summary report
     */
    private function displaySummaryReport()
    {
        $this->newLine(2);
        $this->info('╔════════════════════════════════════════════════════════════╗');
        $this->info('║                    SUMMARY REPORT                          ║');
        $this->info('╚════════════════════════════════════════════════════════════╝');
        $this->newLine();

        $this->info("Total Issues Found: {$this->issuesFound}");

        if ($this->fixMode) {
            $this->info("Issues Fixed: {$this->issuesFixed}");
            $this->info("Issues Requiring Manual Review: " . ($this->issuesFound - $this->issuesFixed));
        }

        $this->newLine();

        if ($this->issuesFound === 0) {
            $this->info("✓ No data inconsistencies detected! Your database is in good shape.");
        } elseif ($this->fixMode) {
            if ($this->issuesFixed > 0) {
                $this->info("✓ Automated fixes have been applied.");
            }
            if ($this->issuesFound > $this->issuesFixed) {
                $this->warn("⚠ Some issues require manual review. Please check the output above.");
            }
        } else {
            $this->warn("⚠ Issues detected. Run again with fix mode to attempt automatic repairs.");
        }

        $this->newLine();
        $this->info('Analysis complete!');
    }

    // Helper methods
    private function sectionHeader($title)
    {
        $this->newLine();
        $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->info($title);
        $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
    }

    private function sectionFooter()
    {
        // Optional: Add any section cleanup or summary
    }

    private function log($message, $level = 'info')
    {
        if (!$this->verbose && $level === 'info') {
            return; // Skip verbose info messages if not in verbose mode
        }

        switch ($level) {
            case 'error':
                $this->command->error("  ✗ {$message}");
                break;
            case 'warn':
                $this->command->warn("  ⚠ {$message}");
                break;
            case 'success':
                $this->command->info("  ✓ {$message}");
                break;
            default:
                $this->command->line("  → {$message}");
        }
    }

    private function info($message)
    {
        $this->command->info($message);
    }

    private function warn($message)
    {
        $this->command->warn($message);
    }

    private function error($message)
    {
        $this->command->error($message);
    }

    private function newLine($count = 1)
    {
        for ($i = 0; $i < $count; $i++) {
            $this->command->newLine();
        }
    }
}
