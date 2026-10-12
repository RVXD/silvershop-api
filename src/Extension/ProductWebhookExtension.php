<?php

declare(strict_types=1);

namespace SilverShop\Api\Extension;

use SilverShop\Api\Serializer\ProductSerializer;
use SilverShop\Api\Webhook\WebhookService;
use SilverStripe\Core\Extension;

/**
 * Emits a `product.published` webhook whenever a {@link \SilverShop\Page\Product} is published (the Versioned
 * `onAfterPublish` hook, fired by both the CMS publish action and the admin catalog-write endpoint).
 */
class ProductWebhookExtension extends Extension
{
    public function onAfterPublish(&$original = null): void
    {
        $product = $this->getOwner();

        WebhookService::create()->dispatch(
            WebhookService::EVENT_PRODUCT_PUBLISHED,
            ProductSerializer::create()->serialise($product)
        );
    }
}
