<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CompanySettingController extends Controller
{
    /**
     * Get all company settings
     */
    public function index()
    {
        try {
            $settings = CompanySetting::all()->pluck('value', 'key');

            // Add logo URL if logo exists
            if (isset($settings['company_logo']) && $settings['company_logo']) {
                $settings['company_logo_url'] = Storage::url($settings['company_logo']);
            }

            return response()->json([
                'success' => true,
                'data' => $settings
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching company settings: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch company settings'
            ], 500);
        }
    }

    /**
     * Get a specific setting by key
     */
    public function show($key)
    {
        try {
            $value = CompanySetting::get($key);

            return response()->json([
                'success' => true,
                'data' => [
                    'key' => $key,
                    'value' => $value
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching company setting: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch setting'
            ], 500);
        }
    }

    /**
     * Update company settings (admin only)
     */
    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'company_name' => 'required|string|max:255',
            'company_logo' => 'nullable|image|mimes:jpeg,jpg,png,gif,svg,webp|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            CompanySetting::set('company_name', $request->company_name);

            // Handle logo upload
            if ($request->hasFile('company_logo')) {
                $logo = $request->file('company_logo');

                // Delete old logo if exists
                $oldLogoPath = CompanySetting::get('company_logo');
                if ($oldLogoPath && Storage::disk('public')->exists($oldLogoPath)) {
                    Storage::disk('public')->delete($oldLogoPath);
                }

                // Store new logo
                $logoPath = $logo->store('company-logos', 'public');
                CompanySetting::set('company_logo', $logoPath);
            }

            Log::info('Company settings updated by admin: ' . $request->user()->email);

            $responseData = [
                'company_name' => $request->company_name
            ];

            $logoPath = CompanySetting::get('company_logo');
            if ($logoPath) {
                $responseData['company_logo'] = $logoPath;
                $responseData['company_logo_url'] = Storage::url($logoPath);
            }

            return response()->json([
                'success' => true,
                'message' => 'Company settings updated successfully',
                'data' => $responseData
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating company settings: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update company settings: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete company logo
     */
    public function deleteLogo(Request $request)
    {
        try {
            $logoPath = CompanySetting::get('company_logo');

            if ($logoPath && Storage::disk('public')->exists($logoPath)) {
                Storage::disk('public')->delete($logoPath);
            }

            CompanySetting::set('company_logo', null);

            Log::info('Company logo deleted by admin: ' . $request->user()->email);

            return response()->json([
                'success' => true,
                'message' => 'Company logo deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting company logo: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete company logo: ' . $e->getMessage()
            ], 500);
        }
    }
}
