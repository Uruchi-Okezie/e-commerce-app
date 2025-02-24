<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class OrderController extends Controller
{
    /**
     * Display a listing of orders for the authenticated user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $orders = $request->user()->orders()->with('items.product')->get();
        
        return response()->json([
            'orders' => $orders
        ]);
    }

    /**
     * Store a newly created order in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            return DB::transaction(function () use ($request) {
                $totalPrice = 0;
                $items = [];

                // Validate stock and calculate total price
                foreach ($request->items as $item) {
                    $product = Product::findOrFail($item['product_id']);
                    
                    // Check if enough stock is available
                    if ($product->stock < $item['quantity']) {
                        throw new \Exception("Not enough stock for product: {$product->name}");
                    }

                    $itemPrice = $product->price * $item['quantity'];
                    $totalPrice += $itemPrice;

                    $items[] = [
                        'product' => $product,
                        'quantity' => $item['quantity'],
                        'price' => $itemPrice
                    ];
                }

                // Create order
                $order = Order::create([
                    'user_id' => $request->user()->id,
                    'total_price' => $totalPrice,
                ]);

                // Create order items and update stock
                foreach ($items as $item) {
                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $item['product']->id,
                        'quantity' => $item['quantity'],
                        'price_at_purchase' => $item['product']->price,
                    ]);

                    // Decrease product stock
                    $item['product']->decrement('stock', $item['quantity']);
                }

                // Load the items relationship
                $order->load('items.product');

                return response()->json([
                    'message' => 'Order created successfully',
                    'order' => $order
                ], 201);
            });
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create order',
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Display the specified order.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show(Request $request, $id)
    {
        $order = Order::with('items.product')->findOrFail($id);

        // Ensure the user owns this order or is an admin
        if ($order->user_id !== $request->user()->id && !$this->isAdmin($request->user())) {
            return response()->json([
                'message' => 'Unauthorized access to this order'
            ], 403);
        }

        return response()->json([
            'order' => $order
        ]);
    }

    /**
     * Check if the user is an admin.
     *
     * @param  \App\Models\User|null  $user
     * @return bool
     */
    private function isAdmin($user)
    {
        // This is a placeholder. In a real application, you would check
        // if the user has admin role or permissions.
        // For example: return $user && $user->hasRole('admin');
        
        // For this example, we'll just assume admin has id = 1
        return $user && $user->id === 1;
    }
}