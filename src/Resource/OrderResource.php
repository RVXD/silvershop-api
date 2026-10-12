<?php

declare(strict_types=1);

namespace SilverShop\Api\Resource;

use SilverShop\Api\ApiException;
use SilverShop\Api\Auth\ApiContext;
use SilverShop\Api\Serializer\OrderSerializer;
use SilverShop\Model\Order;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Security\Permission;

/**
 * The `orders` resource — read-only, scoped to the authenticated customer's own placed orders.
 *
 *  - GET orders        the member's placed orders (newest first, paginated)
 *  - GET orders/{id}    a single order the member owns
 *
 * There is no order-create endpoint: checkout is a hosted hand-off (see {@link CartResource}), never an API payment.
 */
class OrderResource extends AbstractResource
{
    use HandlesListParams;

    public function getName(): string
    {
        return 'orders';
    }

    public function handle(HTTPRequest $request, ApiContext $context, array $path): ResourceResult
    {
        $this->assertMethod($request, ['GET']);
        $member = $this->requireMember($context);

        if ($path === []) {
            $list = Order::get()
                ->filter('MemberID', $member->ID)
                ->exclude('Status', Order::config()->get('hidden_status'));
            $list = $this->applySort($list, $request, ['Placed', 'Created', 'Total']);

            $page = $this->paginate($list, $request);
            $serializer = OrderSerializer::create();
            $payload = [];
            foreach ($page['list'] as $order) {
                if ($order->canView($member)) {
                    $payload[] = $serializer->serialise($order);
                }
            }

            return ResourceResult::ok($payload, $page['headers'], 'orders', 'order');
        }

        if (count($path) === 1 && preg_match('/^\d+$/', $path[0]) === 1) {
            $order = Order::get()->byID((int) $path[0]);

            // Order::canView() is permissive by default, so ownership is enforced explicitly here (a member may
            // only read their own orders; admins may read any). A missing/foreign order is reported as 404 so its
            // existence is not revealed.
            $owned = $order && $order->exists() && (int) $order->MemberID === (int) $member->ID;
            if (!$owned && !Permission::check('ADMIN', 'any', $member)) {
                throw ApiException::notFound('Order not found');
            }

            return ResourceResult::ok(OrderSerializer::create()->serialise($order), [], 'order');
        }

        throw ApiException::notFound();
    }
}
