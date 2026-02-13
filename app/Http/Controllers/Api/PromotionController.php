<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class PromotionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Promotion::with('creator:id,first_name,last_name,email');

        // Filter by active status if requested
        if ($request->has('active_only') && $request->active_only) {
            $query->active();
        }

        $promotions = $query->ordered()->get();

        return response()->json($promotions);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'is_active' => 'boolean',
            'auto_popup_enabled' => 'boolean',
            'auto_popup_interval_hours' => 'integer|min:1|max:168', // 1 hour to 1 week
            'display_order' => 'integer',
            'published_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after:published_at',
            'media' => 'nullable|array',
            'media.*' => 'file|mimes:jpeg,jpg,png,gif,mp4,mov,avi,webp|max:51200', // Max 50MB
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $mediaUrls = [];

        // Handle file uploads
        if ($request->hasFile('media')) {
            foreach ($request->file('media') as $file) {
                $path = $file->store('promotions', 'public');
                $mediaUrls[] = Storage::url($path);
            }
        }

        $promotion = Promotion::create([
            'title' => $request->title,
            'content' => $request->content,
            'media' => $mediaUrls,
            'is_active' => $request->is_active ?? true,
            'auto_popup_enabled' => $request->auto_popup_enabled ?? false,
            'auto_popup_interval_hours' => $request->auto_popup_interval_hours ?? 4,
            'display_order' => $request->display_order ?? 0,
            'published_at' => $request->published_at,
            'expires_at' => $request->expires_at,
            'created_by' => $request->user()->id,
        ]);

        return response()->json($promotion->load('creator'), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $promotion = Promotion::with('creator:id,first_name,last_name,email')->findOrFail($id);

        return response()->json($promotion);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $promotion = Promotion::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'content' => 'sometimes|required|string',
            'is_active' => 'boolean',
            'auto_popup_enabled' => 'boolean',
            'auto_popup_interval_hours' => 'integer|min:1|max:168',
            'display_order' => 'integer',
            'published_at' => 'nullable|date',
            'expires_at' => 'nullable|date',
            'media' => 'nullable|array',
            'media.*' => 'file|mimes:jpeg,jpg,png,gif,mp4,mov,avi,webp|max:51200',
            'existing_media' => 'nullable|array', // URLs of existing media to keep
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $mediaUrls = $request->existing_media ?? [];

        // Handle new file uploads
        if ($request->hasFile('media')) {
            foreach ($request->file('media') as $file) {
                $path = $file->store('promotions', 'public');
                $mediaUrls[] = Storage::url($path);
            }
        }

        $updateData = [
            'title' => $request->title ?? $promotion->title,
            'content' => $request->content ?? $promotion->content,
            'is_active' => $request->is_active ?? $promotion->is_active,
            'display_order' => $request->display_order ?? $promotion->display_order,
        ];

        if ($request->has('auto_popup_enabled')) {
            $updateData['auto_popup_enabled'] = $request->auto_popup_enabled;
        }

        if ($request->has('auto_popup_interval_hours')) {
            $updateData['auto_popup_interval_hours'] = $request->auto_popup_interval_hours;
        }

        if ($request->has('published_at')) {
            $updateData['published_at'] = $request->published_at;
        }

        if ($request->has('expires_at')) {
            $updateData['expires_at'] = $request->expires_at;
        }

        if (!empty($mediaUrls)) {
            $updateData['media'] = $mediaUrls;
        }

        $promotion->update($updateData);

        return response()->json($promotion->load('creator'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $promotion = Promotion::findOrFail($id);

        // Delete associated media files
        if ($promotion->media) {
            foreach ($promotion->media as $mediaUrl) {
                $path = str_replace('/storage/', '', parse_url($mediaUrl, PHP_URL_PATH));
                Storage::disk('public')->delete($path);
            }
        }

        $promotion->delete();

        return response()->json(['message' => 'Promotion deleted successfully']);
    }

    /**
     * Get active promotions for public display
     */
    public function active()
    {
        $promotions = Promotion::active()->ordered()->get();

        return response()->json($promotions);
    }
}
