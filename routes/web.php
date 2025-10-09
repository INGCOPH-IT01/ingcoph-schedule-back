<?php

use Illuminate\Support\Facades\Route;
use App\Models\CartItem;
use App\Models\Booking;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

// Debug route to check cart transactions
Route::get('/debug/transactions', function () {
    $allTransactions = \App\Models\CartTransaction::with(['user', 'cartItems'])->latest()->get();
    $pendingOrCompleted = \App\Models\CartTransaction::whereIn('status', ['pending', 'completed'])->with(['user', 'cartItems'])->latest()->get();
    
    return response()->json([
        'all_transactions' => $allTransactions->count(),
        'pending_or_completed' => $pendingOrCompleted->count(),
        'transactions' => $allTransactions->map(function($t) {
            return [
                'id' => $t->id,
                'user_id' => $t->user_id,
                'user_name' => $t->user->name,
                'status' => $t->status,
                'approval_status' => $t->approval_status,
                'payment_status' => $t->payment_status,
                'total_price' => $t->total_price,
                'cart_items_count' => $t->cartItems->count(),
                'created_at' => $t->created_at
            ];
        })
    ]);
});

// Debug route to check cart items
Route::get('/debug/cart', function () {
    $cartItems = CartItem::with(['user', 'court', 'booking'])->get();
    $bookings = Booking::with(['cartItems'])->latest()->take(10)->get();
    
    return response()->json([
        'total_cart_items' => $cartItems->count(),
        'active_cart_items' => CartItem::whereNull('booking_id')->count(),
        'checked_out_cart_items' => CartItem::whereNotNull('booking_id')->count(),
        'recent_bookings' => $bookings->count(),
        'cart_items' => $cartItems,
        'bookings' => $bookings
    ]);
});

// Test checkout route
Route::get('/debug/test-checkout', function () {
    $user = \App\Models\User::first();
    if (!$user) {
        return response()->json(['error' => 'No users found']);
    }
    
    $cartItems = \App\Models\CartItem::where('user_id', $user->id)
        ->whereNull('booking_id')
        ->get();
    
    if ($cartItems->isEmpty()) {
        return response()->json(['error' => 'No cart items found for user ' . $user->name]);
    }
    
    return response()->json([
        'message' => 'Ready to checkout',
        'user' => $user->name,
        'cart_items' => $cartItems->count(),
        'items' => $cartItems,
        'test_url' => url('/debug/do-checkout')
    ]);
});

// Actually perform checkout
Route::get('/debug/do-checkout', function () {
    $user = \App\Models\User::first();
    
    // Simulate the checkout request
    $request = new \Illuminate\Http\Request();
    $request->merge([
        'payment_method' => 'gcash',
        'gcash_reference' => 'DEBUG_TEST_' . time(),
        'proof_of_payment' => 'data:image/png;base64,test'
    ]);
    $request->setUserResolver(function () use ($user) {
        return $user;
    });
    
    $controller = new \App\Http\Controllers\Api\CartController();
    
    try {
        $response = $controller->checkout($request);
        return response()->json([
            'status' => 'success',
            'response' => $response->getData()
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 500);
    }
});