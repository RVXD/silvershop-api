<?php

declare(strict_types=1);

namespace SilverShop\Api\Enrichment;

use SilverShop\Page\Product;

/**
 * Adds a `gallery` array of image URLs to the serialised product when silvershop/product-gallery is installed.
 */
class GalleryProductEnrichment extends ProductEnrichmentExtension
{
    /**
     * @param array<string, mixed> $data
     */
    public function updateSerialisedProduct(array &$data, Product $product): void
    {
        $images = $this->accessor($product, 'getAllImages');
        if (!is_iterable($images)) {
            return;
        }

        $out = [];
        foreach ($images as $image) {
            if ($image && $image->exists()) {
                $out[] = [
                    'id' => (int) $image->ID,
                    'url' => (string) $image->getAbsoluteURL(),
                    'alt' => (string) ($image->Title ?: $image->Name),
                ];
            }
        }

        $data['gallery'] = $out;
    }
}
