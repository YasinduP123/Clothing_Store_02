<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Product_Sales;
use App\Models\Promotion;
use App\Models\ReturnItem;
use App\Models\Sales;
use App\Models\SalesReturnItem;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesController extends Controller
{
    public function index()
    {
        // Fetch all sales records with product details and customer
        $sales = Sales::with(['product_sales.product', 'customer'])->get();
        return response()->json([
            'status' => 'success',
            'data' => $sales
        ]);
    }

    public function show($id)
    {
        // Fetch a specific sales record by ID with product details
        $sales = Sales::with(['product_sales.product'])->find($id);
        if (!$sales) {
            return response()->json([
                'error' => 'Sales record not found',
                'id' => $id
            ], 404);
        }
        return response()->json([
            'id' => $id,
            'data' => $sales
        ]);
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'cashier_id' => 'required',
                'payment_type' => 'required|in:CASH,CREDIT_CARD,DEBIT_CARD',
                'items' => 'required|array',
                'discount' => 'required|numeric|min:0|max:100',
            ]);
            
            $total = 0;
            foreach ($request->get('items') as $item) {
                $total += $item['price'] * $item['quantity'];
            }

            $discountAmount = ($total * $request->get('discount')) / 100;
            $finalTotal = $total - $discountAmount;

            $salesRecord = [
                'customer_id' => $request->get('customer_id'),
                'cashier_id' => $request->get('cashier_id'),
                'payment_type' => $request->get('payment_type'),
                'time' => now(),
                'status' => 1, // Completed order
                'amount' => $finalTotal,
                'discount' => $request->get('discount'),
            ];

            DB::beginTransaction();
            try {
                $sales = Sales::create($salesRecord);

                $productIds = collect($request->get('items'))->pluck('product_id')->unique();
                $products = Product::whereIn('id', $productIds)->get()->keyBy('id');
                $inventoryUpdates = [];

                foreach ($request->get('items') as $item) {
                    $product = $products->get($item['product_id']);

                    if (!$product) {
                        throw new \Exception("Product with ID {$item['product_id']} not found.");
                    }

                    $inventory = $product->inventory;

                    if (!$inventory) {
                        throw new \Exception("Inventory for Product with ID {$item['product_id']} not found.");
                    }

                    if ($inventory->quantity < $item['quantity']) {
                        throw new \Exception('Not enough stock for product ID ' . $item['product_id']);
                    }

                    Product_Sales::create([
                        'sales_id' => $sales->id,
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'price' => $item['price'],
                    ]);

                    $inventory->quantity -= $item['quantity'];
                    $inventory->save();
                    
                    $this->updateInventoryStatus($inventory);
                }

                DB::commit();
                
                return response()->json([
                    'message' => 'Sales record created successfully',
                    'data' => $sales
                ], 201);
                
            } catch (\Throwable $th) {
                DB::rollBack();
                return response()->json([
                    'error' => 'An error occurred while creating a new sales record',
                    'details' => $th->getMessage()
                ], 500);
            }
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Validation failed',
                'error' => $th->getMessage()
            ], 422);
        }
    }

    public function update(Request $request, $id)
    {
        // Update an existing sales record
        $sales = Sales::find($id);
        if (!$sales) {
            return response()->json([
                'error' => 'Sales record not found',
                'id' => $id
            ], 404);
        }

        try {
            $request->validate([
                'cashier_id' => 'required',
                'payment_type' => 'required|in:CASH,CREDIT_CARD,DEBIT_CARD',
                'items' => 'required',
                'status' => 'required',
            ]);
        } catch (\Illuminate\Validation\ValidationException $th) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $th->errors()
            ], 422);
        }

        $total = 0;

        foreach ($request->get('items') as $item) {
            $total += $item['price'] * $item['quantity'];
        }

        $total -= $total*$request->get('discount')/100;

        $salesRecord = [
            'customer_id' => $request->get('customer_id'),
            'cashier_id' => $request->get('cashier_id'),
            'payment_type' => $request->get('payment_type'),
            'time' => new \DateTime('now'),
            'status' => $request->get('status'),
            'amount' => $total,
            'discount' => $request->get('discount'),
        ];

        DB::beginTransaction();
        try {
            $this->updateSales($request, $sales, $salesRecord);

            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            // Return a generic error message
            return response()->json([
                'error' => 'An error occurred while updating the sales record',
                'details' => $th->getMessage()
            ], 500);
        }

        return response()->json([
            'message' => 'Sales record updated successfully',
            'data' => $sales
        ], 200);
    }

    public function return(Request $request, $id)
    {
        // Return items to inventory
        $sales = Sales::find($id);
        if (!$sales) {
            return response()->json([
                'error' => 'Sales record not found',
                'id' => $id
            ], 404);
        }

        $returnItems[] = [];

        try {
            $request->validate([
                'cashier_id' => 'required',
                'payment_type' => 'required',
                'items' => 'required',
                'status' => 'required',
            ]);
        } catch (\Illuminate\Validation\ValidationException $th) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $th->errors()
            ], 422);
        }

        $date = now();
        $returnItems = [];
        $salesReturnItems = [];

        DB::beginTransaction();
        try {
            // First, get all products being returned
            $productIds = collect($request->get('items'))->pluck('product_id');
            $products = Product::with('inventory')->whereIn('id', $productIds)->get()->keyBy('id');

            // Prepare return items and update inventory
            foreach ($request->get('items') as $item) {
                $product = $products->get($item['product_id']);
                
                if (!$product || !$product->inventory) {
                    throw new \Exception("Product or inventory not found for ID: {$item['product_id']}");
                }

                // Create return item record
                $returnItems[] = [
                    'reason' => $item['reason'],
                    'quantity' => $item['quantity'],
                    'product_id' => $item['product_id'],
                    'created_at' => $date,
                    'updated_at' => $date
                ];

                // Update inventory quantity
                $product->inventory->increment('quantity', $item['quantity']);
                
                // Update inventory status
                $this->updateInventoryStatus($product->inventory);
            }

            // Insert ReturnItems in bulk
            ReturnItem::insert($returnItems);

            // Get the inserted return items
            $insertedReturnItems = ReturnItem::latest('created_at')
                ->take(count($returnItems))
                ->get();

            // Create SalesReturnItems records
            foreach ($insertedReturnItems as $insertedItem) {
                $salesReturnItems[] = [
                    'sales_id' => $sales['id'],
                    'return_item_id' => $insertedItem['id'],
                    'returned_at' => $date,
                    'created_at' => $date,
                    'updated_at' => $date
                ];
            }

            // Insert SalesReturnItems in bulk
            SalesReturnItem::insert($salesReturnItems);

            DB::commit();

            return response()->json([
                'message' => 'Items returned and inventory updated successfully',
                'data' => [
                    'returns' => $returnItems,
                    'updated_products' => $products->map(function($product) {
                        return [
                            'id' => $product->id,
                            'name' => $product->name,
                            'new_quantity' => $product->inventory->quantity
                        ];
                    })
                ]
            ], 200);

        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'error' => 'An error occurred while processing the return',
                'details' => $th->getMessage()
            ], 500);
        }
    }

    private function updateSales(Request $request, $sales, $salesRecord)
    {
        // Update the sales record
        $sales->update($salesRecord);

        // Get all existing products in the sales record
        $product_sales = Product_sales::where('sales_id', $sales['id'])->get()->keyBy('product_id');

        // Prepare arrays for bulk update
        $updateData = [];
        $newItems = [];
        $deleteIds = [];
        $inventoryUpdates = [];
        $currentTimeStamp = now();

        // Collect all product IDs from the new request
        $productIds = collect($request->get('items'))->pluck('product_id')->unique();
        $products = Product::whereIn('id', $productIds)->get()->keyBy('id');

        // Loop through the items in the request
        foreach ($request->get('items') as $newItem) {
            $product = $products->get($newItem['product_id']);

            if (!$product) {
                throw new \Exception("Product with ID {$newItem['product_id']} not found.");
            }

            $inventory = $product->inventory;
            if (!$inventory) {
                throw new \Exception("Inventory for Product with ID {$newItem['product_id']} not found.");
            }

            // If item exists in current sales
            if (isset($product_sales[$newItem['product_id']])) {
                $existingItem = $product_sales[$newItem['product_id']];
                $quantityDiff = $newItem['quantity'] - $existingItem->quantity;

                // Only update inventory if quantity has changed
                if ($quantityDiff !== 0) {
                    // Check if we have enough stock for an increase
                    if ($quantityDiff > 0 && $inventory->quantity < $quantityDiff) {
                        throw new \Exception("Not enough stock for Product ID {$newItem['product_id']}");
                    }

                    // Calculate new inventory quantity
                    $newQuantity = $inventory->quantity - $quantityDiff;
                    $inventoryUpdates[] = [
                        'inventory_id' => $inventory->id,
                        'new_quantity' => $newQuantity
                    ];
                }

                // Update sales item data
                $updateData[] = [
                    'product_id' => $newItem['product_id'],
                    'sales_id' => $sales['id'],
                    'quantity' => $newItem['quantity'],
                    'price' => $newItem['price']
                ];

                unset($product_sales[$newItem['product_id']]);
            } else {
                // For new items
                if ($inventory->quantity < $newItem['quantity']) {
                    throw new \Exception("Not enough stock for Product ID {$newItem['product_id']}");
                }

                $newItems[] = [
                    'product_id' => $newItem['product_id'],
                    'sales_id' => $sales['id'],
                    'quantity' => $newItem['quantity'],
                    'price' => $newItem['price'],
                    'created_at' => $currentTimeStamp,
                    'updated_at' => $currentTimeStamp
                ];

                // Update inventory for new items
                $inventoryUpdates[] = [
                    'inventory_id' => $inventory->id,
                    'new_quantity' => $inventory->quantity - $newItem['quantity']
                ];
            }
        }

        // Handle deletions
        $deleteIds = $product_sales->keys()->toArray();
        if (!empty($deleteIds)) {
            Product_sales::whereIn('product_id', $deleteIds)
                ->where('sales_id', $sales['id'])
                ->delete();

            // Return stock for deleted items
            foreach ($product_sales as $deletedItem) {
                $product = Product::find($deletedItem->product_id);
                if ($product && $product->inventory) {
                    $inventoryUpdates[] = [
                        'inventory_id' => $product->inventory->id,
                        'new_quantity' => $product->inventory->quantity + $deletedItem->quantity
                    ];
                }
            }
        }

        // Apply updates
        foreach ($updateData as $data) {
            Product_sales::where('product_id', $data['product_id'])
                ->where('sales_id', $data['sales_id'])
                ->update([
                    'quantity' => $data['quantity'],
                    'price' => $data['price']
                ]);
        }

        if (!empty($newItems)) {
            Product_sales::insert($newItems);
        }

        // Update inventory quantities and status
        foreach ($inventoryUpdates as $update) {
            $inventory = Inventory::find($update['inventory_id']);
            if ($inventory) {
                $inventory->update(['quantity' => $update['new_quantity']]);
                $this->updateInventoryStatus($inventory);
            }
        }
    }

    private function updateInventoryStatus($inventory)
    {
        $status = 'In Stock';
        if ($inventory->quantity == 0) {
            $status = 'Out Of Stock';
        } elseif ($inventory->quantity < 20) {
            $status = 'Low Stock';
        }
        
        $inventory->status = $status;
        $inventory->save();
    }

    public function salesReportToday()
    {
        $sales = Sales::whereDate('created_at', today())->get();
        $totalCustomers =count(Customer::all());
        $totalSuppiers=count(Supplier::all());
        $totalIncome = 0;
        if ($sales) {
            foreach ($sales as $sale) {
                $totalIncome += $sale['amount'];
            }
        }
        return response()->json([
            "sales_details"=>$sales,
            "total_sales" => count($sales),
            "total_income" => $totalIncome,
            "total_customers" => $totalCustomers,
            "total_suppliers" => $totalSuppiers
        ]);
    }

    public function salesReports(Request $request)
    {
        $startDate = $request->query('from');
        $endDate = $request->query('to');

        if (!$startDate || !$endDate) {
            return response()->json([
                'message' => 'Invalid date range. Please provide both start and end dates.'
            ], 400);
        }

        // Ensure the dates are in the correct format
    try {
        $startDate = new \DateTime($startDate);
        $endDate = new \DateTime($endDate);
    } catch (\Exception $e) {
        return response()->json([
            'message' => 'Invalid date format. Please provide dates in YYYY-MM-DD format.'
        ], 400);
    }

    // Convert the dates to the correct format for the query
    $startDateFormatted = $startDate->format('Y-m-d 00:00:00');
    $endDateFormatted = $endDate->format('Y-m-d 23:59:59');

    $sales = Sales::select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as total'))
    ->whereBetween('created_at', [$startDateFormatted, $endDateFormatted])
    ->groupBy(DB::raw('DATE(created_at)'))
    ->get();

        return response()->json([
            "total_sales" => $sales,
            "start"=>$startDate,
            "end"=>$endDate,
        ]);
    }

    public function bestSelling()
    {
        $bestSellingProducts = Product_Sales::select('product_id', DB::raw('count(*) as total'))
            ->groupBy('product_id')
            ->orderBy('total', 'desc')
            ->first();

        if (!$bestSellingProducts) {
            return response()->json([
                'message' => 'No sales data available'
            ], 404);
        }

        return response()->json([
            'products' => $bestSellingProducts,
            'total_sales' => $bestSellingProducts->total
        ]);
    }

    public function turnOverProducts()
    {
        $turnOverProducts = ReturnItem::select('product_id', DB::raw('count(*) as total'))
            ->groupBy('product_id')
            ->orderBy('total', 'desc')
            ->first();

        if (!$turnOverProducts) {
            return response()->json([
                'message' => 'No sales data available'
            ], 404);
        }

        return response()->json([
            'products' => $turnOverProducts,
            'total_returns' => $turnOverProducts->total
        ]);
    }

    public function paymentDistribution()
    {
        $paymentDistribution = Sales::select('payment_type', DB::raw('count(*) as total'))
            ->groupBy('payment_type')
            ->get();

        if ($paymentDistribution->isEmpty()) {
            return response()->json([
                'message' => 'No sales data available'
            ], 404);
        }

        return response()->json([
            'payment_distribution' => $paymentDistribution
        ]);
    }
}