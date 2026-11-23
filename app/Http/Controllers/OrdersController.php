<?php

namespace App\Http\Controllers;

use App\Models\Orders;
use App\Models\Cart;
use App\Models\Dispute;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\XmrPriceController;
use App\Services\Monero\MoneroWallet;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\Log;

class OrdersController extends Controller
{
    public function __construct(private MoneroWallet $wallet)
    {
    }

    /**
     * Display a listing of the user's orders.
     */
    public function index()
    {
        $orders = Orders::getUserOrders(Auth::id());
        
        return view('orders.index', [
            'orders' => $orders
        ]);
    }

    /**
     * Display the specified order.
     */
    public function show($uniqueUrl)
    {
        $order = Orders::findByUrl($uniqueUrl);
        
        if (!$order) {
            abort(404);
        }

        // Check if the user is either the buyer or the vendor
        if ($order->user_id !== Auth::id() && $order->vendor_id !== Auth::id()) {
            abort(403, 'Unauthorized access.');
        }

        // Determine if the current user is the buyer or vendor
        $isBuyer = $order->user_id === Auth::id();

        // Payments, expiry and automatic status changes are handled by the
        // escrow:process scheduled command. The buyer's payment page also
        // checks this order right away so a payment shows up without waiting.
        $qrCode = null;
        if ($isBuyer && $order->status === Orders::STATUS_WAITING_PAYMENT) {
            if (empty($order->payment_address)) {
                $xmrRate = (new XmrPriceController())->getXmrPrice();

                if ($xmrRate === 'UNAVAILABLE') {
                    return redirect()->back()->with('error', 'Unable to get XMR price. Please try again later.');
                }

                if (!$order->generatePaymentAddress($this->wallet, $xmrRate)) {
                    return redirect()->back()->with('error', 'Unable to generate payment address. Please try again.');
                }
            }

            // Look for payments before checking the expiry, so a payment made
            // in time is never cancelled.
            try {
                $order->checkPayments($this->wallet);
            } catch (\Exception $e) {
                Log::error('Error checking payments: ' . $e->getMessage());
            }

            if ($order->expireIfUnpaid()) {
                $message = 'This order has been automatically cancelled because the payment window has expired.';
                if (\App\Support\Xmr::isPositive($order->total_received_xmr)) {
                    $message .= ' The amount you paid will be refunded to one of your return addresses.';
                }

                return redirect()->route('orders.show', $order->unique_url)->with('info', $message);
            }

            // Generate QR code if payment is not completed
            if (!$order->is_paid && $order->payment_address) {
                try {
                    $qrCode = $this->generateQrCode($order->payment_address);
                } catch (\Exception $e) {
                    Log::error('Error generating QR code: ' . $e->getMessage());
                }
            }
        }

        // If the user is the buyer and the order is completed, prepare existing reviews for each order item.
        if ($isBuyer && $order->status === 'completed') {
            foreach ($order->items as $item) {
                $item->existingReview = \App\Models\ProductReviews::where('user_id', Auth::id())
                    ->where('order_item_id', $item->id)
                    ->first();
            }
        }
        
        // Get dispute if it exists
        $dispute = $order->dispute;
        
        // Calculate total number of items, accounting for bulk options
        $totalItems = 0;
        foreach($order->items as $item) {
            if($item->bulk_option && isset($item->bulk_option['amount'])) {
                $totalItems += $item->quantity * $item->bulk_option['amount'];
            } else {
                $totalItems += $item->quantity;
            }
        }
        
        return view('orders.show', [
            'order' => $order,
            'isBuyer' => $isBuyer,
            'dispute' => $dispute,
            'qrCode' => $qrCode,
            'totalItems' => $totalItems
        ]);
    }

    /**
     * Create a new order from the cart items.
     */
    public function store(Request $request)
    {
        try {
            $user = Auth::user();
            $cartItems = Cart::where('user_id', $user->id)->with(['product', 'product.user'])->get();
            
            if ($cartItems->isEmpty()) {
                return redirect()->route('cart.index')->with('error', 'Your cart is empty.');
            }
            
            // Get vendor ID from cart items
            $vendorId = $cartItems->first()->product->user_id;
            
            // Check if user can create a new order with this vendor (spam prevention)
            [$canCreate, $reason] = Orders::canCreateNewOrder($user->id, $vendorId);
            
            if (!$canCreate) {
                return redirect()->route('cart.checkout')->with('error', $reason);
            }
            
            // Calculate order totals
            $subtotal = Cart::getCartTotal($user);
            $commissionPercentage = config('marketplace.commission_percentage');
            $commission = ($subtotal * $commissionPercentage) / 100;
            $total = $subtotal + $commission;
            
            // Create the order
            $order = Orders::createFromCart($user, $cartItems, $subtotal, $commission, $total);
            
            // Clear the cart
            Cart::where('user_id', $user->id)->delete();
            
            return redirect()->route('orders.show', $order->unique_url)
                ->with('success', 'Order created successfully. Please complete the payment.');
                
        } catch (\Exception $e) {
            Log::error('Failed to create order: ' . $e->getMessage());
            return redirect()->route('cart.checkout')
                ->with('error', 'Failed to create order. Please try again.');
        }
    }

