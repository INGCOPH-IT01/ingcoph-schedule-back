<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class BannerController extends Controller
{
    /**
     * Get all active banners for public display
     */
    public function index()
    {
        $banners = Banner::where('is_active', true)
            ->orderBy('order', 'asc')
            ->get()
            ->map(function ($banner) {
                return [
                    'id' => $banner->id,
                    'title' => $banner->title,
                    'image_url' => $banner->image_path ? url($banner->image_path) : null,
                    'order' => $banner->order,
                ];
            });

        return response()->json($banners);
    }

    /**
     * Get all banners (for admin)
     */
    public function all()
    {
        $banners = Banner::orderBy('order', 'asc')
            ->get()
            ->map(function ($banner) {
                return [
                    'id' => $banner->id,
                    'title' => $banner->title,
                    'image_url' => $banner->image_path ? url($banner->image_path) : null,
                    'image_path' => $banner->image_path,
                    'order' => $banner->order,
                    'is_active' => $banner->is_active,
                    'created_at' => $banner->created_at,
                    'updated_at' => $banner->updated_at,
                ];
            });

        return response()->json($banners);
    }

    /**
     * Store a new banner
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:255',
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:5120', // 5MB max
            'order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Handle file upload
        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $filename = time() . '_' . uniqid() . '.' . $image->getClientOriginalExtension();
            $path = $image->storeAs('banners', $filename, 'public');
            $imagePath = '/storage/' . $path;
        } else {
            return response()->json(['message' => 'Image is required'], 422);
        }

        // Get the highest order value and add 1
        $maxOrder = Banner::max('order') ?? -1;
        $order = $request->input('order', $maxOrder + 1);

        $banner = Banner::create([
            'title' => $request->input('title'),
            'image_path' => $imagePath,
            'order' => $order,
            'is_active' => $request->input('is_active', true),
        ]);

        return response()->json([
            'message' => 'Banner created successfully',
            'banner' => [
                'id' => $banner->id,
                'title' => $banner->title,
                'image_url' => url($banner->image_path),
                'image_path' => $banner->image_path,
                'order' => $banner->order,
                'is_active' => $banner->is_active,
            ]
        ], 201);
    }

    /**
     * Update an existing banner
     */
    public function update(Request $request, $id)
    {
        $banner = Banner::find($id);

        if (!$banner) {
            return response()->json(['message' => 'Banner not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:255',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Handle file upload if new image is provided
        if ($request->hasFile('image')) {
            // Delete old image
            if ($banner->image_path) {
                $oldPath = str_replace('/storage/', '', $banner->image_path);
                Storage::disk('public')->delete($oldPath);
            }

            $image = $request->file('image');
            $filename = time() . '_' . uniqid() . '.' . $image->getClientOriginalExtension();
            $path = $image->storeAs('banners', $filename, 'public');
            $banner->image_path = '/storage/' . $path;
        }

        // Update other fields
        if ($request->has('title')) {
            $banner->title = $request->input('title');
        }
        if ($request->has('order')) {
            $banner->order = $request->input('order');
        }
        if ($request->has('is_active')) {
            $banner->is_active = $request->input('is_active');
        }

        $banner->save();

        return response()->json([
            'message' => 'Banner updated successfully',
            'banner' => [
                'id' => $banner->id,
                'title' => $banner->title,
                'image_url' => url($banner->image_path),
                'image_path' => $banner->image_path,
                'order' => $banner->order,
                'is_active' => $banner->is_active,
            ]
        ]);
    }

    /**
     * Delete a banner
     */
    public function destroy($id)
    {
        $banner = Banner::find($id);

        if (!$banner) {
            return response()->json(['message' => 'Banner not found'], 404);
        }

        // Delete image file
        if ($banner->image_path) {
            $path = str_replace('/storage/', '', $banner->image_path);
            Storage::disk('public')->delete($path);
        }

        $banner->delete();

        return response()->json(['message' => 'Banner deleted successfully']);
    }

    /**
     * Reorder banners
     */
    public function reorder(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'banners' => 'required|array',
            'banners.*.id' => 'required|exists:banners,id',
            'banners.*.order' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        foreach ($request->banners as $bannerData) {
            Banner::where('id', $bannerData['id'])
                ->update(['order' => $bannerData['order']]);
        }

        return response()->json(['message' => 'Banners reordered successfully']);
    }
}
