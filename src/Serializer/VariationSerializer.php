<?php

declare(strict_types=1);

namespace SilverShop\Api\Serializer;

use SilverShop\Model\Variation\Variation;

/**
 * Serialises a product {@link Variation}: its own price/code, the attribute values that define it (e.g.
 * Colour: Red, Size: L) and an optional image. Fires `updateSerialisedVariation`.
 */
class VariationSerializer extends Serializer
{
    public function serialise(object $record): array
    {
        /** @var Variation $variation */
        $variation = $record;

        $attributes = [];
        foreach ($variation->AttributeValues() as $value) {
            $type = $value->Type();
            $attributes[] = [
                'typeID' => (int) $value->TypeID,
                'type' => ($type && $type->exists()) ? (string) $type->Label : '',
                'valueID' => (int) $value->ID,
                'value' => (string) $value->Value,
            ];
        }

        $data = [
            'id' => (int) $variation->ID,
            'productID' => (int) $variation->ProductID,
            'title' => (string) $variation->getTitle(),
            'internalItemID' => (string) $variation->InternalItemID,
            'price' => (float) $variation->sellingPrice(),
            'currency' => $this->siteCurrency(),
            'available' => (bool) $variation->canPurchase(),
            'attributes' => $attributes,
            'image' => $this->serialiseImage($variation->Image()),
        ];

        $this->extend('updateSerialisedVariation', $data, $variation);

        return $data;
    }
}
