<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SportController;
use App\Http\Controllers\Api\CourtController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\RecurringScheduleController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CartTransactionController;
use App\Http\Controllers\Api\UserController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Public sport and court routes
Route::get('/sports', [SportController::class, 'index']);
Route::get('/sports/{id}', [SportController::class, 'show']);
Route::get('/courts', [CourtController::class, 'index']);
Route::get('/courts/{id}', [CourtController::class, 'show']);
Route::get('/courts/{courtId}/available-slots', [BookingController::class, 'availableSlots']);
Route::get('/courts/{id}/recent-bookings', [CourtController::class, 'getRecentBookings']);
Route::post('/save-court-image/{id}', [CourtController::class, 'saveImage']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth routes
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    
    // Sport management (admin only)
    Route::middleware('admin')->group(function () {
        Route::post('/sports', [SportController::class, 'store']);
        Route::put('/sports/{id}', [SportController::class, 'update']);
        Route::delete('/sports/{id}', [SportController::class, 'destroy']);
    });
    
    // Court management (admin only)
    Route::middleware('admin')->group(function () {
        Route::post('/courts', [CourtController::class, 'store']);
        Route::put('/courts/{id}', [CourtController::class, 'update']);
        Route::delete('/courts/{id}', [CourtController::class, 'destroy']);
    });
    
    // Cart routes
    Route::get('/cart', [CartController::class, 'index']);
    Route::get('/cart/count', [CartController::class, 'count']);
    Route::post('/cart', [CartController::class, 'store']);
    Route::delete('/cart/{id}', [CartController::class, 'destroy']);
    Route::delete('/cart', [CartController::class, 'clear']);
    Route::post('/cart/checkout', [CartController::class, 'checkout']);
    
    // Cart Transaction routes
    Route::get('/cart-transactions', [CartTransactionController::class, 'index']);
    Route::get('/cart-transactions/{id}', [CartTransactionController::class, 'show']);
    Route::delete('/cart-transactions/{id}', [CartTransactionController::class, 'destroy']);
    
    // Booking routes
    Route::get('/bookings', [BookingController::class, 'index']);
    Route::post('/bookings', [BookingController::class, 'store']);
    
    // QR Code routes (must come before general booking routes to avoid conflicts)
    Route::post('/bookings/validate-qr', [BookingController::class, 'validateQrCode'])->middleware('staff');
    Route::get('/bookings/{id}/qr-code', [BookingController::class, 'getQrCode']);
    
    // General booking routes
    Route::get('/bookings/{id}', [BookingController::class, 'show']);
    Route::put('/bookings/{id}', [BookingController::class, 'update']);
    Route::delete('/bookings/{id}', [BookingController::class, 'destroy']);
    Route::post('/bookings/{id}/upload-proof', [BookingController::class, 'uploadProofOfPayment']);
    
    // Staff routes (staff can scan QR codes and view approved bookings)
    Route::middleware('staff')->group(function () {
        Route::get('/staff/bookings/approved', [BookingController::class, 'getApprovedBookings']);
        Route::post('/staff/cart-transactions/verify-qr', [CartTransactionController::class, 'verifyQr']);
    });
    
    // Recurring schedule routes
    Route::get('/recurring-schedules', [RecurringScheduleController::class, 'index']);
    Route::post('/recurring-schedules', [RecurringScheduleController::class, 'store']);
    Route::get('/recurring-schedules/{recurringSchedule}', [RecurringScheduleController::class, 'show']);
    Route::put('/recurring-schedules/{recurringSchedule}', [RecurringScheduleController::class, 'update']);
    Route::delete('/recurring-schedules/{recurringSchedule}', [RecurringScheduleController::class, 'destroy']);
    Route::post('/recurring-schedules/{recurringSchedule}/generate-bookings', [RecurringScheduleController::class, 'generateBookings']);
    
    // Admin booking routes (admin only)
    Route::middleware('admin')->group(function () {
        Route::get('/admin/bookings/pending', [BookingController::class, 'pendingBookings']);
        Route::get('/admin/bookings/stats', [BookingController::class, 'getStats']);
        Route::post('/admin/bookings/{id}/approve', [BookingController::class, 'approveBooking']);
        Route::post('/admin/bookings/{id}/reject', [BookingController::class, 'rejectBooking']);
        
        // Admin recurring schedule routes
        Route::get('/admin/recurring-schedules', [RecurringScheduleController::class, 'adminIndex']);
        
        // Admin cart transaction routes
        Route::get('/admin/cart-transactions', [CartTransactionController::class, 'all']);
        Route::get('/admin/cart-transactions/pending', [CartTransactionController::class, 'pending']);
        Route::post('/admin/cart-transactions/{id}/approve', [CartTransactionController::class, 'approve']);
        Route::post('/admin/cart-transactions/{id}/reject', [CartTransactionController::class, 'reject']);
        
        // Admin user management routes
        Route::get('/admin/users', [UserController::class, 'index']);
        Route::get('/admin/users/stats', [UserController::class, 'stats']);
        Route::post('/admin/users', [UserController::class, 'store']);
        Route::get('/admin/users/{id}', [UserController::class, 'show']);
        Route::put('/admin/users/{id}', [UserController::class, 'update']);
        Route::delete('/admin/users/{id}', [UserController::class, 'destroy']);
    });
});
