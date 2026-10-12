<?php

declare(strict_types=1);

namespace SilverShop\Api\Webhook;

use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;

/**
 * A CMS-managed webhook endpoint: deliver a POST to {@link $URL} whenever {@link $Event} fires. An optional
 * {@link $Secret} signs each delivery (HMAC-SHA256 in the `X-SilverShop-Signature` header). Config-declared endpoints
 * ({@see WebhookService::$endpoints}) are an alternative to these records.
 *
 * @property string $Title
 * @property string $Event
 * @property string $URL
 * @property string $Secret
 * @property bool $IsActive
 */
class WebhookSubscription extends DataObject
{
    private static string $table_name = 'SilverShop_WebhookSubscription';

    private static string $singular_name = 'Webhook';

    private static string $plural_name = 'Webhooks';

    private static array $db = [
        'Title' => 'Varchar(100)',
        'Event' => "Enum('order.placed,order.paid,order.statuschange,product.published','order.placed')",
        'URL' => 'Varchar(2083)',
        'Secret' => 'Varchar(100)',
        'IsActive' => 'Boolean(1)',
    ];

    private static array $summary_fields = [
        'Title' => 'Label',
        'Event' => 'Event',
        'URL' => 'Endpoint',
        'IsActive.Nice' => 'Active',
    ];

    private static string $default_sort = '"Event" ASC, "Title" ASC';

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();

        $fields->dataFieldByName('URL')?->setDescription(
            _t(self::class . '.URLDesc', 'HTTPS endpoint that will receive the signed POST.')
        );
        $fields->dataFieldByName('Secret')?->setDescription(
            _t(self::class . '.SecretDesc', 'Optional shared secret used to sign deliveries (X-SilverShop-Signature).')
        );

        return $fields;
    }

    public function canView($member = null): bool
    {
        return Permission::check('ADMIN', 'any', $member);
    }

    public function canEdit($member = null): bool
    {
        return Permission::check('ADMIN', 'any', $member);
    }

    public function canCreate($member = null, $context = []): bool
    {
        return Permission::check('ADMIN', 'any', $member);
    }

    public function canDelete($member = null): bool
    {
        return Permission::check('ADMIN', 'any', $member);
    }
}
