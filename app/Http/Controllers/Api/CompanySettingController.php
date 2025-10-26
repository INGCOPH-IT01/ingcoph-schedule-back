<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
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

            // Booking rules
            if (!isset($settings['user_booking_enabled'])) {
                $settings['user_booking_enabled'] = true;
            } else {
                $settings['user_booking_enabled'] = $settings['user_booking_enabled'] === '1';
            }

            // Payment settings
            if (!isset($settings['payment_gcash_number'])) {
                $settings['payment_gcash_number'] = '0917-123-4567';
            }
            if (!isset($settings['payment_gcash_name'])) {
                $settings['payment_gcash_name'] = 'Perfect Smash';
            }
            if (!isset($settings['payment_instructions'])) {
                $settings['payment_instructions'] = 'Please send payment to our GCash number and upload proof of payment.';
            }

            // Add payment QR code URL if exists
            if (isset($settings['payment_qr_code']) && $settings['payment_qr_code']) {
                $settings['payment_qr_code_url'] = Storage::url($settings['payment_qr_code']);
            }

            // Contact information settings
            if (!isset($settings['contact_viber'])) {
                $settings['contact_viber'] = '';
            }
            if (!isset($settings['contact_mobile'])) {
                $settings['contact_mobile'] = '';
            }
            if (!isset($settings['contact_email'])) {
                $settings['contact_email'] = '';
            }

            // Social media settings
            if (!isset($settings['facebook_page_url'])) {
                $settings['facebook_page_url'] = '';
            }
            if (!isset($settings['facebook_page_name'])) {
                $settings['facebook_page_name'] = '';
            }

            // Operating hours settings
            if (!isset($settings['operating_hours_opening'])) {
                $settings['operating_hours_opening'] = '08:00';
            }
            if (!isset($settings['operating_hours_closing'])) {
                $settings['operating_hours_closing'] = '22:00';
            }
            if (!isset($settings['operating_hours_enabled'])) {
                $settings['operating_hours_enabled'] = true;
            } else {
                $settings['operating_hours_enabled'] = $settings['operating_hours_enabled'] === '1';
            }

            // Day-specific operating hours
            $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
            foreach ($days as $day) {
                if (!isset($settings["operating_hours_{$day}_open"])) {
                    $settings["operating_hours_{$day}_open"] = '08:00';
                }
                if (!isset($settings["operating_hours_{$day}_close"])) {
                    $settings["operating_hours_{$day}_close"] = '22:00';
                }
                if (!isset($settings["operating_hours_{$day}_operational"])) {
                    $settings["operating_hours_{$day}_operational"] = true;
                } else {
                    $settings["operating_hours_{$day}_operational"] = $settings["operating_hours_{$day}_operational"] === '1';
                }
            }

            return response()->json([
                'success' => true,
                'data' => $settings
            ]);
        } catch (\Exception $e) {
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
            // Booking rules
            'user_booking_enabled' => 'nullable|boolean',
            // Payment settings
            'payment_gcash_number' => 'nullable|string|max:50',
            'payment_gcash_name' => 'nullable|string|max:255',
            'payment_instructions' => 'nullable|string|max:1000',
            'payment_qr_code' => 'nullable|image|mimes:jpeg,jpg,png,gif,svg,webp|max:2048',
            // Contact information
            'contact_viber' => 'nullable|string|max:100',
            'contact_mobile' => 'nullable|string|max:50',
            'contact_email' => 'nullable|email|max:255',
            // Social media
            'facebook_page_url' => 'nullable|string|max:500',
            'facebook_page_name' => 'nullable|string|max:255',
            // Operating hours
            'operating_hours_opening' => 'nullable|string|max:5',
            'operating_hours_closing' => 'nullable|string|max:5',
            'operating_hours_enabled' => 'nullable|boolean',
            'operating_hours_monday_open' => 'nullable|string|max:5',
            'operating_hours_monday_close' => 'nullable|string|max:5',
            'operating_hours_tuesday_open' => 'nullable|string|max:5',
            'operating_hours_tuesday_close' => 'nullable|string|max:5',
            'operating_hours_wednesday_open' => 'nullable|string|max:5',
            'operating_hours_wednesday_close' => 'nullable|string|max:5',
            'operating_hours_thursday_open' => 'nullable|string|max:5',
            'operating_hours_thursday_close' => 'nullable|string|max:5',
            'operating_hours_friday_open' => 'nullable|string|max:5',
            'operating_hours_friday_close' => 'nullable|string|max:5',
            'operating_hours_saturday_open' => 'nullable|string|max:5',
            'operating_hours_saturday_close' => 'nullable|string|max:5',
            'operating_hours_sunday_open' => 'nullable|string|max:5',
            'operating_hours_sunday_close' => 'nullable|string|max:5',
            // Day operational status
            'operating_hours_monday_operational' => 'nullable|boolean',
            'operating_hours_tuesday_operational' => 'nullable|boolean',
            'operating_hours_wednesday_operational' => 'nullable|boolean',
            'operating_hours_thursday_operational' => 'nullable|boolean',
            'operating_hours_friday_operational' => 'nullable|boolean',
            'operating_hours_saturday_operational' => 'nullable|boolean',
            'operating_hours_sunday_operational' => 'nullable|boolean',
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

            // Save booking rules
            if ($request->has('user_booking_enabled')) {
                CompanySetting::set('user_booking_enabled', $request->user_booking_enabled ? '1' : '0');
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

            // Save payment settings
            if ($request->has('payment_gcash_number')) {
                CompanySetting::set('payment_gcash_number', $request->payment_gcash_number);
            }
            if ($request->has('payment_gcash_name')) {
                CompanySetting::set('payment_gcash_name', $request->payment_gcash_name);
            }
            if ($request->has('payment_instructions')) {
                CompanySetting::set('payment_instructions', $request->payment_instructions);
            }

            // Handle payment QR code upload
            if ($request->hasFile('payment_qr_code')) {
                $qrCode = $request->file('payment_qr_code');

                // Delete old QR code if exists
                $oldQrCodePath = CompanySetting::get('payment_qr_code');
                if ($oldQrCodePath && Storage::disk('public')->exists($oldQrCodePath)) {
                    Storage::disk('public')->delete($oldQrCodePath);
                }

                // Store new QR code
                $qrCodePath = $qrCode->store('payment-qr-codes', 'public');
                CompanySetting::set('payment_qr_code', $qrCodePath);
            }

            // Save contact information
            if ($request->has('contact_viber')) {
                CompanySetting::set('contact_viber', $request->contact_viber);
            }
            if ($request->has('contact_mobile')) {
                CompanySetting::set('contact_mobile', $request->contact_mobile);
            }
            if ($request->has('contact_email')) {
                CompanySetting::set('contact_email', $request->contact_email);
            }

            // Save social media information
            if ($request->has('facebook_page_url')) {
                CompanySetting::set('facebook_page_url', $request->facebook_page_url);
            }
            if ($request->has('facebook_page_name')) {
                CompanySetting::set('facebook_page_name', $request->facebook_page_name);
            }

            // Save operating hours
            if ($request->has('operating_hours_opening')) {
                CompanySetting::set('operating_hours_opening', $request->operating_hours_opening);
            }
            if ($request->has('operating_hours_closing')) {
                CompanySetting::set('operating_hours_closing', $request->operating_hours_closing);
            }
            if ($request->has('operating_hours_enabled')) {
                CompanySetting::set('operating_hours_enabled', $request->operating_hours_enabled ? '1' : '0');
            }

            // Save day-specific operating hours
            $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
            foreach ($days as $day) {
                if ($request->has("operating_hours_{$day}_open")) {
                    CompanySetting::set("operating_hours_{$day}_open", $request->input("operating_hours_{$day}_open"));
                }
                if ($request->has("operating_hours_{$day}_close")) {
                    CompanySetting::set("operating_hours_{$day}_close", $request->input("operating_hours_{$day}_close"));
                }
                if ($request->has("operating_hours_{$day}_operational")) {
                    CompanySetting::set("operating_hours_{$day}_operational", $request->input("operating_hours_{$day}_operational") ? '1' : '0');
                }
            }

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
                'user_booking_enabled' => CompanySetting::get('user_booking_enabled', '1') === '1',
                'bg_secondary_color' => CompanySetting::get('bg_secondary_color', '#FFEBEE'),
                'bg_accent_color' => CompanySetting::get('bg_accent_color', '#FFCDD2'),
                'bg_overlay_color' => CompanySetting::get('bg_overlay_color', 'rgba(183, 28, 28, 0.08)'),
                'bg_pattern_color' => CompanySetting::get('bg_pattern_color', 'rgba(183, 28, 28, 0.03)'),
                'payment_gcash_number' => CompanySetting::get('payment_gcash_number', '0917-123-4567'),
                'payment_gcash_name' => CompanySetting::get('payment_gcash_name', 'Perfect Smash'),
                'payment_instructions' => CompanySetting::get('payment_instructions', 'Please send payment to our GCash number and upload proof of payment.'),
                'contact_viber' => CompanySetting::get('contact_viber', ''),
                'contact_mobile' => CompanySetting::get('contact_mobile', ''),
                'contact_email' => CompanySetting::get('contact_email', ''),
                'facebook_page_url' => CompanySetting::get('facebook_page_url', ''),
                'facebook_page_name' => CompanySetting::get('facebook_page_name', ''),
                'operating_hours_opening' => CompanySetting::get('operating_hours_opening', '08:00'),
                'operating_hours_closing' => CompanySetting::get('operating_hours_closing', '22:00'),
                'operating_hours_enabled' => CompanySetting::get('operating_hours_enabled', '1') === '1',
            ];

            // Add day-specific operating hours to response
            $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
            foreach ($days as $day) {
                $responseData["operating_hours_{$day}_open"] = CompanySetting::get("operating_hours_{$day}_open", '08:00');
                $responseData["operating_hours_{$day}_close"] = CompanySetting::get("operating_hours_{$day}_close", '22:00');
                $responseData["operating_hours_{$day}_operational"] = CompanySetting::get("operating_hours_{$day}_operational", '1') === '1';
            }

            $logoPath = CompanySetting::get('company_logo');
            if ($logoPath) {
                $responseData['company_logo'] = $logoPath;
                $responseData['company_logo_url'] = Storage::url($logoPath);
            }

            $qrCodePath = CompanySetting::get('payment_qr_code');
            if ($qrCodePath) {
                $responseData['payment_qr_code'] = $qrCodePath;
                $responseData['payment_qr_code_url'] = Storage::url($qrCodePath);
            }

            return response()->json([
                'success' => true,
                'message' => 'Company settings updated successfully',
                'data' => $responseData
            ]);
        } catch (\Exception $e) {
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

            return response()->json([
                'success' => true,
                'message' => 'Company logo deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete company logo: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete payment QR code
     */
    public function deletePaymentQrCode(Request $request)
    {
        try {
            $qrCodePath = CompanySetting::get('payment_qr_code');

            if ($qrCodePath && Storage::disk('public')->exists($qrCodePath)) {
                Storage::disk('public')->delete($qrCodePath);
            }

            CompanySetting::set('payment_qr_code', null);

            return response()->json([
                'success' => true,
                'message' => 'Payment QR code deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete payment QR code: ' . $e->getMessage()
            ], 500);
        }
    }
}
