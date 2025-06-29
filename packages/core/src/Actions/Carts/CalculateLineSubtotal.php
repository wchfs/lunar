<?php

namespace Lunar\Actions\Carts;

use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Collection;
use Lunar\Base\CartLineModifiers;
use Lunar\DataTypes\Price;
use Lunar\Facades\Pricing;
use Lunar\Models\CartLine;
use Lunar\Models\Contracts\CartLine as CartLineContract;

class CalculateLineSubtotal
{
    /**
     * Execute the action.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $customerGroups
     * @return \Lunar\Models\CartLine
     */
    public function execute(
        CartLineContract $cartLine,
        Collection $customerGroups
    ) {
        /** @var CartLine $cartLine */
        $purchasable = $cartLine->purchasable;
        $cart = $cartLine->cart;
        $unitQuantity = $purchasable->getUnitQuantity();

        $priceInclTax = $cartLine->unitPriceInclTax;

        // we check if any cart line modifiers have already specified a unit price in their calculating() method
        if (! ($price = $cartLine->unitPrice) instanceof Price) {
            $priceResponse = Pricing::currency($cart->currency)
                ->qty($cartLine->quantity)
                ->currency($cart->currency)
                ->customerGroups($customerGroups)
                ->for($purchasable)
                ->get();

            $price = new Price(
                $priceResponse->matched->price->value,
                $cart->currency,
                $purchasable->getUnitQuantity()
            );

            $priceInclTax = new Price(
                $priceResponse->matched->priceIncTax()->value,
                $cart->currency,
                $purchasable->getUnitQuantity()
            );
        }

        $unitPrice = (int) round(
            (($price->decimal / $purchasable->getUnitQuantity())
            * $cart->currency->factor),
            $cart->currency->decimal_places
        );

        $unitPriceInclTax = (int) round(
            (($priceInclTax->decimal / $purchasable->getUnitQuantity())
                * $cart->currency->factor),
            $cart->currency->decimal_places
        );

        $cartLine->subTotal = new Price($unitPrice * $cartLine->quantity, $cart->currency, $unitQuantity);
        $cartLine->unitPrice = new Price($unitPrice, $cart->currency, $unitQuantity);
        $cartLine->unitPriceInclTax = new Price($unitPriceInclTax, $cart->currency, $unitQuantity);

        $pipeline = app(Pipeline::class)
            ->through(
                $this->getModifiers()->toArray()
            );

        return $pipeline->send($cartLine)->via('subtotalled')->thenReturn();
    }

    /**
     * Return the cart line modifiers.
     *
     * @return \Illuminate\Support\Collection
     */
    private function getModifiers()
    {
        return app(CartLineModifiers::class)->getModifiers();
    }
}
