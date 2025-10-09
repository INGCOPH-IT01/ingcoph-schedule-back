<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CartTransactionController extends Controller
{
    /**
     * Get all cart transactions for the authenticated user
     */
    public function index(Request $request)
    {
        $transactions = CartTransaction::with(['user', 'cartItems.court.sport', 'cartItems.court.images', 'approver'])
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
        $transactions = CartTransaction::with(['user', 'cartItems.court.sport', 'cartItems.court.images', 'approver'])
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
        $transaction = CartTransaction::with(['user', 'cartItems.court.sport', 'cartItems.court.images', 'bookings', 'approver'])
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
        $transaction = CartTransaction::with(['cartItems.court', 'user'])->findOrFail($id);

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

        $transaction->update([
            'approval_status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'qr_code' => $qrData
        ]);

        // Also update all associated bookings to approved and add QR code
        $transaction->bookings()->update([
            'status' => 'approved',
            'qr_code' => $qrData
        ]);

        Log::info('Cart transaction approved', [
            'transaction_id' => $transaction->id,
            'approved_by' => $request->user()->id,
            'user_id' => $transaction->user_id
        ]);

        return response()->json([
            'message' => 'Transaction approved successfully',
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

        $transaction = CartTransaction::with(['cartItems.court', 'user'])->findOrFail($id);

        if ($transaction->approval_status === 'rejected') {
            return response()->json(['message' => 'Transaction already rejected'], 400);
        }

        $transaction->update([
            'approval_status' => 'rejected',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'rejection_reason' => $request->reason
        ]);

        // Also update all associated bookings to rejected
        $transaction->bookings()->update([
            'status' => 'rejected'
        ]);

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
        $transactions = CartTransaction::with(['user', 'cartItems.court.sport', 'cartItems.court.images'])
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

            $transaction = CartTransaction::with(['user', 'cartItems.court.sport', 'cartItems.court.images'])
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
                $transaction->update(['status' => 'checked_in']);
                
                // Also update associated bookings
                $transaction->bookings()->update(['status' => 'checked_in']);
            }

            Log::info('QR code verified successfully', [
                'transaction_id' => $transaction->id,
                'verified_by' => $request->user()->id
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
}