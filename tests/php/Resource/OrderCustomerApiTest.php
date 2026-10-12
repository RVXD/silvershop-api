<?php

declare(strict_types=1);

namespace SilverShop\Api\Tests\Resource;

use SilverShop\Api\Auth\ApiScope;
use SilverShop\Api\Tests\CatalogFixtures;
use SilverShop\Model\Order;
use SilverShop\Page\Product;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;

class OrderCustomerApiTest extends FunctionalTest
{
    use CatalogFixtures;

    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        Config::modify()->set(Product::class, 'global_allow_purchase', true);
    }

    /**
     * @return array<mixed>
     */
    private function json(string $body): array
    {
        return (array) json_decode($body, true);
    }

    private function makeOrder(Member $member, string $status = 'Unpaid'): Order
    {
        $order = Order::create(['Status' => $status, 'MemberID' => $member->ID]);
        $order->write();

        return $order;
    }

    public function testMemberReadsOwnOrdersOnly(): void
    {
        $alice = $this->makeMember('alice@example.com');
        $bob = $this->makeMember('bob@example.com');
        $aliceOrder = $this->makeOrder($alice);

        [, $aliceToken] = $this->makeToken($alice, [ApiScope::READ]);
        [, $bobToken] = $this->makeToken($bob, [ApiScope::READ]);

        // Alice sees her order in the list and by id.
        $list = $this->json($this->get('api/v1/orders', null, ['Authorization' => 'Bearer ' . $aliceToken])->getBody());
        $this->assertCount(1, $list);
        $this->assertSame((int) $aliceOrder->ID, $list[0]['id']);

        $detail = $this->get('api/v1/orders/' . $aliceOrder->ID, null, ['Authorization' => 'Bearer ' . $aliceToken]);
        $this->assertSame(200, $detail->getStatusCode());

        // Bob cannot read Alice's order (404, existence not revealed) and has no orders of his own.
        $bobView = $this->get('api/v1/orders/' . $aliceOrder->ID, null, ['Authorization' => 'Bearer ' . $bobToken]);
        $this->assertSame(404, $bobView->getStatusCode());
        $this->assertCount(0, $this->json($this->get('api/v1/orders', null, ['Authorization' => 'Bearer ' . $bobToken])->getBody()));
    }

    public function testOrdersRequireAuthentication(): void
    {
        $this->assertSame(401, $this->get('api/v1/orders')->getStatusCode());
    }

    public function testCustomerProfileReadAndUpdate(): void
    {
        $member = $this->makeMember('profile@example.com');
        [, $plain] = $this->makeToken($member, [ApiScope::WRITE]);
        $headers = ['Authorization' => 'Bearer ' . $plain];

        $profile = $this->json($this->get('api/v1/customer', null, $headers)->getBody());
        $this->assertSame('profile@example.com', $profile['email']);

        $update = $this->sendJson('PUT', 'api/v1/customer', ['FirstName' => 'Updated'], $headers);
        $this->assertSame(200, $update->getStatusCode());
        $this->assertSame('Updated', $this->json($update->getBody())['firstName']);
        $this->assertSame('Updated', Member::get()->byID($member->ID)->FirstName);
    }

    public function testCustomerAddressCreate(): void
    {
        $member = $this->makeMember('addr@example.com');
        [, $plain] = $this->makeToken($member, [ApiScope::WRITE]);
        $headers = ['Authorization' => 'Bearer ' . $plain];

        $create = $this->sendJson('POST', 'api/v1/customer/addresses', [
            'Address' => '1 Test Street',
            'City' => 'Amsterdam',
            'State' => 'Noord-Holland',
            'Country' => 'NL',
        ], $headers);

        $this->assertSame(201, $create->getStatusCode());
        $data = $this->json($create->getBody());
        $this->assertSame('1 Test Street', $data['address']);
        $this->assertSame(1, $member->AddressBook()->count());
    }

    public function testAdminCatalogWriteScopeEnforced(): void
    {
        // A write-scope token is not enough for catalog write (needs admin scope).
        $writer = $this->makeMember('writer@example.com');
        [, $writeToken] = $this->makeToken($writer, [ApiScope::WRITE]);
        $denied = $this->sendJson('POST', 'api/v1/products', ['Title' => 'X', 'BasePrice' => 1], [
            'Authorization' => 'Bearer ' . $writeToken,
        ]);
        $this->assertSame(403, $denied->getStatusCode());

        // An admin-scope token whose member can create products succeeds.
        $admin = $this->createMemberWithPermission('ADMIN');
        [, $adminToken] = $this->makeToken($admin, [ApiScope::ADMIN]);
        $created = $this->sendJson('POST', 'api/v1/products', [
            'Title' => 'API Product',
            'BasePrice' => 9.99,
            'InternalItemID' => 'API-1',
        ], ['Authorization' => 'Bearer ' . $adminToken]);

        $this->assertSame(201, $created->getStatusCode());
        $data = $this->json($created->getBody());
        $this->assertSame('API Product', $data['title']);

        $published = Versioned::get_by_stage(Product::class, Versioned::LIVE)->byID($data['id']);
        $this->assertNotNull($published, 'The created product is published to Live');
    }

    /**
     * Issue a JSON-body request through the functional-test stack.
     *
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    private function sendJson(string $method, string $url, array $body, array $headers): \SilverStripe\Control\HTTPResponse
    {
        $headers['Content-Type'] = 'application/json';
        $json = (string) json_encode($body);

        return $this->mainSession->sendRequest(
            $method,
            $url,
            [],
            $headers,
            null,
            $json
        );
    }
}
