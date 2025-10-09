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
        $query = Court::with('sport', 'images')->where('is_active', true);

        if ($request->has('sport_id')) {
            $query->where('sport_id', $request->sport_id);
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
            'price_per_hour' => 'required|numeric|min:0',
            'location' => 'nullable|string',
            'amenities' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        // Automatically assign badminton sport
        $badmintonSport = \App\Models\Sport::where('name', 'Badminton')->where('is_active', true)->first();
        
        if (!$badmintonSport) {
            // Create badminton sport if it doesn't exist
            $badmintonSport = \App\Models\Sport::create([
                'name' => 'Badminton',
                'description' => 'Racquet sport played with a shuttlecock on a rectangular court',
                'is_active' => true,
            ]);
        }

        $courtData = $request->all();
      

        $court = Court::create($courtData);

        return response()->json([
            'success' => true,
            'message' => 'Court created successfully',
            'data' => $court->load('sport')
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $court = Court::with('sport', 'bookings','images')->find($id);

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
            'price_per_hour' => 'required|numeric|min:0',
            'location' => 'nullable|string',
            'amenities' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation errors',
                'errors' => $validator->errors()
            ], 422);
        }

        // Ensure sport remains badminton (prevent sport changes)
        $updateData = $request->all();
 

        if($request->has('trashImages')) {
            foreach ($request->trashImages as $trashImage) {
                $courtImage = CourtImage::where('id', $trashImage['id'])->first();
                $courtImage->delete();

                Storage::disk('public')->delete($courtImage->image_url);
            }
        }
        
        $court->update($updateData);
       
    

        return response()->json([
            'success' => true,
            'message' => 'Court updated successfully',
            'data' => $court->load('sport')
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
}
