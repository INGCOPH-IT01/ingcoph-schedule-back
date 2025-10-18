<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Court;
use App\Models\CourtImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class CourtController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Court::with([
            'sport.timeBasedPricing' => function($query) {
                $query->where('is_active', true);
            },
            'sports.timeBasedPricing' => function($query) {
                $query->where('is_active', true);
            },
            'images'
        ])->where('is_active', true);

        if ($request->has('sport_id')) {
            // Support filtering by sport using the pivot table
            $query->whereHas('sports', function($q) use ($request) {
                $q->where('sports.id', $request->sport_id);
            });
        }

        $courts = $query->get();

        return response()->json([
            'success' => true,
            'data' => $courts
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'amenities' => 'nullable|array',
            'sport_ids' => 'nullable|array',
            'sport_ids.*' => 'exists:sports,id',
            'sport_id' => 'nullable|exists:sports,id', // Keep for backward compatibility
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        $courtData = $request->except('sport_ids');

        // If no sport_id provided and no sport_ids, auto-assign badminton as default
        if (!$request->has('sport_id') && !$request->has('sport_ids')) {
            $badmintonSport = \App\Models\Sport::where('name', 'Badminton')->where('is_active', true)->first();

            if (!$badmintonSport) {
                $badmintonSport = \App\Models\Sport::create([
                    'name' => 'Badminton',
                    'description' => 'Racquet sport played with a shuttlecock on a rectangular court',
                    'is_active' => true,
                ]);
            }
            $courtData['sport_id'] = $badmintonSport->id;
        }

        $court = Court::create($courtData);

        // Sync multiple sports if provided
        if ($request->has('sport_ids') && is_array($request->sport_ids)) {
            $court->sports()->sync($request->sport_ids);
        } elseif ($request->has('sport_id')) {
            // If only single sport_id provided, also add it to the many-to-many relationship
            $court->sports()->sync([$request->sport_id]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Court created successfully',
            'data' => $court->load(['sport', 'sports'])
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $court = Court::with([
            'sport.timeBasedPricing' => function($query) {
                $query->where('is_active', true);
            },
            'sports.timeBasedPricing' => function($query) {
                $query->where('is_active', true);
            },
            'bookings',
            'images'
        ])->find($id);

        if (!$court) {
            return response()->json([
                'success' => false,
                'message' => 'Court not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $court
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {

        $court = Court::find($id);

        if (!$court) {
            return response()->json([
                'success' => false,
                'message' => 'Court not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'amenities' => 'nullable|array',
            'is_active' => 'boolean',
            'sport_ids' => 'nullable|array',
            'sport_ids.*' => 'exists:sports,id',
            'sport_id' => 'nullable|exists:sports,id', // Keep for backward compatibility
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        $updateData = $request->except('sport_ids');

        if($request->has('trashImages')) {
            foreach ($request->trashImages as $trashImage) {
                $courtImage = CourtImage::where('id', $trashImage['id'])->first();
                $courtImage->delete();

                Storage::disk('public')->delete($courtImage->image_url);
            }
        }

        $court->update($updateData);

        // Sync multiple sports if provided
        if ($request->has('sport_ids') && is_array($request->sport_ids)) {
            $court->sports()->sync($request->sport_ids);
        } elseif ($request->has('sport_id')) {
            // If only single sport_id provided, also update the many-to-many relationship
            $court->sports()->sync([$request->sport_id]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Court updated successfully',
            'data' => $court->load(['sport', 'sports'])
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $court = Court::find($id);

        if (!$court) {
            return response()->json([
                'success' => false,
                'message' => 'Court not found'
            ], 404);
        }

        $court->delete();

        return response()->json([
            'success' => true,
            'message' => 'Court deleted successfully'
        ]);
    }

    public function saveImage(Request $request, string $id)
    {
        $court = Court::find($id);

        if (!$court) {
            return response()->json([
                'success' => false,
                'message' => 'Court not found'
            ], 404);
        }




        $savedImages = [];
        if(count($request->file('images')) > 0) {
            foreach ($request->file('images') as $file) {
                // Generate unique filename
                $filename = uniqid() . '.' . $file->getClientOriginalExtension();

                // Store file in storage/app/public/courts
                $path = $file->storeAs('courts', $filename, 'public');

                    // // Save in DB (optional)
                   $courtImage = new CourtImage();
                   $courtImage->court_id = $court->id;
                   $courtImage->image_url = $path;
                   $courtImage->image_name = $filename;
                   $courtImage->image_path = $path;
                   $courtImage->image_type = $file->getClientMimeType();
                   $courtImage->image_size = $file->getSize();
                   $courtImage->save();

                $savedImages[] = $courtImage;
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Images saved successfully',
            'data' => $savedImages
        ]);
    }

    /**
     * Get recent bookings for a specific court
     */
    public function getRecentBookings($id)
    {
        $court = Court::findOrFail($id);

        // Get recent cart transactions for this court
        $recentBookings = \App\Models\CartTransaction::with(['user', 'cartItems' => function($query) use ($id) {
                // Only load cart items for this specific court
                $query->where('court_id', $id)
                      ->where('status', '!=', 'cancelled')
                      ->orderBy('booking_date', 'desc')
                      ->orderBy('start_time', 'asc');
            }])
            ->whereHas('cartItems', function($query) use ($id) {
                $query->where('court_id', $id)
                      ->where('status', '!=', 'cancelled');
            })
            ->where('status', '!=', 'cancelled')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        // Filter out transactions with no cart items for this court
        $recentBookings = $recentBookings->filter(function($transaction) {
            return $transaction->cartItems->count() > 0;
        })->values();

        return response()->json([
            'success' => true,
            'data' => $recentBookings
        ]);
    }

    /**
     * Get total booked hours for a specific court
     */
    public function getTotalBookedHours($id)
    {
        $court = Court::find($id);

        if (!$court) {
            return response()->json([
                'success' => false,
                'message' => 'Court not found'
            ], 404);
        }

        // Calculate total booked hours from cart items for this court
        // 'completed' status means the items have been checked out and are actual bookings
        $cartItems = \App\Models\CartItem::where('court_id', $id)
            ->where('status', 'completed')
            ->get();

        $totalHours = 0;
        foreach ($cartItems as $item) {
            // Parse times with the booking date to handle midnight crossings correctly
            $bookingDate = \Carbon\Carbon::parse($item->booking_date);
            $startTime = \Carbon\Carbon::parse($bookingDate->format('Y-m-d') . ' ' . $item->start_time);
            $endTime = \Carbon\Carbon::parse($bookingDate->format('Y-m-d') . ' ' . $item->end_time);

            // If end time is before or equal to start time, it crosses midnight (next day)
            if ($endTime->lte($startTime)) {
                $endTime->addDay();
            }

            $totalHours += $endTime->diffInHours($startTime, true); // true for floating point hours
        }

        return response()->json([
            'success' => true,
            'data' => [
                'court_id' => $id,
                'total_booked_hours' => round($totalHours, 2)
            ]
        ]);
    }
}
