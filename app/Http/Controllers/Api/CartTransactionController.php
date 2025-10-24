<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartTransaction;
use App\Models\Booking;
use App\Models\BookingWaitlist;
use App\Mail\BookingApproved;
use App\Mail\WaitlistNotificationMail;
use App\Events\BookingStatusChanged;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CartTransactionController extends Controller
{
    /**
     * Get all cart transactions for the authenticated user
     */
    public function index(Request $request)
    {
        $userId = $request->user()->id;

        // Get transactions where user is either the owner OR the booking_for_user in any cart item
        $transactions = CartTransaction::with([
                'user',
                'cartItems' => function($query) {
                    $query->where('status', '!=', 'cancelled')
                          ->whereNull('booking_waitlist_id'); // Filter out waitlist bookings
                },
                'cartItems.court.sport',
                'cartItems.sport',
                'cartItems.court.images',
                'cartItems.bookings',
                'bookings',
                'approver'
            ])
            ->where(function($query) use ($userId) {
                $query->where('user_id', $userId)
                      ->orWhereHas('cartItems', function($q) use ($userId) {
                          $q->where('booking_for_user_id', $userId)
                            ->where('status', '!=', 'cancelled')
                            ->whereNull('booking_waitlist_id'); // Filter out waitlist bookings
                      });
            })
            ->whereIn('status', ['pending', 'completed'])
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json($transactions);
    }

    /**
     * Get all cart transactions (admin/staff only)
     */
    public function all(Request $request)
    {
        $query = CartTransaction::with([
                'user',
                'cartItems' => function($query) {
                    $query->where('status', '!=', 'cancelled')
                          ->whereNull('booking_waitlist_id'); // Filter out waitlist bookings
                },
                'cartItems.court.sport',
                'cartItems.sport',
                'cartItems.court.images',
                'cartItems.bookings',
                'cartItems.bookingForUser',
                'bookings',
                'approver',
                'waitlistEntries' => function($query) {
                    $query->orderBy('position', 'asc');
                },
                'waitlistEntries.user',
                'waitlistEntries.bookingForUser'
            ])
            ->whereIn('status', ['pending', 'completed'])
            ->whereHas('bookings'); // Only load transactions that have associated bookings

        // Filter by booking date range if provided
        if ($request->filled('date_from')) {
            $query->whereHas('cartItems', function($q) use ($request) {
                $q->where('booking_date', '>=', $request->date_from)
                  ->where('status', '!=', 'cancelled')
                  ->whereNull('booking_waitlist_id'); // Filter out waitlist bookings
            });
        }

        if ($request->filled('date_to')) {
            $query->whereHas('cartItems', function($q) use ($request) {
                $q->where('booking_date', '<=', $request->date_to)
                  ->where('status', '!=', 'cancelled')
                  ->whereNull('booking_waitlist_id'); // Filter out waitlist bookings
            });
        }

        // Handle sorting
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'asc');

        // Validate sort order
        $sortOrder = in_array(strtolower($sortOrder), ['asc', 'desc']) ? strtolower($sortOrder) : 'asc';

        // Apply sorting based on the field
        switch ($sortBy) {
            case 'id':
                $query->orderBy('id', $sortOrder);
                break;
            case 'created_at':
                $query->orderBy('created_at', $sortOrder);
                break;
            case 'total_price':
                $query->orderBy('total_price', $sortOrder);
                break;
            case 'booking_date':
                // For booking_date, we need to join with cart_items to sort
                // We'll use a subquery to get the first cart item's booking_date (excluding cancelled)
                $query->orderBy(
                    DB::raw('(SELECT booking_date FROM cart_items WHERE cart_items.cart_transaction_id = cart_transactions.id AND cart_items.status != \'cancelled\' ORDER BY booking_date ASC LIMIT 1)'),
                    $sortOrder
                );
                break;
            default:
                // Default sorting by created_at if invalid sort_by is provided
                $query->orderBy('created_at', $sortOrder);
                break;
        }

        $transactions = $query->get();

        return response()->json($transactions);
    }

    /**
     * Get a specific cart transaction
     */
    public function show(Request $request, $id)
    {
        $transaction = CartTransaction::with([
                'user',
                'cartItems' => function($query) {
                    $query->where('status', '!=', 'cancelled')
                          ->whereNull('booking_waitlist_id'); // Filter out waitlist bookings
                },
                'cartItems.court.sport',
                'cartItems.sport',
                'cartItems.court.images',
                'cartItems.bookings',
                'bookings',
                'approver'
            ])
            ->findOrFail($id);

        // Check if user owns this transaction, is the booking_for_user in any cart item, or is admin/staff
        $isOwner = $transaction->user_id === $request->user()->id;
        $isBookingForUser = $transaction->cartItems()->where('booking_for_user_id', $request->user()->id)
            ->where('status', '!=', 'cancelled')
            ->whereNull('booking_waitlist_id') // Filter out waitlist bookings
            ->exists();
        $isAdminOrStaff = in_array($request->user()->role, ['admin', 'staff']);

        if (!$isOwner && !$isBookingForUser && !$isAdminOrStaff) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($transaction);
    }

    /**
     * Approve a cart transaction (admin/staff only)
     */
    public function approve(Request $request, $id)
    {
        $transaction = CartTransaction::with(['cartItems.court', 'bookings.court', 'user'])->findOrFail($id);

        if ($transaction->approval_status === 'approved') {
            return response()->json(['message' => 'Transaction already approved'], 400);
        }

        // Generate QR code data for the transaction
        $qrData = json_encode([
            'transaction_id' => $transaction->id,
            'user_id' => $transaction->user_id,
            'user_name' => $transaction->user->name,
            'total_price' => $transaction->total_price,
            'payment_method' => $transaction->payment_method,
            'approved_at' => now()->toDateTimeString(),
            'type' => 'cart_transaction'
        ]);

        // Update the cart transaction status
        $transaction->update([
            'approval_status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'qr_code' => $qrData
        ]);

        // Update all associated bookings status to 'approved' with individual QR codes
        foreach ($transaction->bookings as $booking) {
            // Generate unique QR code for each booking
            $bookingQrData = json_encode([
                'transaction_id' => $transaction->id,
                'booking_id' => $booking->id,
                'user_id' => $transaction->user_id,
                'user_name' => $transaction->user->name,
                'court_name' => $booking->court->name ?? 'N/A',
                'date' => $booking->date,
                'start_time' => $booking->start_time,
                'end_time' => $booking->end_time,
                'price' => $booking->price,
                'payment_method' => $transaction->payment_method,
                'approved_at' => now()->toDateTimeString(),
                'type' => 'cart_transaction'
            ]);

            // Update booking status to match cart transaction approval status
            $booking->update([
                'status' => 'approved',
                'qr_code' => $bookingQrData
            ]);

            // Broadcast real-time status change for this booking
            broadcast(new BookingStatusChanged($booking->fresh(['user', 'court.sport']), 'pending', 'approved'))->toOthers();
        }

        // Send email notification to user (with debug logging)
        $emailDebug = [
            'attempted' => false,
            'sent' => false,
            'recipient' => null,
            'error' => null,
        ];

        try {
            // Reload transaction with full relationships for email
            $transactionWithDetails = CartTransaction::with([
                'user',
                'cartItems' => function($query) {
                    $query->where('status', '!=', 'cancelled')
                          ->orderBy('booking_date')
                          ->orderBy('start_time');
                },
                'cartItems.court.sport',
                'cartItems.sport',
                'cartItems.court'
            ])->find($transaction->id);

            if ($transactionWithDetails) {
                // Determine recipient: Booking For user's email if selected, else creator's email
                $recipientEmail = $transactionWithDetails->user->email ?? null;
                $firstCartItem = $transactionWithDetails->cartItems->first();
                if ($firstCartItem && $firstCartItem->booking_for_user_id && $firstCartItem->bookingForUser && $firstCartItem->bookingForUser->email) {
                    $recipientEmail = $firstCartItem->bookingForUser->email;
                }

                $emailDebug['recipient'] = $recipientEmail;
                $emailDebug['attempted'] = true;
                if ($recipientEmail) {
                    Mail::to($recipientEmail)
                        ->send(new BookingApproved($transactionWithDetails));
                    $emailDebug['sent'] = true;
                } else {
                    Log::warning('cart_approval_email.skipped_no_recipient', [
                        'transaction_id' => $transactionWithDetails->id,
                    ]);
                }
            }
        } catch (\Exception $e) {
            // Email error but don't fail the approval; capture debug info
            $emailDebug['error'] = $e->getMessage();
            Log::error('cart_approval_email.error', [
                'transaction_id' => $transaction->id,
                'recipient' => $emailDebug['recipient'],
                'error' => $emailDebug['error'],
            ]);
        }

        // Notify waitlist users - transaction approved, slots are confirmed
        // Cancel all waitlisted bookings for the same time slots
        try {
            $this->cancelWaitlistUsers($transaction);
        } catch (\Exception $e) {
            // Continue silently - approval should not fail due to waitlist notification issues
        }

        return response()->json([
            'message' => 'Transaction approved successfully and notification email sent',
            'transaction' => $transaction->load(['approver', 'bookings']),
            'email_debug' => $emailDebug,
        ]);
    }

    /**
     * Reject a cart transaction (admin/staff only)
     */
    public function reject(Request $request, $id)
    {
        $request->validate([
            'reason' => 'required|string|max:500'
        ]);

        $transaction = CartTransaction::with(['cartItems.court', 'bookings.court', 'user'])->findOrFail($id);

        if ($transaction->approval_status === 'rejected') {
            return response()->json(['message' => 'Transaction already rejected'], 400);
        }

        // Update the cart transaction status
        $transaction->update([
            'approval_status' => 'rejected',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'rejection_reason' => $request->reason
        ]);

        // Update all associated bookings status to 'rejected' to match cart transaction
        $transaction->bookings()->update([
            'status' => 'rejected'
        ]);

        // Broadcast real-time status change for each booking
        foreach ($transaction->bookings as $booking) {
            broadcast(new BookingStatusChanged($booking->fresh(['user', 'court.sport']), 'pending', 'rejected'))->toOthers();
        }

        // Notify waitlist users - transaction rejected, slots are now available
        try {
            $this->notifyWaitlistUsers($transaction, 'rejected');
        } catch (\Exception $e) {
            // Continue silently
        }

        return response()->json([
            'message' => 'Transaction rejected',
            'transaction' => $transaction->load(['approver', 'bookings'])
        ]);
    }

    /**
     * Cancel waitlist entries when a transaction is approved
     * This notifies waitlisted users that the slot is no longer available
     * Also rejects any auto-created bookings for waitlisted users
     */
    private function cancelWaitlistUsers(CartTransaction $transaction)
    {
        // Get all bookings from this transaction
        $approvedBookings = $transaction->bookings;

        foreach ($approvedBookings as $approvedBooking) {
            // Find ALL waitlist entries (pending and notified) linked to this booking
            $waitlistEntries = BookingWaitlist::where('pending_booking_id', $approvedBooking->id)
                ->whereIn('status', [BookingWaitlist::STATUS_PENDING, BookingWaitlist::STATUS_NOTIFIED])
                ->orderBy('position')
                ->orderBy('created_at')
                ->get();

            // Cancel each waitlist entry and reject associated bookings
            foreach ($waitlistEntries as $waitlistEntry) {
                try {
                    // Load relationships for email
                    $waitlistEntry->load(['user', 'court', 'sport']);

                    $rejectedBookingIds = [];
                    $rejectedTransactionIds = [];
                    $cancelledCartItemIds = [];

                    // Find cart items directly linked to this waitlist entry via foreign key
                    $waitlistCartItems = \App\Models\CartItem::where('booking_waitlist_id', $waitlistEntry->id)
                        ->whereNotIn('status', ['cancelled', 'rejected'])
                        ->get();

                    // Reject each cart item and its transaction
                    foreach ($waitlistCartItems as $cartItem) {
                        // Reject the cart item
                        $cartItem->update([
                            'status' => 'rejected',
                            'notes' => 'Rejected: Parent booking was approved, waitlist cancelled'
                        ]);
                        $cancelledCartItemIds[] = $cartItem->id;

                        // Reject the cart transaction if not already rejected
                        if ($cartItem->cart_transaction_id) {
                            $cartTransaction = CartTransaction::find($cartItem->cart_transaction_id);
                            if ($cartTransaction && $cartTransaction->approval_status !== 'rejected') {
                                $cartTransaction->update([
                                    'approval_status' => 'rejected',
                                    'rejection_reason' => 'Parent booking was approved - waitlist cancelled'
                                ]);

                                if (!in_array($cartTransaction->id, $rejectedTransactionIds)) {
                                    $rejectedTransactionIds[] = $cartTransaction->id;
                                }

                                // Find and reject bookings linked to this transaction
                                $transactionBookings = \App\Models\Booking::where('cart_transaction_id', $cartTransaction->id)
                                    ->where('id', '!=', $approvedBooking->id) // Don't touch parent!
                                    ->where('user_id', $waitlistEntry->user_id)
                                    ->whereIn('status', ['pending', 'approved'])
                                    ->get();

                                foreach ($transactionBookings as $booking) {
                                    $oldBookingStatus = $booking->status;
                                    $booking->update([
                                        'status' => 'rejected',
                                        'notes' => ($booking->notes ?? '') . "\n\nAuto-rejected: Parent booking was approved."
                                    ]);
                                    $rejectedBookingIds[] = $booking->id;

                                    // Broadcast status change
                                    broadcast(new \App\Events\BookingStatusChanged($booking, $oldBookingStatus, 'rejected'))->toOthers();
                                }
                            }
                        }
                    }

                    // Mark waitlist as cancelled
                    $waitlistEntry->cancel();

                    // Send cancellation email
                    if ($waitlistEntry->user && $waitlistEntry->user->email) {
                        Mail::to($waitlistEntry->user->email)
                            ->send(new \App\Mail\WaitlistCancelledMail($waitlistEntry));
                    }

                } catch (\Exception $e) {
                    // Silently continue processing other entries
                }
            }
        }
    }

    /**
     * Notify waitlist users when a transaction is rejected
     * This makes the time slots available to waitlisted users and auto-creates bookings
     */
    private function notifyWaitlistUsers(CartTransaction $transaction, string $notificationType = 'rejected')
    {
        // Get all bookings from this transaction
        $rejectedBookings = $transaction->bookings;

        foreach ($rejectedBookings as $rejectedBooking) {
            // Find waitlist entries linked to this specific booking
            $waitlistEntries = BookingWaitlist::where('pending_booking_id', $rejectedBooking->id)
                ->where('status', BookingWaitlist::STATUS_PENDING)
                ->orderBy('position')
                ->orderBy('created_at')
                ->get();

                // Process each waitlist user
                foreach ($waitlistEntries as $waitlistEntry) {
                    try {
                        DB::beginTransaction();

                        // Load relationships
                        $waitlistEntry->load(['user', 'court', 'sport']);

                        // Create booking automatically for waitlisted user
                        $newBooking = Booking::create([
                            'user_id' => $waitlistEntry->user_id,
                            'cart_transaction_id' => null, // Will be linked when they checkout
                            'booking_waitlist_id' => $waitlistEntry->id, // Save the waitlist ID
                            'court_id' => $waitlistEntry->court_id,
                            'sport_id' => $waitlistEntry->sport_id,
                            'start_time' => $waitlistEntry->start_time,
                            'end_time' => $waitlistEntry->end_time,
                            'total_price' => $waitlistEntry->price,
                            'number_of_players' => $waitlistEntry->number_of_players,
                            'status' => 'pending',  // Pending until payment is uploaded
                            'payment_status' => 'unpaid',
                            'payment_method' => 'pending',
                            'notes' => 'Auto-created from waitlist position #' . $waitlistEntry->position
                        ]);

                        // Update cart_items and cart_transactions to set booking_waitlist_id to null
                        \App\Models\CartItem::where('booking_waitlist_id', $waitlistEntry->id)
                            ->update(['booking_waitlist_id' => null]);

                        \App\Models\CartTransaction::where('booking_waitlist_id', $waitlistEntry->id)
                            ->update(['booking_waitlist_id' => null]);

                        // Send notification email with business-hours-aware payment deadline
                        // If rejected after 5pm or before 8am: deadline is 9am next working day
                        // If rejected during business hours: deadline is 1 hour from now
                        $waitlistEntry->sendNotification();

                        // Send email
                        if ($waitlistEntry->user && $waitlistEntry->user->email) {
                            Mail::to($waitlistEntry->user->email)
                                ->send(new WaitlistNotificationMail($waitlistEntry, $notificationType));
                        }

                        DB::commit();
                    } catch (\Exception $e) {
                        DB::rollBack();
                        // Silently continue
                    }
                }
        }
    }

    /**
     * Get pending transactions (admin/staff only)
     */
    public function pending(Request $request)
    {
        $transactions = CartTransaction::with([
                'user',
                'cartItems' => function($query) {
                    $query->where('status', '!=', 'cancelled');
                },
                'cartItems.court.sport',
                'cartItems.sport',
                'cartItems.court.images',
                'cartItems.bookings',
                'bookings'
            ])
            ->where('approval_status', 'pending')
            ->where('payment_status', 'paid')
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json($transactions);
    }

    /**
     * Verify QR code and check-in (staff only)
     */
    public function verifyQr(Request $request)
    {
        try {
            $qrData = json_decode($request->qr_code, true);

            if (!$qrData || !isset($qrData['transaction_id'])) {
                return response()->json([
                    'valid' => false,
                    'message' => 'Invalid QR code format'
                ], 400);
            }

            $transaction = CartTransaction::with(['user', 'cartItems.court.sport', 'cartItems.sport', 'cartItems.court.images'])
                ->find($qrData['transaction_id']);

            if (!$transaction) {
                return response()->json([
                    'valid' => false,
                    'message' => 'Transaction not found'
                ], 404);
            }

            if ($transaction->approval_status !== 'approved') {
                return response()->json([
                    'valid' => false,
                    'message' => 'Transaction is not approved',
                    'transaction' => $transaction
                ], 403);
            }

            // Update transaction status to checked-in if needed
            if ($transaction->status !== 'checked_in') {
                $transaction->update([
                    'status' => 'checked_in',
                    'attendance_status' => 'showed_up'
                ]);

                // Update associated bookings to 'completed' and set attendance status
                $transaction->bookings()->update([
                    'status' => 'completed',
                    'attendance_status' => 'showed_up'
                ]);

                // Broadcast real-time status change for each booking
                foreach ($transaction->bookings as $booking) {
                    broadcast(new BookingStatusChanged($booking->fresh(['user', 'court.sport']), 'approved', 'completed'))->toOthers();
                }
            }

            return response()->json([
                'valid' => true,
                'message' => 'QR code verified successfully',
                'transaction' => $transaction
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'valid' => false,
                'message' => 'QR code verification failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a cart transaction
     */
    public function destroy(Request $request, $id)
    {
        try {
            $transaction = CartTransaction::findOrFail($id);

            // Check if user owns this transaction
            if ($transaction->user_id !== $request->user()->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            // Only allow deletion of pending or expired transactions
            if (!in_array($transaction->status, ['pending', 'expired'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending or expired transactions can be deleted'
                ], 400);
            }

            // Delete the transaction (cart items will be cascade deleted)
            $transaction->delete();

            return response()->json([
                'success' => true,
                'message' => 'Transaction deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete transaction: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update attendance status for a cart transaction
     */
    public function updateAttendanceStatus(Request $request, $id)
    {
        $request->validate([
            'attendance_status' => 'required|string|in:not_set,showed_up,no_show'
        ]);

        $transaction = CartTransaction::findOrFail($id);

        // Only admin can update attendance status
        if ($request->user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to update attendance status'
            ], 403);
        }

        $transaction->update([
            'attendance_status' => $request->attendance_status
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Attendance status updated successfully',
            'transaction' => $transaction->load(['user', 'cartItems.court.sport', 'cartItems.sport'])
        ]);
    }

    /**
     * Upload proof of payment for a cart transaction
     */
    public function uploadProofOfPayment(Request $request, $id)
    {
        $request->validate([
            'proof_of_payment' => 'required|array', // Accept array of files
            'proof_of_payment.*' => 'required|image|max:5120', // 5MB max per file
            'payment_method' => 'required|string|in:gcash,cash'
        ]);

        $transaction = CartTransaction::with(['bookings'])->findOrFail($id);

        // Check if user is authorized to upload proof
        // Only the transaction owner, booking_for_user, admin, or staff can upload
        $user = $request->user();
        $isOwner = $user->id === $transaction->user_id;
        $isBookingForUser = $transaction->cartItems()->where('booking_for_user_id', $user->id)->exists();
        $isAdminOrStaff = in_array($user->role, ['admin', 'staff']);

        if (!$isOwner && !$isBookingForUser && !$isAdminOrStaff) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to upload proof of payment for this transaction'
            ], 403);
        }

        // Disallow uploads for rejected transactions
        if ($transaction->approval_status === 'rejected') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot upload proof of payment for a rejected transaction'
            ], 422);
        }

        try {
            $uploadedPaths = [];

            // Store multiple uploaded files
            foreach ($request->file('proof_of_payment') as $index => $file) {
                $filename = 'proof_txn_' . $transaction->id . '_' . time() . '_' . $index . '.' . $file->getClientOriginalExtension();
                $path = $file->storeAs('proofs', $filename, 'public');
                $uploadedPaths[] = $path;
            }

            // Store as JSON array
            $proofOfPaymentJson = json_encode($uploadedPaths);

            // Update transaction with proof of payment
            $transaction->update([
                'proof_of_payment' => $proofOfPaymentJson,
                'payment_method' => $request->payment_method,
                'payment_status' => 'paid',
                'paid_at' => now()
            ]);

            // Update all associated bookings
            $transaction->bookings()->update([
                'proof_of_payment' => $proofOfPaymentJson,
                'payment_method' => $request->payment_method,
                'payment_status' => 'paid',
                'paid_at' => now()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Proof of payment uploaded successfully',
                'data' => [
                    'proof_of_payment' => $proofOfPaymentJson,
                    'proof_of_payment_files' => $uploadedPaths,
                    'payment_method' => $request->payment_method,
                    'payment_status' => 'paid',
                    'paid_at' => now()->toDateTimeString()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload proof of payment: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Serve proof of payment image for cart transaction
     */
    public function getProofOfPayment(Request $request, $id)
    {
        $transaction = CartTransaction::with('cartItems')->find($id);

        if (!$transaction) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found'
            ], 404);
        }

        // Check if user is authorized to view this proof
        // Only the transaction owner, booking_for_user in any cart item, admin, or staff can view
        $user = $request->user();
        $isOwner = $user->id === $transaction->user_id;
        $isBookingForUser = $transaction->cartItems()->where('booking_for_user_id', $user->id)->exists();
        $isAdmin = $user->role === 'admin';
        $isStaff = $user->role === 'staff';

        if (!$isOwner && !$isBookingForUser && !$isAdmin && !$isStaff) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to view this proof of payment'
            ], 403);
        }

        if (!$transaction->proof_of_payment) {
            return response()->json([
                'success' => false,
                'message' => 'No proof of payment found for this transaction'
            ], 404);
        }

        // Try to decode as JSON array (multiple files)
        $proofFiles = json_decode($transaction->proof_of_payment, true);

        // If it's not JSON (backward compatibility with single file), treat as single file
        if (json_last_error() !== JSON_ERROR_NONE) {
            $proofFiles = [$transaction->proof_of_payment];
        }

        // If index parameter is provided, return specific file
        $index = $request->query('index', 0);

        if (!isset($proofFiles[$index])) {
            return response()->json([
                'success' => false,
                'message' => 'Proof of payment file not found at index ' . $index
            ], 404);
        }

        // Get the file path from storage
        $path = storage_path('app/public/' . $proofFiles[$index]);

        if (!file_exists($path)) {
            return response()->json([
                'success' => false,
                'message' => 'Proof of payment file not found'
            ], 404);
        }

        // Return the file with appropriate headers
        return response()->file($path, [
            'Content-Type' => mime_content_type($path),
            'Cache-Control' => 'public, max-age=3600'
        ]);
    }

    /**
     * Resend confirmation email for an approved cart transaction
     */
    public function resendConfirmationEmail(Request $request, $id)
    {
        $transaction = CartTransaction::with(['user', 'cartItems.court.sport', 'cartItems.sport', 'cartItems.bookingForUser'])->find($id);

        if (!$transaction) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found'
            ], 404);
        }

        // Check if user owns this transaction, is the booking_for_user, is admin, or is staff
        $isOwner = $transaction->user_id === $request->user()->id;
        $isBookingForUser = $transaction->cartItems()->where('booking_for_user_id', $request->user()->id)->exists();
        $isAdmin = $request->user()->isAdmin();
        $isStaff = $request->user()->isStaff();

        if (!$isOwner && !$isBookingForUser && !$isAdmin && !$isStaff) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to resend confirmation email for this transaction'
            ], 403);
        }

        // Only approved transactions should receive confirmation emails
        if ($transaction->approval_status !== 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Confirmation email can only be sent for approved transactions'
            ], 400);
        }

        // Determine which email to send to
        $recipientEmail = $transaction->user->email;

        // If booking was made for another user, send to that user's email
        $firstCartItem = $transaction->cartItems->first();
        if ($firstCartItem && $firstCartItem->booking_for_user_id && $firstCartItem->bookingForUser) {
            $recipientEmail = $firstCartItem->bookingForUser->email;
        }

        // Send confirmation email
        try {
            Mail::to($recipientEmail)->send(new BookingApproved($transaction));

            return response()->json([
                'success' => true,
                'message' => 'Confirmation email sent successfully to ' . $recipientEmail
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send confirmation email: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get waitlist entries for a cart transaction
     */
    public function getWaitlistEntries($id)
    {
        try {
            $transaction = CartTransaction::findOrFail($id);

            // Load waitlist entries with user, court, and sport relationships
            $waitlistEntries = $transaction->waitlistEntries()
                ->with(['user', 'bookingForUser', 'court', 'sport'])
                ->orderBy('position', 'asc')
                ->orderBy('created_at', 'asc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $waitlistEntries
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load waitlist entries: ' . $e->getMessage()
            ], 500);
        }
    }
}