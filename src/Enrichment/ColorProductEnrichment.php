<?php

declare(strict_types=1);

namespace SilverShop\Api\Enrichment;

use SilverShop\Page\Product;

/**
 * Adds a `colours` array (hex swatches) to the serialised product when silvershop/colors is installed.
 */
class ColorProductEnrichment extends ProductEnrichmentExtension
{
    /**
     * @param array<string, mixed> $data
     */
    public function updateSerialisedProduct(array &$data, Product $product): void
    {
        $colours = $this->accessor($product, 'Colours');
        if (!is_iterable($colours)) {
            return;
        }

        $out = [];
        foreach ($colours as $colour) {
            $out[] = [
                'id' => (int) $colour->ID,
                'title' => (string) $colour->Title,
                'hex' => (string) $colour->Hex,
            ];
        }

        $data['colours'] = $out;
    }
}
