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

            // Payment settings defaults and URLs
            if (!isset($settings['payment_gcash_number'])) {
                $settings['payment_gcash_number'] = '';
            }
            if (isset($settings['payment_gcash_qr']) && $settings['payment_gcash_qr']) {
                $settings['payment_gcash_qr_url'] = Storage::url($settings['payment_gcash_qr']);
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

            // Theme Gradient Settings
            if (!isset($settings['theme_gradient_color1'])) {
                $settings['theme_gradient_color1'] = '#FFFFFF';
            }
            if (!isset($settings['theme_gradient_color2'])) {
                $settings['theme_gradient_color2'] = '#FFF5F5';
            }
            if (!isset($settings['theme_gradient_color3'])) {
                $settings['theme_gradient_color3'] = '#FFEBEE';
            }
            if (!isset($settings['theme_gradient_angle'])) {
                $settings['theme_gradient_angle'] = '135';
            }

            // Theme Button Colors
            if (!isset($settings['theme_button_primary_color'])) {
                $settings['theme_button_primary_color'] = '#B71C1C';
            }
            if (!isset($settings['theme_button_secondary_color'])) {
                $settings['theme_button_secondary_color'] = '#5F6368';
            }
            if (!isset($settings['theme_button_success_color'])) {
                $settings['theme_button_success_color'] = '#4CAF50';
            }
            if (!isset($settings['theme_button_error_color'])) {
                $settings['theme_button_error_color'] = '#D32F2F';
            }
            if (!isset($settings['theme_button_warning_color'])) {
                $settings['theme_button_warning_color'] = '#F57C00';
            }
            if (!isset($settings['theme_button_info_color'])) {
                $settings['theme_button_info_color'] = '#757575';
            }

            // Module Titles Settings
            if (!isset($settings['module_courts_text'])) {
                $settings['module_courts_text'] = 'Manage Courts';
            }
            if (!isset($settings['module_courts_color'])) {
                $settings['module_courts_color'] = '#B71C1C';
            }
            if (!isset($settings['module_courts_badge_color'])) {
                $settings['module_courts_badge_color'] = '#D32F2F';
            }
            if (!isset($settings['module_courts_subtitle'])) {
                $settings['module_courts_subtitle'] = 'Create, manage, and configure courts for all sports';
            }
            
            if (!isset($settings['module_sports_text'])) {
                $settings['module_sports_text'] = 'Manage Sports';
            }
            if (!isset($settings['module_sports_color'])) {
                $settings['module_sports_color'] = '#B71C1C';
            }
            if (!isset($settings['module_sports_badge_color'])) {
                $settings['module_sports_badge_color'] = '#D32F2F';
            }
            if (!isset($settings['module_sports_subtitle'])) {
                $settings['module_sports_subtitle'] = 'Configure available sports and their settings';
            }
            
            if (!isset($settings['module_bookings_text'])) {
                $settings['module_bookings_text'] = 'My Bookings';
            }
            if (!isset($settings['module_bookings_color'])) {
                $settings['module_bookings_color'] = '#B71C1C';
            }
            if (!isset($settings['module_bookings_badge_color'])) {
                $settings['module_bookings_badge_color'] = '#D32F2F';
            }
            if (!isset($settings['module_bookings_subtitle'])) {
                $settings['module_bookings_subtitle'] = 'View and manage your court reservations';
            }
            
            if (!isset($settings['module_users_text'])) {
                $settings['module_users_text'] = 'Manage Users';
            }
            if (!isset($settings['module_users_color'])) {
                $settings['module_users_color'] = '#B71C1C';
            }
            if (!isset($settings['module_users_badge_color'])) {
                $settings['module_users_badge_color'] = '#D32F2F';
            }
            if (!isset($settings['module_users_subtitle'])) {
                $settings['module_users_subtitle'] = 'Manage users, staff, and administrators';
            }
            
            if (!isset($settings['module_admin_text'])) {
                $settings['module_admin_text'] = 'Admin Panel';
            }
            if (!isset($settings['module_admin_color'])) {
                $settings['module_admin_color'] = '#B71C1C';
            }
            if (!isset($settings['module_admin_badge_color'])) {
                $settings['module_admin_badge_color'] = '#D32F2F';
            }
            if (!isset($settings['module_admin_subtitle'])) {
                $settings['module_admin_subtitle'] = 'Manage multi-sport court bookings and oversee the entire system with professional precision';
            }

            // Settings version tracker
            if (!isset($settings['settings_version'])) {
                $settings['settings_version'] = '1';
            }
            if (!isset($settings['settings_updated_at'])) {
                $settings['settings_updated_at'] = now()->toISOString();
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
            // Payment settings
            'payment_gcash_number' => 'nullable|string|max:50',
            'payment_gcash_qr' => 'nullable|image|mimes:jpeg,jpg,png,gif,svg,webp|max:4096',
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

            // Save payment settings
            if ($request->has('payment_gcash_number')) {
                CompanySetting::set('payment_gcash_number', $request->payment_gcash_number);
            }

            if ($request->hasFile('payment_gcash_qr')) {
                $oldQrPath = CompanySetting::get('payment_gcash_qr');
                if ($oldQrPath && Storage::disk('public')->exists($oldQrPath)) {
                    Storage::disk('public')->delete($oldQrPath);
                }

                $qrPath = $request->file('payment_gcash_qr')->store('payment', 'public');
                CompanySetting::set('payment_gcash_qr', $qrPath);
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
                'payment_gcash_number' => CompanySetting::get('payment_gcash_number', ''),
            ];

            $logoPath = CompanySetting::get('company_logo');
            if ($logoPath) {
                $responseData['company_logo'] = $logoPath;
                $responseData['company_logo_url'] = Storage::url($logoPath);
            }

            $qrPath = CompanySetting::get('payment_gcash_qr');
            if ($qrPath) {
                $responseData['payment_gcash_qr'] = $qrPath;
                $responseData['payment_gcash_qr_url'] = Storage::url($qrPath);
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

    /**
     * Update theme gradient settings
     */
    public function updateThemeSettings(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'gradientColor1' => 'required|string|max:20',
            'gradientColor2' => 'required|string|max:20',
            'gradientColor3' => 'required|string|max:20',
            'gradientAngle' => 'required|integer|min:0|max:360',
            'buttonPrimaryColor' => 'nullable|string|max:20',
            'buttonSecondaryColor' => 'nullable|string|max:20',
            'buttonSuccessColor' => 'nullable|string|max:20',
            'buttonErrorColor' => 'nullable|string|max:20',
            'buttonWarningColor' => 'nullable|string|max:20',
            'buttonInfoColor' => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Save theme gradient settings
            CompanySetting::set('theme_gradient_color1', $request->gradientColor1);
            CompanySetting::set('theme_gradient_color2', $request->gradientColor2);
            CompanySetting::set('theme_gradient_color3', $request->gradientColor3);
            CompanySetting::set('theme_gradient_angle', (string)$request->gradientAngle);

            // Save button colors if provided
            if ($request->has('buttonPrimaryColor')) {
                CompanySetting::set('theme_button_primary_color', $request->buttonPrimaryColor);
            }
            if ($request->has('buttonSecondaryColor')) {
                CompanySetting::set('theme_button_secondary_color', $request->buttonSecondaryColor);
            }
            if ($request->has('buttonSuccessColor')) {
                CompanySetting::set('theme_button_success_color', $request->buttonSuccessColor);
            }
            if ($request->has('buttonErrorColor')) {
                CompanySetting::set('theme_button_error_color', $request->buttonErrorColor);
            }
            if ($request->has('buttonWarningColor')) {
                CompanySetting::set('theme_button_warning_color', $request->buttonWarningColor);
            }
            if ($request->has('buttonInfoColor')) {
                CompanySetting::set('theme_button_info_color', $request->buttonInfoColor);
            }

            // Update version tracker to trigger frontend refresh
            $currentVersion = (int) CompanySetting::get('settings_version', '1');
            CompanySetting::set('settings_version', (string)($currentVersion + 1));
            CompanySetting::set('settings_updated_at', now()->toISOString());

            Log::info('Theme settings updated by admin: ' . $request->user()->email);

            return response()->json([
                'success' => true,
                'message' => 'Theme settings updated successfully',
                'data' => [
                    'gradientColor1' => $request->gradientColor1,
                    'gradientColor2' => $request->gradientColor2,
                    'gradientColor3' => $request->gradientColor3,
                    'gradientAngle' => $request->gradientAngle,
                    'buttonPrimaryColor' => CompanySetting::get('theme_button_primary_color', '#B71C1C'),
                    'buttonSecondaryColor' => CompanySetting::get('theme_button_secondary_color', '#5F6368'),
                    'buttonSuccessColor' => CompanySetting::get('theme_button_success_color', '#4CAF50'),
                    'buttonErrorColor' => CompanySetting::get('theme_button_error_color', '#D32F2F'),
                    'buttonWarningColor' => CompanySetting::get('theme_button_warning_color', '#F57C00'),
                    'buttonInfoColor' => CompanySetting::get('theme_button_info_color', '#757575'),
                    'settings_version' => (string)($currentVersion + 1),
                    'settings_updated_at' => now()->toISOString(),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating theme settings: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update theme settings: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update module titles settings
     */
    public function updateModuleTitles(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'courts.text' => 'required|string|max:100',
            'courts.color' => 'required|string|max:20',
            'courts.badgeColor' => 'required|string|max:20',
            'courts.subtitle' => 'nullable|string|max:255',
            'sports.text' => 'required|string|max:100',
            'sports.color' => 'required|string|max:20',
            'sports.badgeColor' => 'required|string|max:20',
            'sports.subtitle' => 'nullable|string|max:255',
            'bookings.text' => 'required|string|max:100',
            'bookings.color' => 'required|string|max:20',
            'bookings.badgeColor' => 'required|string|max:20',
            'bookings.subtitle' => 'nullable|string|max:255',
            'users.text' => 'required|string|max:100',
            'users.color' => 'required|string|max:20',
            'users.badgeColor' => 'required|string|max:20',
            'users.subtitle' => 'nullable|string|max:255',
            'admin.text' => 'required|string|max:100',
            'admin.color' => 'required|string|max:20',
            'admin.badgeColor' => 'required|string|max:20',
            'admin.subtitle' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Save Courts module settings
            CompanySetting::set('module_courts_text', $request->input('courts.text'));
            CompanySetting::set('module_courts_color', $request->input('courts.color'));
            CompanySetting::set('module_courts_badge_color', $request->input('courts.badgeColor'));
            if ($request->has('courts.subtitle')) {
                CompanySetting::set('module_courts_subtitle', $request->input('courts.subtitle'));
            }

            // Save Sports module settings
            CompanySetting::set('module_sports_text', $request->input('sports.text'));
            CompanySetting::set('module_sports_color', $request->input('sports.color'));
            CompanySetting::set('module_sports_badge_color', $request->input('sports.badgeColor'));
            if ($request->has('sports.subtitle')) {
                CompanySetting::set('module_sports_subtitle', $request->input('sports.subtitle'));
            }

            // Save Bookings module settings
            CompanySetting::set('module_bookings_text', $request->input('bookings.text'));
            CompanySetting::set('module_bookings_color', $request->input('bookings.color'));
            CompanySetting::set('module_bookings_badge_color', $request->input('bookings.badgeColor'));
            if ($request->has('bookings.subtitle')) {
                CompanySetting::set('module_bookings_subtitle', $request->input('bookings.subtitle'));
            }

            // Save Users module settings
            CompanySetting::set('module_users_text', $request->input('users.text'));
            CompanySetting::set('module_users_color', $request->input('users.color'));
            CompanySetting::set('module_users_badge_color', $request->input('users.badgeColor'));
            if ($request->has('users.subtitle')) {
                CompanySetting::set('module_users_subtitle', $request->input('users.subtitle'));
            }

            // Save Admin module settings
            CompanySetting::set('module_admin_text', $request->input('admin.text'));
            CompanySetting::set('module_admin_color', $request->input('admin.color'));
            CompanySetting::set('module_admin_badge_color', $request->input('admin.badgeColor'));
            if ($request->has('admin.subtitle')) {
                CompanySetting::set('module_admin_subtitle', $request->input('admin.subtitle'));
            }

            // Update version tracker to trigger frontend refresh
            $currentVersion = (int) CompanySetting::get('settings_version', '1');
            CompanySetting::set('settings_version', (string)($currentVersion + 1));
            CompanySetting::set('settings_updated_at', now()->toISOString());

            Log::info('Module titles updated by admin: ' . $request->user()->email);

            return response()->json([
                'success' => true,
                'message' => 'Module titles updated successfully',
                'data' => [
                    'courts' => [
                        'text' => $request->input('courts.text'),
                        'color' => $request->input('courts.color'),
                        'badgeColor' => $request->input('courts.badgeColor'),
                        'subtitle' => $request->input('courts.subtitle', ''),
                    ],
                    'sports' => [
                        'text' => $request->input('sports.text'),
                        'color' => $request->input('sports.color'),
                        'badgeColor' => $request->input('sports.badgeColor'),
                        'subtitle' => $request->input('sports.subtitle', ''),
                    ],
                    'bookings' => [
                        'text' => $request->input('bookings.text'),
                        'color' => $request->input('bookings.color'),
                        'badgeColor' => $request->input('bookings.badgeColor'),
                        'subtitle' => $request->input('bookings.subtitle', ''),
                    ],
                    'users' => [
                        'text' => $request->input('users.text'),
                        'color' => $request->input('users.color'),
                        'badgeColor' => $request->input('users.badgeColor'),
                        'subtitle' => $request->input('users.subtitle', ''),
                    ],
                    'admin' => [
                        'text' => $request->input('admin.text'),
                        'color' => $request->input('admin.color'),
                        'badgeColor' => $request->input('admin.badgeColor'),
                        'subtitle' => $request->input('admin.subtitle', ''),
                    ],
                    'settings_version' => (string)($currentVersion + 1),
                    'settings_updated_at' => now()->toISOString(),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating module titles: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update module titles: ' . $e->getMessage()
            ], 500);
        }
    }
}
