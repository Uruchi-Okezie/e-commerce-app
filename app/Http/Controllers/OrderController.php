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
        return response()->json($orders);
    }

    /**
     * Store a newly created order in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $request->validate([
            'items' => 'required|array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        try {
            return DB::transaction(function () use ($request) {
                $total = 0;
                $items = [];

                // Calculate total and verify stock
                foreach ($request->items as $item) {
                    $product = Product::findOrFail($item['product_id']);
                    
                    if ($product->stock < $item['quantity']) {
                        throw new \Exception("Insufficient stock for product: {$product->name}");
                    }

                    $total += $product->price * $item['quantity'];
                    $items[] = [
                        'product' => $product,
                        'quantity' => $item['quantity']
                    ];
                }

                // Create order
                $order = Order::create([
                    'user_id' => $request->user()->id,
                    'total_price' => $total
                ]);

                // Create order items and update stock
                foreach ($items as $item) {
                    $order->items()->create([
                        'product_id' => $item['product']->id,
                        'quantity' => $item['quantity'],
                        'price_at_purchase' => $item['product']->price
                    ]);

                    // Update stock
                    $item['product']->decrement('stock', $item['quantity']);
                }

                return response()->json([
                    'message' => 'Order created successfully',
                    'order' => $order->load('items.product')
                ], 201);
            });
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
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
        $order = Order::with('items.product')
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);
            
        return response()->json($order);
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