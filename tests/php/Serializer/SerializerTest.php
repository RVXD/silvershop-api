<?php

declare(strict_types=1);

namespace SilverShop\Api\Tests\Serializer;

use SilverShop\Api\Serializer\CategorySerializer;
use SilverShop\Api\Serializer\ProductSerializer;
use SilverShop\Api\Serializer\VariationSerializer;
use SilverShop\Api\Tests\CatalogFixtures;
use SilverShop\Page\Product;
use SilverShop\Page\ProductCategory;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

class SerializerTest extends SapphireTest
{
    use CatalogFixtures;

    protected $usesDatabase = true;

    protected ProductCategory $category;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Config::modify()->set(ProductCategory::class, 'must_have_price', false);
        Config::modify()->set(Product::class, 'global_allow_purchase', true);

        $this->category = $this->makeCategory('Apparel');
        $this->product = $this->makeProduct('T-Shirt', 19.99, $this->category->ID, [
            'InternalItemID' => 'TS-001',
            'Featured' => true,
        ]);
        $this->makeVariation($this->product, 'TS-RED', 21.00, 'Colour', 'Red');
    }

    public function testProductSerialiserBackwardsCompatibleShape(): void
    {
        $data = ProductSerializer::create()->serialise($this->product);

        // core's exact keys, unchanged
        $this->assertSame((int) $this->product->ID, $data['id']);
        $this->assertSame('T-Shirt', $data['title']);
        $this->assertSame(19.99, $data['price']);
        $this->assertIsString($data['link']);
        $this->assertStringContainsString('t-shirt', $data['link']);
    }

    public function testProductSerialiserAdditiveFields(): void
    {
        $data = ProductSerializer::create()->serialise($this->product);

        $this->assertSame('TS-001', $data['internalItemID']);
        $this->assertTrue($data['featured']);
        $this->assertTrue($data['available']);
        $this->assertContains((int) $this->category->ID, $data['categoryIDs']);
        $this->assertNull($data['image']);
        $this->assertNotEmpty($data['variationIDs']);
        $this->assertArrayHasKey('priceRange', $data);
        $this->assertFalse($data['priceRange']['hasRange']);
        $this->assertSame(21.0, $data['priceRange']['min']);
    }

    public function testCategorySerialiser(): void
    {
        $child = $this->makeCategory('Shirts', $this->category->ID);
        $data = CategorySerializer::create()->serialise($this->category);

        $this->assertSame((int) $this->category->ID, $data['id']);
        $this->assertSame('Apparel', $data['title']);
        $this->assertIsString($data['urlSegment']);
        $this->assertContains((int) $child->ID, $data['childCategoryIDs']);
    }

    public function testVariationSerialiser(): void
    {
        $variation = $this->product->Variations()->first();
        $data = VariationSerializer::create()->serialise($variation);

        $this->assertSame((int) $variation->ID, $data['id']);
        $this->assertSame((int) $this->product->ID, $data['productID']);
        $this->assertSame('TS-RED', $data['internalItemID']);
        $this->assertSame(21.0, $data['price']);
        $this->assertCount(1, $data['attributes']);
        $this->assertSame('Colour', $data['attributes'][0]['type']);
        $this->assertSame('Red', $data['attributes'][0]['value']);
    }

    public function testUpdateSerialisedProductHookFires(): void
    {
        ProductSerializer::add_extension(TestProductEnrichment::class);

        $data = ProductSerializer::create()->serialise($this->product);
        $this->assertSame('enriched:T-Shirt', $data['enrichedTitle']);

        ProductSerializer::remove_extension(TestProductEnrichment::class);
    }
}
