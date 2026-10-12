<?php

declare(strict_types=1);

namespace SilverShop\Api\Serializer;

use SilverShop\Model\Order;

/**
 * Serialises an {@link Order} (or a cart, which is an Order with status `Cart`): status, totals, line items and the
 * shipping/billing addresses. Fires `updateSerialisedOrder`.
 */
class OrderSerializer extends Serializer
{
    public function serialise(object $record): array
    {
        /** @var Order $order */
        $order = $record;

        $items = [];
        foreach ($order->Items() as $item) {
            $buyable = $item->Buyable();
            $items[] = [
                'id' => (int) $item->ID,
                'title' => (string) $item->getTableTitle(),
                'buyableID' => $buyable ? (int) $buyable->ID : null,
                'buyableClass' => $buyable ? $buyable->ClassName : null,
                'quantity' => (int) $item->Quantity,
                'unitPrice' => $this->money($item->UnitPrice()),
                'total' => $this->money($item->Total()),
            ];
        }

        $addressSerializer = AddressSerializer::create();
        $shipping = $order->getShippingAddress();
        $billing = $order->getBillingAddress();

        $data = [
            'id' => (int) $order->ID,
            'reference' => (string) $order->getReference(),
            'status' => (string) $order->Status,
            'statusLabel' => (string) $order->getStatusI18N(),
            'placed' => $order->Placed ? (string) $order->Placed : null,
            'created' => (string) $order->Created,
            'currency' => (string) $order->Currency(),
            'subTotal' => $this->money($order->SubTotal()),
            'total' => $this->money($order->Total()),
            'totalOutstanding' => $this->money($order->TotalOutstanding()),
            'paid' => $order->IsPaid(),
            'itemCount' => (int) $order->Items()->count(),
            'items' => $items,
            'shippingAddress' => ($shipping && $shipping->exists()) ? $addressSerializer->serialise($shipping) : null,
            'billingAddress' => ($billing && $billing->exists()) ? $addressSerializer->serialise($billing) : null,
        ];

        $this->extend('updateSerialisedOrder', $data, $order);

        return $data;
    }
}
