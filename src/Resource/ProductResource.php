<?php

declare(strict_types=1);

namespace SilverShop\Api\Resource;

use SilverShop\Api\ApiException;
use SilverShop\Api\Auth\ApiContext;
use SilverShop\Api\Auth\ApiScope;
use SilverShop\Api\Serializer\ProductSerializer;
use SilverShop\Api\Serializer\VariationSerializer;
use SilverShop\Page\Product;
use SilverShop\Page\ProductCategory;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataList;
use SilverStripe\Versioned\Versioned;

/**
 * The `products` resource — a backwards-compatible superset of core's `api/v1/products`.
 *
 *  - GET  products                      list published products (filter/sort/paginate)
 *  - GET  products/{id|urlSegment}      a single published product
 *  - GET  products/{id}/variations      a product's variations
 *  - POST products                      create a product        (admin scope + canCreate)
 *  - PUT/PATCH products/{id}            update a product         (admin scope + canEdit)
 */
class ProductResource extends AbstractResource
{
    use HandlesListParams;

    /**
     * Writable fields for admin catalog write, with a coercion type.
     *
     * @var array<string, string>
     */
    private const WRITABLE = [
        'Title' => 'string',
        'URLSegment' => 'string',
        'BasePrice' => 'float',
        'InternalItemID' => 'string',
        'Model' => 'string',
        'Content' => 'string',
        'Featured' => 'bool',
        'AllowPurchase' => 'bool',
        'Weight' => 'float',
        'Height' => 'float',
        'Width' => 'float',
        'Depth' => 'float',
        'ParentID' => 'int',
    ];

    public function getName(): string
    {
        return 'products';
    }

    public function handle(HTTPRequest $request, ApiContext $context, array $path): ResourceResult
    {
        $method = strtoupper($request->httpMethod());

        // Collection-level write: create.
        if ($path === [] && $method === 'POST') {
            return $this->createProduct($request, $context);
        }

        if ($path === []) {
            $this->assertMethod($request, ['GET']);
            return $this->list($request, $context);
        }

        $identifier = array_shift($path);

        // products/{id}/variations
        if ($path === ['variations']) {
            $this->assertMethod($request, ['GET']);
            return $this->variations($context, $identifier);
        }

        if ($path !== []) {
            throw ApiException::notFound();
        }

        if (in_array($method, ['PUT', 'PATCH'], true)) {
            return $this->updateProduct($request, $context, $identifier);
        }

        $this->assertMethod($request, ['GET', 'PUT', 'PATCH']);

        return $this->detail($context, $identifier);
    }

    private function list(HTTPRequest $request, ApiContext $context): ResourceResult
    {
        $this->requireReadAccess($context);

        return Versioned::withVersionedMode(function () use ($request): ResourceResult {
            Versioned::set_stage(Versioned::LIVE);

            $list = Product::get();
            $list = $this->applyCategoryFilter($list, $request);
            $list = $this->applyFilter($list, $request, [
                'Title' => 'PartialMatch',
                'InternalItemID' => 'ExactMatch',
                'Model' => 'PartialMatch',
                'Featured' => 'ExactMatch',
            ]);
            $list = $this->applySort($list, $request, [
                'Title',
                'BasePrice',
                'Created',
                'LastEdited',
                'Popularity',
                'Featured',
            ]);

            $page = $this->paginate($list, $request);
            $serializer = ProductSerializer::create();

            $payload = [];
            foreach ($page['list'] as $product) {
                if ($product->canView()) {
                    $payload[] = $serializer->serialise($product);
                }
            }

            return ResourceResult::ok($payload, $page['headers'], 'products', 'product');
        });
    }

    private function detail(ApiContext $context, string $identifier): ResourceResult
    {
        $this->requireReadAccess($context);

        return Versioned::withVersionedMode(function () use ($identifier): ResourceResult {
            Versioned::set_stage(Versioned::LIVE);

            $product = $this->findProduct($identifier);
            if (!$product || !$product->canView()) {
                throw ApiException::notFound('Product not found');
            }

            return ResourceResult::ok(ProductSerializer::create()->serialise($product), [], 'product');
        });
    }

