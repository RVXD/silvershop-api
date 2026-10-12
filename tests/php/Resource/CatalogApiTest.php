<?php

declare(strict_types=1);

namespace SilverShop\Api\Tests\Resource;

use SilverShop\Api\Auth\ApiScope;
use SilverShop\Api\Control\ApiController;
use SilverShop\Api\Tests\CatalogFixtures;
use SilverShop\Page\Product;
use SilverShop\Page\ProductCategory;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\FunctionalTest;

class CatalogApiTest extends FunctionalTest
{
    use CatalogFixtures;

    protected $usesDatabase = true;

    protected ProductCategory $category;
    protected Product $tshirt;

    protected function setUp(): void
    {
        parent::setUp();
        Config::modify()->set(ProductCategory::class, 'must_have_price', false);
        Config::modify()->set(Product::class, 'global_allow_purchase', true);

        $this->category = $this->makeCategory('Apparel');
        $this->tshirt = $this->makeProduct('T-Shirt', 19.99, $this->category->ID, [
            'InternalItemID' => 'TS-001',
            'Featured' => true,
        ]);
        $this->makeProduct('Hoodie', 49.00, $this->category->ID, ['InternalItemID' => 'HD-001']);
        $this->makeProduct('Cap', 9.00, $this->category->ID, ['InternalItemID' => 'CP-001']);
    }

    /**
     * @return array<mixed>
     */
    private function json(string $body): array
    {
        return (array) json_decode($body, true);
    }

    public function testListReturnsPublishedProducts(): void
    {
        $response = $this->get('api/v1/products');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('json', (string) $response->getHeader('Content-Type'));

        $data = $this->json($response->getBody());
        $this->assertCount(3, $data);
        $titles = array_column($data, 'title');
        $this->assertContains('T-Shirt', $titles);
    }

    public function testApiIndexListsResources(): void
    {
        $data = $this->json($this->get('api/v1')->getBody());
        $this->assertContains('products', $data['resources']);
        $this->assertContains('cart', $data['resources']);
    }

    public function testPaginationHeaders(): void
    {
        $response = $this->get('api/v1/products?limit=2&start=0');
        $data = $this->json($response->getBody());

        $this->assertCount(2, $data);
        $this->assertSame('3', $response->getHeader('X-Total-Count'));
        $this->assertSame('2', $response->getHeader('X-Page-Limit'));
        $this->assertSame('2', $response->getHeader('X-Total-Pages'));
    }

    public function testFilter(): void
    {
        $data = $this->json($this->get('api/v1/products?filter[Featured]=1')->getBody());
        $this->assertCount(1, $data);
        $this->assertSame('T-Shirt', $data[0]['title']);
    }

    public function testSortDescendingByPrice(): void
    {
        $data = $this->json($this->get('api/v1/products?sort=-BasePrice')->getBody());
        $prices = array_map('floatval', array_column($data, 'price'));
        $this->assertSame([49.0, 19.99, 9.0], $prices);
    }

    public function testInvalidSortFieldRejected(): void
    {
        $this->assertSame(400, $this->get('api/v1/products?sort=Secret')->getStatusCode());
    }

    public function testDetailByIdAndUrlSegment(): void
    {
        $byId = $this->json($this->get('api/v1/products/' . $this->tshirt->ID)->getBody());
        $this->assertSame('T-Shirt', $byId['title']);

        $bySegment = $this->json($this->get('api/v1/products/' . $this->tshirt->URLSegment)->getBody());
        $this->assertSame((int) $this->tshirt->ID, $bySegment['id']);

        $this->assertSame(404, $this->get('api/v1/products/does-not-exist')->getStatusCode());
    }

    public function testXmlContentNegotiation(): void
    {
        $response = $this->get('api/v1/products.xml');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('xml', (string) $response->getHeader('Content-Type'));
        $this->assertStringContainsString('<product', $response->getBody());

        $viaAccept = $this->get('api/v1/products', null, ['Accept' => 'application/xml']);
        $this->assertStringContainsString('xml', (string) $viaAccept->getHeader('Content-Type'));
    }

    public function testCategoryTree(): void
    {
        $this->makeCategory('Shirts', $this->category->ID);
        $data = $this->json($this->get('api/v1/categories/tree')->getBody());

        $this->assertNotEmpty($data);
        $root = null;
        foreach ($data as $node) {
            if ($node['id'] === (int) $this->category->ID) {
                $root = $node;
            }
        }
        $this->assertNotNull($root, 'Apparel is a tree root');
        $this->assertNotEmpty($root['children']);
        $this->assertSame('Shirts', $root['children'][0]['title']);
    }

    public function testUnknownResourceIs404(): void
    {
        $this->assertSame(404, $this->get('api/v1/nope')->getStatusCode());
    }

    public function testPublicReadCanBeTokenGated(): void
    {
        Config::modify()->set(ApiController::class, 'public_read', false);

        $this->assertSame(401, $this->get('api/v1/products')->getStatusCode());

        $member = $this->makeMember('reader@example.com');
        [, $plain] = $this->makeToken($member, [ApiScope::READ]);
        $ok = $this->get('api/v1/products', null, ['Authorization' => 'Bearer ' . $plain]);
        $this->assertSame(200, $ok->getStatusCode());
    }
}
