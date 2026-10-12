<?php

declare(strict_types=1);

namespace SilverShop\Api\Webhook;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\FieldType\DBDatetime;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use Throwable;

/**
 * Dispatches webhook events to the subscribed endpoints. Delivery runs as a {@link WebhookDeliveryJob} when
 * symbiote/queuedjobs is installed (so a slow/failing endpoint never blocks checkout), and inline otherwise. Both
 * paths share {@link self::postJson()}, which signs the body with the subscription secret.
 *
 * Endpoints come from CMS-managed {@link WebhookSubscription} records and/or the `endpoints` config map.
 */
class WebhookService
{
    use Injectable;
    use Configurable;

    private static bool $enabled = true;

    /**
     * Config-declared endpoints: event => list of URLs (or [url, secret] pairs). Merged with CMS subscriptions.
     *
     * @var array<string, array<int, string|array<string, string>>>
     */
    private static array $endpoints = [];

    public const EVENT_ORDER_PLACED = 'order.placed';

    public const EVENT_ORDER_PAID = 'order.paid';

    public const EVENT_ORDER_STATUS_CHANGE = 'order.statuschange';

    public const EVENT_PRODUCT_PUBLISHED = 'product.published';

    /**
     * Dispatch an event to every active target.
     *
     * @param array<string, mixed> $payload
     */
    public function dispatch(string $event, array $payload): void
    {
        if (!$this->config()->get('enabled')) {
            return;
        }

        $targets = $this->targetsFor($event);
        if ($targets === []) {
            return;
        }

        $body = $this->encode($event, $payload);

        foreach ($targets as $target) {
            $this->deliver($event, $body, $target['url'], $target['secret']);
        }
    }

    /**
     * The active targets for an event, from CMS subscriptions plus config endpoints, de-duplicated by URL.
     *
     * @return array<int, array{url: string, secret: string}>
     */
    public function targetsFor(string $event): array
    {
        $targets = [];

        foreach (WebhookSubscription::get()->filter(['Event' => $event, 'IsActive' => true]) as $subscription) {
            $url = trim((string) $subscription->URL);
            if ($url !== '') {
                $targets[$url] = ['url' => $url, 'secret' => (string) $subscription->Secret];
            }
        }

        $configured = (array) $this->config()->get('endpoints');
        foreach ((array) ($configured[$event] ?? []) as $entry) {
            if (is_array($entry)) {
                $url = trim((string) ($entry['url'] ?? ''));
                $secret = (string) ($entry['secret'] ?? '');
            } else {
                $url = trim((string) $entry);
                $secret = '';
            }
            if ($url !== '') {
                $targets[$url] = ['url' => $url, 'secret' => $secret];
            }
        }

        return array_values($targets);
    }

    private function deliver(string $event, string $body, string $url, string $secret): void
    {
        if (class_exists(QueuedJobService::class)) {
            QueuedJobService::singleton()->queueJob(new WebhookDeliveryJob($event, $url, $secret, $body));
            return;
        }

        self::postJson($url, $body, $event, $secret);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(string $event, array $payload): string
    {
        return (string) json_encode([
            'event' => $event,
            'timestamp' => DBDatetime::now()->Rfc2822(),
            'data' => $payload,
        ]);
    }

    /**
     * The HMAC-SHA256 signature header value for a body + secret (`sha256=<hex>`).
     */
    public static function signature(string $body, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    /**
     * Perform the actual POST (Guzzle when available, else a stream context). Returns whether a 2xx was received.
     * Network/HTTP failures are swallowed (and logged) so a webhook never breaks the originating request.
     */
    public static function postJson(string $url, string $body, string $event, string $secret = ''): bool
    {
        $headers = [
            'Content-Type' => 'application/json',
            'X-SilverShop-Event' => $event,
            'User-Agent' => 'SilverShop-Webhook/1.0',
        ];
        if ($secret !== '') {
            $headers['X-SilverShop-Signature'] = self::signature($body, $secret);
        }

        try {
            if (class_exists(Client::class)) {
                $response = (new Client())->request('POST', $url, [
                    'body' => $body,
                    'headers' => $headers,
                    'timeout' => 10,
                    'http_errors' => false,
                ]);
                return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
            }

            $headerLines = '';
            foreach ($headers as $name => $value) {
                $headerLines .= $name . ': ' . $value . "\r\n";
            }
            $context = stream_context_create(['http' => [
                'method' => 'POST',
                'header' => $headerLines,
                'content' => $body,
                'timeout' => 10,
                'ignore_errors' => true,
            ]]);
            $result = @file_get_contents($url, false, $context);

            return $result !== false;
        } catch (Throwable $e) {
            Injector::inst()->get(LoggerInterface::class)->warning(
                sprintf('SilverShop webhook delivery to %s failed: %s', $url, $e->getMessage())
            );

            return false;
        }
    }
}
