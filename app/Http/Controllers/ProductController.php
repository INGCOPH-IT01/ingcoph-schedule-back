<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
    /**
     * Get all categories with their products.
     */
    public function getCategories(Request $request)
    {
        $categories = ProductCategory::with(['products' => function ($query) use ($request) {
            if ($request->boolean('active_only')) {
                $query->where('is_active', true);
            }
            if ($request->boolean('in_stock_only')) {
                $query->inStock();
            }
        }])
        ->when($request->boolean('active_only'), function ($query) {
            $query->where('is_active', true);
        })
        ->ordered()
        ->get();

        return response()->json($categories);
    }

    /**
     * Get all products with filters.
     */
    public function index(Request $request)
    {
        $query = Product::with(['category']);

        // Filter by category
        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // Filter by active status
        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        // Filter by stock status
        if ($request->has('stock_status')) {
            switch ($request->stock_status) {
                case 'low':
                    $query->lowStock();
                    break;
                case 'in_stock':
                    $query->inStock();
                    break;
                case 'out_of_stock':
                    $query->where('track_inventory', true)->where('stock_quantity', '<=', 0);
                    break;
            }
        }

        // Search by name, sku, or barcode
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%")
                  ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'name');
        $sortOrder = $request->get('sort_order', 'asc');
        $query->orderBy($sortBy, $sortOrder);

        $products = $query->get();

        return response()->json($products);
    }

    /**
     * Get a single product.
     */
    public function show($id)
    {
        $product = Product::with(['category', 'stockMovements' => function ($query) {
            $query->latest()->limit(50);
        }])->findOrFail($id);

        return response()->json($product);
    }

    /**
     * Create a new product.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'sku' => 'required|string|unique:products,sku',
            'category_id' => 'nullable|exists:product_categories,id',
            'price' => 'required|numeric|min:0',
            'cost' => 'nullable|numeric|min:0',
            'stock_quantity' => 'nullable|integer|min:0',
            'low_stock_threshold' => 'nullable|integer|min:0',
            'unit' => 'nullable|string|max:50',
            'barcode' => 'nullable|string',
            'description' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'track_inventory' => 'boolean',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $request->except('image');

        // Handle image upload
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('products', 'public');
            $data['image'] = $imagePath;
        }

        $product = Product::create($data);

        // If initial stock is provided, create stock movement
        if ($request->has('stock_quantity') && $request->stock_quantity > 0) {
            $product->increaseStock(
                $request->stock_quantity,
                auth()->id(),
                'in',
                'Initial stock'
            );
        }

        return response()->json($product->load('category'), 201);
    }

    /**
     * Update a product.
     */
    public function update(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'sku' => 'sometimes|required|string|unique:products,sku,' . $id,
            'category_id' => 'nullable|exists:product_categories,id',
            'price' => 'sometimes|required|numeric|min:0',
            'cost' => 'nullable|numeric|min:0',
            'stock_quantity' => 'nullable|integer|min:0',
            'low_stock_threshold' => 'nullable|integer|min:0',
            'unit' => 'nullable|string|max:50',
            'barcode' => 'nullable|string',
            'description' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'track_inventory' => 'boolean',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $request->except('image');

        // Handle image upload
        if ($request->hasFile('image')) {
            // Delete old image
            if ($product->image) {
                Storage::disk('public')->delete($product->image);
            }
            $imagePath = $request->file('image')->store('products', 'public');
            $data['image'] = $imagePath;
        }

        $product->update($data);

        return response()->json($product->load('category'));
    }

    /**
     * Delete a product (soft delete).
     */
    public function destroy($id)
    {
        $product = Product::findOrFail($id);
        $product->delete();

        return response()->json(['message' => 'Product deleted successfully']);
    }

    /**
     * Adjust product stock.
     */
    public function adjustStock(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'quantity' => 'required|integer|min:0',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $product = Product::findOrFail($id);
        $product->adjustStock($request->quantity, auth()->id(), $request->notes);

        return response()->json([
            'message' => 'Stock adjusted successfully',
            'product' => $product->fresh()
        ]);
    }

    /**
     * Add stock to product.
     */
    public function addStock(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string',
            'reference_number' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $product = Product::findOrFail($id);
        $product->increaseStock(
            $request->quantity,
            auth()->id(),
            'in',
            $request->notes,
            $request->reference_number
        );

        return response()->json([
            'message' => 'Stock added successfully',
            'product' => $product->fresh()
        ]);
    }

    /**
     * Get stock movements for a product.
     */
    public function stockMovements($id)
    {
        $product = Product::findOrFail($id);
        $movements = $product->stockMovements()
            ->with(['user:id,name', 'posSale:id,sale_number'])
            ->latest()
            ->paginate(50);

        return response()->json($movements);
    }

    /**
     * Get low stock products.
     */
    public function lowStock()
    {
        $products = Product::with('category')
            ->lowStock()
            ->where('is_active', true)
            ->orderBy('stock_quantity', 'asc')
            ->get();

        return response()->json($products);
    }

    /**
     * Create a new category.
     */
    public function storeCategory(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:product_categories,name',
            'description' => 'nullable|string',
            'icon' => 'nullable|string',
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $category = ProductCategory::create($request->all());

        return response()->json($category, 201);
    }

    /**
     * Update a category.
     */
    public function updateCategory(Request $request, $id)
    {
        $category = ProductCategory::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255|unique:product_categories,name,' . $id,
            'description' => 'nullable|string',
            'icon' => 'nullable|string',
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $category->update($request->all());

        return response()->json($category);
    }

    /**
     * Delete a category.
     */
    public function destroyCategory($id)
    {
        $category = ProductCategory::findOrFail($id);

        // Check if category has products
        if ($category->products()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete category with products. Please reassign or delete products first.'
            ], 422);
        }

        $category->delete();

        return response()->json(['message' => 'Category deleted successfully']);
    }
}

