<?php

declare(strict_types=1);

namespace SilverShop\Api\Resource;

use SilverShop\Api\ApiException;
use SilverShop\Api\Auth\ApiContext;
use SilverShop\Api\Serializer\VariationSerializer;
use SilverShop\Model\Variation\Variation;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Versioned\Versioned;

/**
 * The `variations` resource.
 *
 *  - GET variations        list published variations (filter/sort/paginate)
 *  - GET variations/{id}    a single variation
 */
class VariationResource extends AbstractResource
{
    use HandlesListParams;

    public function getName(): string
    {
        return 'variations';
    }

    public function handle(HTTPRequest $request, ApiContext $context, array $path): ResourceResult
    {
        $this->assertMethod($request, ['GET']);
        $this->requireReadAccess($context);

        if (count($path) > 1) {
            throw ApiException::notFound();
        }

        return Versioned::withVersionedMode(function () use ($request, $path): ResourceResult {
            Versioned::set_stage(Versioned::LIVE);
            $serializer = VariationSerializer::create();

            if ($path !== []) {
                $identifier = $path[0];
                if (preg_match('/^\d+$/', $identifier) !== 1) {
                    throw ApiException::notFound('Variation not found');
                }
                $variation = Variation::get()->byID((int) $identifier);
                if (!$variation || !$variation->canView()) {
                    throw ApiException::notFound('Variation not found');
                }

                return ResourceResult::ok($serializer->serialise($variation), [], 'variation');
            }

            $list = Variation::get();
            $list = $this->applyFilter($list, $request, [
                'InternalItemID' => 'ExactMatch',
                'ProductID' => 'ExactMatch',
            ]);
            $list = $this->applySort($list, $request, ['InternalItemID', 'Price', 'Sort']);

            $page = $this->paginate($list, $request);
            $payload = [];
            foreach ($page['list'] as $variation) {
                if ($variation->canView()) {
                    $payload[] = $serializer->serialise($variation);
                }
            }

            return ResourceResult::ok($payload, $page['headers'], 'variations', 'variation');
        });
    }
}
