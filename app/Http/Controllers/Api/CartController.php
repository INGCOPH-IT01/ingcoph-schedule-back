<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\CartTransaction;
use App\Models\Court;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class CartController extends Controller
{
    /**
     * Get active cart transaction with pending items for the authenticated user
     */
    public function index(Request $request)
    {
        // First, check and expire any cart items older than 1 hour
        $this->checkAndExpireCartItems($request->user()->id);

        $cartTransaction = CartTransaction::with(['cartItems' => function($query) {
                $query->where('status', 'pending');
            }, 'cartItems.court.sport', 'cartItems.court.images', 'user'])
            ->where('user_id', $request->user()->id)
            ->where('status', 'pending')
            ->where('payment_status', 'unpaid')
            ->first();

        if (!$cartTransaction) {
            return response()->json([
                'cart_transaction' => null,
                'cart_items' => []
            ]);
        }

        // Filter to only pending items
        $pendingItems = $cartTransaction->cartItems->where('status', 'pending')->values();

        return response()->json([
            'cart_transaction' => $cartTransaction,
            'cart_items' => $pendingItems
        ]);
    }

    /**
     * Check and expire cart items that have been pending for more than 1 hour
     */
    private function checkAndExpireCartItems($userId)
    {
        try {
            $oneHourAgo = \Carbon\Carbon::now()->subHour();
            
            // Find all pending cart transactions for this user that are older than 1 hour
            $expiredTransactions = CartTransaction::where('user_id', $userId)
                ->where('status', 'pending')
                ->where('payment_status', 'unpaid')
                ->where('created_at', '<', $oneHourAgo)
                ->get();
            
            foreach ($expiredTransactions as $transaction) {
                // Mark all pending cart items as expired
                CartItem::where('cart_transaction_id', $transaction->id)
                    ->where('status', 'pending')
                    ->update(['status' => 'expired']);
                
                // Mark the transaction as expired
                $transaction->update(['status' => 'expired']);
                
                Log::info("Auto-expired cart transaction #{$transaction->id} for user #{$userId}");
            }
        } catch (\Exception $e) {
            Log::error("Failed to auto-expire cart items for user #{$userId}: " . $e->getMessage());
        }
    }

    /**
     * Add item(s) to cart
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'items' => 'required|array',
            'items.*.court_id' => 'required|exists:courts,id',
            'items.*.booking_date' => 'required|date',
            'items.*.start_time' => 'required|date_format:H:i',
            'items.*.end_time' => 'required|date_format:H:i|after:items.*.start_time',
            'items.*.price' => 'required|numeric|min:0'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $addedItems = [];
            $userId = $request->user()->id;
            $totalPrice = 0;

            // Always create a new cart transaction for each booking
            $cartTransaction = CartTransaction::create([
                'user_id' => $userId,
                'total_price' => 0,
                'status' => 'pending',
                'approval_status' => 'pending',
                'payment_method' => 'pending',
                'payment_status' => 'unpaid'
            ]);
            
            Log::info('Created new cart transaction: ' . $cartTransaction->id);

            foreach ($request->items as $item) {
                // Check if item already exists in cart
                $existingItem = CartItem::where('user_id', $userId)
                    ->where('cart_transaction_id', $cartTransaction->id)
                    ->where('court_id', $item['court_id'])
                    ->where('booking_date', $item['booking_date'])
                    ->where('start_time', $item['start_time'])
                    ->where('end_time', $item['end_time'])
                    ->first();

                if ($existingItem) {
                    continue; // Skip duplicates
                }

                // Check if time slot is still available
                $isBooked = Booking::where('court_id', $item['court_id'])
                    ->whereDate('start_time', $item['booking_date'])
                    ->where(function ($query) use ($item) {
                        $query->whereBetween('start_time', [
                            $item['booking_date'] . ' ' . $item['start_time'],
                            $item['booking_date'] . ' ' . $item['end_time']
                        ])->orWhereBetween('end_time', [
                            $item['booking_date'] . ' ' . $item['start_time'],
                            $item['booking_date'] . ' ' . $item['end_time']
                        ]);
                    })
                    ->exists();

                if ($isBooked) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'One or more time slots are no longer available'
                    ], 409);
                }

                $cartItem = CartItem::create([
                    'user_id' => $userId,
                    'cart_transaction_id' => $cartTransaction->id,
                    'court_id' => $item['court_id'],
                    'booking_date' => $item['booking_date'],
                    'start_time' => $item['start_time'],
                    'end_time' => $item['end_time'],
                    'price' => $item['price']
                ]);

                $totalPrice += floatval($item['price']);
                $addedItems[] = $cartItem->load(['court.sport', 'court.images']);
            }

            // Update cart transaction total price
            $cartTransaction->update([
                'total_price' => $cartTransaction->total_price + $totalPrice
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Items added to cart successfully',
                'items' => $addedItems,
                'cart_transaction' => $cartTransaction->load(['cartItems', 'user'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to add items to cart',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove item from cart (mark as cancelled) and update transaction total
     */
    public function destroy(Request $request, $id)
    {
        try {
            DB::beginTransaction();

            $cartItem = CartItem::where('user_id', $request->user()->id)
                ->where('id', $id)
                ->where('status', 'pending')
                ->first();

            if (!$cartItem) {
                return response()->json([
                    'message' => 'Cart item not found'
                ], 404);
            }

            $price = $cartItem->price;
            $transactionId = $cartItem->cart_transaction_id;

            // Mark item as cancelled instead of deleting
            $cartItem->update(['status' => 'cancelled']);

            // Update cart transaction total price
            if ($transactionId) {
                $cartTransaction = CartTransaction::find($transactionId);
                if ($cartTransaction) {
                    // Recalculate total from pending items only
                    $newTotal = $cartTransaction->cartItems()->where('status', 'pending')->sum('price');
                    $cartTransaction->update([
                        'total_price' => max(0, $newTotal)
                    ]);

                    // Mark transaction as cancelled if no pending items left
                    if ($cartTransaction->cartItems()->where('status', 'pending')->count() === 0) {
                        $cartTransaction->update(['status' => 'cancelled']);
                    }
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Item removed from cart successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to remove item',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Clear all pending cart items (mark as cancelled)
     */
    public function clear(Request $request)
    {
        try {
            DB::beginTransaction();

            // Find pending cart transaction
            $cartTransaction = CartTransaction::where('user_id', $request->user()->id)
                ->where('status', 'pending')
                ->where('payment_status', 'unpaid')
                ->first();

            if ($cartTransaction) {
                // Mark all pending cart items as cancelled
                $cartTransaction->cartItems()->where('status', 'pending')->update(['status' => 'cancelled']);
                
                // Mark the transaction as cancelled
                $cartTransaction->update(['status' => 'cancelled']);
            }

            DB::commit();

            return response()->json([
                'message' => 'Cart cleared successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to clear cart',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get cart count (pending items in pending transaction)
     */
    public function count(Request $request)
    {
        $cartTransaction = CartTransaction::where('user_id', $request->user()->id)
            ->where('status', 'pending')
            ->where('payment_status', 'unpaid')
            ->first();

        $count = $cartTransaction ? $cartTransaction->cartItems()->where('status', 'pending')->count() : 0;

        return response()->json([
            'count' => $count
        ]);
    }

    /**
     * Checkout - Convert cart items to bookings
     */
    public function checkout(Request $request)
    {
            Log::info('Checkout called for user: ' . $request->user()->id);
            Log::info('Request data: ' . json_encode($request->all()));

        $validator = Validator::make($request->all(), [
            'payment_method' => 'required|in:pending,gcash',
            'gcash_reference' => 'required_if:payment_method,gcash',
            'proof_of_payment' => 'required_if:payment_method,gcash',
            'selected_items' => 'nullable|array',
            'selected_items.*' => 'integer|exists:cart_items,id'
        ]);

        if ($validator->fails()) {
            Log::error('Validation failed: ' . json_encode($validator->errors()));
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $userId = $request->user()->id;
            
            // Find the pending cart transaction
            $cartTransaction = CartTransaction::where('user_id', $userId)
                ->where('status', 'pending')
                ->where('payment_status', 'unpaid')
                ->first();

            if (!$cartTransaction) {
                Log::warning('No pending cart transaction for user: ' . $userId);
                return response()->json([
                    'message' => 'No pending cart found'
                ], 400);
            }

            // Get cart items - either selected ones or all items in transaction
            $cartItemsQuery = CartItem::with('court')
                ->where('cart_transaction_id', $cartTransaction->id);
            
            if ($request->has('selected_items') && !empty($request->selected_items)) {
                $cartItemsQuery->whereIn('id', $request->selected_items);
                Log::info('Checking out selected items: ' . json_encode($request->selected_items));
            } else {
                Log::info('Checking out all cart items in transaction');
            }
            
            $cartItems = $cartItemsQuery
                ->orderBy('court_id')
                ->orderBy('booking_date')
                ->orderBy('start_time')
                ->get();

            Log::info('Found ' . $cartItems->count() . ' cart items to checkout');

            if ($cartItems->isEmpty()) {
                Log::warning('No items to checkout for user: ' . $userId);
                return response()->json([
                    'message' => 'No items selected for checkout'
                ], 400);
            }

            // Group consecutive time slots for the same court and date
            $groupedBookings = [];
            $currentGroup = null;

            foreach ($cartItems as $item) {
                // Handle Carbon date object
                $bookingDate = $item->booking_date instanceof \Carbon\Carbon 
                    ? $item->booking_date->format('Y-m-d') 
                    : $item->booking_date;
                    
                $groupKey = $item->court_id . '_' . $bookingDate;

                if (!$currentGroup || $currentGroup['key'] !== $groupKey || 
                    $currentGroup['end_time'] !== $item->start_time) {
                    // Start new group
                    if ($currentGroup) {
                        $groupedBookings[] = $currentGroup;
                    }
                    $currentGroup = [
                        'key' => $groupKey,
                        'court_id' => $item->court_id,
                        'booking_date' => $bookingDate,
                        'start_time' => $item->start_time,
                        'end_time' => $item->end_time,
                        'price' => $item->price,
                        'items' => [$item->id]
                    ];
                } else {
                    // Extend current group
                    $currentGroup['end_time'] = $item->end_time;
                    $currentGroup['price'] += $item->price;
                    $currentGroup['items'][] = $item->id;
                }
            }

            // Add last group
            if ($currentGroup) {
                $groupedBookings[] = $currentGroup;
            }

            // Calculate total price for the selected items
            $totalPrice = array_sum(array_column($groupedBookings, 'price'));

            // Update the existing cart transaction with payment info
            $cartTransaction->update([
                'total_price' => $totalPrice,
                'status' => 'completed',
                'payment_method' => $request->payment_method,
                'payment_status' => $request->payment_method === 'gcash' ? 'paid' : 'unpaid',
                'gcash_reference' => $request->gcash_reference,
                'proof_of_payment' => $request->proof_of_payment,
                'paid_at' => $request->payment_method === 'gcash' ? now() : null
            ]);

            Log::info('Cart transaction updated with ID: ' . $cartTransaction->id);

            // Create bookings from grouped items
            $createdBookings = [];
            foreach ($groupedBookings as $group) {
                // Final availability check
                $isBooked = Booking::where('court_id', $group['court_id'])
                    ->where(function ($query) use ($group) {
                        $startDateTime = $group['booking_date'] . ' ' . $group['start_time'];
                        $endDateTime = $group['booking_date'] . ' ' . $group['end_time'];
                        
                        $query->whereBetween('start_time', [$startDateTime, $endDateTime])
                            ->orWhereBetween('end_time', [$startDateTime, $endDateTime])
                            ->orWhere(function ($q) use ($startDateTime, $endDateTime) {
                                $q->where('start_time', '<=', $startDateTime)
                                  ->where('end_time', '>=', $endDateTime);
                            });
                    })
                    ->exists();

                if ($isBooked) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'One or more time slots are no longer available. Please refresh your cart.'
                    ], 409);
                }

                Log::info('Creating booking for group: ' . json_encode($group));

                $booking = Booking::create([
                    'user_id' => $userId,
                    'cart_transaction_id' => $cartTransaction->id,
                    'court_id' => $group['court_id'],
                    'start_time' => $group['booking_date'] . ' ' . $group['start_time'],
                    'end_time' => $group['booking_date'] . ' ' . $group['end_time'],
                    'total_price' => $group['price'],
                    'status' => 'pending',
                    'payment_method' => $request->payment_method,
                    'payment_status' => $request->payment_method === 'gcash' ? 'paid' : 'unpaid',
                    'gcash_reference' => $request->gcash_reference,
                    'proof_of_payment' => $request->proof_of_payment,
                    'paid_at' => $request->payment_method === 'gcash' ? now() : null
                ]);

                Log::info('Booking created with ID: ' . $booking->id);

                $createdBookings[] = $booking->load(['user', 'court.sport', 'court.images', 'cartTransaction']);
            }

            // Mark items as completed instead of deleting them
            if ($request->has('selected_items') && !empty($request->selected_items)) {
                // Mark only selected items as completed
                CartItem::whereIn('id', $request->selected_items)->update(['status' => 'completed']);
                Log::info('Marked selected cart items as completed');
                
                // Check if there are remaining pending items in the original transaction
                $remainingItems = CartItem::where('cart_transaction_id', $cartTransaction->id)
                    ->where('status', 'pending')
                    ->count();
                    
                if ($remainingItems > 0) {
                    // Create a new pending transaction for remaining items
                    $remainingTotal = CartItem::where('cart_transaction_id', $cartTransaction->id)
                        ->where('status', 'pending')
                        ->sum('price');
                        
                    $newTransaction = CartTransaction::create([
                        'user_id' => $userId,
                        'total_price' => $remainingTotal,
                        'status' => 'pending',
                        'approval_status' => 'pending',
                        'payment_method' => 'pending',
                        'payment_status' => 'unpaid'
                    ]);
                    
                    // Move remaining pending items to new transaction
                    CartItem::where('cart_transaction_id', $cartTransaction->id)
                        ->where('status', 'pending')
                        ->update(['cart_transaction_id' => $newTransaction->id]);
                    
                    Log::info('Created new pending transaction for remaining items: ' . $newTransaction->id);
                }
            } else {
                // Full checkout - mark all items as completed
                CartItem::where('cart_transaction_id', $cartTransaction->id)->update(['status' => 'completed']);
                Log::info('Marked all cart items as completed');
            }

            Log::info('Created ' . count($createdBookings) . ' bookings total');

            DB::commit();
            Log::info('Transaction committed successfully');

            return response()->json([
                'message' => 'Checkout successful',
                'transaction' => $cartTransaction->load(['cartItems.court', 'bookings']),
                'bookings' => $createdBookings
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Checkout failed: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return response()->json([
                'message' => 'Checkout failed',
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null
            ], 500);
        }
    }
}