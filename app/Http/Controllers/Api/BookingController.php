<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Court;
use App\Mail\BookingApprovalMail;
use App\Events\BookingCreated;
use App\Events\BookingStatusChanged;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;

class BookingController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Booking::with(['user', 'bookingForUser', 'court', 'sport', 'court.images', 'cartTransaction.cartItems.court', 'cartTransaction.cartItems.sport']);

        // For regular users, show bookings where they are either:
        // 1. The user who created it (user_id)
        // 2. The user the booking was made for (booking_for_user_id)
        // For admins, show all bookings unless filtered
        if (!$request->user()->isAdmin()) {
            $query->where(function($q) use ($request) {
                $q->where('user_id', $request->user()->id)
                  ->orWhere('booking_for_user_id', $request->user()->id);
            });
        } else {
            // Admin can filter by user_id if provided
            if ($request->has('user_id')) {
                $query->where('user_id', $request->user_id);
            }
        }

        if ($request->has('court_id')) {
            $query->where('court_id', $request->court_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $bookings = $query->orderBy('start_time', 'asc')->get();

        return response()->json([
            'success' => true,
            'data' => $bookings
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'court_id' => 'required|exists:courts,id',
            'sport_id' => 'nullable|exists:sports,id',
            'start_time' => 'required|date',
            'end_time' => 'required|date|after:start_time',
            'number_of_players' => 'nullable|integer|min:1|max:100',
            'status' => 'nullable|string|in:pending,approved,rejected,cancelled,completed,recurring_schedule',
            'notes' => 'nullable|string',
            'recurring_schedule' => 'nullable|string',
            'recurring_schedule_data' => 'nullable|array',
            'frequency_type' => 'nullable|string|in:once,daily,weekly,monthly,yearly',
            'frequency_days' => 'nullable|array',
            'frequency_times' => 'nullable|array',
            'frequency_duration_months' => 'nullable|integer|min:1',
            'frequency_end_date' => 'nullable|date|after:start_time',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        // Check if user role is 'user' and booking is within current month only
        if ($request->user()->isUser()) {
            $bookingDate = Carbon::parse($request->start_time);
            $currentMonthStart = Carbon::now()->startOfMonth();
            $currentMonthEnd = Carbon::now()->endOfMonth();

            if ($bookingDate->lt($currentMonthStart) || $bookingDate->gt($currentMonthEnd)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Regular users can only book time slots within the current month (' . $currentMonthStart->format('F Y') . ')',
                    'errors' => [
                        'start_time' => ['Booking date must be within the current month']
                    ]
                ], 422);
            }
        }

        // Get the selected court
        $court = \App\Models\Court::find($request->court_id);

        if (!$court) {
            return response()->json([
                'success' => false,
                'message' => 'Selected court not found'
            ], 404);
        }

        if (!$court->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Selected court is not available for booking'
            ], 400);
        }

        // Parse start and end times
        $startTime = Carbon::parse($request->start_time);
        $endTime = Carbon::parse($request->end_time);

        // Check for time conflicts with existing bookings (skip for recurring schedules)
        if ($request->status !== 'recurring_schedule') {
            $conflictingBooking = Booking::where('court_id', $court->id)
                ->whereIn('status', ['pending', 'approved', 'completed']) // Only check active bookings
            ->where(function ($query) use ($startTime, $endTime) {
                $query->where(function ($q) use ($startTime, $endTime) {
                    // New booking starts during existing booking
                    $q->where('start_time', '<=', $startTime)
                      ->where('end_time', '>', $startTime);
                })->orWhere(function ($q) use ($startTime, $endTime) {
                    // New booking ends during existing booking
                    $q->where('start_time', '<', $endTime)
                      ->where('end_time', '>', $endTime);
                })->orWhere(function ($q) use ($startTime, $endTime) {
                    // New booking completely contains existing booking
                    $q->where('start_time', '>=', $startTime)
                      ->where('end_time', '<=', $endTime);
                });
            })
            ->first();

        if ($conflictingBooking) {
            return response()->json([
                'success' => false,
                'message' => 'This time slot is already booked. Please choose a different time.',
            ], 409);
        }
        }


        // For recurring schedules, set total_price to 0 (will be calculated per session)
        if ($request->status === 'recurring_schedule') {
            $totalPrice = 0;
        } else {
            // Use time-based pricing calculation
            $totalPrice = $court->sport->calculatePriceForRange($startTime, $endTime);
        }

        $booking = Booking::create([
            'user_id' => $request->user()->id,
            'court_id' => $court->id,
            'sport_id' => $request->sport_id ?? $court->sport_id,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'total_price' => $totalPrice,
            'number_of_players' => $request->number_of_players ?? 1,
            'status' => $request->status ?? 'pending',
            'notes' => $request->notes,
            'booking_for_user_id' => $request->booking_for_user_id,
            'booking_for_user_name' => $request->booking_for_user_name,
            'admin_notes' => $request->admin_notes,
            'recurring_schedule' => $request->recurring_schedule,
            'recurring_schedule_data' => $request->recurring_schedule_data,
            'frequency_type' => $request->frequency_type ?? 'once',
            'frequency_days' => $request->frequency_days,
            'frequency_times' => $request->frequency_times,
            'frequency_duration_months' => $request->frequency_duration_months,
            'frequency_end_date' => $request->frequency_end_date,
            'payment_method' => $request->payment_method ?? 'pending',
            'payment_status' => $request->payment_status ?? 'unpaid',
            'proof_of_payment' => $request->proof_of_payment,
            'paid_at' => $request->payment_status === 'paid' ? now() : null,
        ]);

        // Broadcast booking created event
        broadcast(new BookingCreated($booking))->toOthers();

        return response()->json([
            'success' => true,
            'message' => 'Booking created successfully',
            'data' => $booking->load(['user', 'court', 'sport'])
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $booking = Booking::with(['user', 'bookingForUser', 'court', 'sport', 'court.images', 'cartTransaction.cartItems.court', 'cartTransaction.cartItems.sport'])->find($id);

        if (!$booking) {
            return response()->json([
                'success' => false,
                'message' => 'Booking not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $booking
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $booking = Booking::find($id);

        if (!$booking) {
            return response()->json([
                'success' => false,
                'message' => 'Booking not found'
            ], 404);
        }

        // Disallow uploads for rejected bookings
        if ($booking->status === 'rejected') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot upload proof of payment for a rejected booking'
            ], 422);
        }

        // Check if user owns this booking, is the booking_for_user, or is admin
        $isBookingOwner = $booking->user_id === $request->user()->id;
        $isBookingForUser = $booking->booking_for_user_id === $request->user()->id;
        $isAdmin = $request->user()->isAdmin();

        if (!$isBookingOwner && !$isBookingForUser && !$isAdmin) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to update this booking'
            ], 403);
        }
            if($request->status !== "cancelled"){
                    $validationRules = [
                        'start_time' => 'required|date',
                        'end_time' => 'required|date|after:start_time',
                        'total_price' => 'required|numeric|min:0',
                        'status' => 'required|in:pending,approved,rejected,cancelled,completed,recurring_schedule',
                        'notes' => 'nullable|string|max:1000',
                        'frequency_type' => 'nullable|string|in:once,daily,weekly,monthly,yearly',
                        'frequency_days' => 'nullable|array',
                        'frequency_times' => 'nullable|array',
                        'frequency_duration_months' => 'nullable|integer|min:1',
                        'frequency_end_date' => 'nullable|date|after:start_time',
                        'payment_method' => 'nullable|string|in:cash,gcash,bank_transfer',
                    ];

                    // Only admins can change court_id
                    if ($isAdmin && $request->has('court_id')) {
                        $validationRules['court_id'] = 'required|exists:courts,id';
                    }

                    $validator = Validator::make($request->all(), $validationRules);

                    if ($validator->fails()) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Validation errors',
                            'errors' => $validator->errors()
                        ], 422);
                    }

        // Determine which court_id to use for conflict checking
        $courtIdForConflict = $request->has('court_id') ? $request->court_id : $booking->court_id;

        // Check for time conflicts with other bookings
        $conflictingBooking = Booking::where('court_id', $courtIdForConflict)
            ->where('id', '!=', $id)
            ->whereIn('status', ['pending', 'approved', 'completed'])
            ->where(function ($query) use ($request) {
                $query->where(function ($q) use ($request) {
                    // Existing booking starts during new booking (exclusive boundaries)
                    $q->where('start_time', '>=', $request->start_time)
                      ->where('start_time', '<', $request->end_time);
                })->orWhere(function ($q) use ($request) {
                    // Existing booking ends during new booking (exclusive boundaries)
                    $q->where('end_time', '>', $request->start_time)
                      ->where('end_time', '<=', $request->end_time);
                })->orWhere(function ($q) use ($request) {
                    // Existing booking completely contains new booking
                    $q->where('start_time', '<=', $request->start_time)
                      ->where('end_time', '>=', $request->end_time);
                });
            })
            ->first();

        if ($conflictingBooking) {
            return response()->json([
                'success' => false,
                'message' => 'Time slot conflicts with existing booking'
            ], 409);
        }
    }
        $onyFields = [];

        if($request->status === "cancelled"){
            $onyFields = [
                'status',
                'notes',
            ];
        }else{
            $onyFields = [
                'start_time',
                'end_time',
                'total_price',
                'status',
                'notes',
                'frequency_type',
                'frequency_days',
                'frequency_times',
                'frequency_duration_months',
                'frequency_end_date',
                'payment_method'
            ];

            // Only admins can update court_id
            if ($isAdmin && $request->has('court_id')) {
                $onyFields[] = 'court_id';
            }
        }

        // Store old status before updating
        $oldStatus = $booking->status;

        $booking->update($request->only($onyFields));

        // Broadcast status change event if status changed
        if ($request->has('status') && $oldStatus !== $booking->status) {
            broadcast(new BookingStatusChanged($booking, $oldStatus, $booking->status))->toOthers();
        }

        return response()->json([
            'success' => true,
            'message' => 'Booking updated successfully',
            'data' => $booking->load(['user', 'court', 'sport'])
        ]);
    }

    /**
     * Upload proof of payment for a booking
     */
    public function uploadProofOfPayment(Request $request, $id)
    {
        $booking = Booking::find($id);

        if (!$booking) {
            return response()->json([
                'success' => false,
                'message' => 'Booking not found'
            ], 404);
        }

        // Check if user owns this booking, is the booking_for_user, or is admin
        $isBookingOwner = $booking->user_id === $request->user()->id;
        $isBookingForUser = $booking->booking_for_user_id === $request->user()->id;
        $isAdmin = $request->user()->role === 'admin';

        if (!$isBookingOwner && !$isBookingForUser && !$isAdmin) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to upload proof for this booking'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'proof_of_payment' => 'required|array', // Accept array of files
            'proof_of_payment.*' => 'required|image|mimes:jpeg,png,jpg,gif|max:5120', // 5MB max per file
            'payment_method' => 'nullable|string|in:cash,gcash,bank_transfer' // Optional, defaults to gcash
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $uploadedPaths = [];

            // Store multiple uploaded files
            foreach ($request->file('proof_of_payment') as $index => $file) {
                $filename = 'proof_' . $booking->id . '_' . time() . '_' . $index . '.' . $file->getClientOriginalExtension();
                $path = $file->storeAs('proofs', $filename, 'public');

                if (!$path) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Failed to store file at index ' . $index
                    ], 500);
                }

                $uploadedPaths[] = $path;
            }

            // Store as JSON array
            $proofOfPaymentJson = json_encode($uploadedPaths);

            // Determine payment method: use provided, existing, or default to gcash
            $paymentMethod = $request->payment_method;
            if (!$paymentMethod) {
                // Use existing payment method if not 'pending', otherwise default to 'gcash'
                $paymentMethod = ($booking->payment_method && $booking->payment_method !== 'pending')
                    ? $booking->payment_method
                    : 'gcash';
            }

            // Update booking with proof of payment path, payment method, and mark as paid
            $booking->update([
                'proof_of_payment' => $proofOfPaymentJson,
                'payment_method' => $paymentMethod,
                'payment_status' => 'paid',
                'paid_at' => now()
            ]);

            // Also update the cart transaction if it exists
            if ($booking->cart_transaction_id) {
                $cartTransaction = \App\Models\CartTransaction::find($booking->cart_transaction_id);
                if ($cartTransaction && $cartTransaction->payment_status !== 'paid') {
                    $cartTransaction->update([
                        'payment_status' => 'paid',
                        'payment_method' => $paymentMethod,
                        'proof_of_payment' => $proofOfPaymentJson,
                        'paid_at' => now()
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Proof of payment uploaded successfully. Booking is now marked as paid.',
                'data' => [
                    'proof_of_payment' => $proofOfPaymentJson,
                    'proof_of_payment_files' => $uploadedPaths,
                    'payment_method' => $paymentMethod,
                    'payment_status' => 'paid',
                    'paid_at' => $booking->paid_at
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
     * Serve proof of payment image
     */
    public function getProofOfPayment(Request $request, $id)
    {
        $booking = Booking::find($id);

        if (!$booking) {
            return response()->json([
                'success' => false,
                'message' => 'Booking not found'
            ], 404);
        }

        // Check if user is authorized to view this proof
        // Only the booking owner, booking_for_user, admin, or staff can view
        $user = $request->user();
        $isBookingOwner = $user->id === $booking->user_id;
        $isBookingForUser = $user->id === $booking->booking_for_user_id;
        $isAdmin = $user->role === 'admin';
        $isStaff = $user->role === 'staff';

        if (!$isBookingOwner && !$isBookingForUser && !$isAdmin && !$isStaff) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to view this proof of payment'
            ], 403);
        }

        if (!$booking->proof_of_payment) {
            return response()->json([
                'success' => false,
                'message' => 'No proof of payment found for this booking'
            ], 404);
        }

        // Try to decode as JSON array (multiple files)
        $proofFiles = json_decode($booking->proof_of_payment, true);

        // If it's not JSON (backward compatibility with single file), treat as single file
        if (json_last_error() !== JSON_ERROR_NONE) {
            $proofFiles = [$booking->proof_of_payment];
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
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $booking = Booking::find($id);

        if (!$booking) {
            return response()->json([
                'success' => false,
                'message' => 'Booking not found'
            ], 404);
        }

        $booking->delete();

        return response()->json([
            'success' => true,
            'message' => 'Booking deleted successfully'
        ]);
    }

    /**
     * Get available time slots for a court
     */
    public function availableSlots(Request $request, $courtId)
    {
        $validator = Validator::make($request->all(), [
            'date' => 'required|date|after_or_equal:today',
            'duration' => 'nullable|integer|min:1|max:12',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        // Get the court to access price
        $court = Court::find($courtId);
        if (!$court) {
            return response()->json([
                'success' => false,
                'message' => 'Court not found'
            ], 404);
        }

        $date = Carbon::parse($request->date);
        $duration = (int) ($request->duration ?? 1); // Default to 1 hour if not specified, cast to int
        $startOfDay = $date->copy()->startOfDay();
        $endOfDay = $date->copy()->endOfDay();

        // Get operating hours for the specific day
        $dayOfWeek = strtolower($date->englishDayOfWeek); // monday, tuesday, etc.
        $operatingHoursOpen = \App\Models\CompanySetting::get("operating_hours_{$dayOfWeek}_open", '08:00');
        $operatingHoursClose = \App\Models\CompanySetting::get("operating_hours_{$dayOfWeek}_close", '22:00');
        $isOperational = \App\Models\CompanySetting::get("operating_hours_{$dayOfWeek}_operational", '1') === '1';

        // Check if the facility is operational on this day
        if (!$isOperational) {
            return response()->json([
                'success' => true,
                'data' => [],
                'message' => 'Facility is closed on this day'
            ]);
        }

        // Get all non-cancelled bookings for this court on the specified date
        $bookings = Booking::with(['user', 'bookingForUser'])
            ->where('court_id', $courtId)
            ->whereIn('status', ['pending', 'approved', 'completed']) // Only consider active bookings
            ->whereBetween('start_time', [$startOfDay, $endOfDay])
            ->orderBy('start_time')
            ->get();

        // Get all pending and completed cart items for this court on the specified date
        // Exclude pending cart items that are older than 1 hour (unpaid)
        // BUT: Always include pending cart items created by admin users (even if no payment proof yet)
        $oneHourAgo = Carbon::now()->subHour();

        // Get cart items with various statuses
        // Include BOTH paid and unpaid pending items to show waitlist status
        $cartItems = \App\Models\CartItem::with([
                'cartTransaction.user',
                'cartTransaction.bookingForUser'
            ])
            ->where('court_id', $courtId)
            ->where('booking_date', $date->format('Y-m-d'))
            ->where('status', '!=', 'cancelled') // Exclude cancelled items
            ->whereHas('cartTransaction', function($transQuery) use ($oneHourAgo) {
                // Include approved transactions (definitely booked, must be paid)
                $transQuery->where(function($approvedQuery) {
                        $approvedQuery->where('approval_status', 'approved')
                            ->where('payment_status', 'paid');
                    })
                    // OR include pending approval with payment (paid, waiting approval - waitlist available)
                    ->orWhere(function($paidPendingQuery) use ($oneHourAgo) {
                        $paidPendingQuery->where('approval_status', 'pending')
                            ->where('payment_status', 'paid')
                            ->where(function($timeQuery) use ($oneHourAgo) {
                                $timeQuery->whereHas('user', function($userQuery) {
                                        $userQuery->where('role', 'admin');
                                    })
                                    ->orWhere('created_at', '>=', $oneHourAgo);
                            });
                    })
                    // OR include pending approval WITHOUT payment (unpaid - also waitlist available)
                    ->orWhere(function($unpaidPendingQuery) use ($oneHourAgo) {
                        $unpaidPendingQuery->where('approval_status', 'pending')
                            ->where('payment_status', 'unpaid')
                            ->where(function($timeQuery) use ($oneHourAgo) {
                                $timeQuery->whereHas('user', function($userQuery) {
                                        $userQuery->where('role', 'admin');
                                    })
                                    ->orWhere('created_at', '>=', $oneHourAgo);
                            });
                    });
            })
            ->orderBy('start_time')
            ->get();

        $availableSlots = [];

        // Parse operating hours and set start/end times
        [$openHour, $openMinute] = explode(':', $operatingHoursOpen);
        [$closeHour, $closeMinute] = explode(':', $operatingHoursClose);

        $currentTime = $startOfDay->copy()->setHour((int)$openHour)->setMinute((int)$openMinute)->setSecond(0);

        // Handle closing time of 00:00 as midnight (next day)
        // This allows bookings up to 23:00-00:00 (11 PM to midnight)
        if ($operatingHoursClose === '00:00') {
            $endTime = $startOfDay->copy()->addDay()->setHour(0)->setMinute(0)->setSecond(0);
        } else {
            $endTime = $startOfDay->copy()->setHour((int)$closeHour)->setMinute((int)$closeMinute)->setSecond(0);
        }

        // Always generate 1-hour increment slots
        while ($currentTime->lt($endTime)) {
            $slotEnd = $currentTime->copy()->addHour(); // Always 1-hour slots

            // Check if the slot end time exceeds the court's operating hours
            if ($slotEnd->gt($endTime)) {
                break;
            }

            // Check if this time slot conflicts with any existing booking
            $conflictingBooking = $bookings->first(function ($booking) use ($currentTime, $slotEnd) {
                // Parse booking times without timezone conversion since they're stored as local time strings
                $bookingStart = Carbon::createFromFormat('Y-m-d H:i:s', $booking->start_time);
                $bookingEnd = Carbon::createFromFormat('Y-m-d H:i:s', $booking->end_time);

                // Check for any overlap between the slot and the booking
                return $currentTime->lt($bookingEnd) && $slotEnd->gt($bookingStart);
            });

            // Check if this time slot conflicts with any cart item
            $conflictingCartItem = $cartItems->first(function ($cartItem) use ($currentTime, $slotEnd, $date) {
                // Create full datetime strings for cart items
                $cartStart = Carbon::createFromFormat('Y-m-d H:i:s', $date->format('Y-m-d') . ' ' . $cartItem->start_time);
                $cartEnd = Carbon::createFromFormat('Y-m-d H:i:s', $date->format('Y-m-d') . ' ' . $cartItem->end_time);

                // Check for any overlap between the slot and the cart item
                return $currentTime->lt($cartEnd) && $slotEnd->gt($cartStart);
            });

            if (!$conflictingBooking && !$conflictingCartItem) {
                // Regular available slot - use time-based pricing
                $price = $court->sport->calculatePriceForRange($currentTime, $slotEnd);
                $availableSlots[] = [
                    'start' => $currentTime->format('H:i'),
                    'end' => $slotEnd->format('H:i'),
                    'start_time' => $currentTime->format('Y-m-d H:i:s'),
                    'end_time' => $slotEnd->format('Y-m-d H:i:s'),
                    'formatted_time' => $currentTime->format('H:i') . ' - ' . $slotEnd->format('H:i'),
                    'duration_hours' => 1,
                    'price' => $price,
                    'available' => true,
                    'is_booked' => false
                ];
            } else {
                // Prioritize cart items over old booking records (new system uses cart items)
                // Show booking info for each 1-hour slot that is covered by the booking
                if ($conflictingCartItem) {
                    $cartStart = Carbon::createFromFormat('Y-m-d H:i:s', $date->format('Y-m-d') . ' ' . $conflictingCartItem->start_time);
                    $cartEnd = Carbon::createFromFormat('Y-m-d H:i:s', $date->format('Y-m-d') . ' ' . $conflictingCartItem->end_time);
                    $cartDuration = $cartEnd->diffInHours($cartStart);
                    // Use time-based pricing for this 1-hour slot
                    $cartPrice = $court->sport->calculatePriceForRange($currentTime, $slotEnd);

                    // Check approval status and payment status
                    $approvalStatus = $conflictingCartItem->cartTransaction->approval_status ?? 'pending';
                    $paymentStatus = $conflictingCartItem->cartTransaction->payment_status ?? 'unpaid';
                    $isApproved = $approvalStatus === 'approved';
                    $isPaid = $paymentStatus === 'paid';

                    // Determine the display type and status
                    $displayType = 'waitlist_available'; // Default for pending
                    $displayStatus = 'pending_approval';

                    if ($isApproved && $isPaid) {
                        // Approved and paid = Truly booked
                        $displayType = 'booked';
                        $displayStatus = 'approved';
                    } elseif (!$isApproved && $isPaid) {
                        // Pending but paid = Pending approval (paid waitlist)
                        $displayType = 'pending_approval';
                        $displayStatus = 'pending_approval';
                    } elseif (!$isApproved && !$isPaid) {
                        // Pending and unpaid = Waitlist available (unpaid)
                        $displayType = 'waitlist_available';
                        $displayStatus = 'waitlist_available';
                    }

                    // Get customer information from cart transaction
                    $transaction = $conflictingCartItem->cartTransaction;
                    $effectiveUser = $transaction->bookingForUser ?? $transaction->user;
                    $displayName = $transaction->booking_for_user_name ?? $effectiveUser->name ?? 'Unknown';
                    $createdByUser = $transaction->user;
                    $isAdminBooking = $createdByUser && in_array($createdByUser->role, ['admin', 'staff']);

                    // Show current 1-hour slot with booking info
                    $availableSlots[] = [
                        'start' => $currentTime->format('H:i'),
                        'end' => $slotEnd->format('H:i'),
                        'start_time' => $currentTime->format('Y-m-d H:i:s'),
                        'end_time' => $slotEnd->format('Y-m-d H:i:s'),
                        'formatted_time' => $currentTime->format('H:i') . ' - ' . $slotEnd->format('H:i'),
                        'duration_hours' => 1,
                        'price' => $cartPrice,
                        'available' => false,
                        'is_booked' => $isApproved && $isPaid, // Only true if approved AND paid
                        'is_pending_approval' => !$isApproved && $isPaid, // Paid but pending
                        'is_waitlist_available' => !($isApproved && $isPaid), // False only when fully booked (approved AND paid)
                        'is_unpaid' => !$isPaid, // Flag for unpaid bookings
                        'cart_item_id' => $conflictingCartItem->id,
                        'type' => $displayType,
                        'status' => $displayStatus,
                        'approval_status' => $approvalStatus,
                        'payment_status' => $paymentStatus,
                        // Customer information
                        'display_name' => $displayName,
                        'booking_for_user_name' => $transaction->booking_for_user_name,
                        'user_name' => $transaction->user->name ?? null,
                        'user_email' => $effectiveUser->email ?? null,
                        'user_phone' => $effectiveUser->phone ?? null,
                        'effective_user' => $effectiveUser ? [
                            'id' => $effectiveUser->id,
                            'name' => $effectiveUser->name,
                            'email' => $effectiveUser->email,
                            'phone' => $effectiveUser->phone ?? null
                        ] : null,
                        // Admin booking information
                        'is_admin_booking' => $isAdminBooking,
                        'created_by' => $createdByUser ? [
                            'id' => $createdByUser->id,
                            'name' => $createdByUser->name,
                            'role' => $createdByUser->role
                        ] : null,
                        // Additional info about the full booking
                        'full_booking_start' => $cartStart->format('H:i'),
                        'full_booking_end' => $cartEnd->format('H:i'),
                        'full_booking_duration' => $cartDuration
                    ];
                } elseif ($conflictingBooking) {
                    // Only show old booking records if there's no cart item for this time slot
                    $bookingStart = Carbon::createFromFormat('Y-m-d H:i:s', $conflictingBooking->start_time);
                    $bookingEnd = Carbon::createFromFormat('Y-m-d H:i:s', $conflictingBooking->end_time);
                    $bookingDuration = $bookingEnd->diffInHours($bookingStart);
                    // Use time-based pricing for this 1-hour slot
                    $bookingPrice = $court->sport->calculatePriceForRange($currentTime, $slotEnd);

                    // Check booking status and payment status
                    $bookingStatus = $conflictingBooking->status ?? 'pending';
                    $bookingPaymentStatus = $conflictingBooking->payment_status ?? 'unpaid';
                    $isBookingApproved = $bookingStatus === 'approved';
                    $isBookingPaid = $bookingPaymentStatus === 'paid';

                    // Determine display type
                    $bookingDisplayType = 'waitlist_available';
                    if ($isBookingApproved && $isBookingPaid) {
                        $bookingDisplayType = 'booking';
                    } elseif (!$isBookingApproved && $isBookingPaid) {
                        $bookingDisplayType = 'pending_approval';
                    }

                    // Get customer information from booking
                    $bookingEffectiveUser = $conflictingBooking->bookingForUser ?? $conflictingBooking->user;
                    $bookingDisplayName = $conflictingBooking->booking_for_user_name ?? $bookingEffectiveUser->name ?? 'Unknown';
                    $bookingCreatedByUser = $conflictingBooking->user;
                    $isBookingAdminBooking = $bookingCreatedByUser && in_array($bookingCreatedByUser->role, ['admin', 'staff']);

                    // Show current 1-hour slot with booking info
                    $availableSlots[] = [
                        'start' => $currentTime->format('H:i'),
                        'end' => $slotEnd->format('H:i'),
                        'start_time' => $currentTime->format('Y-m-d H:i:s'),
                        'end_time' => $slotEnd->format('Y-m-d H:i:s'),
                        'formatted_time' => $currentTime->format('H:i') . ' - ' . $slotEnd->format('H:i'),
                        'duration_hours' => 1,
                        'price' => $bookingPrice,
                        'available' => false,
                        'is_booked' => $isBookingApproved && $isBookingPaid,
                        'is_pending_approval' => !$isBookingApproved && $isBookingPaid,
                        'is_waitlist_available' => !($isBookingApproved && $isBookingPaid), // False only when fully booked
                        'is_unpaid' => !$isBookingPaid,
                        'booking_id' => $conflictingBooking->id,
                        'type' => $bookingDisplayType,
                        'status' => $bookingStatus,
                        'payment_status' => $bookingPaymentStatus,
                        // Customer information
                        'display_name' => $bookingDisplayName,
                        'booking_for_user_name' => $conflictingBooking->booking_for_user_name,
                        'user_name' => $conflictingBooking->user->name ?? null,
                        'user_email' => $bookingEffectiveUser->email ?? null,
                        'user_phone' => $bookingEffectiveUser->phone ?? null,
                        'effective_user' => $bookingEffectiveUser ? [
                            'id' => $bookingEffectiveUser->id,
                            'name' => $bookingEffectiveUser->name,
                            'email' => $bookingEffectiveUser->email,
                            'phone' => $bookingEffectiveUser->phone ?? null
                        ] : null,
                        // Admin booking information
                        'is_admin_booking' => $isBookingAdminBooking,
                        'created_by' => $bookingCreatedByUser ? [
                            'id' => $bookingCreatedByUser->id,
                            'name' => $bookingCreatedByUser->name,
                            'role' => $bookingCreatedByUser->role
                        ] : null,
                        // Additional info about the full booking
                        'full_booking_start' => $bookingStart->format('H:i'),
                        'full_booking_end' => $bookingEnd->format('H:i'),
                        'full_booking_duration' => $bookingDuration
                    ];
                }
            }

            $currentTime->addHour();
        }

        return response()->json([
            'success' => true,
            'data' => $availableSlots
        ]);
    }

    /**
     * Get all pending bookings for admin approval
     */
    public function pendingBookings()
    {
        $bookings = Booking::with(['user', 'court', 'sport', 'court.images', 'cartTransaction.cartItems.court', 'cartTransaction.cartItems.sport'])
            ->where('status', 'pending')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $bookings
        ]);
    }

    /**
     * Approve a booking
     */
    public function approveBooking(Request $request, string $id)
    {
        $booking = Booking::find($id);

        if (!$booking) {
            return response()->json([
                'success' => false,
                'message' => 'Booking not found'
            ], 404);
        }

        if ($booking->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending bookings can be approved'
            ], 400);
        }

        $oldStatus = $booking->status;
        $booking->update(['status' => 'approved']);

        // Generate QR code for approved booking
        $booking->generateQrCode();

        // Load relationships for email
        $booking->load(['user', 'bookingForUser', 'court.sport']);

        // Send approval email to the user or bookingForUser (with debug logging)
        $emailDebug = [
            'attempted' => false,
            'sent' => false,
            'recipient' => null,
            'error' => null,
        ];

        try {
            // Prefer Booking For user's email if available; otherwise fallback to creator's email
            $recipientEmail = $booking->booking_for_user_id && $booking->bookingForUser && $booking->bookingForUser->email
                ? $booking->bookingForUser->email
                : ($booking->user->email ?? null);

            $emailDebug['recipient'] = $recipientEmail;

            if ($recipientEmail) {
                $emailDebug['attempted'] = true;
                Log::info('booking_approval_email.begin', [
                    'booking_id' => $booking->id,
                    'recipient' => $recipientEmail,
                ]);
                Mail::to($recipientEmail)->send(new BookingApprovalMail($booking));
                $emailDebug['sent'] = true;
                Log::info('booking_approval_email.success', [
                    'booking_id' => $booking->id,
                    'recipient' => $recipientEmail,
                ]);
            } else {
                Log::warning('booking_approval_email.skipped_no_recipient', [
                    'booking_id' => $booking->id,
                ]);
            }
        } catch (\Exception $e) {
            // Don't fail the approval if email fails; capture debug info
            $emailDebug['error'] = $e->getMessage();
            Log::error('booking_approval_email.error', [
                'booking_id' => $booking->id,
                'recipient' => $emailDebug['recipient'],
                'error' => $emailDebug['error'],
            ]);
        }

        // Broadcast status change event
        broadcast(new BookingStatusChanged($booking, $oldStatus, $booking->status))->toOthers();

        return response()->json([
            'success' => true,
            'message' => 'Booking approved successfully',
            'data' => $booking,
            'email_debug' => $emailDebug,
        ]);
    }

    /**
     * Reject a booking
     */
    public function rejectBooking(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        $booking = Booking::find($id);

        if (!$booking) {
            return response()->json([
                'success' => false,
                'message' => 'Booking not found'
            ], 404);
        }

        $isAdmin = $request->user()->isAdmin();

        // Allow admins to reject approved bookings, regular users can only reject pending bookings
        if ($booking->status !== 'pending' && $booking->status !== 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending or approved bookings can be rejected'
            ], 400);
        }

        // If the booking is approved, only admins can reject it
        if ($booking->status === 'approved' && !$isAdmin) {
            return response()->json([
                'success' => false,
                'message' => 'Only administrators can reject approved bookings'
            ], 403);
        }

        $oldStatus = $booking->status;

        $booking->update([
            'status' => 'rejected',
            'notes' => $request->reason ? $booking->notes . "\n\nRejection reason: " . $request->reason : $booking->notes
        ]);

        // Broadcast status change event
        broadcast(new BookingStatusChanged($booking, $oldStatus, $booking->status))->toOthers();

        return response()->json([
            'success' => true,
            'message' => 'Booking rejected successfully',
            'data' => $booking->load(['user', 'court', 'sport'])
        ]);
    }

    /**
     * Get booking statistics for admin dashboard
     */
    public function getStats()
    {
        // Get today's date
        $today = Carbon::today()->toDateString();

        // Calculate total hours booked for today across all courts using CartItem
        // 'completed' = checked out and booked
        // 'pending' = temporarily in cart (we can include or exclude these)
        $todayCartItems = \App\Models\CartItem::whereDate('booking_date', $today)
            ->where('status', 'completed')
            ->get();

        $totalHours = 0;
        foreach ($todayCartItems as $item) {
            // Parse times with the booking date to handle midnight crossings correctly
            $bookingDate = Carbon::parse($item->booking_date);
            $startTime = Carbon::parse($bookingDate->format('Y-m-d') . ' ' . $item->start_time);
            $endTime = Carbon::parse($bookingDate->format('Y-m-d') . ' ' . $item->end_time);

            // If end time is before or equal to start time, it crosses midnight (next day)
            if ($endTime->lte($startTime)) {
                $endTime->addDay();
            }

            $totalHours += $endTime->diffInHours($startTime, true); // true for floating point hours
        }

        // Calculate revenue from cart transactions
        $totalRevenue = \App\Models\CartTransaction::whereIn('approval_status', ['approved', 'paid'])
            ->sum('total_price');

        $pendingRevenue = \App\Models\CartTransaction::where('approval_status', 'pending')
            ->sum('total_price');

        // Get transaction counts
        $totalTransactions = \App\Models\CartTransaction::count();
        $pendingTransactions = \App\Models\CartTransaction::where('approval_status', 'pending')->count();
        $approvedTransactions = \App\Models\CartTransaction::whereIn('approval_status', ['approved', 'paid'])->count();
        $rejectedTransactions = \App\Models\CartTransaction::where('approval_status', 'rejected')->count();

        // Get total users count
        $totalUsers = \App\Models\User::count();

        $stats = [
            'total_bookings' => $totalTransactions,
            'pending_bookings' => $pendingTransactions,
            'approved_bookings' => $approvedTransactions,
            'rejected_bookings' => $rejectedTransactions,
            'cancelled_bookings' => \App\Models\CartTransaction::where('status', 'cancelled')->count(),
            'completed_bookings' => \App\Models\CartItem::where('status', 'paid')->distinct('cart_transaction_id')->count(),
            'total_revenue' => $totalRevenue ?? 0,
            'pending_revenue' => $pendingRevenue ?? 0,
            'total_hours' => round($totalHours, 2),
            'total_users' => $totalUsers
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Validate QR code and check in booking
     */
    public function validateQrCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'qr_code' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'QR code is required',
                'errors' => $validator->errors()
            ], 422);
        }

        $booking = Booking::where('qr_code', $request->qr_code)->first();

        if (!$booking) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid QR code'
            ], 404);
        }

        // Load relationships
        $booking->load(['user', 'court.sport']);

        if (!$booking->canCheckIn()) {
            $message = 'Cannot check in: ';
            if ($booking->status !== Booking::STATUS_APPROVED) {
                $message .= 'Booking is not approved';
            } elseif ($booking->attendance_scan_count >= $booking->number_of_players) {
                $message .= 'All players (' . $booking->number_of_players . ') have already been scanned';
            } elseif (!now()->between($booking->start_time, $booking->end_time)) {
                $message .= 'Not within booking time window';
            }

            return response()->json([
                'success' => false,
                'message' => $message,
                'booking' => $booking
            ], 400);
        }

        // Check in the booking
        $oldStatus = $booking->status;
        if ($booking->checkIn()) {
            // Broadcast status change event if status changed
            if ($oldStatus !== $booking->status) {
                broadcast(new BookingStatusChanged($booking, $oldStatus, $booking->status))->toOthers();
            }

            $message = 'Successfully checked in (Player ' . $booking->attendance_scan_count . ' of ' . $booking->number_of_players . ')';

            if ($booking->hasAllPlayersScanned()) {
                $message .= ' - All players scanned!';
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $booking
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Failed to check in'
        ], 500);
    }

    /**
     * Get QR code for a booking
     */
    public function getQrCode(Request $request, $id)
    {
        $booking = Booking::find($id);

        if (!$booking) {
            return response()->json([
                'success' => false,
                'message' => 'Booking not found'
            ], 404);
        }

        // Check if user owns this booking, is the booking_for_user, or is admin
        $isBookingOwner = $booking->user_id === $request->user()->id;
        $isBookingForUser = $booking->booking_for_user_id === $request->user()->id;
        $isAdmin = $request->user()->isAdmin();

        if (!$isBookingOwner && !$isBookingForUser && !$isAdmin) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to access this booking'
            ], 403);
        }

        if ($booking->status !== Booking::STATUS_APPROVED) {
            return response()->json([
                'success' => false,
                'message' => 'QR code is only available for approved bookings'
            ], 400);
        }

        $qrCode = $booking->generateQrCode();

        return response()->json([
            'success' => true,
            'data' => [
                'qr_code' => $qrCode,
                'booking' => $booking->load(['user', 'court.sport'])
            ]
        ]);
    }

    /**
     * Get approved bookings for staff to view
     */
    public function getApprovedBookings(Request $request)
    {
        $bookings = Booking::with(['user', 'court', 'sport', 'court.images', 'cartTransaction.cartItems.court', 'cartTransaction.cartItems.sport'])
            ->where('status', Booking::STATUS_APPROVED)
            ->orderBy('start_time', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $bookings
        ]);
    }

    /**
     * Update booking attendance status
     */
    public function updateAttendanceStatus(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'attendance_status' => 'required|string|in:not_set,showed_up,no_show',
            'players_attended' => 'nullable|integer|min:0|max:100'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        $booking = Booking::find($id);

        if (!$booking) {
            return response()->json([
                'success' => false,
                'message' => 'Booking not found'
            ], 404);
        }

        // Only admin/staff can update attendance status
        if (!$request->user()->isAdmin() && !$request->user()->isStaff()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to update attendance status'
            ], 403);
        }

        // Validate players_attended doesn't exceed number_of_players
        if ($request->has('players_attended') && $request->players_attended > $booking->number_of_players) {
            return response()->json([
                'success' => false,
                'message' => 'Players attended cannot exceed the number of players booked (' . $booking->number_of_players . ')'
            ], 422);
        }

        // Prevent marking as showed_up if payment has not been completed
        if ($request->attendance_status === 'showed_up' && $booking->payment_status !== 'paid') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot mark as showed up: Payment has not been completed for this booking'
            ], 422);
        }

        $updateData = [
            'attendance_status' => $request->attendance_status
        ];

        // If marking as showed_up and players_attended is provided, update it
        if ($request->attendance_status === 'showed_up' && $request->has('players_attended')) {
            $updateData['players_attended'] = $request->players_attended;

            // If not already checked in, mark as checked in
            if (!$booking->checked_in_at) {
                $updateData['checked_in_at'] = now();
                $updateData['status'] = Booking::STATUS_CHECKED_IN;
            }
        } elseif ($request->attendance_status === 'no_show') {
            $updateData['players_attended'] = 0;
        }

        $booking->update($updateData);

        return response()->json([
            'success' => true,
            'message' => 'Attendance status updated successfully',
            'data' => $booking->load(['user', 'court', 'sport'])
        ]);
    }

    /**
     * Resend confirmation email for an approved booking
     */
    public function resendConfirmationEmail(Request $request, string $id)
    {
        $booking = Booking::with(['user', 'court.sport'])->find($id);

        if (!$booking) {
            return response()->json([
                'success' => false,
                'message' => 'Booking not found'
            ], 404);
        }

        // Check if user owns this booking, is the booking_for_user, is admin, or is staff
        $isBookingOwner = $booking->user_id === $request->user()->id;
        $isBookingForUser = $booking->booking_for_user_id === $request->user()->id;
        $isAdmin = $request->user()->isAdmin();
        $isStaff = $request->user()->isStaff();

        if (!$isBookingOwner && !$isBookingForUser && !$isAdmin && !$isStaff) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to resend confirmation email for this booking'
            ], 403);
        }

        // Only approved bookings should receive confirmation emails
        if ($booking->status !== Booking::STATUS_APPROVED) {
            return response()->json([
                'success' => false,
                'message' => 'Confirmation email can only be sent for approved bookings'
            ], 400);
        }

        // Determine which email to send to
        $recipientEmail = $booking->user->email;
        if ($booking->booking_for_user_id && $booking->bookingForUser) {
            $recipientEmail = $booking->bookingForUser->email;
        }

        // Send confirmation email
        try {
            Mail::to($recipientEmail)->send(new BookingApprovalMail($booking));

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
}
