<?php

declare(strict_types=1);

namespace SilverShop\Api\Tests\Serializer;

use SilverShop\Page\Product;
use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;

/**
 * A stand-in soft-dep enricher used to prove the serializer's `updateSerialisedProduct` extension hook fires.
 */
class TestProductEnrichment extends Extension implements TestOnly
{
    /**
     * @param array<string, mixed> $data
     */
    public function updateSerialisedProduct(array &$data, Product $product): void
    {
        $data['enrichedTitle'] = 'enriched:' . $product->Title;
    }
}
