<?php

namespace App\Http\Controllers;

use App\Models\ProductVariations;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Models\GRNNote;
use App\Models\Product; // Import the Product model
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductVariationController extends Controller
{
    public function index()
    {
        try {
            $variations = ProductVariations::all();
            return $this->successResponse('Product variations retrieved successfully', $variations, 200);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve product variations: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve product variations', 500);
        } catch (\Throwable $e){
            Log::error('An unexpected error occurred: ' . $e->getMessage());
            return $this->errorResponse('An unexpected error occurred', $e->getCode());
        }
    }

    public function show($id)
    {
        try {
            $variation = ProductVariations::findOrFail($id);
            return $this->successResponse('Product variation found', $variation, 200);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Product variation not found', 404);
        } catch (\Exception $e) {
            Log::error('Error retrieving product variation: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve product variation', 500);
        }
    }

    public function store(Request $request)
    {
        DB::beginTransaction();
        try {
            // Validate the request
            $validatedData = $request->validate([
                '*.product_id' => 'required|exists:products,id',
                '*.size' => 'required|string|max:255',
                '*.color' => 'required|string|max:255',
                '*.price' => 'required|numeric|min:0',
                '*.seller_price' => 'required|numeric|min:0',
                '*.discount' => 'required|numeric|min:0|max:100',
                '*.quantity' => 'required|integer|min:0',
                '*.barcode' => 'nullable|string|max:255',
            ]);

            $variations = [];

            foreach ($validatedData as $variationData) {
                // Prepare the product variation data
                $variation = [
                    'product_id' => $variationData['product_id'],
                    'size' => $variationData['size'],
                    'color' => $variationData['color'],
                    'price' => $variationData['price'],
                    'seller_price' => $variationData['seller_price'],
                    'discount' => $variationData['discount'],
                    'quantity' => $variationData['quantity'],
                    'barcode' => $variationData['barcode'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $variations[] = $variation;

                // Create GRN Note
                $product = Product::findOrFail($variationData['product_id']);
                $grnData = [
                    'grn_number' => 'GRN-' . strtoupper(uniqid()), // Generate a unique GRN number
                    'product_id' => $variationData['product_id'],
                    'supplier_id' => $product->supplier_id ?? null,
                    'admin_id' => 1, // Use the authenticated admin ID or default to 1
                    'price' => $variationData['price'],
                    'name' => $product->name,
                    'description' => $product->description,
                    'brand_name' => $product->brand_name,
                    'size' => $variationData['size'],
                    'color' => $variationData['color'],
                    'bar_code' => $variationData['barcode'],
                    'received_date' => now(),
                    'previous_quantity' => 0, // Since it's a new variation
                    'new_quantity' => $variationData['quantity'],
                    'adjusted_quantity' => $variationData['quantity'],
                    'adjustment_type' => 'addition',
                ];

                GRNNote::create($grnData);
            }

            // Insert product variations
            ProductVariations::insert($variations);

            DB::commit();

            return $this->successResponse('Product variations and GRN Notes created successfully', $variations, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Product variation creation failed: ' . $e->getMessage());
            return $this->errorResponse('Failed to create product variations and GRN Notes', 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $validatedData = $request->validate([
                'product_id' => 'required|exists:products,id',
                'size' => 'required|string|max:255',
                'color' => 'required|string|max:255',
                'price' => 'required|numeric|min:0',
                'quantity' => 'required|integer|min:0',
                'barcode' => 'nullable|string|max:255',
            ]);
            $variation = ProductVariations::findOrFail($id);
            $variation->update($validatedData);
            return $this->successResponse('Product variation updated successfully', $variation, 200);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Product variation not found', 404);
        } catch (\Exception $e) {
            Log::error('Product variation update failed: ' . $e->getMessage());
            return $this->errorResponse('Failed to update product variation', $e->getCode());
        }
    }

    public function destroy($id)
    {
        try {
            $variation = ProductVariations::findOrFail($id);
            $variation->delete();
            return $this->successResponse('Product variation deleted successfully', null, 200);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Product variation not found', 404);
        } catch (\Exception $e) {
            Log::error('Product variation deletion failed: ' . $e->getMessage());
            return $this->errorResponse('Failed to delete product variation', 500);
        }
    }

    public function showByProduct($id)
    {
        try {
            $variations = ProductVariations::where('product_id', $id)->get();
            return $this->successResponse('Product variations retrieved successfully', $variations, 200);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve product variations: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve product variations', 500);
        } catch (\Throwable $e) {
            Log::error('Error retrieving product variations: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve product variations', $e->getCode());
        }
    }

    public function showByBarcode($barcode)
    {
        try {
            $variation = ProductVariations::where('barcode', $barcode)->firstOrFail();
            return $this->successResponse('Product variation found', $variation, 200);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Product variation not found', 404);
        } catch (\Exception $e) {
            Log::error('Error retrieving product variation: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve product variation', 500);
        }
    }

    public function createGRN(Request $request, $productId)
    {
        try {
            $product = Product::findOrFail($productId);
            $variations = ProductVariations::where('product_id', $productId)->get();

            if ($variations->isEmpty()) {
                return $this->errorResponse('No variations found for this product', 404);
            }

            $validatedData = $request->validate([
                'grn_number' => 'required|string|max:255',
                'quantity' => 'required|integer|min:0',
            ]);

            $grnNotes = $this->createGRNNote($product, $variations, $validatedData);

            return $this->successResponse('GRN Notes created successfully', $grnNotes, 201);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Product not found', 404);
        } catch (\Exception $e) {
            Log::error('Failed to create GRN Notes: ' . $e->getMessage());
            return $this->errorResponse('Failed to create GRN Notes', 500);
        }
    }

    protected function createGRNNote($product, $variations, $data)
    {
        try {
            $grnNotes = [];

            foreach ($variations as $variation) {
                $grnNotes[] = [
                    'grn_number' => $data['grn_number'],
                    'product_id' => $product->id,
                    'supplier_id' => $product->supplier_id,
                    'admin_id' => $product->admin_id,
                    'product_details' => [
                        'name' => $product->name,
                        'description' => $product->description,
                        'brand_name' => $product->brand_name,
                        'size' => $variation->size,
                        'color' => $variation->color,
                        'category' => $product->category,
                        'quantity' => $variation->quantity,
                        'bar_code' => $variation->barcode,
                        'location' => $product->location
                    ],
                    'received_date' => now(),
                    'previous_quantity' => $variation->quantity - $data['quantity'], // Add previous quantity
                    'new_quantity' => $variation->quantity,                         // Add new quantity
                    'adjusted_quantity' => $data['quantity'],                       // Add adjusted quantity
                    'adjustment_type' => 'addition'                                 // Add adjustment type
                ];
            }

            // Insert all GRN notes at once
            GRNNote::insert($grnNotes);

            return $grnNotes;
        } catch (\Exception $e) {
            Log::error('GRN Note creation failed: ' . $e->getMessage());
            throw $e;
        }
    }

    //============= Response =================

    public function successResponse($message, $data, $status)
    {
        if (is_object($data) && method_exists($data, 'toArray')) {
            $data = $data->toArray();
        }

        if (is_array($data)) {
            foreach ($data as &$item) {
                if (is_array($item) && isset($item['id'])) {
                    $item['product_variation_id'] = $item['id'];
                }
            }
        } elseif (isset($data['id'])) {
            $data['product_variation_id'] = $data['id'];
        }

        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $data
        ], $status);
    }

    public function errorResponse($message, $status)
    {
        return response()->json([
            'status' => 'error',
            'message' => $message
        ], $status);
    }
}
