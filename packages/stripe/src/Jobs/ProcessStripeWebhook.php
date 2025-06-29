<?php

namespace Lunar\Stripe\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Lunar\Facades\Payments;
use Lunar\Models\Cart;
use Lunar\Models\Order;
use Lunar\Stripe\Events\Webhook\CartMissingForIntent;
use Lunar\Stripe\Models\StripePaymentIntent;

class ProcessStripeWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(
        public string $paymentIntentId,
        public ?string $orderId
    ) {
        //
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // Do we have an order with this intent?
        $cart = null;
        $order = null;

        if ($this->orderId) {
            $order = Order::find($this->orderId);

            if ($order->placed_at) {
                return;
            }
        }

        if (! $order) {
            $cart = StripePaymentIntent::where('intent_id', $this->paymentIntentId)->first()?->cart ?:
                Cart::where('meta->payment_intent', '=', $this->paymentIntentId)->first();
        }

        if (! $cart && ! $order) {
            CartMissingForIntent::dispatch($this->paymentIntentId);

            return;
        }

        $payment = Payments::driver('stripe')->withData([
            'payment_intent' => $this->paymentIntentId,
        ]);

        if ($order) {
            $payment->order($order)->authorize();

            return;
        }

        $payment->cart($cart->calculate())->authorize();
    }
}
