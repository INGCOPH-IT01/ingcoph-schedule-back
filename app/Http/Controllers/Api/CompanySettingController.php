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

            // Add default values for system settings if not set
            if (!isset($settings['theme_primary_color'])) {
                $settings['theme_primary_color'] = '#B71C1C';
            }
            if (!isset($settings['theme_secondary_color'])) {
                $settings['theme_secondary_color'] = '#5F6368';
            }
            if (!isset($settings['theme_background_color'])) {
                $settings['theme_background_color'] = '#F5F5F5';
            }
            if (!isset($settings['theme_mode'])) {
                $settings['theme_mode'] = 'light';
            }
            
            // Background color settings
            if (!isset($settings['bg_primary_color'])) {
                $settings['bg_primary_color'] = '#FFFFFF';
            }
            if (!isset($settings['bg_secondary_color'])) {
                $settings['bg_secondary_color'] = '#FFEBEE';
            }
            if (!isset($settings['bg_accent_color'])) {
                $settings['bg_accent_color'] = '#FFCDD2';
            }
            if (!isset($settings['bg_overlay_color'])) {
                $settings['bg_overlay_color'] = 'rgba(183, 28, 28, 0.08)';
            }
            if (!isset($settings['bg_pattern_color'])) {
                $settings['bg_pattern_color'] = 'rgba(183, 28, 28, 0.03)';
            }
            if (!isset($settings['dashboard_welcome_message'])) {
                $settings['dashboard_welcome_message'] = '';
            }
            if (!isset($settings['dashboard_announcement'])) {
                $settings['dashboard_announcement'] = '';
            }
            if (!isset($settings['dashboard_show_stats'])) {
                $settings['dashboard_show_stats'] = true;
            } else {
                $settings['dashboard_show_stats'] = $settings['dashboard_show_stats'] === '1';
            }
            if (!isset($settings['dashboard_show_recent_bookings'])) {
                $settings['dashboard_show_recent_bookings'] = true;
            } else {
                $settings['dashboard_show_recent_bookings'] = $settings['dashboard_show_recent_bookings'] === '1';
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
            // System settings
            'theme_primary_color' => 'nullable|string|max:50',
            'theme_secondary_color' => 'nullable|string|max:50',
            'theme_background_color' => 'nullable|string|max:50',
            'theme_mode' => 'nullable|in:light,dark',
            'dashboard_welcome_message' => 'nullable|string|max:500',
            'dashboard_announcement' => 'nullable|string|max:1000',
            'dashboard_show_stats' => 'nullable|boolean',
            'dashboard_show_recent_bookings' => 'nullable|boolean',
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

            // Save theme settings
            if ($request->has('theme_primary_color')) {
                CompanySetting::set('theme_primary_color', $request->theme_primary_color);
            }
            if ($request->has('theme_secondary_color')) {
                CompanySetting::set('theme_secondary_color', $request->theme_secondary_color);
            }
            if ($request->has('theme_background_color')) {
                CompanySetting::set('theme_background_color', $request->theme_background_color);
            }
            if ($request->has('theme_mode')) {
                CompanySetting::set('theme_mode', $request->theme_mode);
            }

            // Save dashboard settings
            if ($request->has('dashboard_welcome_message')) {
                CompanySetting::set('dashboard_welcome_message', $request->dashboard_welcome_message);
            }
            if ($request->has('dashboard_announcement')) {
                CompanySetting::set('dashboard_announcement', $request->dashboard_announcement);
            }
            if ($request->has('dashboard_show_stats')) {
                CompanySetting::set('dashboard_show_stats', $request->dashboard_show_stats ? '1' : '0');
            }
            if ($request->has('dashboard_show_recent_bookings')) {
                CompanySetting::set('dashboard_show_recent_bookings', $request->dashboard_show_recent_bookings ? '1' : '0');
            }
            
            // Save background color settings
            if ($request->has('bg_primary_color')) {
                CompanySetting::set('bg_primary_color', $request->bg_primary_color);
            }
            if ($request->has('bg_secondary_color')) {
                CompanySetting::set('bg_secondary_color', $request->bg_secondary_color);
            }
            if ($request->has('bg_accent_color')) {
                CompanySetting::set('bg_accent_color', $request->bg_accent_color);
            }
            if ($request->has('bg_overlay_color')) {
                CompanySetting::set('bg_overlay_color', $request->bg_overlay_color);
            }
            if ($request->has('bg_pattern_color')) {
                CompanySetting::set('bg_pattern_color', $request->bg_pattern_color);
            }

            Log::info('Company settings updated by admin: ' . $request->user()->email);

            $responseData = [
                'company_name' => $request->company_name,
                'theme_primary_color' => CompanySetting::get('theme_primary_color', '#B71C1C'),
                'theme_secondary_color' => CompanySetting::get('theme_secondary_color', '#5F6368'),
                'theme_background_color' => CompanySetting::get('theme_background_color', '#F5F5F5'),
                'theme_mode' => CompanySetting::get('theme_mode', 'light'),
                'dashboard_welcome_message' => CompanySetting::get('dashboard_welcome_message', ''),
                'dashboard_announcement' => CompanySetting::get('dashboard_announcement', ''),
                'dashboard_show_stats' => CompanySetting::get('dashboard_show_stats', '1') === '1',
                'dashboard_show_recent_bookings' => CompanySetting::get('dashboard_show_recent_bookings', '1') === '1',
                'bg_secondary_color' => CompanySetting::get('bg_secondary_color', '#FFEBEE'),
                'bg_accent_color' => CompanySetting::get('bg_accent_color', '#FFCDD2'),
                'bg_overlay_color' => CompanySetting::get('bg_overlay_color', 'rgba(183, 28, 28, 0.08)'),
                'bg_pattern_color' => CompanySetting::get('bg_pattern_color', 'rgba(183, 28, 28, 0.03)'),
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
