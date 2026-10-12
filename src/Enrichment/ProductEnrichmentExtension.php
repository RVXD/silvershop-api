<?php

declare(strict_types=1);

namespace SilverShop\Api\Enrichment;

use SilverShop\Page\Product;
use SilverStripe\Core\Extension;

/**
 * Base for the soft-dependency product enrichers. Each is applied to {@link \SilverShop\Api\Serializer\ProductSerializer}
 * and implements `updateSerialisedProduct(&$data, $product)` to add its module's fields. The accessors they call
 * (Brand(), Colours(), AverageRating(), …) come from optional sibling modules, so they are invoked dynamically via
 * {@link self::accessor()} — the base API neither depends on nor references those modules' classes.
 */
abstract class ProductEnrichmentExtension extends Extension
{
    /**
     * Call an optional accessor on the product if it exists, returning its result or null. The method is invoked
     * through a variable name so it stays valid when the contributing module is absent.
     */
    protected function accessor(Product $product, string $method): mixed
    {
        // Real extension methods (AverageRating, getAllImages, …) surface via hasMethod(); relation accessors
        // (Brand has_one, Colours many_many, Downloads has_many) surface via getRelationType().
        if (!$product->hasMethod($method) && !$product->getRelationType($method)) {
            return null;
        }

        return $product->{$method}();
    }
}
