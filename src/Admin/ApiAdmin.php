<?php

declare(strict_types=1);

namespace SilverShop\Api\Admin;

use SilverShop\Api\Auth\ApiToken;
use SilverShop\Api\Webhook\WebhookSubscription;
use SilverStripe\Admin\ModelAdmin;

/**
 * CMS administration for the API: issue/revoke {@link ApiToken}s and manage {@link WebhookSubscription}s.
 */
class ApiAdmin extends ModelAdmin
{
    private static string $url_segment = 'shop-api';

    private static string $menu_title = 'API';

    private static string $menu_icon_class = 'font-icon-connector';

    private static array $managed_models = [
        ApiToken::class,
        WebhookSubscription::class,
    ];
}
