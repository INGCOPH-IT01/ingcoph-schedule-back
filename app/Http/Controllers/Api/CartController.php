<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\CartTransaction;
use App\Models\Court;
use App\Models\Booking;
use App\Models\BookingWaitlist;
use App\Events\BookingCreated;
use App\Helpers\BusinessHoursHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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

        // Get ALL pending cart transactions for the user
        $cartTransactions = CartTransaction::with(['cartItems' => function($query) {
                $query->where('status', 'pending');
            }, 'cartItems.court', 'cartItems.sport', 'cartItems.court.images', 'cartItems.bookingForUser', 'user'])
            ->where('user_id', $request->user()->id)
            ->where('status', 'pending')
            ->where('payment_status', 'unpaid')
            ->orderBy('created_at', 'asc')
            ->get();

        if ($cartTransactions->isEmpty()) {
            return response()->json([
                'cart_transaction' => null,
                'cart_items' => []
            ]);
        }

        // Collect all pending items from all transactions
        $allPendingItems = collect();
        foreach ($cartTransactions as $transaction) {
            $pendingItems = $transaction->cartItems->where('status', 'pending');
            $allPendingItems = $allPendingItems->merge($pendingItems);
        }

        // Return the first transaction as primary, but include all items
        return response()->json([
            'cart_transaction' => $cartTransactions->first(),
            'cart_items' => $allPendingItems->values()
        ]);
    }

    /**
     * Check and expire cart items based on business hours rules
     * Admin bookings are excluded from automatic expiration
     */
    private function checkAndExpireCartItems($userId)
    {
        try {
            // Find all pending cart transactions for this user
            // Load the user relationship to check if admin
            $pendingTransactions = CartTransaction::with('user')
                ->where('user_id', $userId)
                ->where('status', 'pending')
                ->where('payment_status', 'unpaid')
                ->get();

            foreach ($pendingTransactions as $transaction) {
                // Skip admin bookings - they should not expire automatically
                if ($transaction->user && $transaction->user->isAdmin()) {
                    Log::info("Skipped admin cart transaction #{$transaction->id} from expiration");
                    continue;
                }

                // Check if transaction has expired based on business hours
                $createdAt = \Carbon\Carbon::parse($transaction->created_at);

                if (!BusinessHoursHelper::isExpired($createdAt)) {
                    continue;
                }

                // Mark all pending cart items as expired
                CartItem::where('cart_transaction_id', $transaction->id)
                    ->where('status', 'pending')
                    ->update(['status' => 'expired']);

                // Mark the transaction as expired
                $transaction->update(['status' => 'expired']);

                Log::info("Auto-expired cart transaction #{$transaction->id} for user #{$userId} (business hours)");
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
        Log::info('CartController::store - Received request data:', $request->all());

        $validator = Validator::make($request->all(), [
            'items' => 'required|array',
            'items.*.court_id' => 'required|exists:courts,id',
            'items.*.sport_id' => 'required|exists:sports,id',
            'items.*.booking_date' => 'required|date',
            'items.*.start_time' => 'required|date_format:H:i',
            'items.*.end_time' => 'required|date_format:H:i',  // Removed after validation to allow midnight crossing
            'items.*.price' => 'required|numeric|min:0',
            'items.*.number_of_players' => 'nullable|integer|min:1|max:100'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Check if user role is 'user' and all booking dates are within current month only
        if ($request->user()->role === 'user') {
            $currentMonthStart = \Carbon\Carbon::now()->startOfMonth();
            $currentMonthEnd = \Carbon\Carbon::now()->endOfMonth();

            foreach ($request->items as $item) {
                $bookingDate = \Carbon\Carbon::parse($item['booking_date']);

                if ($bookingDate->lt($currentMonthStart) || $bookingDate->gt($currentMonthEnd)) {
                    return response()->json([
                        'message' => 'Regular users can only book time slots within the current month (' . $currentMonthStart->format('F Y') . ')',
                        'errors' => [
                            'booking_date' => ['All booking dates must be within the current month']
                        ]
                    ], 422);
                }
            }
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
                // Handle midnight crossing: if end_time < start_time, it means next day
                $startDateTime = $item['booking_date'] . ' ' . $item['start_time'];

                // Check if slot crosses midnight (end time is before or equal to start time)
                $startTime = \Carbon\Carbon::parse($item['start_time']);
                $endTime = \Carbon\Carbon::parse($item['end_time']);

                if ($endTime->lte($startTime)) {
                    // Slot crosses midnight, so end time is on the next day
                    $endDate = \Carbon\Carbon::parse($item['booking_date'])->addDay()->format('Y-m-d');
                    $endDateTime = $endDate . ' ' . $item['end_time'];
                } else {
                    $endDateTime = $item['booking_date'] . ' ' . $item['end_time'];
                }

                // Check for conflicting bookings
                $conflictingBooking = Booking::where('court_id', $item['court_id'])
                    ->whereDate('start_time', $item['booking_date'])
                    ->where(function ($query) use ($startDateTime, $endDateTime) {
                        $query->where(function ($q) use ($startDateTime, $endDateTime) {
                            // Existing booking starts during new booking (exclusive boundaries)
                            $q->where('start_time', '>=', $startDateTime)
                              ->where('start_time', '<', $endDateTime);
                        })->orWhere(function ($q) use ($startDateTime, $endDateTime) {
                            // Existing booking ends during new booking (exclusive boundaries)
                            $q->where('end_time', '>', $startDateTime)
                              ->where('end_time', '<=', $endDateTime);
                        })->orWhere(function ($q) use ($startDateTime, $endDateTime) {
                            // Existing booking completely contains new booking
                            $q->where('start_time', '<=', $startDateTime)
                              ->where('end_time', '>=', $endDateTime);
                        });
                    })
                    ->first();

                // Check for conflicting cart items (pending bookings via cart system)
                $conflictingCartItems = CartItem::where('court_id', $item['court_id'])
                    ->where('booking_date', $item['booking_date'])
                    ->where('status', 'pending')
                    ->where(function ($query) use ($item) {
                        $query->where(function ($q) use ($item) {
                            $q->where('start_time', '>=', $item['start_time'])
                              ->where('start_time', '<', $item['end_time']);
                        })->orWhere(function ($q) use ($item) {
                            $q->where('end_time', '>', $item['start_time'])
                              ->where('end_time', '<=', $item['end_time']);
                        })->orWhere(function ($q) use ($item) {
                            $q->where('start_time', '<=', $item['start_time'])
                              ->where('end_time', '>=', $item['end_time']);
                        });
                    })
                    ->with('cartTransaction.user')
                    ->get();

                // Determine if the conflict is with a pending approval user booking
                // Waitlist should only trigger when approval_status is 'pending' (not yet approved by admin)
                $isPendingApprovalBooking = false;
                $pendingCartTransactionId = null;

                if ($conflictingBooking &&
                    $conflictingBooking->status === 'pending' &&
                    $conflictingBooking->payment_status === 'unpaid') {
                    // Check if it's from a regular user (not admin)
                    $bookingUser = $conflictingBooking->user;
                    if ($bookingUser && $bookingUser->role === 'user') {
                        $isPendingApprovalBooking = true;
                    }
                }

                // Check cart items for pending approval bookings
                // The key check is: approval_status === 'pending' (not yet approved by admin)
                // Both paid and unpaid pending bookings trigger waitlist
                foreach ($conflictingCartItems as $cartItem) {
                    $cartTrans = $cartItem->cartTransaction;
                    if ($cartTrans &&
                        $cartTrans->approval_status === 'pending' &&  // Not yet approved by admin
                        $cartTrans->user &&
                        $cartTrans->user->role === 'user') {
                        $isPendingApprovalBooking = true;
                        $pendingCartTransactionId = $cartTrans->id;
                        break;
                    }
                }

                // If the current user is a regular user and there's a booking pending approval
                // Add them to waitlist instead of rejecting
                // Admins can bypass waitlist and book directly
                if ($request->user()->role === 'user' && $isPendingApprovalBooking) {
                    // Instead of adding to cart, add to waitlist
                    DB::rollBack();

                    Log::info('Adding user to waitlist', [
                        'user_id' => $userId,
                        'court_id' => $item['court_id'],
                        'start_time' => $startDateTime,
                        'end_time' => $endDateTime,
                        'pending_cart_transaction_id' => $pendingCartTransactionId
                    ]);

                    // Start new transaction for waitlist
                    DB::beginTransaction();

                    // Get the next position in waitlist
                    $nextPosition = BookingWaitlist::where('court_id', $item['court_id'])
                        ->where('start_time', $startDateTime)
                        ->where('end_time', $endDateTime)
                        ->where('status', BookingWaitlist::STATUS_PENDING)
                        ->count() + 1;

                    $waitlistEntry = BookingWaitlist::create([
                        'user_id' => $userId,
                        'pending_cart_transaction_id' => $pendingCartTransactionId,
                        'court_id' => $item['court_id'],
                        'sport_id' => $item['sport_id'],
                        'start_time' => $startDateTime,
                        'end_time' => $endDateTime,
                        'price' => $item['price'],
                        'number_of_players' => $item['number_of_players'] ?? 1,
                        'position' => $nextPosition,
                        'status' => BookingWaitlist::STATUS_PENDING
                    ]);

                    DB::commit();

                    Log::info('User added to waitlist successfully', [
                        'waitlist_id' => $waitlistEntry->id,
                        'position' => $nextPosition
                    ]);

                    return response()->json([
                        'message' => 'This time slot is currently pending approval for another user. You have been added to the waitlist.',
                        'waitlisted' => true,
                        'waitlist_entry' => $waitlistEntry->load(['court', 'sport']),
                        'position' => $nextPosition
                    ], 200);
                }

                // If the slot is taken by approved/paid bookings (not pending approval), reject
                // We only reject if there's a conflict with bookings that are already approved
                $hasApprovedConflict = false;

                if ($conflictingBooking && $conflictingBooking->status === 'approved') {
                    $hasApprovedConflict = true;
                }

                // Check if any conflicting cart items have been approved (and paid)
                foreach ($conflictingCartItems as $cartItem) {
                    $cartTrans = $cartItem->cartTransaction;
                    if ($cartTrans &&
                        $cartTrans->approval_status === 'approved' &&
                        $cartTrans->payment_status === 'paid') {
                        $hasApprovedConflict = true;
                        break;
                    }
                }

                if ($hasApprovedConflict) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'One or more time slots are no longer available'
                    ], 409);
                }

                // If we reach here, booking is allowed (either no conflict or admin bypassing waitlist)
                if ($isPendingApprovalBooking && $request->user()->role !== 'user') {
                    Log::info('Admin/Staff bypassing waitlist for pending slot', [
                        'user_id' => $userId,
                        'user_role' => $request->user()->role,
                        'court_id' => $item['court_id'],
                        'start_time' => $startDateTime,
                        'end_time' => $endDateTime
                    ]);
                }

                Log::info('Creating cart item with admin fields:', [
                    'booking_for_user_id' => $item['booking_for_user_id'] ?? null,
                    'booking_for_user_name' => $item['booking_for_user_name'] ?? null,
                    'admin_notes' => $item['admin_notes'] ?? null
                ]);

                $cartItem = CartItem::create([
                    'user_id' => $userId,
                    'cart_transaction_id' => $cartTransaction->id,
                    'court_id' => $item['court_id'],
                    'sport_id' => $item['sport_id'],
                    'booking_date' => $item['booking_date'],
                    'start_time' => $item['start_time'],
                    'end_time' => $item['end_time'],
                    'price' => $item['price'],
                    'number_of_players' => $item['number_of_players'] ?? 1,
                    'notes' => $item['notes'] ?? null,
                    'booking_for_user_id' => $item['booking_for_user_id'] ?? null,
                    'booking_for_user_name' => $item['booking_for_user_name'] ?? null,
                    'admin_notes' => $item['admin_notes'] ?? null
                ]);

                Log::info('Cart item created with ID: ' . $cartItem->id . ', admin fields: ' . json_encode([
                    'booking_for_user_id' => $cartItem->booking_for_user_id,
                    'booking_for_user_name' => $cartItem->booking_for_user_name,
                    'admin_notes' => $cartItem->admin_notes
                ]));

                $totalPrice += floatval($item['price']);
                $addedItems[] = $cartItem->load(['court', 'sport', 'court.images']);
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
     * Get expiration info for a cart transaction (supports business hours logic)
     */
    public function getExpirationInfo(Request $request)
    {
        $cartTransaction = CartTransaction::with('user')
            ->where('user_id', $request->user()->id)
            ->where('status', 'pending')
            ->where('payment_status', 'unpaid')
            ->first();

        if (!$cartTransaction) {
            return response()->json([
                'success' => true,
                'has_transaction' => false
            ]);
        }

        // Check if admin user
        if ($cartTransaction->user && $cartTransaction->user->isAdmin()) {
            return response()->json([
                'success' => true,
                'has_transaction' => true,
                'is_admin' => true,
                'expires_at' => null,
                'time_remaining_seconds' => null,
                'time_remaining_formatted' => 'No expiration (Admin)',
                'is_expired' => false
            ]);
        }

        $createdAt = \Carbon\Carbon::parse($cartTransaction->created_at);
        $expirationTime = BusinessHoursHelper::calculateExpirationTime($createdAt);
        $isExpired = BusinessHoursHelper::isExpired($createdAt);
        $timeRemainingSeconds = BusinessHoursHelper::getTimeRemainingSeconds($createdAt);
        $timeRemainingFormatted = BusinessHoursHelper::getTimeRemainingFormatted($createdAt);

        return response()->json([
            'success' => true,
            'has_transaction' => true,
            'is_admin' => false,
            'created_at' => $createdAt->toIso8601String(),
            'expires_at' => $expirationTime->toIso8601String(),
            'time_remaining_seconds' => $timeRemainingSeconds,
            'time_remaining_formatted' => $timeRemainingFormatted,
            'is_expired' => $isExpired
        ]);
    }

    /**
     * Checkout - Convert cart items to bookings
     */
    public function checkout(Request $request)
    {
            Log::info('Checkout called for user: ' . $request->user()->id);
            Log::info('Request data: ' . json_encode($request->all()));

        // Check if user is Admin or Staff
        $isAdminOrStaff = in_array($request->user()->role, ['admin', 'staff']);

        // Build validation rules - payment is optional for Admin/Staff
        $validationRules = [
            'payment_method' => $isAdminOrStaff ? 'nullable|in:pending,gcash,cash' : 'required|in:pending,gcash',
            'proof_of_payment' => $isAdminOrStaff ? 'nullable' : 'required_if:payment_method,gcash',
            'selected_items' => 'nullable|array',
            'selected_items.*' => 'integer|exists:cart_items,id',
            'skip_payment' => 'nullable|boolean' // New field for Admin/Staff to explicitly skip payment
        ];

        $validator = Validator::make($request->all(), $validationRules);

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

            // Process proof of payment - decode base64 and save as file
            $proofOfPaymentPath = null;
            if ($request->proof_of_payment) {
                try {
                    $base64String = $request->proof_of_payment;

                    // Remove data URL prefix if present (e.g., "data:image/jpeg;base64,")
                    if (preg_match('/^data:image\/(\w+);base64,/', $base64String, $type)) {
                        $base64String = substr($base64String, strpos($base64String, ',') + 1);
                        $imageType = strtolower($type[1]); // jpg, png, gif, etc.
                    } else {
                        $imageType = 'jpg';
                    }

                    // Decode base64 to binary image data
                    $imageData = base64_decode($base64String);

                    if ($imageData === false) {
                        Log::error('Failed to decode base64 proof of payment');
                    } else {
                        // Create filename with transaction ID and timestamp
                        $filename = 'proof_txn_' . $cartTransaction->id . '_' . time() . '.' . $imageType;

                        // Save to storage/app/public/proofs/
                        Storage::disk('public')->put('proofs/' . $filename, $imageData);

                        $proofOfPaymentPath = 'proofs/' . $filename;
                        Log::info('Proof of payment saved as file: ' . $proofOfPaymentPath);
                    }
                } catch (\Exception $e) {
                    Log::error('Failed to save proof of payment as file: ' . $e->getMessage());
                    // Continue without proof file - validation will handle missing proof
                }
            }

            // Determine payment status based on user role and payment method
            $paymentMethod = $request->payment_method ?? 'pending';
            $skipPayment = $request->skip_payment ?? false;

            // Admin/Staff can skip payment, marking slots as booked but unpaid
            $paymentStatus = 'unpaid';
            $paidAt = null;

            if ($paymentMethod === 'gcash' && $proofOfPaymentPath) {
                $paymentStatus = 'paid';
                $paidAt = now();
            } elseif ($isAdminOrStaff && $skipPayment) {
                // Admin/Staff explicitly skipped payment - keep as unpaid
                $paymentStatus = 'unpaid';
                Log::info('Admin/Staff skipped payment for transaction ' . $cartTransaction->id);
            }

            // Update the existing cart transaction with payment info
            $cartTransaction->update([
                'total_price' => $totalPrice,
                'status' => 'completed',
                'payment_method' => $paymentMethod,
                'payment_status' => $paymentStatus,
                'proof_of_payment' => $proofOfPaymentPath, // Now stores file path, not base64
                'paid_at' => $paidAt
            ]);

            Log::info('Cart transaction updated with ID: ' . $cartTransaction->id);

            // Create bookings from grouped items
            $createdBookings = [];
            foreach ($groupedBookings as $group) {
                // Handle midnight crossing when creating datetime strings
                $startDateTime = $group['booking_date'] . ' ' . $group['start_time'];

                $startTime = \Carbon\Carbon::parse($group['start_time']);
                $endTime = \Carbon\Carbon::parse($group['end_time']);

                if ($endTime->lte($startTime)) {
                    // Slot crosses midnight
                    $endDate = \Carbon\Carbon::parse($group['booking_date'])->addDay()->format('Y-m-d');
                    $endDateTime = $endDate . ' ' . $group['end_time'];
                } else {
                    $endDateTime = $group['booking_date'] . ' ' . $group['end_time'];
                }

                // Final availability check
                $isBooked = Booking::where('court_id', $group['court_id'])
                    ->where(function ($query) use ($startDateTime, $endDateTime) {

                        $query->where(function ($q) use ($startDateTime, $endDateTime) {
                            // Existing booking starts during new booking (exclusive boundaries)
                            $q->where('start_time', '>=', $startDateTime)
                              ->where('start_time', '<', $endDateTime);
                        })->orWhere(function ($q) use ($startDateTime, $endDateTime) {
                            // Existing booking ends during new booking (exclusive boundaries)
                            $q->where('end_time', '>', $startDateTime)
                              ->where('end_time', '<=', $endDateTime);
                        })->orWhere(function ($q) use ($startDateTime, $endDateTime) {
                            // Existing booking completely contains new booking
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

                // Get the first cart item from this group to extract admin booking fields
                $firstCartItem = CartItem::whereIn('id', $group['items'])->first();

                $booking = Booking::create([
                    'user_id' => $userId,
                    'cart_transaction_id' => $cartTransaction->id,
                    'court_id' => $group['court_id'],
                    'sport_id' => $firstCartItem->sport_id,
                    'start_time' => $startDateTime,  // Use adjusted datetime that handles midnight crossing
                    'end_time' => $endDateTime,  // Use adjusted datetime that handles midnight crossing
                    'total_price' => $group['price'],
                    'number_of_players' => $firstCartItem->number_of_players ?? 1,
                    'status' => 'pending',
                    'notes' => $firstCartItem->notes,
                    'payment_method' => $paymentMethod,
                    'payment_status' => $paymentStatus,
                    'proof_of_payment' => $proofOfPaymentPath, // Use the saved file path
                    'paid_at' => $paidAt,
                    'booking_for_user_id' => $firstCartItem->booking_for_user_id,
                    'booking_for_user_name' => $firstCartItem->booking_for_user_name,
                    'admin_notes' => $firstCartItem->admin_notes,
                ]);

                Log::info('Booking created with ID: ' . $booking->id);

                $createdBookings[] = $booking->load(['user', 'court', 'sport', 'court.images', 'cartTransaction']);

                // Broadcast booking created event in real-time
                broadcast(new BookingCreated($booking))->toOthers();
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

    /**
     * Get available courts for a specific cart item time slot
     */
    public function getAvailableCourts(Request $request, $id)
    {
        $cartItem = CartItem::find($id);

        if (!$cartItem) {
            return response()->json([
                'success' => false,
                'message' => 'Cart item not found'
            ], 404);
        }

        // Get all active courts
        $courts = Court::where('is_active', true)->get();

        // Prepare datetime strings for conflict checking
        $bookingDate = \Carbon\Carbon::parse($cartItem->booking_date)->format('Y-m-d');
        $startDateTime = $bookingDate . ' ' . $cartItem->start_time;
        $endDateTime = $bookingDate . ' ' . $cartItem->end_time;

        // Handle midnight crossing
        $startTime = \Carbon\Carbon::parse($startDateTime);
        $endTime = \Carbon\Carbon::parse($endDateTime);
        if ($endTime->lte($startTime)) {
            $endDateTime = \Carbon\Carbon::parse($bookingDate)->addDay()->format('Y-m-d') . ' ' . $cartItem->end_time;
        }

        $availableCourts = [];

        foreach ($courts as $court) {
            // The current court (where the cart item is currently assigned) is always available
            if ($court->id == $cartItem->court_id) {
                $availableCourts[] = [
                    'id' => $court->id,
                    'name' => $court->name,
                    'surface_type' => $court->surface_type,
                    'is_available' => true,
                    'is_current' => true
                ];
                continue;
            }

            $isAvailable = true;

            // Check for conflicts with existing bookings
            $conflictingBooking = Booking::where('court_id', $court->id)
                ->whereIn('status', ['pending', 'approved', 'completed'])
                ->where(function ($query) use ($startDateTime, $endDateTime) {
                    $query->where(function ($q) use ($startDateTime, $endDateTime) {
                        $q->where('start_time', '>=', $startDateTime)
                          ->where('start_time', '<', $endDateTime);
                    })->orWhere(function ($q) use ($startDateTime, $endDateTime) {
                        $q->where('end_time', '>', $startDateTime)
                          ->where('end_time', '<=', $endDateTime);
                    })->orWhere(function ($q) use ($startDateTime, $endDateTime) {
                        $q->where('start_time', '<=', $startDateTime)
                          ->where('end_time', '>=', $endDateTime);
                    });
                })
                ->exists();

            if ($conflictingBooking) {
                $isAvailable = false;
            }

            // Check for conflicts with other cart items
            if ($isAvailable) {
                $conflictingCartItem = CartItem::where('court_id', $court->id)
                    ->where('id', '!=', $id)
                    ->where('booking_date', $bookingDate)
                    ->where('status', '!=', 'cancelled')
                    ->whereHas('cartTransaction', function($query) {
                        $query->whereIn('approval_status', ['pending', 'approved'])
                              ->whereIn('payment_status', ['unpaid', 'paid']);
                    })
                    ->where(function ($query) use ($cartItem) {
                        $query->where(function ($q) use ($cartItem) {
                            $q->where('start_time', '>=', $cartItem->start_time)
                              ->where('start_time', '<', $cartItem->end_time);
                        })->orWhere(function ($q) use ($cartItem) {
                            $q->where('end_time', '>', $cartItem->start_time)
                              ->where('end_time', '<=', $cartItem->end_time);
                        })->orWhere(function ($q) use ($cartItem) {
                            $q->where('start_time', '<=', $cartItem->start_time)
                              ->where('end_time', '>=', $cartItem->end_time);
                        });
                    })
                    ->exists();

                if ($conflictingCartItem) {
                    $isAvailable = false;
                }
            }

            $availableCourts[] = [
                'id' => $court->id,
                'name' => $court->name,
                'surface_type' => $court->surface_type,
                'is_available' => $isAvailable,
                'is_current' => false
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $availableCourts
        ]);
    }

    /**
     * Update a cart item (admin only - for updating court)
     */
    public function updateCartItem(Request $request, $id)
    {
        // Only admins can update cart items
        if (!$request->user()->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Admin privileges required.'
            ], 403);
        }

        $cartItem = CartItem::find($id);

        if (!$cartItem) {
            return response()->json([
                'success' => false,
                'message' => 'Cart item not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'court_id' => 'required|exists:courts,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        // Get the new court
        $newCourt = Court::find($request->court_id);

        if (!$newCourt || !$newCourt->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Selected court is not available'
            ], 400);
        }

        // Create full datetime for conflict checking
        // Ensure we only have the date part (booking_date might be a full datetime or just a date)
        $bookingDate = \Carbon\Carbon::parse($cartItem->booking_date)->format('Y-m-d');
        $startDateTime = $bookingDate . ' ' . $cartItem->start_time;
        $endDateTime = $bookingDate . ' ' . $cartItem->end_time;

        // Handle midnight crossing
        $startTime = \Carbon\Carbon::parse($startDateTime);
        $endTime = \Carbon\Carbon::parse($endDateTime);
        if ($endTime->lte($startTime)) {
            $endDateTime = \Carbon\Carbon::parse($bookingDate)->addDay()->format('Y-m-d') . ' ' . $cartItem->end_time;
        }

        // Check for conflicts on the new court
        $conflictingBooking = Booking::where('court_id', $request->court_id)
            ->whereIn('status', ['pending', 'approved', 'completed'])
            ->where(function ($query) use ($startDateTime, $endDateTime) {
                $query->where(function ($q) use ($startDateTime, $endDateTime) {
                    // Existing booking starts during new booking
                    $q->where('start_time', '>=', $startDateTime)
                      ->where('start_time', '<', $endDateTime);
                })->orWhere(function ($q) use ($startDateTime, $endDateTime) {
                    // Existing booking ends during new booking
                    $q->where('end_time', '>', $startDateTime)
                      ->where('end_time', '<=', $endDateTime);
                })->orWhere(function ($q) use ($startDateTime, $endDateTime) {
                    // Existing booking completely contains new booking
                    $q->where('start_time', '<=', $startDateTime)
                      ->where('end_time', '>=', $endDateTime);
                });
            })
            ->first();

        if ($conflictingBooking) {
            return response()->json([
                'success' => false,
                'message' => 'Time slot conflicts with existing booking on the selected court'
            ], 409);
        }

        // Check for conflicts with other cart items on the new court
        $conflictingCartItem = CartItem::where('court_id', $request->court_id)
            ->where('id', '!=', $id)
            ->where('booking_date', $bookingDate)
            ->where('status', '!=', 'cancelled')
            ->whereHas('cartTransaction', function($query) {
                $query->whereIn('approval_status', ['pending', 'approved'])
                      ->whereIn('payment_status', ['unpaid', 'paid']);
            })
            ->where(function ($query) use ($cartItem) {
                $query->where(function ($q) use ($cartItem) {
                    $q->where('start_time', '>=', $cartItem->start_time)
                      ->where('start_time', '<', $cartItem->end_time);
                })->orWhere(function ($q) use ($cartItem) {
                    $q->where('end_time', '>', $cartItem->start_time)
                      ->where('end_time', '<=', $cartItem->end_time);
                })->orWhere(function ($q) use ($cartItem) {
                    $q->where('start_time', '<=', $cartItem->start_time)
                      ->where('end_time', '>=', $cartItem->end_time);
                });
            })
            ->first();

        if ($conflictingCartItem) {
            return response()->json([
                'success' => false,
                'message' => 'Time slot conflicts with another pending booking on the selected court'
            ], 409);
        }

        // Update the cart item
        $cartItem->update([
            'court_id' => $request->court_id
        ]);

        // Also update any related booking records with the same cart_transaction_id and time slot
        // This ensures availability checks show the correct status
        if ($cartItem->cart_transaction_id) {
            Booking::where('cart_transaction_id', $cartItem->cart_transaction_id)
                ->where('start_time', $bookingDate . ' ' . $cartItem->start_time)
                ->where('end_time', $endDateTime)
                ->update([
                    'court_id' => $request->court_id
                ]);
        }

        // Refresh the cart item from database to ensure we have the latest data
        $cartItem->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Cart item updated successfully',
            'data' => $cartItem->load(['court', 'sport', 'court.images'])
        ]);
    }
}