    private function variations(ApiContext $context, string $identifier): ResourceResult
    {
        $this->requireReadAccess($context);

        return Versioned::withVersionedMode(function () use ($identifier): ResourceResult {
            Versioned::set_stage(Versioned::LIVE);

            $product = $this->findProduct($identifier);
            if (!$product || !$product->canView()) {
                throw ApiException::notFound('Product not found');
            }

            $serializer = VariationSerializer::create();
            $payload = [];
            if ($product->hasMethod('Variations')) {
                foreach ($product->Variations() as $variation) {
                    if ($variation->canView()) {
                        $payload[] = $serializer->serialise($variation);
                    }
                }
            }

            return ResourceResult::ok($payload, [], 'variations', 'variation');
        });
    }

    private function createProduct(HTTPRequest $request, ApiContext $context): ResourceResult
    {
        $this->requireScope($context, ApiScope::ADMIN);

        $product = Product::create();
        if (!$product->canCreate($context->getMember())) {
            throw ApiException::forbidden('You are not allowed to create products.');
        }

        $this->applyWritable($product, $this->jsonBody($request));
        $this->persist($product);
        $product->publishRecursive();

        return ResourceResult::created(ProductSerializer::create()->serialise($product), 'product');
    }

    private function updateProduct(HTTPRequest $request, ApiContext $context, string $identifier): ResourceResult
    {
        $this->requireScope($context, ApiScope::ADMIN);

        // Admin write operates on the draft stage.
        $product = $this->findProduct($identifier, Versioned::DRAFT);
        if (!$product) {
            throw ApiException::notFound('Product not found');
        }
        if (!$product->canEdit($context->getMember())) {
            throw ApiException::forbidden('You are not allowed to edit this product.');
        }

        $this->applyWritable($product, $this->jsonBody($request));
        $this->persist($product);
        $product->publishRecursive();

        return ResourceResult::ok(ProductSerializer::create()->serialise($product), [], 'product');
    }

    /**
     * Filter the list by `filter[category]=<id>`, recursively (via the category-index `EffectiveCategories` relation
     * when present, otherwise via the category's descendant ids). A no-op when the param is absent.
     */
    private function applyCategoryFilter(DataList $list, HTTPRequest $request): DataList
    {
        $filters = $request->getVar('filter');
        if (!is_array($filters) || !isset($filters['category']) || !is_scalar($filters['category'])) {
            return $list;
        }

        $categoryId = (int) $filters['category'];
        if ($categoryId <= 0) {
            return $list;
        }

        if (Product::singleton()->hasMethod('EffectiveCategories')) {
            return $list->filter('EffectiveCategories.ID', $categoryId);
        }

        $category = ProductCategory::get()->byID($categoryId);
        $ids = [$categoryId];
        if ($category && $category->hasMethod('AllChildCategoryIDs')) {
            $ids = array_merge($ids, array_map('intval', $category->AllChildCategoryIDs()));
        }

        return $list->filterAny([
            'ParentID' => $ids,
            'ProductCategories.ID' => $ids,
        ]);
    }

    private function findProduct(string $identifier, string $stage = Versioned::LIVE): ?Product
    {
        /** @var DataList<Product> $products */
        $products = Versioned::get_by_stage(Product::class, $stage);

        if (preg_match('/^\d+$/', $identifier) === 1) {
            return $products->byID((int) $identifier);
        }

        return $products->filter('URLSegment', $identifier)->first();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyWritable(Product $product, array $data): void
    {
        $applied = false;
        foreach (self::WRITABLE as $field => $type) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];
            $product->setField($field, match ($type) {
                'float' => (float) $value,
                'int' => (int) $value,
                'bool' => (bool) $value,
                default => (string) $value,
            });
            $applied = true;
        }

        if (!$applied) {
            throw ApiException::badRequest('No writable product fields supplied.');
        }
    }
}
