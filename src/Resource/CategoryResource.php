<?php

declare(strict_types=1);

namespace SilverShop\Api\Resource;

use SilverShop\Api\ApiException;
use SilverShop\Api\Auth\ApiContext;
use SilverShop\Api\Serializer\CategorySerializer;
use SilverShop\Page\ProductCategory;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\ORM\DataList;
use SilverStripe\Versioned\Versioned;

/**
 * The `categories` resource.
 *
 *  - GET categories                 flat list of published categories (filter/sort/paginate)
 *  - GET categories/tree            the full category tree, nested
 *  - GET categories/{id|urlSegment} a single category
 */
class CategoryResource extends AbstractResource
{
    use HandlesListParams;

    public function getName(): string
    {
        return 'categories';
    }

    public function handle(HTTPRequest $request, ApiContext $context, array $path): ResourceResult
    {
        $this->assertMethod($request, ['GET']);
        $this->requireReadAccess($context);

        if ($path === ['tree']) {
            return $this->tree();
        }

        if ($path === []) {
            return $this->list($request);
        }

        if (count($path) === 1) {
            return $this->detail($path[0]);
        }

        throw ApiException::notFound();
    }

    private function list(HTTPRequest $request): ResourceResult
    {
        return Versioned::withVersionedMode(function () use ($request): ResourceResult {
            Versioned::set_stage(Versioned::LIVE);

            $list = ProductCategory::get();
            $list = $this->applyFilter($list, $request, [
                'Title' => 'PartialMatch',
                'ParentID' => 'ExactMatch',
            ]);
            $list = $this->applySort($list, $request, ['Title', 'Sort', 'Created', 'LastEdited']);

            $page = $this->paginate($list, $request);
            $serializer = CategorySerializer::create();

            $payload = [];
            foreach ($page['list'] as $category) {
                if ($category->canView()) {
                    $payload[] = $serializer->serialise($category);
                }
            }

            return ResourceResult::ok($payload, $page['headers'], 'categories', 'category');
        });
    }

    private function detail(string $identifier): ResourceResult
    {
        return Versioned::withVersionedMode(function () use ($identifier): ResourceResult {
            Versioned::set_stage(Versioned::LIVE);

            /** @var DataList<ProductCategory> $categories */
            $categories = ProductCategory::get();
            $category = preg_match('/^\d+$/', $identifier) === 1
                ? $categories->byID((int) $identifier)
                : $categories->filter('URLSegment', $identifier)->first();

            if (!$category || !$category->canView()) {
                throw ApiException::notFound('Category not found');
            }

            return ResourceResult::ok(CategorySerializer::create()->serialise($category), [], 'category');
        });
    }

    /**
     * The category tree, nested. Assembled in memory from the published categories (roots are the categories whose
     * parent is not itself a category).
     */
    private function tree(): ResourceResult
    {
        return Versioned::withVersionedMode(function (): ResourceResult {
            Versioned::set_stage(Versioned::LIVE);

            $serializer = CategorySerializer::create();

            /** @var array<int, array<string, mixed>> $nodes */
            $nodes = [];
            $childrenOf = [];
            foreach (ProductCategory::get() as $category) {
                if (!$category->canView()) {
                    continue;
                }
                $id = (int) $category->ID;
                $nodes[$id] = $serializer->serialise($category);
                $childrenOf[(int) $category->ParentID][] = $id;
            }

            $build = function (int $id) use (&$build, &$nodes, $childrenOf): array {
                $node = $nodes[$id];
                $node['children'] = [];
                foreach ($childrenOf[$id] ?? [] as $childId) {
                    if (isset($nodes[$childId])) {
                        $node['children'][] = $build($childId);
                    }
                }
                return $node;
            };

            $roots = [];
            foreach ($nodes as $id => $node) {
                $parentId = (int) $node['parentID'];
                if (!isset($nodes[$parentId])) {
                    $roots[] = $build($id);
                }
            }

            return ResourceResult::ok($roots, [], 'categories', 'category');
        });
    }
}
