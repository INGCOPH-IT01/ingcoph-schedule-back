<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartTransaction;
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
                    $query->where('status', '!=', 'cancelled');
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
                            ->where('status', '!=', 'cancelled');
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
                    $query->where('status', '!=', 'cancelled');
                },
                'cartItems.court.sport',
                'cartItems.sport',
                'cartItems.court.images',
                'cartItems.bookings',
                'cartItems.bookingForUser',
                'bookings',
                'approver'
            ])
            ->whereIn('status', ['pending', 'completed']);

        // Filter by booking date range if provided
        if ($request->filled('date_from')) {
            $query->whereHas('cartItems', function($q) use ($request) {
                $q->where('booking_date', '>=', $request->date_from)
                  ->where('status', '!=', 'cancelled');
            });
        }

        if ($request->filled('date_to')) {
            $query->whereHas('cartItems', function($q) use ($request) {
                $q->where('booking_date', '<=', $request->date_to)
                  ->where('status', '!=', 'cancelled');
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
                    $query->where('status', '!=', 'cancelled');
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

        Log::info('Cart transaction approved', [
            'transaction_id' => $transaction->id,
            'approved_by' => $request->user()->id,
            'user_id' => $transaction->user_id
        ]);

        // Send email notification to user
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

            if ($transactionWithDetails && $transactionWithDetails->user && $transactionWithDetails->user->email) {
                Mail::to($transactionWithDetails->user->email)
                    ->send(new BookingApproved($transactionWithDetails));

                Log::info('Approval email sent to: ' . $transactionWithDetails->user->email);
            }
        } catch (\Exception $e) {
            // Log error but don't fail the approval
            Log::error('Failed to send approval email: ' . $e->getMessage());
        }

        // Notify waitlist users - transaction approved, slots are confirmed
        // No action needed for waitlist as the slot is now taken

        return response()->json([
            'message' => 'Transaction approved successfully and notification email sent',
            'transaction' => $transaction->load(['approver', 'bookings'])
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

        Log::info('Cart transaction rejected', [
            'transaction_id' => $transaction->id,
            'rejected_by' => $request->user()->id,
            'user_id' => $transaction->user_id,
            'reason' => $request->reason
        ]);

        // Notify waitlist users - transaction rejected, slots are now available
        try {
            $this->notifyWaitlistUsers($transaction, 'rejected');
        } catch (\Exception $e) {
            Log::error('Failed to notify waitlist users: ' . $e->getMessage());
        }

        return response()->json([
            'message' => 'Transaction rejected',
            'transaction' => $transaction->load(['approver', 'bookings'])
        ]);
    }

    /**
     * Notify waitlist users when a transaction is rejected
     * This makes the time slots available to waitlisted users
     */
    private function notifyWaitlistUsers(CartTransaction $transaction, string $notificationType = 'rejected')
    {
        // Get all cart items from this transaction
        $cartItems = $transaction->cartItems()->where('status', '!=', 'cancelled')->get();

        foreach ($cartItems as $cartItem) {
            // Create datetime strings for the cart item
            $startDateTime = $cartItem->booking_date . ' ' . $cartItem->start_time;
            $endDateTime = $cartItem->booking_date . ' ' . $cartItem->end_time;

            // Find pending waitlist entries for this time slot
            $waitlistEntries = BookingWaitlist::where('court_id', $cartItem->court_id)
                ->where('start_time', $startDateTime)
                ->where('end_time', $endDateTime)
                ->where('status', BookingWaitlist::STATUS_PENDING)
                ->orderBy('position')
                ->orderBy('created_at')
                ->get();

            Log::info('Found ' . $waitlistEntries->count() . ' waitlist entries for court ' . $cartItem->court_id);

            // Notify each waitlist user
            foreach ($waitlistEntries as $waitlistEntry) {
                try {
                    // Load relationships for email
                    $waitlistEntry->load(['user', 'court', 'sport']);

                    // Send notification email and start expiration timer (1 hour by default)
                    $waitlistEntry->sendNotification(1);

                    // Send email
                    if ($waitlistEntry->user && $waitlistEntry->user->email) {
                        Mail::to($waitlistEntry->user->email)
                            ->send(new WaitlistNotificationMail($waitlistEntry, $notificationType));

                        Log::info('Waitlist notification email sent', [
                            'waitlist_id' => $waitlistEntry->id,
                            'user_id' => $waitlistEntry->user_id,
                            'user_email' => $waitlistEntry->user->email,
                            'notification_type' => $notificationType
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error('Failed to notify waitlist user', [
                        'waitlist_id' => $waitlistEntry->id,
                        'error' => $e->getMessage()
                    ]);
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

            Log::info('QR code verified successfully', [
                'transaction_id' => $transaction->id,
                'verified_by' => $request->user()->id,
                'attendance_status' => 'showed_up'
            ]);

            return response()->json([
                'valid' => true,
                'message' => 'QR code verified successfully',
                'transaction' => $transaction
            ]);

        } catch (\Exception $e) {
            Log::error('QR verification failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

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

            Log::info('Cart transaction deleted', [
                'transaction_id' => $id,
                'user_id' => $request->user()->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Transaction deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to delete cart transaction', [
                'transaction_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

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

        Log::info('Attendance status updated', [
            'transaction_id' => $transaction->id,
            'attendance_status' => $request->attendance_status,
            'updated_by' => $request->user()->id
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

            Log::info('Proof of payment uploaded for cart transaction', [
                'transaction_id' => $transaction->id,
                'uploaded_by' => $user->id,
                'payment_method' => $request->payment_method,
                'file_count' => count($uploadedPaths)
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
            Log::error('Failed to upload proof of payment for cart transaction', [
                'transaction_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

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
            Log::info('Transaction confirmation email resent successfully', [
                'transaction_id' => $transaction->id,
                'recipient_email' => $recipientEmail,
                'resent_by' => $request->user()->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Confirmation email sent successfully to ' . $recipientEmail
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to resend transaction confirmation email', [
                'transaction_id' => $transaction->id,
                'recipient_email' => $recipientEmail,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send confirmation email: ' . $e->getMessage()
            ], 500);
        }
    }
}