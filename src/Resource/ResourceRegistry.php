<?php

declare(strict_types=1);

namespace SilverShop\Api\Resource;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;

/**
 * Registry of the API resources available to the shop. Resources are declared in config (name => Injector service),
 * so each module plugs its own resource in without the API core knowing about it — the same pattern search uses for
 * facets/backends and reviews uses for providers:
 *
 *   SilverShop\Api\Resource\ResourceRegistry:
 *     resources:
 *       products: '%$SilverShop\Api\Resource\ProductResource'
 *       search:   '%$SilverShop\Api\Resource\SearchResource'   # registered by the search bridge, guarded by moduleexists
 *
 * Config order is the resolution order (irrelevant for dispatch, which is keyed by name). Each service's own
 * {@link ApiResource::getName()} is the authoritative URL segment; the config key is just a label.
 */
class ResourceRegistry
{
    use Injectable;
    use Configurable;

    /**
     * @var array<string, string> label => Injector service name / class
     */
    private static array $resources = [];

    /**
     * All registered resources, keyed by their URL segment name.
     *
     * @return array<string, ApiResource>
     */
    public function all(): array
    {
        $resources = [];
        foreach ((array) $this->config()->get('resources') as $service) {
            if (!$service) {
                continue;
            }
            $resource = Injector::inst()->get($service);
            if ($resource instanceof ApiResource) {
                $resources[$resource->getName()] = $resource;
            }
        }

        return $resources;
    }

    /**
     * The resource answering to the given URL segment, or null.
     */
    public function get(string $name): ?ApiResource
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * The registered resource names.
     *
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_keys($this->all());
    }
}
