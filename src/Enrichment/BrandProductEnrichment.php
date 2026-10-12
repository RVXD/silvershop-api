<?php

declare(strict_types=1);

namespace SilverShop\Api\Enrichment;

use SilverShop\Page\Product;

/**
 * Adds a `brand` object to the serialised product when silvershop/brands is installed.
 */
class BrandProductEnrichment extends ProductEnrichmentExtension
{
    /**
     * @param array<string, mixed> $data
     */
    public function updateSerialisedProduct(array &$data, Product $product): void
    {
        $brand = $this->accessor($product, 'Brand');

        $data['brand'] = ($brand && $brand->exists())
            ? [
                'id' => (int) $brand->ID,
                'title' => (string) $brand->Title,
                'urlSegment' => (string) $brand->URLSegment,
            ]
            : null;
    }
}
