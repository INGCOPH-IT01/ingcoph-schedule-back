<?php

namespace App\Http\Controllers;

use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\Product;
use App\Models\CartTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PosSaleController extends Controller
{
    /**
     * Get all POS sales with filters.
     */
    public function index(Request $request)
    {
        $query = PosSale::with(['user:id,first_name,last_name', 'customer:id,first_name,last_name,email', 'booking', 'saleItems.product']);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by date range
        if ($request->has('date_from') && $request->has('date_to')) {
            // Parse dates in application timezone
            $dateFromStart = \Carbon\Carbon::parse($request->date_from, config('app.timezone'))->startOfDay();
            $dateToEnd = \Carbon\Carbon::parse($request->date_to, config('app.timezone'))->endOfDay();
            $query->whereBetween('sale_date', [$dateFromStart, $dateToEnd]);
        }

        // Filter by user (staff/admin)
        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Filter by customer
        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        // Search by sale number, customer name, or payment reference
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('sale_number', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('payment_reference', 'like', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'sale_date');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage = $request->get('per_page', 50);
        $sales = $query->paginate($perPage);

        return response()->json($sales);
    }

    /**
     * Get a single POS sale.
     */
    public function show($id)
    {
        $sale = PosSale::with([
            'user:id,first_name,last_name',
            'customer:id,first_name,last_name,email',
            'booking.cartItems.bookingForUser:id,first_name,last_name,email',
            'booking.cartItems.court',
            'booking.user:id,first_name,last_name',
            'saleItems.product.category',
            'stockMovements.product'
        ])->findOrFail($id);

        return response()->json($sale);
    }

    /**
     * Create a new POS sale.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'booking_id' => 'nullable|exists:cart_transactions,id',
            'customer_id' => 'nullable|exists:users,id',
            'customer_name' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.discount' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|string',
            'payment_reference' => 'nullable|string',
            'notes' => 'nullable|string',
            'status' => 'nullable|in:pending,completed,cancelled,refunded',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            // Calculate totals
            $subtotal = 0;
            $saleItemsData = [];

            foreach ($request->items as $item) {
                $product = Product::findOrFail($item['product_id']);

                // Check stock availability
                if ($product->track_inventory && $product->stock_quantity < $item['quantity']) {
                    DB::rollBack();
                    return response()->json([
                        'message' => "Insufficient stock for product: {$product->name}. Available: {$product->stock_quantity}"
                    ], 422);
                }

                $itemDiscount = $item['discount'] ?? 0;
                $itemSubtotal = ($product->price * $item['quantity']) - $itemDiscount;
                $subtotal += $itemSubtotal;

                $saleItemsData[] = [
                    'product_id' => $product->id,
                    'product' => $product,
                    'quantity' => $item['quantity'],
                    'unit_price' => $product->price,
                    'unit_cost' => $product->cost,
                    'discount' => $itemDiscount,
                    'subtotal' => $itemSubtotal,
                ];
            }

            $discount = $request->discount ?? 0;
            $tax = $request->tax ?? 0;
            $totalAmount = $subtotal - $discount + $tax;

            // Create POS sale
            $sale = PosSale::create([
                'booking_id' => $request->booking_id,
                'user_id' => auth()->id(),
                'customer_id' => $request->customer_id,
                'customer_name' => $request->customer_name,
                'subtotal' => $subtotal,
                'tax' => $tax,
                'discount' => $discount,
                'total_amount' => $totalAmount,
                'payment_method' => $request->payment_method,
                'payment_reference' => $request->payment_reference,
                'status' => $request->status ?? 'completed',
                'notes' => $request->notes,
                'sale_date' => now(),
            ]);

            // Create sale items and decrease stock
            foreach ($saleItemsData as $itemData) {
                PosSaleItem::create([
                    'pos_sale_id' => $sale->id,
                    'product_id' => $itemData['product_id'],
                    'quantity' => $itemData['quantity'],
                    'unit_price' => $itemData['unit_price'],
                    'unit_cost' => $itemData['unit_cost'],
                    'discount' => $itemData['discount'],
                    'subtotal' => $itemData['subtotal'],
                ]);

                // Decrease stock if status is completed
                if ($sale->status === 'completed') {
                    $itemData['product']->decreaseStock(
                        $itemData['quantity'],
                        auth()->id(),
                        "Sale #{$sale->sale_number}",
                        $sale->id
                    );
                }
            }

            // If linked to booking, update the booking's POS amount
            if ($request->booking_id) {
                $booking = CartTransaction::findOrFail($request->booking_id);
                $booking->pos_amount = ($booking->pos_amount ?? 0) + $totalAmount;
                $booking->total_price = ($booking->booking_amount ?? $booking->total_price) + $booking->pos_amount;
                $booking->save();
            }

            DB::commit();

            return response()->json($sale->load(['saleItems.product', 'user', 'customer']), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to create sale: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Update a POS sale status.
     */
    public function updateStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:pending,completed,cancelled,refunded',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $sale = PosSale::findOrFail($id);
        $oldStatus = $sale->status;

        DB::beginTransaction();
        try {
            // If changing from completed to cancelled/refunded, restore stock
            if ($oldStatus === 'completed' && in_array($request->status, ['cancelled', 'refunded'])) {
                foreach ($sale->saleItems as $item) {
                    $item->product->increaseStock(
                        $item->quantity,
                        auth()->id(),
                        'return',
                        "Refund/Cancel of sale #{$sale->sale_number}"
                    );
                }

                // Update booking POS amount if applicable
                if ($sale->booking_id) {
                    $booking = CartTransaction::findOrFail($sale->booking_id);
                    $booking->pos_amount = ($booking->pos_amount ?? 0) - $sale->total_amount;
                    $booking->total_price = ($booking->booking_amount ?? $booking->total_price) + $booking->pos_amount;
                    $booking->save();
                }
            }

            // If changing from pending/cancelled to completed, decrease stock
            if (in_array($oldStatus, ['pending', 'cancelled']) && $request->status === 'completed') {
                foreach ($sale->saleItems as $item) {
                    $product = $item->product;
                    if ($product->track_inventory && $product->stock_quantity < $item->quantity) {
                        DB::rollBack();
                        return response()->json([
                            'message' => "Insufficient stock for product: {$product->name}"
                        ], 422);
                    }

                    $product->decreaseStock(
                        $item->quantity,
                        auth()->id(),
                        "Sale #{$sale->sale_number}",
                        $sale->id
                    );
                }

                // Update booking POS amount if applicable
                if ($sale->booking_id) {
                    $booking = CartTransaction::findOrFail($sale->booking_id);
                    $booking->pos_amount = ($booking->pos_amount ?? 0) + $sale->total_amount;
                    $booking->total_price = ($booking->booking_amount ?? $booking->total_price) + $booking->pos_amount;
                    $booking->save();
                }
            }

            $sale->update([
                'status' => $request->status,
                'notes' => $request->notes ? $sale->notes . "\n" . $request->notes : $sale->notes,
            ]);

            DB::commit();

            return response()->json($sale->load(['saleItems.product']));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to update status: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Delete a POS sale.
     */
    public function destroy($id)
    {
        $sale = PosSale::findOrFail($id);

        // Only allow deletion of pending sales
        if ($sale->status !== 'pending') {
            return response()->json([
                'message' => 'Only pending sales can be deleted. Please cancel or refund completed sales.'
            ], 422);
        }

        $sale->delete();

        return response()->json(['message' => 'Sale deleted successfully']);
    }

    /**
     * Get POS statistics.
     */
    public function statistics(Request $request)
    {
        $dateFrom = $request->get('date_from', now()->startOfMonth()->toDateString());
        $dateTo = $request->get('date_to', now()->toDateString());

        // Parse dates in application timezone and ensure dateTo includes the entire day
        $dateFromStart = \Carbon\Carbon::parse($dateFrom, config('app.timezone'))->startOfDay();
        $dateToEnd = \Carbon\Carbon::parse($dateTo, config('app.timezone'))->endOfDay();

        $query = PosSale::whereBetween('sale_date', [$dateFromStart, $dateToEnd]);

        $stats = [
            'total_sales' => $query->clone()->completed()->count(),
            'total_revenue' => $query->clone()->completed()->sum('total_amount'),
            'today_sales' => PosSale::today()->completed()->count(),
            'today_revenue' => PosSale::today()->completed()->sum('total_amount'),
            'pending_sales' => PosSale::where('status', 'pending')->count(),
            'cancelled_sales' => $query->clone()->where('status', 'cancelled')->count(),
            'refunded_sales' => $query->clone()->where('status', 'refunded')->count(),
        ];

        // Only admins can see profit data
        if (auth()->check() && auth()->user()->role === 'admin') {
            $stats['total_profit'] = 0;

            // Calculate profit
            $completedSales = $query->clone()->completed()->with('saleItems')->get();
            $totalProfit = 0;
            foreach ($completedSales as $sale) {
                $totalProfit += $sale->profit;
            }
            $stats['total_profit'] = $totalProfit;
        }

        return response()->json($stats);
    }

    /**
     * Get sales report data.
     */
    public function salesReport(Request $request)
    {
        $dateFrom = $request->get('date_from', now()->startOfMonth()->toDateString());
        $dateTo = $request->get('date_to', now()->toDateString());

        // Parse dates in application timezone and ensure dateTo includes the entire day
        $dateFromStart = \Carbon\Carbon::parse($dateFrom, config('app.timezone'))->startOfDay();
        $dateToEnd = \Carbon\Carbon::parse($dateTo, config('app.timezone'))->endOfDay();

        $sales = PosSale::with(['user:id,first_name,last_name', 'customer:id,first_name,last_name', 'saleItems.product'])
            ->whereBetween('sale_date', [$dateFromStart, $dateToEnd])
            ->completed()
            ->orderBy('sale_date', 'desc')
            ->get();

        // Hide profit data for non-admin users
        if (!auth()->check() || auth()->user()->role !== 'admin') {
            $sales->makeHidden('profit');
        }

        return response()->json($sales);
    }

    /**
     * Get product sales summary.
     */
    public function productSalesSummary(Request $request)
    {
        $dateFrom = $request->get('date_from', now()->startOfMonth()->toDateString());
        $dateTo = $request->get('date_to', now()->toDateString());

        // Parse dates in application timezone and ensure dateTo includes the entire day
        $dateFromStart = \Carbon\Carbon::parse($dateFrom, config('app.timezone'))->startOfDay();
        $dateToEnd = \Carbon\Carbon::parse($dateTo, config('app.timezone'))->endOfDay();

        $query = DB::table('pos_sale_items')
            ->join('pos_sales', 'pos_sale_items.pos_sale_id', '=', 'pos_sales.id')
            ->join('products', 'pos_sale_items.product_id', '=', 'products.id')
            ->whereBetween('pos_sales.sale_date', [$dateFromStart, $dateToEnd])
            ->where('pos_sales.status', 'completed')
            ->select(
                'products.id',
                'products.name',
                'products.sku',
                DB::raw('SUM(pos_sale_items.quantity) as total_quantity'),
                DB::raw('SUM(pos_sale_items.subtotal) as total_revenue')
            );

        // Only admins can see profit data
        if (auth()->check() && auth()->user()->role === 'admin') {
            $query->addSelect(DB::raw('SUM((pos_sale_items.unit_price - pos_sale_items.unit_cost) * pos_sale_items.quantity) as total_profit'));
        }

        $productSales = $query
            ->groupBy('products.id', 'products.name', 'products.sku')
            ->orderBy('total_revenue', 'desc')
            ->get();

        return response()->json($productSales);
    }
}
