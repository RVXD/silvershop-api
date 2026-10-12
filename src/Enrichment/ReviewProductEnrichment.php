<?php

declare(strict_types=1);

namespace SilverShop\Api\Enrichment;

use SilverShop\Page\Product;

/**
 * Adds a `rating` object (average + count) to the serialised product when silvershop/reviews is installed.
 */
class ReviewProductEnrichment extends ProductEnrichmentExtension
{
    /**
     * @param array<string, mixed> $data
     */
    public function updateSerialisedProduct(array &$data, Product $product): void
    {
        if (!$product->hasMethod('AverageRating')) {
            return;
        }

        $data['rating'] = [
            'average' => (float) $this->accessor($product, 'AverageRating'),
            'count' => (int) $this->accessor($product, 'RatingCount'),
        ];
    }
}
