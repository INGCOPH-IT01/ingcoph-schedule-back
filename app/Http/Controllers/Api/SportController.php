<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SportController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $sports = Sport::where('is_active', true)->get();
        
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
            
            $sports = Sport::where('is_active', true)->get();
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
        $sport = Sport::with('courts')->find($id);

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

        // Check if sport has courts
        if ($sport->courts()->count() > 0) {
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
}
