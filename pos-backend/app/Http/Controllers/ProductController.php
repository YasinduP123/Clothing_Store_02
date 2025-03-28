<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\SupplierProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Exception;
use App\Models\Inventory;
use App\Models\GRNNote;
use App\Models\ProductImages;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;

class ProductController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $products = Product::with('image')->get();
            
            $products = $products->map(function ($product) {
                $image_url = null;
                if ($product->image) {
                    $image_url = asset('storage/' . $product->image->path);
                }
                return array_merge($product->toArray(), ['image_url' => $image_url]);
            });

            return $this->successResponse('Product retrieved successfully', $products);
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
    public function show(string $id): JsonResponse
    {
        try {
            $product = Product::findOrFail($id);
            return $this->successResponse('Product retrieved successfully', $product);
        } catch (Exception $e) {
            return $this->errorResponse('Product not found', 404);
        }
    }
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'supplier_id' => 'required|exists:suppliers,id',
                'seller_price' => 'required|numeric|min:0',
                'discount' => 'required|numeric|min:0',
                'price' => 'required|numeric|min:0',
                'brand_name' => 'required|string|max:255',
                'size' => 'required|string',
                'color' => 'string',
                'category' => 'required|string',
                'added_stock_amount' => 'required|integer',
                'location' => 'string',
                'status' => 'required|string',
                'quantity' => 'required|integer',
                'description' => 'required|string',
                'admin_id' => 'required|exists:admins,id',
                'bar_code' => 'string|nullable'  // Changed validation rule
            ]);

            if ($validator->fails()) {
                return $this->errorResponse($validator->errors(), 422);
            }

            DB::beginTransaction();

            $profit = $request->price - $request->seller_price;

            $productData = [
                'name' => $request->name,
                'price' => $request->price,
                'seller_price' => $request->seller_price,
                'profit' => $profit,
                'discount' => $request->discount ?? 0,
                'size' => $request->size,
                'color' => $request->color,
                'category' => $request->category,
                'description' => $request->description,
                'brand_name' => $request->brand_name,
                'location' => $request->location,
                'status' => $request->status,
                'quantity' => $request->quantity,
                'supplier_id' => $request->supplier_id,
                'admin_id' => $request->admin_id,
                'added_stock_amount' => $request->added_stock_amount,
                'bar_code' => $request->bar_code  // Explicitly include bar_code
            ];

            $product = Product::create($productData);

            // Load supplier details
            $supplier = \App\Models\Supplier::find($request->supplier_id);

            // Generate GRN number
            $grnNumber = 'GRN-' . date('Y') . '-' . str_pad($product->id, 5, '0', STR_PAD_LEFT);

            // Create GRN Note with more detailed information
            $grnNote = $this->createGRNNote($product, [
                'grn_number' => $grnNumber,
                'quantity' => $request->quantity
            ]);

            // Load relationships for complete data
            $product->load(['supplier']);
            $grnNote->load(['supplier', 'admin']);

            SupplierProduct::create([
                'product_id' => $product->id,
                'supplier_id' => $request->supplier_id
            ]);

            DB::commit();

            // Return detailed response
            return $this->successResponse('Product created successfully with GRN', [
                'product' => array_merge($product->toArray(), [
                    'supplierDetails' => [
                        'name' => $supplier->name,
                        'email' => $supplier->email,
                        'contact' => $supplier->contact,
                        'address' => $supplier->address ?? 'N/A'
                    ]
                ]),
                'grn' => [
                    'number' => $grnNumber,
                    'details' => $grnNote,
                    'supplier' => $supplier,
                    'received_date' => now()->format('Y-m-d')
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Product creation failed: ' . $e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $product = Product::findOrFail($id);
            
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'price' => 'required|numeric|min:0',
                'seller_price' => 'required|numeric|min:0',
                'discount' => 'required|numeric|min:0',
                'size' => 'required|string|max:255',
                'color' => 'required|string|max:255',
                'description' => 'required|string',
                'category' => 'required|string|max:255',
                'quantity' => 'required|integer|min:0',
                'location' => 'nullable|string|max:255',
                'status' => 'required|string|max:255',
                'brand_name' => 'required|string|max:255',
                'supplier_id' => 'required|exists:suppliers,id',
                'admin_id' => 'required|exists:admins,id',
                'added_stock_amount' => 'required|integer|min:0',
                'bar_code' => 'nullable|string|max:255'  // Added validation for bar_code
            ]);

            if ($validator->fails()) {
                return $this->errorResponse($validator->errors(), 422);
            }

            // Calculate profit
            $profit = $request->price - $request->seller_price;

            // Update product
            $product->update([
                'name' => $request->name,
                'price' => $request->price,
                'seller_price' => $request->seller_price,
                'profit' => $profit,
                'discount' => $request->discount,
                'size' => $request->size,
                'color' => $request->color,
                'description' => $request->description,
                'category' => $request->category,
                'quantity' => $request->quantity,
                'location' => $request->location,
                'status' => $request->status,
                'brand_name' => $request->brand_name,
                'supplier_id' => $request->supplier_id,
                'admin_id' => $request->admin_id,
                'added_stock_amount' => $request->added_stock_amount,
                'bar_code' => $request->bar_code  // Added bar_code to update
            ]);

            return $this->successResponse('Product updated successfully', $product);
        } catch (\Exception $e) {
            Log::error('Product update error: ' . $e->getMessage());
            return $this->errorResponse('Failed to update product: ' . $e->getMessage(), 500);
        }
    }

    public function destroy(string $id): JsonResponse
    {
        try {
            $product = Product::find($id);
            if (!$product) {
                return $this->errorResponse('Product not found', 404);
            }
            $product->delete();
            return $this->successResponse('Product deleted successfully');
        } catch (Exception $e) {
            return $this->errorResponse('Failed to delete product', 500);
        }
    }

    public function getByInventoryId($inventoryId)
    {
        try {
            $product = Product::with(['supplier' => function($query) {
                $query->select('id', 'name', 'email', 'contact'); // Add any other supplier fields you need
            }])
            ->where('inventory_id', $inventoryId)
            ->firstOrFail();

            // Format the product data with supplier details
            $formattedProduct = array_merge($product->toArray(), [
                'supplierDetails' => $product->supplier ? [
                    'name' => $product->supplier->name,
                    'email' => $product->supplier->email,
                    'contact' => $product->supplier->contact
                ] : null
            ]);

            return response()->json($formattedProduct);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'No product found for this inventory ID'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch product: ' . $e->getMessage()
            ], 500);
        }
    }

    public function uploadImage(Request $request, $id)
    {
        try {
            $product = Product::findOrFail($id);
            
            $request->validate([
                'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048'
            ]);

            if ($request->hasFile('image')) {
                $image = $request->file('image');
                $filename = time() . '.' . $image->getClientOriginalExtension();
                $path = $image->storeAs('products', $filename, 'public');

                // Create or update product image
                $productImage = ProductImages::updateOrCreate(
                    ['product_id' => $product->id],
                    [
                        'path' => $path,
                        'name' => $filename,
                        'size' => $image->getSize()
                    ]
                );

                return $this->successResponse('Image uploaded successfully', [
                    'image' => $productImage,
                    'url' => asset('storage/' . $path)
                ]);
            }

            return $this->errorResponse('No image file provided', 400);
        } catch (\Exception $e) {
            Log::error('Error uploading image: ' . $e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    // response functions

    private function successResponse(string $message, mixed $data = null, int $code = 200): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $data
        ], $code);
    }

    private function errorResponse(mixed $error, int $code): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => $error,
        ], $code);
    }

    protected function createGRNNote($product, $data)
    {
        try {
            return GRNNote::create([
                'grn_number' => $data['grn_number'],
                'product_id' => $product->id,
                'supplier_id' => $product->supplier_id,
                'admin_id' => $product->admin_id,
                'price' => $product->price,
                'product_details' => [
                    'name' => $product->name,
                    'description' => $product->description,
                    'brand_name' => $product->brand_name,
                    'size' => $product->size,
                    'color' => $product->color,
                    'category' => $product->category,
                    'quantity' => $product->quantity,
                    'bar_code' => $product->bar_code,
                    'location' => $product->location
                ],
                'received_date' => now(),
                'previous_quantity' => $product->quantity - $data['quantity'], // Add previous quantity
                'new_quantity' => $product->quantity,                         // Add new quantity
                'adjusted_quantity' => $data['quantity'],                     // Add adjusted quantity
                'adjustment_type' => 'addition'                               // Add adjustment type
            ]);
        } catch (\Exception $e) {
            Log::error('GRN Note creation failed: ' . $e->getMessage());
            throw $e;
        }
    }
}