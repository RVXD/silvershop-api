<?php

declare(strict_types=1);

namespace SilverShop\Api\Tests\Resource;

use SilverShop\Api\Auth\ApiScope;
use SilverShop\Api\Tests\CatalogFixtures;
use SilverShop\Page\Product;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Security\SecurityToken;

class CartApiTest extends FunctionalTest
{
    use CatalogFixtures;

    protected $usesDatabase = true;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Config::modify()->set(Product::class, 'global_allow_purchase', true);
        $this->product = $this->makeProduct('Mug', 12.50, 0, ['InternalItemID' => 'MUG-1']);
    }

    /**
     * @return array<mixed>
     */
    private function json(string $body): array
    {
        return (array) json_decode($body, true);
    }

    public function testTokenAddAndGet(): void
    {
        $member = $this->makeMember('cart@example.com');
        [, $plain] = $this->makeToken($member, [ApiScope::WRITE]);
        $headers = ['Authorization' => 'Bearer ' . $plain];

        $add = $this->post('api/v1/cart/add', ['ProductID' => $this->product->ID, 'quantity' => 2], $headers);
        $this->assertSame(200, $add->getStatusCode());
        $addData = $this->json($add->getBody());
        $this->assertTrue($addData['success']);
        $this->assertSame(1, $addData['cart']['itemCount']);

        $get = $this->get('api/v1/cart', null, $headers);
        $getData = $this->json($get->getBody());
        $this->assertSame(1, $getData['itemCount']);
        $this->assertSame(2, $getData['items'][0]['quantity']);
    }

    public function testReadScopeCannotMutateCart(): void
    {
        $member = $this->makeMember('readonly@example.com');
        [, $plain] = $this->makeToken($member, [ApiScope::READ]);

        $response = $this->post(
            'api/v1/cart/add',
            ['ProductID' => $this->product->ID],
            ['Authorization' => 'Bearer ' . $plain]
        );
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testSessionCartWithCsrfDisabled(): void
    {
        SecurityToken::disable();
        try {
            $add = $this->post('api/v1/cart/add', ['ProductID' => $this->product->ID]);
            $this->assertSame(200, $add->getStatusCode());
            $this->assertTrue($this->json($add->getBody())['success']);
        } finally {
            SecurityToken::enable();
        }
    }

    public function testCheckoutHandoffRequiresItems(): void
    {
        $member = $this->makeMember('checkout@example.com');
        [, $plain] = $this->makeToken($member, [ApiScope::WRITE]);
        $headers = ['Authorization' => 'Bearer ' . $plain];

        // Empty cart → 400
        $this->assertSame(400, $this->get('api/v1/cart/checkout', null, $headers)->getStatusCode());

        $this->post('api/v1/cart/add', ['ProductID' => $this->product->ID], $headers);
        $checkout = $this->get('api/v1/cart/checkout', null, $headers);
        $this->assertSame(200, $checkout->getStatusCode());
        $this->assertArrayHasKey('checkoutUrl', $this->json($checkout->getBody()));
    }
}
