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
        $query = Booking::with(['user', 'bookingForUser', 'court.sport', 'court.images', 'cartTransaction.cartItems.court']);

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
            'start_time' => 'required|date',
            'end_time' => 'required|date|after:start_time',
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
            $hours = $endTime->diffInHours($startTime);
            $totalPrice = $court->sport->price_per_hour * $hours;
        }

        $booking = Booking::create([
            'user_id' => $request->user()->id,
            'court_id' => $court->id,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'total_price' => $totalPrice,
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
            'gcash_reference' => $request->gcash_reference,
            'proof_of_payment' => $request->proof_of_payment,
            'paid_at' => $request->payment_status === 'paid' ? now() : null,
        ]);

        // Broadcast booking created event
        broadcast(new BookingCreated($booking))->toOthers();

        return response()->json([
            'success' => true,
            'message' => 'Booking created successfully',
            'data' => $booking->load(['user', 'court.sport'])
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $booking = Booking::with(['user', 'bookingForUser', 'court.sport', 'court.images', 'cartTransaction.cartItems.court'])->find($id);

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

        // Check if user owns this booking or is admin
        if ($booking->user_id !== $request->user()->id && !$request->user()->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to update this booking'
            ], 403);
        }
            if($request->status !== "cancelled"){
                    $validator = Validator::make($request->all(), [
                        // 'court_id' => 'required|exists:courts,id',
                        'start_time' => 'required|date|after:now',
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
                    ]);

                    if ($validator->fails()) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Validation errors',
                            'errors' => $validator->errors()
                        ], 422);
                    }

        // Check for time conflicts with other bookings
        $conflictingBooking = Booking::where('court_id', $request->court_id)
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
            'data' => $booking->load(['user', 'court.sport'])
        ]);
    }

    /**
     * Upload proof of payment for a booking
     */
    public function uploadProofOfPayment(Request $request, $id)
    {
        Log::info('Upload proof of payment request received', [
            'booking_id' => $id,
            'user_id' => $request->user()->id,
            'has_file' => $request->hasFile('proof_of_payment'),
            'payment_method' => $request->input('payment_method'),
            'all_input' => $request->all()
        ]);

        $booking = Booking::find($id);

        if (!$booking) {
            return response()->json([
                'success' => false,
                'message' => 'Booking not found'
            ], 404);
        }

        // Check if user owns this booking or is admin
        if ($booking->user_id !== $request->user()->id && $request->user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to upload proof for this booking'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'proof_of_payment' => 'required|image|mimes:jpeg,png,jpg,gif|max:5120', // 5MB max
            'payment_method' => 'required|string|in:cash,gcash,bank_transfer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Store the uploaded file
            $file = $request->file('proof_of_payment');

            if (!$file) {
                return response()->json([
                    'success' => false,
                    'message' => 'No file uploaded'
                ], 400);
            }

            $filename = 'proof_' . $booking->id . '_' . time() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('proofs', $filename, 'public');

            if (!$path) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to store file'
                ], 500);
            }

            // Update booking with proof of payment path and payment method
            $booking->update([
                'proof_of_payment' => $path,
                'payment_method' => $request->payment_method
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Proof of payment uploaded successfully',
                'data' => [
                    'proof_of_payment' => $path,
                    'payment_method' => $request->payment_method
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Upload proof of payment error: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

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
        // Only the booking owner, admin, or staff can view
        $user = $request->user();
        if ($user->id !== $booking->user_id &&
            $user->role !== 'admin' &&
            $user->role !== 'staff') {
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

        // Get the file path from storage
        $path = storage_path('app/public/' . $booking->proof_of_payment);

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

        // Get all non-cancelled bookings for this court on the specified date
        $bookings = Booking::where('court_id', $courtId)
            ->whereIn('status', ['pending', 'approved', 'completed']) // Only consider active bookings
            ->whereBetween('start_time', [$startOfDay, $endOfDay])
            ->orderBy('start_time')
            ->get();

        // Get all pending and completed cart items for this court on the specified date
        // Exclude pending cart items that are older than 1 hour (unpaid)
        $oneHourAgo = Carbon::now()->subHour();

        $cartItems = \App\Models\CartItem::with('cartTransaction')
            ->where('court_id', $courtId)
            ->where('booking_date', $date->format('Y-m-d'))
            ->where(function($query) use ($oneHourAgo) {
                // Include completed (paid) cart items regardless of age
                $query->where('status', 'completed')
                    // Include pending cart items only if they are less than 1 hour old
                    ->orWhere(function($subQuery) use ($oneHourAgo) {
                        $subQuery->where('status', 'pending')
                            ->whereHas('cartTransaction', function($transQuery) use ($oneHourAgo) {
                                $transQuery->where('created_at', '>=', $oneHourAgo);
                            });
                    });
            })
            ->orderBy('start_time')
            ->get();

        $availableSlots = [];
        $currentTime = $startOfDay->copy()->setHour(6); // Start from 6 AM
        $endTime = $endOfDay->copy()->setHour(22); // End at 10 PM
        $addedBookingIds = []; // Track which bookings we've already added
        $addedCartItemIds = []; // Track which cart items we've already added

        while ($currentTime->lt($endTime)) {
            $slotEnd = $currentTime->copy()->addHours($duration);

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
                // Regular available slot
                $price = $court->sport->price_per_hour * $duration;
                $availableSlots[] = [
                    'start' => $currentTime->format('H:i'),
                    'end' => $slotEnd->format('H:i'),
                    'start_time' => $currentTime->format('Y-m-d H:i:s'),
                    'end_time' => $slotEnd->format('Y-m-d H:i:s'),
                    'formatted_time' => $currentTime->format('H:i') . ' - ' . $slotEnd->format('H:i'),
                    'duration_hours' => $duration,
                    'price' => $price,
                    'available' => true,
                    'is_booked' => false
                ];
            } else {
                // Handle conflicting booking
                if ($conflictingBooking && !in_array($conflictingBooking->id, $addedBookingIds)) {
                    $bookingStart = Carbon::createFromFormat('Y-m-d H:i:s', $conflictingBooking->start_time);
                    $bookingEnd = Carbon::createFromFormat('Y-m-d H:i:s', $conflictingBooking->end_time);
                    $bookingDuration = $bookingEnd->diffInHours($bookingStart);
                    $bookingPrice = $court->sport->price_per_hour * $bookingDuration;

                    $availableSlots[] = [
                        'start' => $bookingStart->format('H:i'),
                        'end' => $bookingEnd->format('H:i'),
                        'start_time' => $conflictingBooking->start_time,
                        'end_time' => $conflictingBooking->end_time,
                        'formatted_time' => $bookingStart->format('H:i') . ' - ' . $bookingEnd->format('H:i'),
                        'duration_hours' => $bookingDuration,
                        'price' => $bookingPrice,
                        'available' => false,
                        'is_booked' => true,
                        'booking_id' => $conflictingBooking->id,
                        'type' => 'booking'
                    ];

                    $addedBookingIds[] = $conflictingBooking->id;
                }

                // Handle conflicting cart item
                if ($conflictingCartItem && !in_array($conflictingCartItem->id, $addedCartItemIds)) {
                    $cartStart = Carbon::createFromFormat('Y-m-d H:i:s', $date->format('Y-m-d') . ' ' . $conflictingCartItem->start_time);
                    $cartEnd = Carbon::createFromFormat('Y-m-d H:i:s', $date->format('Y-m-d') . ' ' . $conflictingCartItem->end_time);
                    $cartDuration = $cartEnd->diffInHours($cartStart);
                    $cartPrice = $court->sport->price_per_hour * $cartDuration;

                    $availableSlots[] = [
                        'start' => $cartStart->format('H:i'),
                        'end' => $cartEnd->format('H:i'),
                        'start_time' => $date->format('Y-m-d') . ' ' . $conflictingCartItem->start_time,
                        'end_time' => $date->format('Y-m-d') . ' ' . $conflictingCartItem->end_time,
                        'formatted_time' => $cartStart->format('H:i') . ' - ' . $cartEnd->format('H:i'),
                        'duration_hours' => $cartDuration,
                        'price' => $cartPrice,
                        'available' => false,
                        'is_booked' => true,
                        'cart_item_id' => $conflictingCartItem->id,
                        'type' => $conflictingCartItem->status === 'completed' ? 'paid' : 'in_cart',
                        'status' => $conflictingCartItem->status
                    ];

                    $addedCartItemIds[] = $conflictingCartItem->id;
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
        $bookings = Booking::with(['user', 'court.sport', 'court.images', 'cartTransaction.cartItems.court'])
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
        $booking->load(['user', 'court.sport']);

        // Send approval email to the user
        try {
            Mail::to($booking->user->email)->send(new BookingApprovalMail($booking));
            Log::info('Booking approval email sent successfully', [
                'booking_id' => $booking->id,
                'user_email' => $booking->user->email
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send booking approval email', [
                'booking_id' => $booking->id,
                'user_email' => $booking->user->email,
                'error' => $e->getMessage()
            ]);
            // Don't fail the approval if email fails
        }

        // Broadcast status change event
        broadcast(new BookingStatusChanged($booking, $oldStatus, $booking->status))->toOthers();

        return response()->json([
            'success' => true,
            'message' => 'Booking approved successfully',
            'data' => $booking
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

        if ($booking->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending bookings can be rejected'
            ], 400);
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
            'data' => $booking->load(['user', 'court.sport'])
        ]);
    }

    /**
     * Get booking statistics for admin dashboard
     */
    public function getStats()
    {
        $stats = [
            'total_bookings' => Booking::count(),
            'pending_bookings' => Booking::where('status', 'pending')->count(),
            'approved_bookings' => Booking::where('status', 'approved')->count(),
            'rejected_bookings' => Booking::where('status', 'rejected')->count(),
            'cancelled_bookings' => Booking::where('status', 'cancelled')->count(),
            'completed_bookings' => Booking::where('status', 'completed')->count(),
            'total_revenue' => Booking::where('status', 'approved')->sum('total_price'),
            'pending_revenue' => Booking::where('status', 'pending')->sum('total_price')
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
            } elseif ($booking->checked_in_at) {
                $message .= 'Already checked in';
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
            // Broadcast status change event
            broadcast(new BookingStatusChanged($booking, $oldStatus, $booking->status))->toOthers();

            return response()->json([
                'success' => true,
                'message' => 'Successfully checked in',
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

        // Check if user owns this booking or is admin
        if ($booking->user_id !== $request->user()->id && !$request->user()->isAdmin()) {
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
        $bookings = Booking::with(['user', 'court.sport', 'court.images', 'cartTransaction.cartItems.court'])
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
            'attendance_status' => 'required|string|in:not_set,showed_up,no_show'
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

        // Only admin can update attendance status
        if (!$request->user()->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to update attendance status'
            ], 403);
        }

        $booking->update([
            'attendance_status' => $request->attendance_status
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Attendance status updated successfully',
            'data' => $booking->load(['user', 'court.sport'])
        ]);
    }
}
