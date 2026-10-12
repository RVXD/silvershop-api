<?php

declare(strict_types=1);

namespace SilverShop\Api\Serializer;

use SilverShop\Model\Address;

/**
 * Serialises an {@link Address}. Fires `updateSerialisedAddress`.
 */
class AddressSerializer extends Serializer
{
    public function serialise(object $record): array
    {
        /** @var Address $address */
        $address = $record;

        $data = [
            'id' => (int) $address->ID,
            'firstName' => (string) $address->FirstName,
            'surname' => (string) $address->Surname,
            'company' => (string) $address->Company,
            'address' => (string) $address->Address,
            'addressLine2' => (string) $address->AddressLine2,
            'city' => (string) $address->City,
            'state' => (string) $address->State,
            'postalCode' => (string) $address->PostalCode,
            'country' => (string) $address->Country,
            'phone' => (string) $address->Phone,
            'formatted' => (string) $address->toString(),
        ];

        $this->extend('updateSerialisedAddress', $data, $address);

        return $data;
    }
}
