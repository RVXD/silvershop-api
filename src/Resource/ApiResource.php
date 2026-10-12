<?php

declare(strict_types=1);

namespace SilverShop\Api\Resource;

use SilverShop\Api\Auth\ApiContext;
use SilverStripe\Control\HTTPRequest;

/**
 * A resource plugged into the {@link ResourceRegistry} and mounted by {@link \SilverShop\Api\Control\ApiController}
 * at `api/v1/<name>`. Implementations resolve the remaining path segments and HTTP method themselves and return a
 * {@link ResourceResult}, or throw a {@link \SilverShop\Api\ApiException} to signal an HTTP error.
 */
interface ApiResource
{
    /**
     * The URL segment this resource answers to (e.g. `products`).
     */
    public function getName(): string;

    /**
     * Handle a request for this resource.
     *
     * @param array<int, string> $path Segments after the resource name (e.g. `['5']` for `products/5`,
     *                                 `['add']` for `cart/add`).
     */
    public function handle(HTTPRequest $request, ApiContext $context, array $path): ResourceResult;
}
