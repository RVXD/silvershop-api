<?php

declare(strict_types=1);

namespace SilverShop\Api\Resource;

use SilverShop\Api\ApiException;
use SilverShop\Api\Auth\ApiContext;
use SilverShop\Api\Control\ApiController;
use SilverShop\Api\Serializer\ProductSerializer;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Versioned\Versioned;

/**
 * Bridge to silvershop/search: `GET search?q=…` runs the shop's active search backend and returns the matching
 * products (serialised with {@link ProductSerializer}, so soft-dep enrichment applies). Deliberately decoupled —
 * it references the search module only by string class name, so the base API carries no dependency on it and this
 * class is inert (returns 501) when search is not installed. Registered in config guarded by `moduleexists`.
 */
class SearchResource extends AbstractResource
{
    private const REGISTRY_CLASS = 'SilverShop\\Search\\Backend\\BackendRegistry';

    private const QUERY_CLASS = 'SilverShop\\Search\\Query\\SearchQuery';

    public function getName(): string
    {
        return 'search';
    }

    public function handle(HTTPRequest $request, ApiContext $context, array $path): ResourceResult
    {
        $this->assertMethod($request, ['GET']);
        $this->requireReadAccess($context);

        if ($path !== []) {
            throw ApiException::notFound();
        }

        if (!class_exists(self::REGISTRY_CLASS) || !class_exists(self::QUERY_CLASS)) {
            throw new ApiException(501, 'Search is not available. Install silvershop/search to enable this resource.');
        }

        $terms = trim((string) $request->getVar('q'));
        $config = Config::forClass(ApiController::class);
        $max = (int) $config->get('max_page_size');
        $limit = (int) ($request->getVar('limit') ?? $config->get('default_page_size'));
        $limit = $limit > 0 ? min($limit, $max) : (int) $config->get('default_page_size');
        $start = max(0, (int) ($request->getVar('start') ?? 0));

        $registry = Injector::inst()->get(self::REGISTRY_CLASS);
        if (!is_object($registry) || !method_exists($registry, 'getActive')) {
            throw new ApiException(501, 'Search backend unavailable.');
        }
        $backend = $registry->getActive();
        if (!is_object($backend) || !method_exists($backend, 'query')) {
            throw new ApiException(501, 'Search backend unavailable.');
        }

        $query = Injector::inst()->createWithArgs(self::QUERY_CLASS, [$terms, [], '', $start, $limit]);

        return Versioned::withVersionedMode(function () use ($backend, $query, $limit, $start): ResourceResult {
            Versioned::set_stage(Versioned::LIVE);

            $results = $backend->query($query);
            $vars = is_object($results) ? get_object_vars($results) : [];
            $hits = $vars['hits'] ?? [];
            $total = (int) ($vars['total'] ?? 0);

            $serializer = ProductSerializer::create();
            $payload = [];
            if (is_iterable($hits)) {
                foreach ($hits as $product) {
                    if (is_object($product) && method_exists($product, 'canView') && $product->canView()) {
                        $payload[] = $serializer->serialise($product);
                    }
                }
            }

            $headers = [
                'X-Total-Count' => (string) $total,
                'X-Page-Start' => (string) $start,
                'X-Page-Limit' => (string) $limit,
            ];

            return ResourceResult::ok($payload, $headers, 'results', 'product');
        });
    }
}
