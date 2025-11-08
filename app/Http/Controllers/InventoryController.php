<?php

namespace App\Http\Controllers;

use App\Models\ReceivingReport;
use App\Models\ReceivingReportItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    /**
     * Get all receiving reports with filters.
     */
    public function index(Request $request)
    {
        $query = ReceivingReport::with(['user:id,name', 'confirmedBy:id,name', 'items.product'])
            ->withCount('items');

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by date range
        if ($request->has('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        // Search by report number or notes
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('report_number', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = $request->get('per_page', 15);
        $reports = $query->paginate($perPage);

        return response()->json($reports);
    }

    /**
     * Get a single receiving report.
     */
    public function show($id)
    {
        $report = ReceivingReport::with([
            'user:id,name',
            'confirmedBy:id,name',
            'items.product'
        ])->findOrFail($id);

        return response()->json($report);
    }

    /**
     * Create a new receiving report (draft).
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'items.*.notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            // Create receiving report
            $report = ReceivingReport::create([
                'report_number' => ReceivingReport::generateReportNumber(),
                'user_id' => auth()->id(),
                'notes' => $request->notes,
                'status' => 'draft',
            ]);

            // Create items
            foreach ($request->items as $item) {
                $report->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            DB::commit();

            return response()->json($report->load(['user', 'items.product']), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to create receiving report', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update a receiving report (only if draft or pending).
     */
    public function update(Request $request, $id)
    {
        $report = ReceivingReport::findOrFail($id);

        // Only allow updates if report is draft or pending
        if (!in_array($report->status, ['draft', 'pending'])) {
            return response()->json(['message' => 'Cannot update confirmed or cancelled reports'], 422);
        }

        $validator = Validator::make($request->all(), [
            'notes' => 'nullable|string',
            'items' => 'sometimes|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'items.*.notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            // Update report
            $report->update([
                'notes' => $request->notes ?? $report->notes,
            ]);

            // Update items if provided
            if ($request->has('items')) {
                // Delete existing items
                $report->items()->delete();

                // Create new items
                foreach ($request->items as $item) {
                    $report->items()->create([
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'unit_cost' => $item['unit_cost'],
                        'notes' => $item['notes'] ?? null,
                    ]);
                }
            }

            DB::commit();

            return response()->json($report->load(['user', 'confirmedBy', 'items.product']));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to update receiving report', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Submit receiving report for confirmation (change status to pending).
     */
    public function submit($id)
    {
        $report = ReceivingReport::findOrFail($id);

        if ($report->status !== 'draft') {
            return response()->json(['message' => 'Only draft reports can be submitted'], 422);
        }

        if ($report->items()->count() === 0) {
            return response()->json(['message' => 'Cannot submit report without items'], 422);
        }

        $report->status = 'pending';
        $report->save();

        return response()->json([
            'message' => 'Receiving report submitted successfully',
            'report' => $report->load(['user', 'items.product'])
        ]);
    }

    /**
     * Confirm receiving report and adjust stock.
     */
    public function confirm($id)
    {
        $report = ReceivingReport::findOrFail($id);

        if ($report->status !== 'pending') {
            return response()->json(['message' => 'Only pending reports can be confirmed'], 422);
        }

        try {
            $report->confirm(auth()->id());

            return response()->json([
                'message' => 'Receiving report confirmed and stock adjusted successfully',
                'report' => $report->load(['user', 'confirmedBy', 'items.product'])
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to confirm receiving report', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Cancel a receiving report.
     */
    public function cancel($id)
    {
        $report = ReceivingReport::findOrFail($id);

        if (!in_array($report->status, ['draft', 'pending'])) {
            return response()->json(['message' => 'Only draft or pending reports can be cancelled'], 422);
        }

        $report->cancel();

        return response()->json([
            'message' => 'Receiving report cancelled successfully',
            'report' => $report->load(['user', 'items.product'])
        ]);
    }

    /**
     * Delete a receiving report (only if draft or cancelled).
     */
    public function destroy($id)
    {
        $report = ReceivingReport::findOrFail($id);

        if (!in_array($report->status, ['draft', 'cancelled'])) {
            return response()->json(['message' => 'Only draft or cancelled reports can be deleted'], 422);
        }

        $report->delete();

        return response()->json(['message' => 'Receiving report deleted successfully']);
    }

    /**
     * Export receiving reports to Excel.
     */
    public function export(Request $request)
    {
        $query = ReceivingReport::with(['user:id,name', 'confirmedBy:id,name', 'items.product']);

        // Apply same filters as index
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        $query->orderBy('created_at', 'desc');

        $reports = $query->get();

        // Return JSON data for frontend Excel generation
        return response()->json([
            'reports' => $reports,
            'exported_at' => now()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Get inventory statistics.
     */
    public function statistics()
    {
        $stats = [
            'total_reports' => ReceivingReport::count(),
            'draft_reports' => ReceivingReport::where('status', 'draft')->count(),
            'pending_reports' => ReceivingReport::where('status', 'pending')->count(),
            'confirmed_reports' => ReceivingReport::where('status', 'confirmed')->count(),
            'total_items_received' => ReceivingReportItem::whereHas('receivingReport', function ($query) {
                $query->where('status', 'confirmed');
            })->sum('quantity'),
            'total_value' => ReceivingReportItem::whereHas('receivingReport', function ($query) {
                $query->where('status', 'confirmed');
            })->sum('total_cost'),
        ];

        return response()->json($stats);
    }
}
