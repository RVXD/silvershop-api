<?php

declare(strict_types=1);

namespace SilverShop\Api\Enrichment;

use SilverShop\Page\Product;

/**
 * Flags the serialised product as `downloadable` when silvershop/downloads is installed and the product has files.
 */
class DownloadProductEnrichment extends ProductEnrichmentExtension
{
    /**
     * @param array<string, mixed> $data
     */
    public function updateSerialisedProduct(array &$data, Product $product): void
    {
        $downloads = $this->accessor($product, 'Downloads');
        if (!is_iterable($downloads)) {
            return;
        }

        $downloadable = false;
        foreach ($downloads as $ignored) {
            $downloadable = true;
            break;
        }

        $data['downloadable'] = $downloadable;
    }
}
