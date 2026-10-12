<?php

declare(strict_types=1);

namespace SilverShop\Api\Resource;

use SilverShop\Api\ApiException;
use SilverShop\Api\Auth\ApiContext;
use SilverShop\Api\Auth\ApiScope;
use SilverShop\Api\Serializer\OrderSerializer;
use SilverShop\Cart\ShoppingCart;
use SilverShop\Model\Buyable;
use SilverShop\Model\Order;
use SilverShop\Model\OrderItem;
use SilverShop\Page\CartPage;
use SilverShop\Page\CheckoutPage;
use SilverShop\ShopTools;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\SecurityToken;
use SilverStripe\Versioned\Versioned;

/**
 * The `cart` resource — a backwards-compatible superset of core's `api/v1/cart`.
 *
 *  - GET  cart | cart/get       the current cart
 *  - POST cart/add              add a buyable (BC with core)
 *  - POST cart/update           set a buyable's quantity
 *  - POST cart/remove           remove a buyable (BC with core)
 *  - POST cart/clear            empty the cart (BC with core)
 *  - GET  cart/checkout         checkout hand-off: the hosted checkout/cart URLs (no API payment capture)
 *
 * Same-origin storefront JS authenticates by session + CSRF (exactly as core); headless clients authenticate with a
 * bearer token carrying the `write` scope, which both authorises the mutation and binds the cart to the token's member.
 */
class CartResource extends AbstractResource
{
    public function getName(): string
    {
        return 'cart';
    }

    public function handle(HTTPRequest $request, ApiContext $context, array $path): ResourceResult
    {
        $operation = $path[0] ?? 'get';
        if (count($path) > 1) {
            throw ApiException::notFound();
        }

        $cart = $this->resolveCart($context);

        return match ($operation) {
            'get' => $this->get($cart),
            'checkout' => $this->checkout($cart),
            'add' => $this->add($request, $context, $cart),
            'update' => $this->update($request, $context, $cart),
            'remove' => $this->remove($request, $context, $cart),
            'clear' => $this->clear($context, $cart),
            default => throw ApiException::notFound(),
        };
    }

    private function get(ShoppingCart $cart): ResourceResult
    {
        return ResourceResult::ok($this->cartPayload($cart), [], 'cart');
    }

    private function checkout(ShoppingCart $cart): ResourceResult
    {
        $current = $cart->current();
        if (!$current || !$current->Items()->exists()) {
            throw ApiException::badRequest('The cart is empty.');
        }

        return ResourceResult::ok([
            'checkoutUrl' => CheckoutPage::find_link(),
            'cartUrl' => CartPage::find_link(),
            'reference' => (string) $current->getReference(),
        ], [], 'checkout');
    }

    private function add(HTTPRequest $request, ApiContext $context, ShoppingCart $cart): ResourceResult
    {
        $this->authoriseMutation($request, $context);

        $buyable = $this->buyableFromRequest($request, $cart);
        if (!$buyable instanceof Buyable) {
            throw ApiException::notFound('Buyable not found');
        }

        $quantity = $this->requestedQuantity($request, 1);
        $result = $quantity === 0
            ? $cart->remove($buyable, null, $this->params($request))
            : $cart->add($buyable, $quantity, $this->params($request));

        if ($result === null) {
            return $this->cartOperationResult($cart, false, 400);
        }

        $this->bindCartToMember($cart, $context);
        $extra = ($result instanceof OrderItem) ? ['itemId' => (int) $result->ID] : [];

        return $this->cartOperationResult($cart, true, 200, $extra);
    }

    private function update(HTTPRequest $request, ApiContext $context, ShoppingCart $cart): ResourceResult
    {
        $this->authoriseMutation($request, $context);

        $buyable = $this->buyableFromRequest($request, $cart);
        if (!$buyable instanceof Buyable) {
            throw ApiException::notFound('Buyable not found');
        }

        $quantity = $this->requestedQuantity($request, null);
        if ($quantity === null) {
            throw ApiException::badRequest('A quantity of zero or greater is required.');
        }

        $result = $cart->setQuantity($buyable, $quantity, $this->params($request));
        if ($result === null) {
            return $this->cartOperationResult($cart, false, 400);
        }

        $this->bindCartToMember($cart, $context);

        return $this->cartOperationResult($cart, true);
    }

    private function remove(HTTPRequest $request, ApiContext $context, ShoppingCart $cart): ResourceResult
    {
        $this->authoriseMutation($request, $context);

        $buyable = $this->buyableFromRequest($request, $cart);
        if (!$buyable instanceof Buyable) {
            throw ApiException::notFound('Buyable not found');
        }

        if (!$cart->findLineItem($buyable) instanceof OrderItem) {
            throw ApiException::notFound('Item not found in cart');
        }

        $quantity = $this->requestedQuantity($request, null);
        $result = $cart->remove($buyable, $quantity, $this->params($request));

        if ($result === null || $result === false) {
            return $this->cartOperationResult($cart, false, 400);
        }

        return $this->cartOperationResult($cart, true);
    }

