<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class HolidayController extends Controller
{
    /**
     * Get all holidays
     */
    public function index(Request $request)
    {
        $year = $request->query('year', Carbon::now()->year);

        $holidays = Holiday::orderBy('date', 'asc')->get();

        return response()->json([
            'success' => true,
            'data' => $holidays
        ]);
    }

    /**
     * Get holidays for a specific year (includes recurring holidays)
     */
    public function getForYear(Request $request, $year)
    {
        $holidayDates = Holiday::getHolidaysForYear($year);

        return response()->json([
            'success' => true,
            'year' => $year,
            'holidays' => $holidayDates
        ]);
    }

    /**
     * Create a new holiday
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date' => 'required|date',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_recurring' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $holiday = Holiday::create([
                'date' => $request->date,
                'name' => $request->name,
                'description' => $request->description,
                'is_recurring' => $request->is_recurring ?? false
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Holiday created successfully',
                'data' => $holiday
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create holiday',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a holiday
     */
    public function update(Request $request, $id)
    {
        $holiday = Holiday::find($id);

        if (!$holiday) {
            return response()->json([
                'success' => false,
                'message' => 'Holiday not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'date' => 'required|date',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_recurring' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $holiday->update([
                'date' => $request->date,
                'name' => $request->name,
                'description' => $request->description,
                'is_recurring' => $request->is_recurring ?? false
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Holiday updated successfully',
                'data' => $holiday
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update holiday',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a holiday
     */
    public function destroy($id)
    {
        $holiday = Holiday::find($id);

        if (!$holiday) {
            return response()->json([
                'success' => false,
                'message' => 'Holiday not found'
            ], 404);
        }

        try {
            $holiday->delete();

            return response()->json([
                'success' => true,
                'message' => 'Holiday deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete holiday',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check if a specific date is a holiday
     */
    public function checkDate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date' => 'required|date'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $date = Carbon::parse($request->date);
        $isHoliday = Holiday::isHoliday($date);

        return response()->json([
            'success' => true,
            'date' => $date->format('Y-m-d'),
            'is_holiday' => $isHoliday
        ]);
    }
}
