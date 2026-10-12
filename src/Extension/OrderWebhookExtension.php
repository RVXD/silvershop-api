<?php

declare(strict_types=1);

namespace SilverShop\Api\Extension;

use SilverShop\Api\Serializer\OrderSerializer;
use SilverShop\Api\Webhook\WebhookService;
use SilverStripe\Core\Extension;

/**
 * Emits order-lifecycle webhooks off core's own extension hooks: `onPlaceOrder` (fired by
 * {@see \SilverShop\Checkout\OrderProcessor}), and `onPaid` / `onStatusChange` (fired by {@see \SilverShop\Model\Order}
 * on status transitions). Applied to {@link \SilverShop\Model\Order}.
 */
class OrderWebhookExtension extends Extension
{
    public function onPlaceOrder(): void
    {
        $this->fire(WebhookService::EVENT_ORDER_PLACED);
    }

    public function onPaid(): void
    {
        $this->fire(WebhookService::EVENT_ORDER_PAID);
    }

    public function onStatusChange($fromStatus, $toStatus): void
    {
        $this->fire(WebhookService::EVENT_ORDER_STATUS_CHANGE, [
            'fromStatus' => (string) $fromStatus,
            'toStatus' => (string) $toStatus,
        ]);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function fire(string $event, array $extra = []): void
    {
        $order = $this->getOwner();
        $payload = OrderSerializer::create()->serialise($order);
        if ($extra !== []) {
            $payload = array_merge($payload, $extra);
        }

        WebhookService::create()->dispatch($event, $payload);
    }
}
