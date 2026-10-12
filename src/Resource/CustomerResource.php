<?php

declare(strict_types=1);

namespace SilverShop\Api\Resource;

use SilverShop\Api\ApiException;
use SilverShop\Api\Auth\ApiContext;
use SilverShop\Api\Serializer\AddressSerializer;
use SilverShop\Api\Serializer\CustomerSerializer;
use SilverShop\Api\Serializer\OrderSerializer;
use SilverShop\Model\Address;
use SilverShop\Model\Order;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Security\Member;

/**
 * The `customer` resource — the authenticated customer's own profile, address book and order history.
 *
 *  - GET        customer | customer/me        profile + addresses
 *  - PUT/PATCH  customer | customer/me        update profile (first name, surname)
 *  - GET        customer/addresses            list addresses
 *  - GET        customer/addresses/{id}       one address
 *  - POST       customer/addresses            create an address
 *  - PUT/PATCH  customer/addresses/{id}       update an address
 *  - GET        customer/orders               order history
 */
class CustomerResource extends AbstractResource
{
    use HandlesListParams;

    /**
     * @var array<string, string>
     */
    private const PROFILE_WRITABLE = [
        'FirstName' => 'string',
        'Surname' => 'string',
    ];

    /**
     * @var array<string, string>
     */
    private const ADDRESS_WRITABLE = [
        'FirstName' => 'string',
        'Surname' => 'string',
        'Company' => 'string',
        'Address' => 'string',
        'AddressLine2' => 'string',
        'City' => 'string',
        'State' => 'string',
        'PostalCode' => 'string',
        'Country' => 'string',
        'Phone' => 'string',
    ];

    public function getName(): string
    {
        return 'customer';
    }

    public function handle(HTTPRequest $request, ApiContext $context, array $path): ResourceResult
    {
        $member = $this->requireMember($context);
        $section = $path[0] ?? 'me';

        return match ($section) {
            'me' => $this->profile($request, $context, $member),
            'addresses' => $this->addresses($request, $context, $member, array_slice($path, 1)),
            'orders' => $this->orders($request, $member),
            default => throw ApiException::notFound(),
        };
    }

    private function profile(HTTPRequest $request, ApiContext $context, Member $member): ResourceResult
    {
        $method = strtoupper($request->httpMethod());

        if (in_array($method, ['PUT', 'PATCH'], true)) {
            $this->authoriseMemberWrite($request, $context);
            $this->applyWritable($member, self::PROFILE_WRITABLE, $this->jsonBody($request));
            $this->persist($member);
        } else {
            $this->assertMethod($request, ['GET', 'PUT', 'PATCH']);
        }

        return ResourceResult::ok(CustomerSerializer::create()->serialise($member), [], 'customer');
    }

    /**
     * @param array<int, string> $path
     */
    private function addresses(HTTPRequest $request, ApiContext $context, Member $member, array $path): ResourceResult
    {
        $method = strtoupper($request->httpMethod());

        // Collection
        if ($path === []) {
            if ($method === 'POST') {
                return $this->createAddress($request, $context, $member);
            }
            $this->assertMethod($request, ['GET', 'POST']);

            $serializer = AddressSerializer::create();
            $payload = [];
            foreach ($member->AddressBook() as $address) {
                $payload[] = $serializer->serialise($address);
            }

            return ResourceResult::ok($payload, [], 'addresses', 'address');
        }

        // Member (single address)
        if (count($path) !== 1 || preg_match('/^\d+$/', $path[0]) !== 1) {
            throw ApiException::notFound();
        }

        $address = $this->ownedAddress($member, (int) $path[0]);

        if (in_array($method, ['PUT', 'PATCH'], true)) {
            $this->authoriseMemberWrite($request, $context);
            $this->applyWritable($address, self::ADDRESS_WRITABLE, $this->jsonBody($request));
            $this->persist($address);
        } else {
            $this->assertMethod($request, ['GET', 'PUT', 'PATCH']);
        }

        return ResourceResult::ok(AddressSerializer::create()->serialise($address), [], 'address');
    }

    private function createAddress(HTTPRequest $request, ApiContext $context, Member $member): ResourceResult
    {
        $this->authoriseMemberWrite($request, $context);

        $address = Address::create();
        $address->MemberID = $member->ID;
        $this->applyWritable($address, self::ADDRESS_WRITABLE, $this->jsonBody($request));
        $this->persist($address);

        return ResourceResult::created(AddressSerializer::create()->serialise($address), 'address');
    }

    private function orders(HTTPRequest $request, Member $member): ResourceResult
    {
        $this->assertMethod($request, ['GET']);

        $list = Order::get()
            ->filter('MemberID', $member->ID)
            ->exclude('Status', Order::config()->get('hidden_status'));
        $list = $this->applySort($list, $request, ['Placed', 'Created', 'Total']);

        $page = $this->paginate($list, $request);
        $serializer = OrderSerializer::create();
        $payload = [];
        foreach ($page['list'] as $order) {
            if ($order->canView($member)) {
                $payload[] = $serializer->serialise($order);
            }
        }

        return ResourceResult::ok($payload, $page['headers'], 'orders', 'order');
    }

    private function ownedAddress(Member $member, int $id): Address
    {
        $address = Address::get()->byID($id);
        if (!$address || !$address->exists() || (int) $address->MemberID !== (int) $member->ID) {
            throw ApiException::notFound('Address not found');
        }

        return $address;
    }

    /**
     * @param array<string, string> $allowed
     * @param array<string, mixed> $data
     */
    private function applyWritable(object $record, array $allowed, array $data): void
    {
        $applied = false;
        foreach ($allowed as $field => $type) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];
            $record->setField($field, match ($type) {
                'float' => (float) $value,
                'int' => (int) $value,
                'bool' => (bool) $value,
                default => (string) $value,
            });
            $applied = true;
        }

        if (!$applied) {
            throw ApiException::badRequest('No writable fields supplied.');
        }
    }
}