    private function clear(ApiContext $context, ShoppingCart $cart): ResourceResult
    {
        // Clearing has no buyable to CSRF-bind a replay to, but still gate it like any mutation.
        $result = $cart->clear();

        return $this->cartOperationResult($cart, $result !== null, $result !== null ? 200 : 400);
    }

    /**
     * Resolve the cart to operate on: for a token-authenticated member, prefer their existing cart order so the
     * cart persists across stateless requests; otherwise the session cart (same-origin JS).
     */
    private function resolveCart(ApiContext $context): ShoppingCart
    {
        $cart = ShoppingCart::singleton();
        $member = $context->isAuthenticated() ? $context->getMember() : null;

        if ($member && $member->exists()) {
            $existing = Order::get()
                ->filter(['MemberID' => $member->ID, 'Status' => 'Cart'])
                ->first();
            if ($existing && $existing->exists()) {
                $cart->setCurrent($existing);
            }
        }

        return $cart;
    }

    private function bindCartToMember(ShoppingCart $cart, ApiContext $context): void
    {
        $member = $context->isAuthenticated() ? $context->getMember() : null;
        $current = $cart->current();
        if ($member && $member->exists() && $current && !$current->MemberID) {
            $current->MemberID = $member->ID;
            $current->write();
        }
    }

    /**
     * Gate a cart mutation: a bearer token needs the write scope; a same-origin session needs a valid CSRF token.
     */
    private function authoriseMutation(HTTPRequest $request, ApiContext $context): void
    {
        if ($context->isAuthenticated()) {
            $this->requireScope($context, ApiScope::WRITE);
            return;
        }

        if (SecurityToken::is_enabled() && !SecurityToken::inst()->checkRequest($request)) {
            throw new ApiException(400, 'Invalid security token, possible CSRF attack.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function cartPayload(ShoppingCart $cart): array
    {
        $current = $cart->current();
        if (!$current || !$current->exists()) {
            return ['id' => null, 'itemCount' => 0, 'items' => [], 'total' => 0.0];
        }

        return OrderSerializer::create()->serialise($current);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function cartOperationResult(ShoppingCart $cart, bool $success, int $status = 200, array $extra = []): ResourceResult
    {
        $payload = array_merge([
            'success' => $success,
            'message' => (string) $cart->getMessage(),
            'messageType' => $cart->getMessageType(),
        ], $extra);

        $payload['cart'] = $this->cartPayload($cart);

        return new ResourceResult($payload, $status, [], 'response');
    }

    private function buyableFromRequest(HTTPRequest $request, ShoppingCart $cart): ?Buyable
    {
        $params = $this->params($request);
        $buyableClass = (string) ($params['Buyable'] ?? \SilverShop\Page\Product::class);
        if (!ClassInfo::exists($buyableClass)) {
            $buyableClass = ShopTools::unsanitiseClassName($buyableClass);
        }

        $buyableId = (int) ($params['ProductID'] ?? $params['BuyableID'] ?? 0);

        if ($buyableId === 0 || !ClassInfo::exists($buyableClass) || !is_a($buyableClass, Buyable::class, true)) {
            return null;
        }

        // Every Buyable is a DataObject; narrow the type so the Versioned/DataObject calls type-check.
        /** @var class-string<Buyable&DataObject> $buyableClass */
        $buyable = DataObject::singleton($buyableClass)->hasExtension(Versioned::class)
            ? Versioned::get_by_stage($buyableClass, Versioned::LIVE)->byID($buyableId)
            : DataObject::get($buyableClass)->byID($buyableId);

        if (!$buyable instanceof Buyable) {
            return null;
        }

        return $cart->getCorrectBuyable($buyable);
    }

    private function requestedQuantity(HTTPRequest $request, ?int $default): ?int
    {
        $params = $this->params($request);
        if (!array_key_exists('quantity', $params) || $params['quantity'] === '' || $params['quantity'] === null) {
            return $default;
        }

        $quantity = (int) $params['quantity'];

        return $quantity < 0 ? null : $quantity;
    }

    /**
     * Merge GET, POST and JSON-body params into one lookup so the same resource serves form and JSON clients.
     *
     * @return array<string, mixed>
     */
    private function params(HTTPRequest $request): array
    {
        $params = $request->requestVars() ?: [];

        $raw = trim((string) $request->getBody());
        if ($raw !== '' && str_contains((string) $request->getHeader('Content-Type'), 'json')) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $params = array_merge($params, $decoded);
            }
        }

        return $params;
    }
}
