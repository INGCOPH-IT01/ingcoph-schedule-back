<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CartTransactionResource;
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
use Illuminate\Support\Facades\Storage;

class CartTransactionController extends Controller
{
    /**
     * Store waitlist entries to notify after transaction commit
     */
    private $waitlistEntriesToNotify = [];

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
                'approver',
                'posSales.saleItems.product'
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

        return CartTransactionResource::collection($transactions);
    }

    /**
     * Get all cart transactions (admin/staff only) - Optimized version
     */
    public function all(Request $request)
    {
        // Start building query with base filters
        $query = CartTransaction::query()
            ->whereIn('status', ['pending', 'completed']);

        // Apply server-side filters early to reduce dataset

        // Filter by approval status
        if ($request->filled('approval_status')) {
            $approvalStatuses = is_array($request->approval_status)
                ? $request->approval_status
                : explode(',', $request->approval_status);

            // Handle special 'pending_waitlist' filter
            if (in_array('pending_waitlist', $approvalStatuses)) {
                // Show transactions that have waitlist entries AND are not approved
                $query->whereHas('waitlistEntries')
                    ->where('approval_status', '!=', 'approved');
            } else if (!empty($approvalStatuses)) {
                $query->whereIn('approval_status', $approvalStatuses);
            }
        }

        // Filter by payment status
        if ($request->filled('payment_status')) {
            $paymentStatuses = is_array($request->payment_status)
                ? $request->payment_status
                : explode(',', $request->payment_status);

            foreach ($paymentStatuses as $status) {
                if ($status === 'complete') {
                    $query->where(function($q) {
                        $q->whereNotNull('payment_method')
                          ->where('payment_method', '!=', '')
                          ->whereNotNull('proof_of_payment')
                          ->where('proof_of_payment', '!=', '');
                    });
                } else if ($status === 'missing_proof') {
                    $query->where(function($q) {
                        $q->whereNull('proof_of_payment')
                          ->orWhere('proof_of_payment', '');
                    });
                }
            }
        }

        // Filter by booking date range if provided
        if ($request->filled('date_from') || $request->filled('date_to')) {
            $query->whereHas('cartItems', function($q) use ($request) {
                $q->where('status', '!=', 'cancelled')
                  ->whereNull('booking_waitlist_id');

                if ($request->filled('date_from')) {
                    $q->where('booking_date', '>=', $request->date_from);
                }

                if ($request->filled('date_to')) {
                    $q->where('booking_date', '<=', $request->date_to);
                }
            });
        }

        // Filter by sport
        if ($request->filled('sport')) {
            $query->whereHas('cartItems.sport', function($q) use ($request) {
                $q->where('name', $request->sport);
            });
        }

        // Filter by user (name, email, or admin notes)
        if ($request->filled('user_search')) {
            $searchTerm = $request->user_search;
            $query->where(function($q) use ($searchTerm) {
                // Search in transaction user
                $q->whereHas('user', function($userQuery) use ($searchTerm) {
                    $userQuery->where('name', 'like', "%{$searchTerm}%")
                             ->orWhere('email', 'like', "%{$searchTerm}%");
                })
                // Search in booking_for_user
                ->orWhereHas('cartItems.bookingForUser', function($bookingForQuery) use ($searchTerm) {
                    $bookingForQuery->where('name', 'like', "%{$searchTerm}%")
                                   ->orWhere('email', 'like', "%{$searchTerm}%");
                })
                // Search in booking_for_user_name (walk-in customers)
                ->orWhereHas('cartItems', function($cartItemQuery) use ($searchTerm) {
                    $cartItemQuery->where('booking_for_user_name', 'like', "%{$searchTerm}%")
                                 ->orWhere('admin_notes', 'like', "%{$searchTerm}%");
                });
            });
        }

        // Handle sorting
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'asc');
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
                $query->orderBy(
                    DB::raw('(SELECT booking_date FROM cart_items WHERE cart_items.cart_transaction_id = cart_transactions.id AND cart_items.status != \'cancelled\' ORDER BY booking_date ASC LIMIT 1)'),
                    $sortOrder
                );
                break;
            default:
                $query->orderBy('created_at', $sortOrder);
                break;
        }

        // Add pagination support
        $perPage = $request->input('per_page', 50); // Default 50 items per page
        $perPage = min($perPage, 100); // Max 100 items per page

        // Get total count before pagination for statistics
        $totalCount = $query->count();

        // Only load relationships after filtering and before pagination
        $query->with([
            'user:id,first_name,last_name,email,role',
            'cartItems' => function($q) {
                $q->where('status', '!=', 'cancelled')
                  ->whereNull('booking_waitlist_id')
                  ->select('id', 'cart_transaction_id', 'court_id', 'sport_id', 'booking_date',
                          'start_time', 'end_time', 'price', 'status', 'booking_for_user_id',
                          'booking_for_user_name', 'admin_notes', 'notes');
            },
            'cartItems.court:id,name',
            'cartItems.sport:id,name,icon',
            'cartItems.bookingForUser:id,first_name,last_name,email',
            'posSales.saleItems.product',
            'approver:id,first_name,last_name,email',
            'waitlistEntries' => function($q) {
                $q->select('id', 'pending_booking_id', 'user_id', 'booking_for_user_id',
                          'booking_for_user_name', 'position', 'status')
                  ->orderBy('position', 'asc');
            },
            'waitlistEntries.user:id,first_name,last_name,email',
            'waitlistEntries.bookingForUser:id,first_name,last_name,email'
        ]);

        // Apply pagination
        if ($request->has('page')) {
            $transactions = $query->paginate($perPage);

            return response()->json([
                'data' => CartTransactionResource::collection($transactions->items()),
                'pagination' => [
                    'total' => $transactions->total(),
                    'per_page' => $transactions->perPage(),
                    'current_page' => $transactions->currentPage(),
                    'last_page' => $transactions->lastPage(),
                    'from' => $transactions->firstItem(),
                    'to' => $transactions->lastItem()
                ],
                'summary' => [
                    'total_filtered' => $totalCount
                ]
            ]);
        }

        // If no pagination requested, return all (maintain backward compatibility)
        $transactions = $query->get();
        return CartTransactionResource::collection($transactions);
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
                'cartItems.bookingForUser',
                'bookings',
                'posSales.saleItems.product',
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

        return new CartTransactionResource($transaction);
    }

    /**
     * Approve a cart transaction (admin/staff only)
     */
    public function approve(Request $request, $id)
    {
        // Wrap entire operation in transaction for atomicity
        DB::beginTransaction();
        try {
            // Use lockForUpdate to prevent concurrent approvals
            $transaction = CartTransaction::with(['cartItems.court', 'bookings.court', 'user'])
                ->lockForUpdate()
                ->findOrFail($id);

            if ($transaction->approval_status === 'approved') {
                DB::rollBack();
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

            // Get booking IDs for bulk update
            $bookingIds = $transaction->bookings->pluck('id')->toArray();

            // Bulk update all bookings to 'approved' status for atomicity
            if (!empty($bookingIds)) {
                Booking::whereIn('id', $bookingIds)->update(['status' => 'approved']);
            }

            // Bulk update all cart items to 'approved' status for data consistency
            $transaction->cartItems()->update(['status' => 'approved']);

            // Update individual QR codes (within same transaction)
            foreach ($transaction->bookings()->whereIn('id', $bookingIds)->get() as $booking) {
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

                $booking->update(['qr_code' => $bookingQrData]);
            }

            // Cancel waitlist within same transaction
            $this->cancelWaitlistUsers($transaction);

            // Reject waitlist cart records
            $waitlistCartService = app(\App\Services\WaitlistCartService::class);
            foreach ($transaction->bookings as $approvedBooking) {
                $waitlistEntries = BookingWaitlist::where('pending_booking_id', $approvedBooking->id)
                    ->whereIn('status', [BookingWaitlist::STATUS_PENDING, BookingWaitlist::STATUS_NOTIFIED])
                    ->get();

                foreach ($waitlistEntries as $waitlistEntry) {
                    $waitlistCartService->rejectWaitlistCartRecords($waitlistEntry);
                }
            }

            // Commit all changes atomically
            DB::commit();

            // AFTER commit: Broadcast events (failures here won't affect data integrity)
            foreach ($transaction->bookings()->whereIn('id', $bookingIds)->get() as $booking) {
                try {
                    broadcast(new BookingStatusChanged($booking->fresh(['user', 'court.sport']), 'pending', 'approved'))->toOthers();
                } catch (\Exception $e) {
                    Log::error('Failed to broadcast booking status change', [
                        'booking_id' => $booking->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // AFTER commit: Process waitlist notifications
            foreach ($this->waitlistEntriesToNotify as $notifyData) {
                if (isset($notifyData['waitlist'])) {
                    try {
                        $waitlistEntry = $notifyData['waitlist'];
                        if ($waitlistEntry->user && $waitlistEntry->user->email) {
                            Mail::to($waitlistEntry->user->email)
                                ->send(new \App\Mail\WaitlistCancelledMail($waitlistEntry));
                        }
                    } catch (\Exception $e) {
                        Log::error('Failed to send waitlist cancellation email', [
                            'waitlist_id' => $notifyData['waitlist']->id ?? null,
                            'error' => $e->getMessage()
                        ]);
                    }
                }

                if (isset($notifyData['booking'])) {
                    try {
                        broadcast(new \App\Events\BookingStatusChanged(
                            $notifyData['booking'],
                            $notifyData['old_status'],
                            'rejected'
                        ))->toOthers();
                    } catch (\Exception $e) {
                        Log::error('Failed to broadcast waitlist booking rejection', [
                            'booking_id' => $notifyData['booking']->id ?? null,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }

            // Clear the notifications array
            $this->waitlistEntriesToNotify = [];

            // AFTER commit: Send email notification (failures here won't affect data integrity)
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

            return response()->json([
                'message' => 'Transaction approved successfully and notification email sent',
                'transaction' => $transaction->load(['approver', 'bookings']),
                'email_debug' => $emailDebug,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to approve cart transaction', [
                'transaction_id' => $id,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to approve transaction. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Reject a cart transaction (admin/staff only)
     */
    public function reject(Request $request, $id)
    {
        $request->validate([
            'reason' => 'required|string|max:500'
        ]);

        // Wrap entire operation in transaction for atomicity
        DB::beginTransaction();
        try {
            // Use lockForUpdate to prevent concurrent rejections
            $transaction = CartTransaction::with(['cartItems.court', 'bookings.court', 'user'])
                ->lockForUpdate()
                ->findOrFail($id);

            if ($transaction->approval_status === 'rejected') {
                DB::rollBack();
                return response()->json(['message' => 'Transaction already rejected'], 400);
            }

            // Update the cart transaction status
            $transaction->update([
                'approval_status' => 'rejected',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
                'rejection_reason' => $request->reason
            ]);

            // Bulk update all associated bookings status to 'rejected' for atomicity
            $transaction->bookings()->update([
                'status' => 'rejected'
            ]);

            // Bulk update all cart items to 'rejected' status for data consistency
            $transaction->cartItems()->update(['status' => 'rejected']);

            // Notify waitlist users within same transaction - slots are now available
            $this->notifyWaitlistUsers($transaction, 'rejected');

            // Commit all changes atomically
            DB::commit();

            // AFTER commit: Broadcast events (failures here won't affect data integrity)
            foreach ($transaction->bookings as $booking) {
                try {
                    broadcast(new BookingStatusChanged($booking->fresh(['user', 'court.sport']), 'pending', 'rejected'))->toOthers();
                } catch (\Exception $e) {
                    Log::error('Failed to broadcast booking status change', [
                        'booking_id' => $booking->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // AFTER commit: Send waitlist notification emails
            $rejectedBookings = $transaction->bookings;
            foreach ($rejectedBookings as $rejectedBooking) {
                $waitlistEntries = BookingWaitlist::where('pending_booking_id', $rejectedBooking->id)
                    ->where('status', BookingWaitlist::STATUS_NOTIFIED)
                    ->with(['user', 'court', 'sport'])
                    ->get();

                foreach ($waitlistEntries as $waitlistEntry) {
                    try {
                        if ($waitlistEntry->user && $waitlistEntry->user->email) {
                            Mail::to($waitlistEntry->user->email)
                                ->send(new WaitlistNotificationMail($waitlistEntry, 'rejected'));
                        }
                    } catch (\Exception $e) {
                        Log::error('Failed to send waitlist notification email', [
                            'waitlist_id' => $waitlistEntry->id,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }

            return response()->json([
                'message' => 'Transaction rejected',
                'transaction' => $transaction->load(['approver', 'bookings'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to reject cart transaction', [
                'transaction_id' => $id,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to reject transaction. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Cancel waitlist entries when a transaction is approved
     * This notifies waitlisted users that the slot is no longer available
     * Also rejects any auto-created bookings for waitlisted users
     *
     * NOTE: This method is designed to be called within an existing transaction.
     * It does not create its own transaction wrapper.
     */
    private function cancelWaitlistUsers(CartTransaction $transaction)
    {
        // Store waitlist entries for email sending after commit
        $waitlistEntriesToNotify = [];

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
                // Load relationships
                $waitlistEntry->load(['user', 'court', 'sport']);

                $rejectedBookingIds = [];
                $rejectedTransactionIds = [];

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

                                // Store for broadcasting after commit
                                $waitlistEntriesToNotify[] = [
                                    'booking' => $booking,
                                    'old_status' => $oldBookingStatus
                                ];
                            }
                        }
                    }
                }

                // Mark waitlist as cancelled
                $waitlistEntry->cancel();

                // Store for email sending after commit
                $waitlistEntriesToNotify[] = [
                    'waitlist' => $waitlistEntry,
                    'rejected_bookings' => $rejectedBookingIds
                ];
            }
        }

        // Store the entries to notify in a class property for after-commit processing
        $this->waitlistEntriesToNotify = $waitlistEntriesToNotify;
    }

    /**
     * Notify waitlist users when a transaction is rejected
     * IMPORTANT: Only notifies the FIRST user in queue (Position #1)
     * Other users (Position #2+) remain pending until Position #1's outcome is resolved
     *
     * NOTE: This method is designed to be called within an existing transaction.
     * It does not create its own transaction wrapper.
     */
    private function notifyWaitlistUsers(CartTransaction $transaction, string $notificationType = 'rejected')
    {
        // Get all bookings from this transaction
        $rejectedBookings = $transaction->bookings;

        foreach ($rejectedBookings as $rejectedBooking) {
            // Find ONLY the next waitlist entry (Position #1) linked to this specific booking
            // Position #2, #3, etc. will remain pending until Position #1 outcome is determined
            $waitlistEntry = BookingWaitlist::where('pending_booking_id', $rejectedBooking->id)
                ->where('status', BookingWaitlist::STATUS_PENDING)
                ->orderBy('position')
                ->orderBy('created_at')
                ->first(); // Only get Position #1

            // Process Position #1 waitlist user if exists
            if ($waitlistEntry) {
                $waitlistCartService = app(\App\Services\WaitlistCartService::class);
                try {
                    // Load relationships
                    $waitlistEntry->load(['user', 'court', 'sport']);

                    // Convert waitlist to booking using the service
                    // This will create CartTransaction, CartItems, and Booking from WaitlistCartTransaction and WaitlistCartItems
                    $newBooking = $waitlistCartService->convertWaitlistToBooking($waitlistEntry);

                    // Send notification email with business-hours-aware payment deadline
                    // If rejected after 5pm or before 8am: deadline is 9am next working day
                    // If rejected during business hours: deadline is 1 hour from now
                    $waitlistEntry->sendNotification();

                    Log::info('Notified Position #1 waitlist user', [
                        'waitlist_id' => $waitlistEntry->id,
                        'position' => $waitlistEntry->position,
                        'user_id' => $waitlistEntry->user_id,
                        'pending_booking_id' => $rejectedBooking->id
                    ]);

                    // Note: Email sending moved outside transaction in calling code
                } catch (\Exception $e) {
                    // Log error but don't break the transaction
                    Log::error('Failed to process waitlist entry', [
                        'waitlist_id' => $waitlistEntry->id,
                        'position' => $waitlistEntry->position,
                        'error' => $e->getMessage()
                    ]);
                    // Re-throw to trigger rollback of parent transaction
                    throw $e;
                }
            } else {
                Log::info('No pending waitlist entries found for booking', [
                    'booking_id' => $rejectedBooking->id
                ]);
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
                // Wrap database updates in transaction for atomicity
                DB::beginTransaction();
                try {
                    $transaction->update([
                        'status' => 'checked_in',
                        'attendance_status' => 'showed_up'
                    ]);

                    // Bulk update all associated bookings to 'completed' and set attendance status
                    $transaction->bookings()->update([
                        'status' => 'completed',
                        'attendance_status' => 'showed_up'
                    ]);

                    // Bulk update all cart items to 'completed' status for data consistency
                    $transaction->cartItems()->update(['status' => 'completed']);

                    // Commit all changes atomically
                    DB::commit();

                    // AFTER commit: Broadcast real-time status change for each booking
                    foreach ($transaction->bookings as $booking) {
                        try {
                            broadcast(new BookingStatusChanged($booking->fresh(['user', 'court.sport']), 'approved', 'completed'))->toOthers();
                        } catch (\Exception $e) {
                            Log::error('Failed to broadcast QR check-in status change', [
                                'booking_id' => $booking->id,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    DB::rollBack();
                    Log::error('Failed to check-in transaction via QR', [
                        'transaction_id' => $transaction->id,
                        'error' => $e->getMessage()
                    ]);
                    throw $e;
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
            'payment_method' => 'required|string|in:gcash,cash',
            'payment_reference_number' => 'nullable|string|max:255'
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

        $uploadedPaths = [];

        try {
            // Upload files first (before database transaction)
            foreach ($request->file('proof_of_payment') as $index => $file) {
                $filename = 'proof_txn_' . $transaction->id . '_' . time() . '_' . $index . '.' . $file->getClientOriginalExtension();
                $path = $file->storeAs('proofs', $filename, 'public');
                $uploadedPaths[] = $path;
            }

            // Store as JSON array
            $proofOfPaymentJson = json_encode($uploadedPaths);

            // Wrap database updates in transaction for atomicity
            DB::beginTransaction();
            try {
                // Update transaction with proof of payment
                $transaction->update([
                    'proof_of_payment' => $proofOfPaymentJson,
                    'payment_method' => $request->payment_method,
                    'payment_reference_number' => $request->payment_reference_number,
                    'payment_status' => 'paid',
                    'paid_at' => now()
                ]);

                // Bulk update all associated bookings with payment reference number
                $transaction->bookings()->update([
                    'proof_of_payment' => $proofOfPaymentJson,
                    'payment_method' => $request->payment_method,
                    'payment_reference_number' => $request->payment_reference_number,
                    'payment_status' => 'paid',
                    'paid_at' => now()
                ]);

                // Commit all changes atomically
                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Proof of payment uploaded successfully',
                    'data' => [
                        'proof_of_payment' => $proofOfPaymentJson,
                        'proof_of_payment_files' => $uploadedPaths,
                        'payment_method' => $request->payment_method,
                        'payment_reference_number' => $request->payment_reference_number,
                        'payment_status' => 'paid',
                        'paid_at' => now()->toDateTimeString()
                    ]
                ]);

            } catch (\Exception $e) {
                DB::rollBack();

                // Clean up uploaded files on database failure
                foreach ($uploadedPaths as $path) {
                    \Storage::disk('public')->delete($path);
                }

                Log::error('Failed to update payment status in database', [
                    'transaction_id' => $id,
                    'error' => $e->getMessage()
                ]);

                throw $e;
            }

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