<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sport;
use App\Models\SportTimeBasedPricing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SportController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $sports = Sport::with('timeBasedPricing')
            ->withCount('courtsMany as courts_count')
            ->where('is_active', true)
            ->get();

        // If no sports exist, create some default sports
        if ($sports->isEmpty()) {
            $defaultSports = [
                [
                    'name' => 'Badminton',
                    'description' => 'Racquet sport played with a shuttlecock on a rectangular court',
                    'is_active' => true,
                ],
                [
                    'name' => 'Tennis',
                    'description' => 'Racquet sport played on a rectangular court with a net',
                    'is_active' => true,
                ],
                [
                    'name' => 'Basketball',
                    'description' => 'Team sport played on a rectangular court with hoops',
                    'is_active' => true,
                ],
                [
                    'name' => 'Volleyball',
                    'description' => 'Team sport played on a rectangular court with a net',
                    'is_active' => true,
                ]
            ];

            foreach ($defaultSports as $sportData) {
                Sport::create($sportData);
            }

            $sports = Sport::withCount('courtsMany as courts_count')->where('is_active', true)->get();
        }

        return response()->json([
            'success' => true,
            'data' => $sports
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:sports,name',
            'description' => 'nullable|string',
            'image' => 'nullable|string',
            'icon' => 'nullable|string|max:255',
            'price_per_hour' => 'required|numeric|min:0',
            'is_active' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $sport = Sport::create([
            'name' => $request->name,
            'description' => $request->description,
            'image' => $request->image,
            'icon' => $request->icon,
            'price_per_hour' => $request->price_per_hour,
            'is_active' => $request->is_active ?? true
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Sport created successfully',
            'data' => $sport
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $sport = Sport::with(['courtsMany', 'timeBasedPricing'])
            ->withCount('courtsMany as courts_count')
            ->find($id);

        if (!$sport) {
            return response()->json([
                'success' => false,
                'message' => 'Sport not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $sport
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $sport = Sport::find($id);

        if (!$sport) {
            return response()->json([
                'success' => false,
                'message' => 'Sport not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:sports,name,' . $id,
            'description' => 'nullable|string',
            'image' => 'nullable|string',
            'icon' => 'nullable|string|max:255',
            'price_per_hour' => 'required|numeric|min:0',
            'is_active' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $sport->update([
            'name' => $request->name,
            'description' => $request->description,
            'image' => $request->image,
            'icon' => $request->icon,
            'price_per_hour' => $request->price_per_hour,
            'is_active' => $request->is_active ?? $sport->is_active
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Sport updated successfully',
            'data' => $sport
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $sport = Sport::find($id);

        if (!$sport) {
            return response()->json([
                'success' => false,
                'message' => 'Sport not found'
            ], 404);
        }

        // Check if sport has courts (check both legacy and many-to-many relationships)
        if ($sport->courts()->count() > 0 || $sport->courtsMany()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete sport. It has associated courts.'
            ], 422);
        }

        $sport->delete();

        return response()->json([
            'success' => true,
            'message' => 'Sport deleted successfully'
        ]);
    }

    /**
     * Get time-based pricing for a sport
     */
    public function getTimeBasedPricing(string $sportId)
    {
        $sport = Sport::find($sportId);

        if (!$sport) {
            return response()->json([
                'success' => false,
                'message' => 'Sport not found'
            ], 404);
        }

        $pricing = $sport->timeBasedPricing()
            ->orderBy('priority', 'desc')
            ->orderBy('start_time', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $pricing
        ]);
    }

    /**
     * Store time-based pricing for a sport
     */
    public function storeTimeBasedPricing(Request $request, string $sportId)
    {
        $sport = Sport::find($sportId);

        if (!$sport) {
            return response()->json([
                'success' => false,
                'message' => 'Sport not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'price_per_hour' => 'required|numeric|min:0',
            'days_of_week' => 'nullable|array',
            'days_of_week.*' => 'integer|min:0|max:6',
            'is_active' => 'boolean',
            'priority' => 'nullable|integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $pricing = SportTimeBasedPricing::create([
            'sport_id' => $sportId,
            'name' => $request->name,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'price_per_hour' => $request->price_per_hour,
            'days_of_week' => $request->days_of_week,
            'is_active' => $request->is_active ?? true,
            'priority' => $request->priority ?? 0
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Time-based pricing created successfully',
            'data' => $pricing
        ], 201);
    }

    /**
     * Update time-based pricing
     */
    public function updateTimeBasedPricing(Request $request, string $sportId, string $pricingId)
    {
        $sport = Sport::find($sportId);

        if (!$sport) {
            return response()->json([
                'success' => false,
                'message' => 'Sport not found'
            ], 404);
        }

        $pricing = SportTimeBasedPricing::where('sport_id', $sportId)->find($pricingId);

        if (!$pricing) {
            return response()->json([
                'success' => false,
                'message' => 'Time-based pricing not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'price_per_hour' => 'required|numeric|min:0',
            'days_of_week' => 'nullable|array',
            'days_of_week.*' => 'integer|min:0|max:6',
            'is_active' => 'boolean',
            'priority' => 'nullable|integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $pricing->update([
            'name' => $request->name,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'price_per_hour' => $request->price_per_hour,
            'days_of_week' => $request->days_of_week,
            'is_active' => $request->is_active ?? $pricing->is_active,
            'priority' => $request->priority ?? $pricing->priority
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Time-based pricing updated successfully',
            'data' => $pricing
        ]);
    }

    /**
     * Delete time-based pricing
     */
    public function deleteTimeBasedPricing(string $sportId, string $pricingId)
    {
        $sport = Sport::find($sportId);

        if (!$sport) {
            return response()->json([
                'success' => false,
                'message' => 'Sport not found'
            ], 404);
        }

        $pricing = SportTimeBasedPricing::where('sport_id', $sportId)->find($pricingId);

        if (!$pricing) {
            return response()->json([
                'success' => false,
                'message' => 'Time-based pricing not found'
            ], 404);
        }

        $pricing->delete();

        return response()->json([
            'success' => true,
            'message' => 'Time-based pricing deleted successfully'
        ]);
    }
}
