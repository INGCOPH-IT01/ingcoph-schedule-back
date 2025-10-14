<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class CompanySettingController extends Controller
{
    /**
     * Get all company settings
     */
    public function index()
    {
        try {
            $settings = CompanySetting::all()->pluck('value', 'key');

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

            Log::info('Company settings updated by admin: ' . $request->user()->email);

            return response()->json([
                'success' => true,
                'message' => 'Company settings updated successfully',
                'data' => [
                    'company_name' => $request->company_name
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating company settings: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update company settings: ' . $e->getMessage()
            ], 500);
        }
    }
}
