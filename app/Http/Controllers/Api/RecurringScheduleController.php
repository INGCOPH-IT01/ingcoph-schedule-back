<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RecurringSchedule;
use App\Models\Booking;
use App\Models\Court;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class RecurringScheduleController extends Controller
{
    /**
     * Get all recurring schedules for the authenticated user
     */
    public function index(): JsonResponse
    {
        $schedules = RecurringSchedule::with(['court', 'court.sport'])
            ->where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $schedules
        ]);
    }

    /**
     * Get all recurring schedules (admin only)
     */
    public function adminIndex(): JsonResponse
    {
        $schedules = RecurringSchedule::with(['user', 'court', 'court.sport'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $schedules
        ]);
    }

    /**
     * Create a new recurring schedule
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'court_id' => 'required|exists:courts,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_time' => 'required_unless:recurrence_type,weekly_multiple_times,yearly_multiple_times|date_format:H:i',
            'end_time' => 'required_unless:recurrence_type,weekly_multiple_times,yearly_multiple_times|date_format:H:i|after:start_time',
            'recurrence_type' => 'required|in:daily,weekly,weekly_multiple_times,monthly,yearly,yearly_multiple_times',
            'recurrence_days' => 'required_if:recurrence_type,weekly|array|min:1',
            'recurrence_days.*' => 'integer|min:0|max:6',
            'day_specific_times' => 'required_if:recurrence_type,weekly_multiple_times,yearly_multiple_times|array|min:1',
            'day_specific_times.*.day' => 'required|integer|min:0|max:6',
            'day_specific_times.*.start_time' => 'required|date_format:H:i',
            'day_specific_times.*.end_time' => 'required|date_format:H:i|after:day_specific_times.*.start_time',
            'recurrence_interval' => 'integer|min:1|max:52',
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'nullable|date|after:start_date',
            'max_occurrences' => 'nullable|integer|min:1|max:365',
            'auto_approve' => 'boolean',
            'notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();
        $data['user_id'] = Auth::id();
        
        // Calculate duration based on recurrence type
        if (in_array($data['recurrence_type'], ['weekly_multiple_times', 'yearly_multiple_times'])) {
            // For multiple times, set duration to 0 (will be calculated per day)
            $data['duration_hours'] = 0;
        } else {
            $startTime = Carbon::parse($data['start_time']);
            $endTime = Carbon::parse($data['end_time']);
            $data['duration_hours'] = $endTime->diffInHours($startTime);
        }

        $schedule = RecurringSchedule::create($data);

        // Always generate bookings for the entire schedule duration
        $this->generateBookingsForEntireSchedule($schedule);

        return response()->json([
            'success' => true,
            'message' => 'Recurring schedule created successfully',
            'data' => $schedule->load(['court', 'court.sport'])
        ], 201);
    }

    /**
     * Get a specific recurring schedule
     */
    public function show(RecurringSchedule $recurringSchedule): JsonResponse
    {
        // Check if user owns this schedule or is admin
        if ($recurringSchedule->user_id !== Auth::id() && Auth::user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $recurringSchedule->load(['user', 'court', 'court.sport'])
        ]);
    }

    /**
     * Update a recurring schedule
     */
    public function update(Request $request, RecurringSchedule $recurringSchedule): JsonResponse
    {
        // Check if user owns this schedule or is admin
        if ($recurringSchedule->user_id !== Auth::id() && Auth::user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'start_time' => 'sometimes|date_format:H:i',
            'end_time' => 'sometimes|date_format:H:i|after:start_time',
            'recurrence_type' => 'sometimes|in:daily,weekly,weekly_multiple_times,monthly,yearly,yearly_multiple_times',
            'recurrence_days' => 'sometimes|array|min:1',
            'recurrence_days.*' => 'integer|min:0|max:6',
            'day_specific_times' => 'sometimes|array|min:1',
            'day_specific_times.*.day' => 'required|integer|min:0|max:6',
            'day_specific_times.*.start_time' => 'required|date_format:H:i',
            'day_specific_times.*.end_time' => 'required|date_format:H:i|after:day_specific_times.*.start_time',
            'recurrence_interval' => 'sometimes|integer|min:1|max:52',
            'start_date' => 'sometimes|date',
            'end_date' => 'nullable|date|after:start_date',
            'max_occurrences' => 'nullable|integer|min:1|max:365',
            'is_active' => 'sometimes|boolean',
            'auto_approve' => 'sometimes|boolean',
            'notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();

        // Recalculate duration if times are updated
        if (isset($data['start_time']) || isset($data['end_time']) || isset($data['recurrence_type'])) {
            $recurrenceType = $data['recurrence_type'] ?? $recurringSchedule->recurrence_type;
            
            if (in_array($recurrenceType, ['weekly_multiple_times', 'yearly_multiple_times'])) {
                $data['duration_hours'] = 0; // Will be calculated per day
            } else {
                $startTime = Carbon::parse($data['start_time'] ?? $recurringSchedule->start_time);
                $endTime = Carbon::parse($data['end_time'] ?? $recurringSchedule->end_time);
                $data['duration_hours'] = $endTime->diffInHours($startTime);
            }
        }

        $recurringSchedule->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Recurring schedule updated successfully',
            'data' => $recurringSchedule->load(['court', 'court.sport'])
        ]);
    }

    /**
     * Delete a recurring schedule
     */
    public function destroy(RecurringSchedule $recurringSchedule): JsonResponse
    {
        // Check if user owns this schedule or is admin
        if ($recurringSchedule->user_id !== Auth::id() && Auth::user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        // Cancel all future bookings from this schedule
        Booking::where('recurring_schedule_id', $recurringSchedule->id)
            ->where('start_time', '>', now())
            ->where('status', '!=', 'cancelled')
            ->update(['status' => 'cancelled']);

        $recurringSchedule->delete();

        return response()->json([
            'success' => true,
            'message' => 'Recurring schedule deleted successfully'
        ]);
    }

    /**
     * Generate bookings for a schedule
     */
    public function generateBookings(Request $request, RecurringSchedule $recurringSchedule): JsonResponse
    {
        // Check if user owns this schedule or is admin
        if ($recurringSchedule->user_id !== Auth::id() && Auth::user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'months' => 'required|integer|min:1|max:12'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $months = $validator->validated()['months'];
        $this->generateBookingsForSchedule($recurringSchedule, $months);

        return response()->json([
            'success' => true,
            'message' => "Generated bookings for the next {$months} months"
        ]);
    }

    /**
     * Helper method to generate bookings for a schedule
     */
    private function generateBookingsForSchedule(RecurringSchedule $schedule, int $months): void
    {
        $startDate = Carbon::now();
        $endDate = $startDate->copy()->addMonths($months);

        $bookings = $schedule->generateBookingsForDateRange($startDate, $endDate);

        foreach ($bookings as $bookingData) {
            // Check if booking already exists
            $existingBooking = Booking::where('user_id', $bookingData['user_id'])
                ->where('court_id', $bookingData['court_id'])
                ->where('start_time', $bookingData['start_time'])
                ->first();

            if (!$existingBooking) {
                Booking::create($bookingData);
            }
        }
    }

    /**
     * Generate bookings for the entire schedule duration
     */
    private function generateBookingsForEntireSchedule(RecurringSchedule $schedule): void
    {
        $startDate = Carbon::parse($schedule->start_date);
        
        // Determine end date
        if ($schedule->end_date) {
            $endDate = Carbon::parse($schedule->end_date);
        } elseif ($schedule->max_occurrences) {
            // Calculate end date based on max occurrences
            $endDate = $this->calculateEndDateFromOccurrences($schedule);
        } else {
            // Default to 1 year from start date
            $endDate = $startDate->copy()->addYear();
        }

        $bookings = $schedule->generateBookingsForDateRange($startDate, $endDate);

        foreach ($bookings as $bookingData) {
            // Check if booking already exists
            $existingBooking = Booking::where('user_id', $bookingData['user_id'])
                ->where('court_id', $bookingData['court_id'])
                ->where('start_time', $bookingData['start_time'])
                ->first();

            if (!$existingBooking) {
                Booking::create($bookingData);
            }
        }
    }

    /**
     * Calculate end date based on max occurrences
     */
    private function calculateEndDateFromOccurrences(RecurringSchedule $schedule): Carbon
    {
        $startDate = Carbon::parse($schedule->start_date);
        $occurrences = 0;
        $current = $startDate->copy();
        $maxOccurrences = $schedule->max_occurrences;

        while ($occurrences < $maxOccurrences && $current->lte($startDate->copy()->addYear())) {
            if ($schedule->isActiveOnDate($current)) {
                $occurrences++;
            }
            $current->addDay();
        }

        return $current->copy()->subDay();
    }
}
