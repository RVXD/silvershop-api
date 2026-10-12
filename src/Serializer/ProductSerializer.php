<?php

declare(strict_types=1);

namespace SilverShop\Api\Serializer;

use SilverShop\Page\Product;

/**
 * Serialises a {@link Product}. The first four keys (`id`, `title`, `price`, `link`) reproduce core's
 * {@see \SilverShop\Control\WebServiceController} shape exactly, so existing `api/v1/products` consumers keep working;
 * everything else is additive. Soft-dependency modules (brands, colours, reviews, gallery, …) enrich the payload
 * through the `updateSerialisedProduct` extension hook.
 */
class ProductSerializer extends Serializer
{
    public function serialise(object $record): array
    {
        /** @var Product $product */
        $product = $record;

        $data = [
            // --- backwards-compatible core shape ---
            'id' => (int) $product->ID,
            'title' => (string) $product->Title,
            'price' => (float) $product->sellingPrice(),
            'link' => (string) $product->Link(),
            // --- additive superset ---
            'urlSegment' => (string) $product->URLSegment,
            'internalItemID' => (string) $product->InternalItemID,
            'model' => (string) $product->Model,
            'currency' => $this->siteCurrency(),
            'featured' => (bool) $product->Featured,
            'available' => (bool) $product->canPurchase(),
            'categoryIDs' => array_values(array_map('intval', array_keys($product->getCategoryIDs()))),
            'image' => $this->serialiseImage($product->Image()),
        ];

        if ($product->hasMethod('Variations')) {
            $data['variationIDs'] = array_map('intval', $product->Variations()->column('ID'));
        }

        if ($product->hasMethod('PriceRange') && ($range = $product->PriceRange()) !== null) {
            $data['priceRange'] = [
                'hasRange' => (bool) $range->HasRange,
                'min' => (float) $range->Min->getValue(),
                'max' => (float) $range->Max->getValue(),
            ];
        }

        $this->extend('updateSerialisedProduct', $data, $product);

        return $data;
    }
}