    /**
     * Generate a QR code for the given address.
     */
    private function generateQrCode($address)
    {
        try {
            $result = Builder::create()
                ->writer(new PngWriter())
                ->writerOptions([])
                ->data($address)
                ->encoding(new Encoding('UTF-8'))
                ->errorCorrectionLevel(ErrorCorrectionLevel::High)
                ->size(300)
                ->margin(10)
                ->build();
            
            return $result->getDataUri();
        } catch (\Exception $e) {
            Log::error('Error generating QR code: ' . $e->getMessage());
            return null;
        }
    }


    /**
     * Mark the order as sent.
     */
    public function markAsSent($uniqueUrl)
    {
        $order = Orders::findByUrl($uniqueUrl);
        
        if (!$order) {
            abort(404);
        }

        // Verify ownership - only the vendor can mark as sent
        if ($order->vendor_id !== Auth::id()) {
            abort(403, 'Unauthorized action.');
        }

        if ($order->markAsSent()) {
            return redirect()->route('vendor.sales.show', $order->unique_url)
                ->with('success', 'Product marked as sent. The buyer has been notified.');
        }

        return redirect()->route('vendor.sales.show', $order->unique_url)
            ->with('error', 'Unable to mark as sent at this time.');
    }

    /**
     * Mark the order as completed.
     */
    public function markAsCompleted($uniqueUrl)
    {
        $order = Orders::findByUrl($uniqueUrl);
        
        if (!$order) {
            abort(404);
        }

        // Verify ownership - only the buyer can mark as completed
        if ($order->user_id !== Auth::id()) {
            abort(403, 'Unauthorized action.');
        }

        if ($order->markAsCompleted()) {
            return redirect()->route('orders.show', $order->unique_url)
                ->with('success', 'Order marked as completed. The payment to the vendor has been scheduled. Thank you for your purchase.');
        }

        return redirect()->route('orders.show', $order->unique_url)
            ->with('error', 'Unable to mark as completed at this time.');
    }

    /**
     * Mark the order as cancelled.
     */
    public function markAsCancelled($uniqueUrl)
    {
        $order = Orders::findByUrl($uniqueUrl);
        
        if (!$order) {
            abort(404);
        }

        // Verify ownership - both buyer and vendor can cancel
        if ($order->user_id !== Auth::id() && $order->vendor_id !== Auth::id()) {
            abort(403, 'Unauthorized action.');
        }

        // The permission check runs again on the locked order inside cancelBy().
        if ($order->cancelBy(Auth::id())) {
            // Determine the redirect route based on whether the user is buyer or vendor
            $isBuyer = $order->user_id === Auth::id();
            $route = $isBuyer ? 'orders.show' : 'vendor.sales.show';
            
            return redirect()->route($route, $order->unique_url)
                ->with('success', 'Order has been cancelled successfully.');
        }

        if ($order->status === Orders::STATUS_DISPUTED) {
            return redirect()->back()->with('error', 'Disputed orders cannot be cancelled. An administrator will resolve the dispute.');
        }

        if ($order->user_id === Auth::id() && $order->status === Orders::STATUS_PRODUCT_SENT) {
            return redirect()->back()->with('error', 'The vendor has already sent this order. Open a dispute if there is a problem with it.');
        }

        return redirect()->back()->with('error', 'Unable to cancel the order at this time.');
    }

    /**
     * Submit a review for a product in a completed order.
     */
    public function submitReview(Request $request, $uniqueUrl, $orderItemId)
    {
        // Find the order
        $order = Orders::findByUrl($uniqueUrl);
        
        if (!$order) {
            abort(404);
        }

        // Verify ownership - only the buyer can submit reviews
        if ($order->user_id !== Auth::id()) {
            abort(403, 'Unauthorized action.');
        }

        // Verify order is completed
        if ($order->status !== Orders::STATUS_COMPLETED) {
            return redirect()->route('orders.show', $order->unique_url)
                ->with('error', 'You can only review products from completed orders.');
        }

        // Find the order item
        $orderItem = $order->items()->where('id', $orderItemId)->first();
        
        if (!$orderItem) {
            abort(404);
        }

        // Check if a review already exists for this item
        $existingReview = \App\Models\ProductReviews::where('user_id', Auth::id())
            ->where('order_item_id', $orderItem->id)
            ->first();
            
        if ($existingReview) {
            return redirect()->route('orders.show', $order->unique_url)
                ->with('error', 'You have already reviewed this product.');
        }

        // Validate the request
        $validated = $request->validate([
            'review_text' => 'required|string|min:8|max:800',
            'sentiment' => 'required|in:positive,mixed,negative',
        ]);

        // Create the review
        \App\Models\ProductReviews::create([
            'product_id' => $orderItem->product_id,
            'user_id' => Auth::id(),
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'review_text' => $validated['review_text'],
            'sentiment' => $validated['sentiment'],
        ]);

        return redirect()->route('orders.show', $order->unique_url)
            ->with('success', 'Your review has been submitted successfully.');
    }
}

