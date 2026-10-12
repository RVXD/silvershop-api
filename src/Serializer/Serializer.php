<?php

declare(strict_types=1);

namespace SilverShop\Api\Serializer;

use SilverShop\Extension\ShopConfigExtension;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;

/**
 * Base for the per-resource serializers. A serializer turns a model into a plain array and fires an
 * `updateSerialised<Resource>` extension hook so other modules can enrich the payload — generalising core's single
 * `updateSerialisedProduct` hook on {@see \SilverShop\Control\WebServiceController}.
 *
 * Serializers are plain services (resolved via Injector, so {@link Extensible} picks up configured extensions) and
 * are deliberately free of request/response concerns.
 */
abstract class Serializer
{
    use Extensible;
    use Injectable;
    use Configurable;

    /**
     * @return array<string, mixed>
     */
    abstract public function serialise(object $record): array;

    /**
     * The shop's base ISO-4217 currency code.
     */
    protected function siteCurrency(): string
    {
        return ShopConfigExtension::get_site_currency();
    }

    /**
     * Coerce a money-ish value (a `DBCurrency`/`DBField` or a scalar) to a float for the JSON payload.
     */
    protected function money(mixed $value): float
    {
        if (is_object($value) && method_exists($value, 'getValue')) {
            return (float) $value->getValue();
        }

        return (float) $value;
    }

    /**
     * Serialise an image (or null if absent). Guarded so a serializer never fatals on a missing/invalid file.
     *
     * @return array{id:int,url:string,alt:string,width:?int,height:?int}|null
     */
    protected function serialiseImage(?Image $image): ?array
    {
        if (!$image || !$image->exists()) {
            return null;
        }

        $width = (int) $image->getWidth();
        $height = (int) $image->getHeight();

        return [
            'id' => (int) $image->ID,
            'url' => (string) $image->getAbsoluteURL(),
            'alt' => (string) ($image->Title ?: $image->Name),
            'width' => $width > 0 ? $width : null,
            'height' => $height > 0 ? $height : null,
        ];
    }
}
