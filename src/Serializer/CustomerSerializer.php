<?php

declare(strict_types=1);

namespace SilverShop\Api\Serializer;

use SilverStripe\Security\Member;

/**
 * Serialises the authenticated customer ({@link Member}) — profile plus their saved address book. Fires
 * `updateSerialisedCustomer`.
 */
class CustomerSerializer extends Serializer
{
    public function serialise(object $record): array
    {
        /** @var Member $member */
        $member = $record;

        $addresses = [];
        $addressSerializer = AddressSerializer::create();
        if ($member->hasMethod('AddressBook')) {
            foreach ($member->AddressBook() as $address) {
                $addresses[] = $addressSerializer->serialise($address);
            }
        }

        $data = [
            'id' => (int) $member->ID,
            'firstName' => (string) $member->FirstName,
            'surname' => (string) $member->Surname,
            'email' => (string) $member->Email,
            'defaultShippingAddressID' => (int) $member->DefaultShippingAddressID,
            'defaultBillingAddressID' => (int) $member->DefaultBillingAddressID,
            'addresses' => $addresses,
        ];

        $this->extend('updateSerialisedCustomer', $data, $member);

        return $data;
    }
}
