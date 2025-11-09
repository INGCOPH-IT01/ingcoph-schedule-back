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

        // Get ALL pending cart transactions for the user (exclude rejected transactions)
        $cartTransactions = CartTransaction::with(['cartItems' => function($query) {
                $query->where('status', 'pending');
            }, 'cartItems.court', 'cartItems.sport', 'cartItems.court.images', 'cartItems.bookingForUser', 'user'])
            ->where('user_id', $request->user()->id)
            ->where('status', 'pending')
            ->where('payment_status', 'unpaid')
            ->whereIn('approval_status', ['pending', 'approved'])
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
                // Use universal helper to check if transaction should expire
                if (!BusinessHoursHelper::shouldExpire($transaction)) {
                    continue;
                }

                // Mark all pending cart items as expired
                CartItem::where('cart_transaction_id', $transaction->id)
                    ->where('status', 'pending')
                    ->update(['status' => 'expired']);

                // Mark the transaction as expired
                $transaction->update(['status' => 'expired']);
            }
        } catch (\Exception $e) {
            // Continue silently
        }
    }

    /**
     * Add item(s) to cart
     */
    public function store(Request $request)
    {
        // Company setting: block regular users from creating bookings via cart if disabled
        if ($request->user()->role === 'user') {
            $userBookingEnabled = \App\Models\CompanySetting::get('user_booking_enabled', '1') === '1';
            if (!$userBookingEnabled) {
                return response()->json([
                    'message' => 'Booking creation is currently disabled for user accounts. Please contact the administrator.'
                ], 403);
            }
        }

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

            // Removed debug timezone context logs

            // Always create a new cart transaction for each booking
            $cartTransaction = CartTransaction::create([
                'user_id' => $userId,
                'total_price' => 0,
                'status' => 'pending',
                'approval_status' => 'pending',
                'payment_method' => 'pending',
                'payment_status' => 'unpaid'
            ]);

            // Track waitlisted items separately (Issue #11 fix)
            $waitlistedItems = [];
            $hasAnyWaitlist = false;

            foreach ($request->items as $item) {
                // Removed incoming item debug logs
                // Ensure booking_date is in Y-m-d format only (strip any time component)
                $bookingDate = \Carbon\Carbon::parse($item['booking_date'])->format('Y-m-d');

                // Check if item already exists in cart
                $existingItem = CartItem::where('user_id', $userId)
                    ->where('cart_transaction_id', $cartTransaction->id)
                    ->where('court_id', $item['court_id'])
                    ->where('booking_date', $bookingDate)
                    ->where('start_time', $item['start_time'])
                    ->where('end_time', $item['end_time'])
                    ->first();

                if ($existingItem) {
                    continue; // Skip duplicates
                }

                // Check if time slot is still available
                // Handle midnight crossing: if end_time < start_time, it means next day (Issue #12 fix)
                $startDateTime = $bookingDate . ' ' . $item['start_time'];

                // Check if slot crosses midnight (end time is before or equal to start time)
                $startTime = \Carbon\Carbon::parse($item['start_time']);
                $endTime = \Carbon\Carbon::parse($item['end_time']);
                $crossesMidnight = $endTime->lte($startTime);

                if ($crossesMidnight) {
                    // Slot crosses midnight, so end time is on the next day
                    $endDate = \Carbon\Carbon::parse($bookingDate)->addDay()->format('Y-m-d');
                    $endDateTime = $endDate . ' ' . $item['end_time'];
                } else {
                    $endDateTime = $bookingDate . ' ' . $item['end_time'];
                }

                // Removed computed datetime debug logs

                // FIX #12: Check for conflicting bookings with proper midnight crossing support
                // We need to check bookings on both the start date and potentially the next day
                $conflictingBooking = Booking::where('court_id', $item['court_id'])
                    ->whereIn('status', ['pending', 'approved', 'completed', 'checked_in'])
                    ->where(function ($query) use ($startDateTime, $endDateTime, $bookingDate, $crossesMidnight) {
                        // Check bookings that start on the same day as the requested slot
                        $query->where(function ($q) use ($startDateTime, $endDateTime, $bookingDate) {
                            $q->whereDate('start_time', $bookingDate)
                              ->where(function ($sq) use ($startDateTime, $endDateTime) {
                                  $sq->where(function ($innerQ) use ($startDateTime, $endDateTime) {
                                      // Existing booking starts during new booking (exclusive boundaries)
                                      $innerQ->where('start_time', '>=', $startDateTime)
                                             ->where('start_time', '<', $endDateTime);
                                  })->orWhere(function ($innerQ) use ($startDateTime, $endDateTime) {
                                      // Existing booking ends during new booking (exclusive boundaries)
                                      $innerQ->where('end_time', '>', $startDateTime)
                                             ->where('end_time', '<=', $endDateTime);
                                  })->orWhere(function ($innerQ) use ($startDateTime, $endDateTime) {
                                      // Existing booking completely contains new booking
                                      $innerQ->where('start_time', '<=', $startDateTime)
                                             ->where('end_time', '>=', $endDateTime);
                                  });
                              });
                        });

                        // If the new booking crosses midnight, also check previous day bookings
                        if ($crossesMidnight) {
                            $prevDate = \Carbon\Carbon::parse($bookingDate)->subDay()->format('Y-m-d');
                            $query->orWhere(function ($q) use ($startDateTime, $endDateTime, $prevDate) {
                                $q->whereDate('start_time', $prevDate)
                                  ->where(function ($sq) use ($startDateTime, $endDateTime) {
                                      $sq->where(function ($innerQ) use ($startDateTime, $endDateTime) {
                                          $innerQ->where('start_time', '>=', $startDateTime)
                                                 ->where('start_time', '<', $endDateTime);
                                      })->orWhere(function ($innerQ) use ($startDateTime, $endDateTime) {
                                          $innerQ->where('end_time', '>', $startDateTime)
                                                 ->where('end_time', '<=', $endDateTime);
                                      })->orWhere(function ($innerQ) use ($startDateTime, $endDateTime) {
                                          $innerQ->where('start_time', '<=', $startDateTime)
                                                 ->where('end_time', '>=', $endDateTime);
                                      });
                                  });
                            });
                        }
                    })
                    ->first();

                // FIX #12: Check for conflicting cart items with proper midnight crossing support
                // IMPORTANT: Exclude items from the current transaction being built in this request
                $conflictingCartItems = CartItem::where('court_id', $item['court_id'])
                    ->where('status', 'pending')
                    ->where('cart_transaction_id', '!=', $cartTransaction->id) // Exclude items from current transaction
                    ->where(function ($query) use ($bookingDate, $startDateTime, $endDateTime, $crossesMidnight) {
                        // Check cart items on the same date
                        $query->where(function ($q) use ($bookingDate, $startDateTime, $endDateTime) {
                            $q->where('booking_date', $bookingDate)
                              ->where(function ($sq) use ($startDateTime, $endDateTime) {
                                  // Use full datetime comparison for accuracy
                                  $sq->whereRaw("CONCAT(booking_date, ' ', start_time) >= ? AND CONCAT(booking_date, ' ', start_time) < ?",
                                      [$startDateTime, $endDateTime])
                                     ->orWhereRaw("CONCAT(booking_date, ' ', end_time) > ? AND CONCAT(booking_date, ' ', end_time) <= ?",
                                      [$startDateTime, $endDateTime])
                                     ->orWhereRaw("CONCAT(booking_date, ' ', start_time) <= ? AND CONCAT(booking_date, ' ', end_time) >= ?",
                                      [$startDateTime, $endDateTime]);
                              });
                        });

                        // If the new booking crosses midnight, also check previous day cart items
                        if ($crossesMidnight) {
                            $prevDate = \Carbon\Carbon::parse($bookingDate)->subDay()->format('Y-m-d');
                            $query->orWhere(function ($q) use ($prevDate, $startDateTime, $endDateTime) {
                                $q->where('booking_date', $prevDate)
                                  ->where(function ($sq) use ($startDateTime, $endDateTime) {
                                      $sq->whereRaw("CONCAT(booking_date, ' ', start_time) >= ? AND CONCAT(booking_date, ' ', start_time) < ?",
                                          [$startDateTime, $endDateTime])
                                         ->orWhereRaw("CONCAT(booking_date, ' ', end_time) > ? AND CONCAT(booking_date, ' ', end_time) <= ?",
                                          [$startDateTime, $endDateTime])
                                         ->orWhereRaw("CONCAT(booking_date, ' ', start_time) <= ? AND CONCAT(booking_date, ' ', end_time) >= ?",
                                          [$startDateTime, $endDateTime]);
                                  });
                            });
                        }
                    })
                    ->with('cartTransaction.user')
                    ->get();

                // Determine if the conflict is with a pending approval booking
                // ALL users (including admin/staff) must go through waitlist to ensure fairness
                $isPendingApprovalBooking = false;
                $pendingCartTransactionId = null;
                $pendingBookingId = null;

                // Track the parent booking's actual times (for waitlist)
                $parentStartTime = null;
                $parentEndTime = null;

                // Check for pending bookings - all users must be waitlisted
                if ($conflictingBooking &&
                    $conflictingBooking->status === 'pending') {
                    // All users get waitlisted when there's a pending booking
                    $isPendingApprovalBooking = true;
                    $pendingBookingId = $conflictingBooking->id;
                    $pendingCartTransactionId = $conflictingBooking->cart_transaction_id;
                    // Use the parent booking's actual times
                    $parentStartTime = $conflictingBooking->start_time;
                    $parentEndTime = $conflictingBooking->end_time;
                }

                // Check cart items for pending approval bookings
                // All users get waitlisted for any pending transaction
                foreach ($conflictingCartItems as $cartItem) {
                    $cartTrans = $cartItem->cartTransaction;
                    if ($cartTrans &&
                        in_array($cartTrans->approval_status, ['pending', 'pending_waitlist'])) {
                        // All users with pending transaction triggers waitlist
                        $isPendingApprovalBooking = true;
                        $pendingCartTransactionId = $cartTrans->id;

                        // Use the cart item's times to construct parent datetime
                        if (!$parentStartTime) {
                            $cartItemDate = \Carbon\Carbon::parse($cartItem->booking_date)->format('Y-m-d');
                            $parentStartTime = $cartItemDate . ' ' . $cartItem->start_time;
                            // Handle midnight crossing
                            $cartStartTime = \Carbon\Carbon::parse($cartItem->start_time);
                            $cartEndTime = \Carbon\Carbon::parse($cartItem->end_time);
                            if ($cartEndTime->lte($cartStartTime)) {
                                $endDate = \Carbon\Carbon::parse($cartItemDate)->addDay()->format('Y-m-d');
                                $parentEndTime = $endDate . ' ' . $cartItem->end_time;
                            } else {
                                $parentEndTime = $cartItemDate . ' ' . $cartItem->end_time;
                            }
                        }

                        // Find the associated booking if it exists
                        if (!$pendingBookingId) {
                            $associatedBooking = Booking::where('cart_transaction_id', $cartTrans->id)
                                ->where('court_id', $item['court_id'])
                                ->where('start_time', $parentStartTime)
                                ->where('end_time', $parentEndTime)
                                ->first();
                            if ($associatedBooking) {
                                $pendingBookingId = $associatedBooking->id;
                                // Use booking's times if found
                                $parentStartTime = $associatedBooking->start_time;
                                $parentEndTime = $associatedBooking->end_time;
                            }
                        }
                        break;
                    }
                }

                // If there's a booking pending approval, add ALL users to waitlist (no bypassing)
                if ($isPendingApprovalBooking) {
                    // Use parent booking's times (not the incoming item's times)
                    // This ensures the waitlist shows the correct time slot
                    $waitlistStartTime = $parentStartTime ?? $startDateTime;
                    $waitlistEndTime = $parentEndTime ?? $endDateTime;

                    // Get the next position in waitlist
                    $nextPosition = BookingWaitlist::where('court_id', $item['court_id'])
                        ->where('start_time', $waitlistStartTime)
                        ->where('end_time', $waitlistEndTime)
                        ->where('status', BookingWaitlist::STATUS_PENDING)
                        ->count() + 1;

                    // Create waitlist entry FIRST so we have the ID
                    $waitlistEntry = BookingWaitlist::create([
                        'user_id' => $userId,
                        'booking_for_user_id' => $item['booking_for_user_id'] ?? null,
                        'booking_for_user_name' => $item['booking_for_user_name'] ?? null,
                        'pending_booking_id' => $pendingBookingId,
                        'pending_cart_transaction_id' => $pendingCartTransactionId,
                        'court_id' => $item['court_id'],
                        'sport_id' => $item['sport_id'],
                        'start_time' => $waitlistStartTime,
                        'end_time' => $waitlistEndTime,
                        'price' => $item['price'],
                        'number_of_players' => $item['number_of_players'] ?? 1,
                        'position' => $nextPosition,
                        'status' => BookingWaitlist::STATUS_PENDING,
                        'admin_notes' => $item['admin_notes'] ?? null,
                        'notes' => $item['notes'] ?? null
                    ]);

                    // Create cart item for the waitlisted slot with waitlist ID link
                    $cartItem = CartItem::create([
                        'user_id' => $userId,
                        'cart_transaction_id' => $cartTransaction->id,
                        'booking_waitlist_id' => $waitlistEntry->id, // Link to waitlist!
                        'court_id' => $item['court_id'],
                        'sport_id' => $item['sport_id'],
                        'booking_date' => $bookingDate,
                        'start_time' => $item['start_time'],
                        'end_time' => $item['end_time'],
                        'price' => $item['price'],
                        'number_of_players' => $item['number_of_players'] ?? 1,
                        'notes' => $item['notes'] ?? null,
                        'booking_for_user_id' => $item['booking_for_user_id'] ?? null,
                        'booking_for_user_name' => $item['booking_for_user_name'] ?? null,
                        'admin_notes' => $item['admin_notes'] ?? null
                    ]);

                    // Create waitlist cart records (WaitlistCartItem and WaitlistCartTransaction)
                    $waitlistCartService = app(\App\Services\WaitlistCartService::class);
                    $waitlistCartService->createWaitlistCartRecords(
                        $waitlistEntry,
                        $cartItem,
                        $cartTransaction
                    );

                    // Update cart transaction total price
                    $totalPrice += floatval($item['price']);

                    // Track waitlisted items for comprehensive response
                    $waitlistedItems[] = [
                        'waitlist_entry' => $waitlistEntry->load(['court', 'sport']),
                        'cart_item' => $cartItem->load(['court', 'sport', 'court.images']),
                        'position' => $nextPosition
                    ];
                    $hasAnyWaitlist = true;

                    // Continue to next item instead of returning immediately
                    continue;
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

                // If we reach here, booking is allowed (no conflict detected)
                $cartItem = CartItem::create([
                    'user_id' => $userId,
                    'cart_transaction_id' => $cartTransaction->id,
                    'court_id' => $item['court_id'],
                    'sport_id' => $item['sport_id'],
                    'booking_date' => $bookingDate,
                    'start_time' => $item['start_time'],
                    'end_time' => $item['end_time'],
                    'price' => $item['price'],
                    'number_of_players' => $item['number_of_players'] ?? 1,
                    'notes' => $item['notes'] ?? null,
                    'booking_for_user_id' => $item['booking_for_user_id'] ?? null,
                    'booking_for_user_name' => $item['booking_for_user_name'] ?? null,
                    'admin_notes' => $item['admin_notes'] ?? null
                ]);

                $totalPrice += floatval($item['price']);
                $addedItems[] = $cartItem->load(['court', 'sport', 'court.images']);
            }

            // Update cart transaction total price
            $cartTransaction->update([
                'total_price' => $cartTransaction->total_price + $totalPrice
            ]);

            DB::commit();

            // FIX #11: Return comprehensive response handling both regular and waitlisted items
            if ($hasAnyWaitlist && count($addedItems) === 0) {
                // All items were waitlisted - return waitlist-specific response
                $firstWaitlist = $waitlistedItems[0];
                return response()->json([
                    'message' => count($waitlistedItems) > 1
                        ? 'All time slots are currently pending approval. You have been added to the waitlist.'
                        : 'This time slot is currently pending approval for another user. You have been added to the waitlist.',
                    'waitlisted' => true,
                    'waitlist_entry' => $firstWaitlist['waitlist_entry'],
                    'waitlist_entries' => array_map(function($item) {
                        return $item['waitlist_entry'];
                    }, $waitlistedItems),
                    'cart_item' => $firstWaitlist['cart_item'],
                    'cart_items' => array_map(function($item) {
                        return $item['cart_item'];
                    }, $waitlistedItems),
                    'cart_transaction' => $cartTransaction->fresh()->load(['cartItems', 'user']),
                    'position' => $firstWaitlist['position'],
                    'total_waitlisted' => count($waitlistedItems)
                ], 200);
            } elseif ($hasAnyWaitlist && count($addedItems) > 0) {
                // Mixed - some items added normally, some waitlisted
                return response()->json([
                    'message' => sprintf(
                        'Successfully added %d item(s) to cart. %d item(s) added to waitlist.',
                        count($addedItems),
                        count($waitlistedItems)
                    ),
                    'items' => $addedItems,
                    'waitlisted_items' => array_map(function($item) {
                        return $item['cart_item'];
                    }, $waitlistedItems),
                    'waitlist_entries' => array_map(function($item) {
                        return $item['waitlist_entry'];
                    }, $waitlistedItems),
                    'cart_transaction' => $cartTransaction->load(['cartItems', 'user']),
                    'has_waitlist' => true,
                    'total_added' => count($addedItems),
                    'total_waitlisted' => count($waitlistedItems)
                ], 201);
            } else {
                // All items added successfully (no waitlist)
                return response()->json([
                    'message' => 'Items added to cart successfully',
                    'items' => $addedItems,
                    'cart_transaction' => $cartTransaction->load(['cartItems', 'user']),
                    'has_waitlist' => false
                ], 201);
            }

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

            // Note: CartItemObserver will automatically sync related bookings

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

            // Find pending cart transaction (exclude rejected transactions)
            $cartTransaction = CartTransaction::where('user_id', $request->user()->id)
                ->where('status', 'pending')
                ->where('payment_status', 'unpaid')
                ->whereIn('approval_status', ['pending', 'approved'])
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
            ->whereIn('approval_status', ['pending', 'approved'])
            ->first();

        $count = $cartTransaction ? $cartTransaction->cartItems()->where('status', 'pending')->count() : 0;

        return response()->json([
            'count' => $count
        ]);
    }

    /**
     * Validate cart items availability
     * Checks if cart items are still available or should be in waitlist
     */
    public function validateCartItems(Request $request)
    {
        try {
            DB::beginTransaction();

            $userId = $request->user()->id;

            // Get all pending cart items for this user
            $cartItems = CartItem::with(['court', 'cartTransaction'])
                ->where('user_id', $userId)
                ->where('status', 'pending')
                ->whereHas('cartTransaction', function($query) {
                    $query->where('status', 'pending')
                          ->where('payment_status', 'unpaid')
                          ->whereIn('approval_status', ['pending', 'approved']);
                })
                ->get();

            if ($cartItems->isEmpty()) {
                DB::commit();
                return response()->json([
                    'success' => true,
                    'has_unavailable_items' => false,
                    'unavailable_items' => [],
                    'message' => 'No items to validate'
                ]);
            }

            $unavailableItems = [];
            $removedItemIds = [];

            foreach ($cartItems as $cartItem) {
                // Prepare datetime strings for conflict checking
                $bookingDate = \Carbon\Carbon::parse($cartItem->booking_date)->format('Y-m-d');
                $startDateTime = $bookingDate . ' ' . $cartItem->start_time;

                // Handle midnight crossing
                $startTime = \Carbon\Carbon::parse($cartItem->start_time);
                $endTime = \Carbon\Carbon::parse($cartItem->end_time);
                $crossesMidnight = $endTime->lte($startTime);

                if ($crossesMidnight) {
                    $endDate = \Carbon\Carbon::parse($bookingDate)->addDay()->format('Y-m-d');
                    $endDateTime = $endDate . ' ' . $cartItem->end_time;
                } else {
                    $endDateTime = $bookingDate . ' ' . $cartItem->end_time;
                }

                // Check for conflicting bookings (including pending, approved, completed, checked_in)
                $conflictingBooking = Booking::where('court_id', $cartItem->court_id)
                    ->whereIn('status', ['pending', 'approved', 'completed', 'checked_in'])
                    ->where(function ($query) use ($startDateTime, $endDateTime, $bookingDate, $crossesMidnight) {
                        $query->where(function ($q) use ($startDateTime, $endDateTime, $bookingDate) {
                            $q->whereDate('start_time', $bookingDate)
                              ->where(function ($sq) use ($startDateTime, $endDateTime) {
                                  $sq->where(function ($innerQ) use ($startDateTime, $endDateTime) {
                                      $innerQ->where('start_time', '>=', $startDateTime)
                                             ->where('start_time', '<', $endDateTime);
                                  })->orWhere(function ($innerQ) use ($startDateTime, $endDateTime) {
                                      $innerQ->where('end_time', '>', $startDateTime)
                                             ->where('end_time', '<=', $endDateTime);
                                  })->orWhere(function ($innerQ) use ($startDateTime, $endDateTime) {
                                      $innerQ->where('start_time', '<=', $startDateTime)
                                             ->where('end_time', '>=', $endDateTime);
                                  });
                              });
                        });

                        if ($crossesMidnight) {
                            $prevDate = \Carbon\Carbon::parse($bookingDate)->subDay()->format('Y-m-d');
                            $query->orWhere(function ($q) use ($startDateTime, $endDateTime, $prevDate) {
                                $q->whereDate('start_time', $prevDate)
                                  ->where(function ($sq) use ($startDateTime, $endDateTime) {
                                      $sq->where(function ($innerQ) use ($startDateTime, $endDateTime) {
                                          $innerQ->where('start_time', '>=', $startDateTime)
                                                 ->where('start_time', '<', $endDateTime);
                                      })->orWhere(function ($innerQ) use ($startDateTime, $endDateTime) {
                                          $innerQ->where('end_time', '>', $startDateTime)
                                                 ->where('end_time', '<=', $endDateTime);
                                      })->orWhere(function ($innerQ) use ($startDateTime, $endDateTime) {
                                          $innerQ->where('start_time', '<=', $startDateTime)
                                                 ->where('end_time', '>=', $endDateTime);
                                      });
                                  });
                            });
                        }
                    })
                    ->exists();

                // Check for conflicting approved/paid cart items from other transactions
                $conflictingCartItems = CartItem::where('court_id', $cartItem->court_id)
                    ->where('status', 'pending')
                    ->where('cart_transaction_id', '!=', $cartItem->cart_transaction_id)
                    ->where(function ($query) use ($bookingDate, $startDateTime, $endDateTime, $crossesMidnight) {
                        $query->where(function ($q) use ($bookingDate, $startDateTime, $endDateTime) {
                            $q->where('booking_date', $bookingDate)
                              ->where(function ($sq) use ($startDateTime, $endDateTime) {
                                  $sq->whereRaw("CONCAT(booking_date, ' ', start_time) >= ? AND CONCAT(booking_date, ' ', start_time) < ?",
                                      [$startDateTime, $endDateTime])
                                     ->orWhereRaw("CONCAT(booking_date, ' ', end_time) > ? AND CONCAT(booking_date, ' ', end_time) <= ?",
                                      [$startDateTime, $endDateTime])
                                     ->orWhereRaw("CONCAT(booking_date, ' ', start_time) <= ? AND CONCAT(booking_date, ' ', end_time) >= ?",
                                      [$startDateTime, $endDateTime]);
                              });
                        });

                        if ($crossesMidnight) {
                            $prevDate = \Carbon\Carbon::parse($bookingDate)->subDay()->format('Y-m-d');
                            $query->orWhere(function ($q) use ($prevDate, $startDateTime, $endDateTime) {
                                $q->where('booking_date', $prevDate)
                                  ->where(function ($sq) use ($startDateTime, $endDateTime) {
                                      $sq->whereRaw("CONCAT(booking_date, ' ', start_time) >= ? AND CONCAT(booking_date, ' ', start_time) < ?",
                                          [$startDateTime, $endDateTime])
                                         ->orWhereRaw("CONCAT(booking_date, ' ', end_time) > ? AND CONCAT(booking_date, ' ', end_time) <= ?",
                                          [$startDateTime, $endDateTime])
                                         ->orWhereRaw("CONCAT(booking_date, ' ', start_time) <= ? AND CONCAT(booking_date, ' ', end_time) >= ?",
                                          [$startDateTime, $endDateTime]);
                                  });
                            });
                        }
                    })
                    ->whereHas('cartTransaction', function($query) {
                        $query->where('approval_status', 'approved')
                              ->where('payment_status', 'paid');
                    })
                    ->exists();

                // If there's a conflict with approved/completed bookings or paid cart items, mark as unavailable
                if ($conflictingBooking || $conflictingCartItems) {
                    $unavailableItems[] = [
                        'id' => $cartItem->id,
                        'court_name' => $cartItem->court ? $cartItem->court->name : 'Unknown',
                        'booking_date' => $bookingDate,
                        'start_time' => $cartItem->start_time,
                        'end_time' => $cartItem->end_time,
                        'reason' => 'Time slot is no longer available'
                    ];

                    // Automatically remove the unavailable item
                    $cartItem->update(['status' => 'cancelled']);
                    $removedItemIds[] = $cartItem->id;

                    // Update transaction total price
                    if ($cartItem->cartTransaction) {
                        $newTotal = $cartItem->cartTransaction->cartItems()
                            ->where('status', 'pending')
                            ->sum('price');

                        $cartItem->cartTransaction->update([
                            'total_price' => max(0, $newTotal)
                        ]);

                        // If no pending items left, cancel the transaction
                        if ($cartItem->cartTransaction->cartItems()->where('status', 'pending')->count() === 0) {
                            $cartItem->cartTransaction->update(['status' => 'cancelled']);
                        }
                    }
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'has_unavailable_items' => count($unavailableItems) > 0,
                'unavailable_items' => $unavailableItems,
                'removed_count' => count($removedItemIds),
                'message' => count($unavailableItems) > 0
                    ? sprintf('%d item(s) are no longer available and have been removed from your cart.', count($unavailableItems))
                    : 'All cart items are still available'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to validate cart items',
                'error' => $e->getMessage()
            ], 500);
        }
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
            ->whereIn('approval_status', ['pending', 'approved'])
            ->first();

        if (!$cartTransaction) {
            return response()->json([
                'success' => true,
                'has_transaction' => false
            ]);
        }

        // Check if transaction is exempt from expiration using universal helper
        if (BusinessHoursHelper::isExemptFromExpiration($cartTransaction)) {
            // Determine the reason for exemption
            $reason = 'No expiration';
            if ($cartTransaction->user && $cartTransaction->user->isAdmin()) {
                $reason = 'No expiration (Admin)';
            } elseif ($cartTransaction->approval_status === 'approved') {
                $reason = 'No expiration (Approved)';
            } elseif ($cartTransaction->proof_of_payment) {
                $reason = 'No expiration (Proof of payment uploaded)';
            }

            return response()->json([
                'success' => true,
                'has_transaction' => true,
                'is_exempt' => true,
                'is_admin' => $cartTransaction->user && $cartTransaction->user->isAdmin(),
                'is_approved' => $cartTransaction->approval_status === 'approved',
                'has_proof_of_payment' => (bool) $cartTransaction->proof_of_payment,
                'expires_at' => null,
                'time_remaining_seconds' => null,
                'time_remaining_formatted' => $reason,
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
        // Company setting: block regular users from checking out (creating bookings) if disabled
        if ($request->user()->role === 'user') {
            $userBookingEnabled = \App\Models\CompanySetting::get('user_booking_enabled', '1') === '1';
            if (!$userBookingEnabled) {
                return response()->json([
                    'message' => 'Booking creation is currently disabled for user accounts. Please contact the administrator.'
                ], 403);
            }
        }

        // Check if user is Admin or Staff
        $isAdminOrStaff = in_array($request->user()->role, ['admin', 'staff']);

        // Build validation rules - payment is optional for Admin/Staff
        $validationRules = [
            'payment_method' => $isAdminOrStaff ? 'nullable|in:pending,gcash,cash' : 'required|in:pending,gcash',
            'proof_of_payment' => $isAdminOrStaff ? 'nullable' : 'required_if:payment_method,gcash',
            'payment_reference_number' => 'nullable|string|max:255',
            'selected_items' => 'nullable|array',
            'selected_items.*' => 'integer|exists:cart_items,id',
            'skip_payment' => 'nullable|boolean', // New field for Admin/Staff to explicitly skip payment
            'pos_items' => 'nullable|array',
            'pos_items.*.product_id' => 'required_with:pos_items|integer|exists:products,id',
            'pos_items.*.quantity' => 'required_with:pos_items|integer|min:1',
            'pos_items.*.unit_price' => 'required_with:pos_items|numeric|min:0',
            'pos_items.*.discount' => 'nullable|numeric|min:0',
            'pos_amount' => 'nullable|numeric|min:0',
            'booking_amount' => 'nullable|numeric|min:0'
        ];

        $validator = Validator::make($request->all(), $validationRules);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $userId = $request->user()->id;

            // Resolve the correct pending cart transaction (prefer the one referenced by selected_items)
            $selectedItemIds = $request->selected_items ?? [];
            $cartTransaction = null;

            if (!empty($selectedItemIds)) {
                // Removed checkout selected_items debug logs
                // Find the most recent transaction among the selected items
                $transactionIds = CartItem::whereIn('id', $selectedItemIds)
                    ->pluck('cart_transaction_id')
                    ->filter()
                    ->unique()
                    ->toArray();

                if (!empty($transactionIds)) {
                    $cartTransaction = CartTransaction::where('user_id', $userId)
                        ->whereIn('id', $transactionIds)
                        ->where('status', 'pending')
                        ->where('payment_status', 'unpaid')
                        ->whereIn('approval_status', ['pending', 'approved'])
                        ->orderBy('created_at', 'desc')
                        ->first();
                    // Removed checkout resolved transaction debug log
                }
            }

            // Fallback: use the most recently created pending transaction for this user
            if (!$cartTransaction) {
                $cartTransaction = CartTransaction::where('user_id', $userId)
                    ->where('status', 'pending')
                    ->where('payment_status', 'unpaid')
                    ->whereIn('approval_status', ['pending', 'approved'])
                    ->orderBy('created_at', 'desc')
                    ->first();
                // Removed checkout fallback transaction debug log
            }

            if (!$cartTransaction) {
                return response()->json([
                    'message' => 'No pending cart found'
                ], 400);
            }

            // Early validation: Check if transaction has been rejected
            if ($cartTransaction->approval_status === 'rejected') {
                DB::rollBack();
                return response()->json([
                    'message' => 'This booking has been rejected. Reason: ' . ($cartTransaction->rejection_reason ?? 'Not specified'),
                    'error' => 'TRANSACTION_REJECTED',
                    'rejection_reason' => $cartTransaction->rejection_reason
                ], 422);
            }

            // Get cart items - either selected ones or all items in transaction
            // IMPORTANT: Only process items with status='pending' to avoid cancelled/expired items
            $cartItemsQuery = CartItem::with('court')
                ->where('cart_transaction_id', $cartTransaction->id)
                ->where('status', 'pending');

            if ($request->has('selected_items') && !empty($request->selected_items)) {
                $cartItemsQuery->whereIn('id', $request->selected_items);
            }

            $cartItems = $cartItemsQuery
                ->orderBy('court_id')
                ->orderBy('booking_date')
                ->orderBy('start_time')
                ->get();

            // Removed checkout cart items snapshot debug log

            if ($cartItems->isEmpty()) {
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

            // Process proof of payment - decode base64 and save as file(s)
            $proofOfPaymentPath = null;
            if ($request->proof_of_payment) {
                try {
                    // Handle both single base64 string and array of base64 strings
                    $proofData = $request->proof_of_payment;
                    $proofArray = is_array($proofData) ? $proofData : [$proofData];
                    $savedPaths = [];

                    foreach ($proofArray as $index => $base64String) {
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
                            continue;
                        }

                        // Create filename with transaction ID, timestamp, and index
                        $filename = 'proof_txn_' . $cartTransaction->id . '_' . time() . '_' . $index . '.' . $imageType;

                        // Save to storage/app/public/proofs/
                        Storage::disk('public')->put('proofs/' . $filename, $imageData);

                        $savedPaths[] = 'proofs/' . $filename;
                    }

                    // Store as JSON array if multiple files, otherwise as single string for backward compatibility
                    if (count($savedPaths) > 1) {
                        $proofOfPaymentPath = json_encode($savedPaths);
                    } elseif (count($savedPaths) === 1) {
                        $proofOfPaymentPath = $savedPaths[0];
                    }
                } catch (\Exception $e) {
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
            }

            // Check if any cart items match active waitlist entries for this user
            // If yes, this checkout should be auto-approved (no need for admin approval again)
            $hasWaitlistEntry = false;
            $matchedWaitlistEntries = [];

            foreach ($groupedBookings as $group) {
                $startDateTime = $group['booking_date'] . ' ' . $group['start_time'];

                $startTime = \Carbon\Carbon::parse($group['start_time']);
                $endTime = \Carbon\Carbon::parse($group['end_time']);

                if ($endTime->lte($startTime)) {
                    $endDate = \Carbon\Carbon::parse($group['booking_date'])->addDay()->format('Y-m-d');
                    $endDateTime = $endDate . ' ' . $group['end_time'];
                } else {
                    $endDateTime = $group['booking_date'] . ' ' . $group['end_time'];
                }

                // Check for active waitlist entry (notified and not expired)
                $waitlistEntry = BookingWaitlist::where('user_id', $userId)
                    ->where('court_id', $group['court_id'])
                    ->where('start_time', $startDateTime)
                    ->where('end_time', $endDateTime)
                    ->where('status', BookingWaitlist::STATUS_NOTIFIED)
                    ->where(function($query) {
                        $query->whereNull('expires_at')
                              ->orWhere('expires_at', '>', now());
                    })
                    ->first();

                if ($waitlistEntry) {
                    $hasWaitlistEntry = true;
                    $matchedWaitlistEntries[] = $waitlistEntry;
                }
            }

            // Determine approval status based on waitlist match
            // If user has an active waitlist entry, use separate 'pending_waitlist' status
            // This ensures waitlist bookings still go through admin approval
            $approvalStatus = $hasWaitlistEntry ? 'pending_waitlist' : 'pending';
            $approvedAt = null; // Waitlist bookings need admin approval, not auto-approved

            // IMPORTANT: Create bookings BEFORE updating cart transaction/items status
            // This ensures data integrity - if booking creation fails, nothing is marked as completed
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

                // Final availability check - only check active bookings (exclude cancelled/rejected)
                $isBooked = Booking::where('court_id', $group['court_id'])
                    ->whereIn('status', ['pending', 'approved', 'completed', 'checked_in'])
                    ->where(function ($query) use ($startDateTime, $endDateTime) {
                        // Removed debug logs for computed datetimes
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

                // Get the first cart item from this group to extract admin booking fields
                $firstCartItem = CartItem::whereIn('id', $group['items'])->first();

                // Check if this specific booking matches a waitlist entry
                $matchedWaitlistForBooking = null;
                foreach ($matchedWaitlistEntries as $waitlistEntry) {
                    if ($waitlistEntry->court_id == $group['court_id'] &&
                        $waitlistEntry->start_time == $startDateTime &&
                        $waitlistEntry->end_time == $endDateTime) {
                        $matchedWaitlistForBooking = $waitlistEntry;
                        break;
                    }
                }

                // Set booking status based on waitlist match
                // Waitlist bookings remain 'pending' until admin approval
                $bookingStatus = 'pending';

                $booking = Booking::create([
                    'user_id' => $userId,
                    'cart_transaction_id' => $cartTransaction->id,
                    'court_id' => $group['court_id'],
                    'sport_id' => $firstCartItem->sport_id,
                    'start_time' => $startDateTime,  // Use adjusted datetime that handles midnight crossing
                    'end_time' => $endDateTime,  // Use adjusted datetime that handles midnight crossing
                    'total_price' => $group['price'],
                    'number_of_players' => $firstCartItem->number_of_players ?? 1,
                    'status' => $bookingStatus,
                    'notes' => $firstCartItem->notes,
                    'payment_method' => $paymentMethod,
                    'payment_reference_number' => $request->payment_reference_number,
                    'payment_status' => $paymentStatus,
                    'proof_of_payment' => $proofOfPaymentPath, // Use the saved file path
                    'paid_at' => $paidAt,
                    'booking_for_user_id' => $firstCartItem->booking_for_user_id,
                    'booking_for_user_name' => $firstCartItem->booking_for_user_name,
                    'admin_notes' => $firstCartItem->admin_notes,
                ]);

                // If this booking was created from a waitlist entry, mark the waitlist as converted
                if ($matchedWaitlistForBooking) {
                    $matchedWaitlistForBooking->update([
                        'status' => BookingWaitlist::STATUS_CONVERTED,
                        'converted_cart_transaction_id' => $cartTransaction->id
                    ]);
                }

                $createdBookings[] = $booking->load(['user', 'court', 'sport', 'court.images', 'cartTransaction']);

                // Broadcast booking created event in real-time
                broadcast(new BookingCreated($booking))->toOthers();
            }

            // Process POS items if provided
            $posAmount = 0;
            $bookingAmount = $totalPrice;
            if ($request->has('pos_items') && count($request->pos_items) > 0) {
                // Create POS sale linked to this transaction
                $posSubtotal = 0;
                $posSaleItems = [];

                foreach ($request->pos_items as $item) {
                    $product = \App\Models\Product::findOrFail($item['product_id']);

                    // Check stock availability
                    if ($product->track_inventory && $product->stock_quantity < $item['quantity']) {
                        DB::rollBack();
                        return response()->json([
                            'message' => "Insufficient stock for product: {$product->name}. Available: {$product->stock_quantity}"
                        ], 422);
                    }

                    $itemDiscount = $item['discount'] ?? 0;
                    $itemSubtotal = ($item['unit_price'] * $item['quantity']) - $itemDiscount;
                    $posSubtotal += $itemSubtotal;

                    $posSaleItems[] = [
                        'product_id' => $product->id,
                        'product' => $product,
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'unit_cost' => $product->cost,
                        'discount' => $itemDiscount,
                        'subtotal' => $itemSubtotal,
                    ];
                }

                $posAmount = $request->pos_amount ?? $posSubtotal;

                // Create POS sale
                $posSale = \App\Models\PosSale::create([
                    'booking_id' => $cartTransaction->id,
                    'user_id' => $userId,
                    'total_amount' => $posAmount,
                    'subtotal' => $posSubtotal,
                    'discount' => 0,
                    'tax' => 0,
                    'payment_method' => $paymentMethod,
                    'payment_status' => $paymentStatus,
                    'status' => 'completed',
                    'notes' => 'POS items added to booking'
                ]);

                // Create POS sale items
                foreach ($posSaleItems as $saleItem) {
                    \App\Models\PosSaleItem::create([
                        'pos_sale_id' => $posSale->id,
                        'product_id' => $saleItem['product_id'],
                        'quantity' => $saleItem['quantity'],
                        'unit_price' => $saleItem['unit_price'],
                        'unit_cost' => $saleItem['unit_cost'],
                        'discount' => $saleItem['discount'],
                        'subtotal' => $saleItem['subtotal'],
                    ]);

                    // Deduct stock if tracking inventory
                    if ($saleItem['product']->track_inventory) {
                        // Use Product model's decreaseStock method which properly handles stock movement recording
                        $saleItem['product']->decreaseStock(
                            $saleItem['quantity'],
                            $userId,
                            "Sold with booking transaction #{$cartTransaction->id}",
                            $posSale->id
                        );
                    }
                }
            }

            // Override amounts if provided
            if ($request->has('booking_amount')) {
                $bookingAmount = $request->booking_amount;
            }
            if ($request->has('pos_amount')) {
                $posAmount = $request->pos_amount;
            }

            // Calculate total price
            $finalTotalPrice = $bookingAmount + $posAmount;

            // IMPORTANT: Update cart transaction status ONLY AFTER bookings are successfully created
            // This ensures data integrity - cart is only marked 'completed' if bookings exist
            $cartTransaction->update([
                'total_price' => $finalTotalPrice,
                'booking_amount' => $bookingAmount,
                'pos_amount' => $posAmount,
                'status' => 'completed',
                'payment_method' => $paymentMethod,
                'payment_reference_number' => $request->payment_reference_number,
                'payment_status' => $paymentStatus,
                'proof_of_payment' => $proofOfPaymentPath,
                'paid_at' => $paidAt,
                'approval_status' => $approvalStatus,
                'approved_at' => $approvedAt
            ]);

            // Mark items as completed instead of deleting them
            if ($request->has('selected_items') && !empty($request->selected_items)) {
                // Mark only selected items as completed
                CartItem::whereIn('id', $request->selected_items)->update(['status' => 'completed']);

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
                }
            } else {
                // Full checkout - mark all items as completed
                CartItem::where('cart_transaction_id', $cartTransaction->id)->update(['status' => 'completed']);
            }

            DB::commit();

            return response()->json([
                'message' => 'Checkout successful',
                'transaction' => $cartTransaction->load(['cartItems.court', 'bookings']),
                'bookings' => $createdBookings,
                'waitlist_converted' => $hasWaitlistEntry,
                'auto_approved' => $hasWaitlistEntry
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
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
                ->whereIn('status', ['pending', 'approved', 'completed', 'checked_in'])
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
     * Update a cart item (admin and staff - for updating court/time)
     */
    public function updateCartItem(Request $request, $id)
    {
        // Only admins and staff can update cart items
        if (!$request->user()->isAdmin() && !$request->user()->isStaff()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Admin or staff privileges required.'
            ], 403);
        }

        try {
            DB::beginTransaction();

            $cartItem = CartItem::find($id);

            if (!$cartItem) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Cart item not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'court_id' => 'sometimes|required|exists:courts,id',
                'booking_date' => 'sometimes|required|date',
                'start_time' => 'sometimes|required|date_format:H:i',
                'end_time' => 'sometimes|required|date_format:H:i',
                'notes' => 'sometimes|nullable|string|max:1000',
                'admin_notes' => 'sometimes|nullable|string|max:1000'
            ]);

            if ($validator->fails()) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Use new values if provided, otherwise use existing values
            $courtId = $request->has('court_id') ? $request->court_id : $cartItem->court_id;
            $bookingDate = $request->has('booking_date') ? \Carbon\Carbon::parse($request->booking_date)->format('Y-m-d') : \Carbon\Carbon::parse($cartItem->booking_date)->format('Y-m-d');
            $startTime = $request->has('start_time') ? $request->start_time : $cartItem->start_time;
            $endTime = $request->has('end_time') ? $request->end_time : $cartItem->end_time;

            // Get the court
            $court = Court::find($courtId);

            if (!$court || !$court->is_active) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Selected court is not available'
                ], 400);
            }

            // Create full datetime for conflict checking
            $startDateTime = $bookingDate . ' ' . $startTime;
            $endDateTime = $bookingDate . ' ' . $endTime;

            // Handle midnight crossing
            $startTimeParsed = \Carbon\Carbon::parse($startDateTime);
            $endTimeParsed = \Carbon\Carbon::parse($endDateTime);
            if ($endTimeParsed->lte($startTimeParsed)) {
                $endDateTime = \Carbon\Carbon::parse($bookingDate)->addDay()->format('Y-m-d') . ' ' . $endTime;
            }

            // Check for conflicts on the new court
            $conflictingBooking = Booking::where('court_id', $courtId)
                ->whereIn('status', ['pending', 'approved', 'completed', 'checked_in'])
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
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Time slot conflicts with existing booking on the selected court'
                ], 409);
            }

            // Check for conflicts with other cart items on the court
            $conflictingCartItem = CartItem::where('court_id', $courtId)
                ->where('id', '!=', $id)
                ->where('booking_date', $bookingDate)
                ->where('status', '!=', 'cancelled')
                ->whereHas('cartTransaction', function($query) {
                    $query->whereIn('approval_status', ['pending', 'approved'])
                          ->whereIn('payment_status', ['unpaid', 'paid']);
                })
                ->where(function ($query) use ($startTime, $endTime) {
                    $query->where(function ($q) use ($startTime, $endTime) {
                        $q->where('start_time', '>=', $startTime)
                          ->where('start_time', '<', $endTime);
                    })->orWhere(function ($q) use ($startTime, $endTime) {
                        $q->where('end_time', '>', $startTime)
                          ->where('end_time', '<=', $endTime);
                    })->orWhere(function ($q) use ($startTime, $endTime) {
                        $q->where('start_time', '<=', $startTime)
                          ->where('end_time', '>=', $endTime);
                    });
                })
                ->first();

            if ($conflictingCartItem) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Time slot conflicts with another pending booking on the selected court'
                ], 409);
            }

            // Store old values for updating related bookings
            $oldBookingDate = \Carbon\Carbon::parse($cartItem->booking_date)->format('Y-m-d');
            $oldStartTime = $cartItem->start_time;
            $oldEndTime = $cartItem->end_time;
            $oldCourtId = $cartItem->court_id;

            // Prepare update data
            $updateData = [];
            if ($request->has('court_id')) {
                $updateData['court_id'] = $courtId;
            }
            if ($request->has('booking_date')) {
                $updateData['booking_date'] = $bookingDate;
            }
            if ($request->has('start_time')) {
                $updateData['start_time'] = $startTime;
            }
            if ($request->has('end_time')) {
                $updateData['end_time'] = $endTime;
            }
            if ($request->has('notes')) {
                $updateData['notes'] = $request->notes;
            }
            if ($request->has('admin_notes')) {
                $updateData['admin_notes'] = $request->admin_notes;
            }

            // Update the cart item
            // Note: CartItemObserver will automatically sync related booking records
            // when court_id or other fields change (see app/Observers/CartItemObserver.php)
            $cartItem->update($updateData);

            // Refresh the cart item from database to ensure we have the latest data
            $cartItem->refresh();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Cart item updated successfully',
                'data' => $cartItem->load(['court', 'sport', 'court.images'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update cart item',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a cart item (admin and staff - for removing time slots)
     */
    public function deleteCartItem(Request $request, $id)
    {
        // Only admins and staff can delete cart items
        if (!$request->user()->isAdmin() && !$request->user()->isStaff()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Admin or staff privileges required.'
            ], 403);
        }

        try {
            DB::beginTransaction();

            $cartItem = CartItem::with('cartTransaction')->find($id);

            if (!$cartItem) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cart item not found'
                ], 404);
            }

            // Check if the transaction is still pending (including waitlist pending)
            if ($cartItem->cartTransaction && !in_array($cartItem->cartTransaction->approval_status, ['pending', 'pending_waitlist'])) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete time slots from ' . $cartItem->cartTransaction->approval_status . ' bookings. Only pending bookings can be modified.'
                ], 400);
            }

            $transactionId = $cartItem->cart_transaction_id;
            $price = $cartItem->price;

            // Check if this is the last item in the transaction
            if ($transactionId) {
                $itemCount = CartItem::where('cart_transaction_id', $transactionId)
                    ->where('status', '!=', 'cancelled')
                    ->count();

                if ($itemCount <= 1) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot delete the last time slot. Please delete the entire booking instead.'
                    ], 400);
                }
            }

            // Mark the cart item as cancelled
            $cartItem->update(['status' => 'cancelled']);

            // Update cart transaction total price if exists
            if ($transactionId) {
                $cartTransaction = CartTransaction::find($transactionId);
                if ($cartTransaction) {
                    // Recalculate total from non-cancelled items
                    $newTotal = CartItem::where('cart_transaction_id', $transactionId)
                        ->where('status', '!=', 'cancelled')
                        ->sum('price');

                    $cartTransaction->update([
                        'total_price' => max(0, $newTotal)
                    ]);
                }
            }

            // Note: CartItemObserver will automatically sync related bookings

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Time slot deleted successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete time slot: ' . $e->getMessage()
            ], 500);
        }
    }
}