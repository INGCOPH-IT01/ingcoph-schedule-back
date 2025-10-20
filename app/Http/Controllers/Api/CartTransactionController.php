<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartTransaction;
use App\Mail\BookingApproved;
use App\Events\BookingStatusChanged;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CartTransactionController extends Controller
{
    /**
     * Get all cart transactions for the authenticated user
     */
    public function index(Request $request)
    {
        $transactions = CartTransaction::with(['user', 'cartItems.court.sport', 'cartItems.sport', 'cartItems.court.images', 'cartItems.bookings', 'bookings', 'approver'])
            ->where('user_id', $request->user()->id)
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
        $transactions = CartTransaction::with(['user', 'cartItems.court.sport', 'cartItems.sport', 'cartItems.court.images', 'cartItems.bookings', 'cartItems.bookingForUser', 'bookings', 'approver'])
            ->whereIn('status', ['pending', 'completed'])
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json($transactions);
    }

    /**
     * Get a specific cart transaction
     */
    public function show(Request $request, $id)
    {
        $transaction = CartTransaction::with(['user', 'cartItems.court.sport', 'cartItems.sport', 'cartItems.court.images', 'cartItems.bookings', 'bookings', 'approver'])
            ->findOrFail($id);

        // Check if user owns this transaction or is admin/staff
        if ($transaction->user_id !== $request->user()->id &&
            !in_array($request->user()->role, ['admin', 'staff'])) {
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

        return response()->json([
            'message' => 'Transaction rejected',
            'transaction' => $transaction->load(['approver', 'bookings'])
        ]);
    }

    /**
     * Get pending transactions (admin/staff only)
     */
    public function pending(Request $request)
    {
        $transactions = CartTransaction::with(['user', 'cartItems.court.sport', 'cartItems.sport', 'cartItems.court.images', 'cartItems.bookings', 'bookings'])
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
     * Serve proof of payment image for cart transaction
     */
    public function getProofOfPayment(Request $request, $id)
    {
        $transaction = CartTransaction::find($id);

        if (!$transaction) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found'
            ], 404);
        }

        // Check if user is authorized to view this proof
        // Only the transaction owner, admin, or staff can view
        $user = $request->user();
        if ($user->id !== $transaction->user_id &&
            $user->role !== 'admin' &&
            $user->role !== 'staff') {
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

        // Get the file path from storage
        $path = storage_path('app/public/' . $transaction->proof_of_payment);

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
}