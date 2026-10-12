<?php

declare(strict_types=1);

namespace SilverShop\Api\Tests\Webhook;

use SilverShop\Api\Webhook\WebhookDeliveryJob;
use SilverShop\Api\Webhook\WebhookService;
use SilverShop\Api\Webhook\WebhookSubscription;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;

class WebhookServiceTest extends SapphireTest
{
    protected $usesDatabase = true;

    public function testTargetsForFromSubscriptions(): void
    {
        $this->makeSubscription('order.paid', 'https://a.example.com/hook', 'topsecret', true);
        $this->makeSubscription('order.paid', 'https://inactive.example.com/hook', '', false);
        $this->makeSubscription('order.placed', 'https://other.example.com/hook', '', true);

        $targets = WebhookService::create()->targetsFor('order.paid');

        $this->assertCount(1, $targets);
        $this->assertSame('https://a.example.com/hook', $targets[0]['url']);
        $this->assertSame('topsecret', $targets[0]['secret']);
    }

    public function testTargetsForFromConfig(): void
    {
        Config::modify()->set(WebhookService::class, 'endpoints', [
            'product.published' => [
                'https://config.example.com/hook',
                ['url' => 'https://config2.example.com/hook', 'secret' => 's'],
            ],
        ]);

        $targets = WebhookService::create()->targetsFor('product.published');
        $urls = array_column($targets, 'url');

        $this->assertContains('https://config.example.com/hook', $urls);
        $this->assertContains('https://config2.example.com/hook', $urls);
    }

    public function testSignatureFormat(): void
    {
        $sig = WebhookService::signature('{"a":1}', 'secret');
        $this->assertSame('sha256=' . hash_hmac('sha256', '{"a":1}', 'secret'), $sig);
        $this->assertStringStartsWith('sha256=', $sig);
    }

    public function testDispatchQueuesJobWhenQueuedJobsPresent(): void
    {
        $this->makeSubscription('order.placed', 'https://queue.example.com/hook', '', true);

        $before = QueuedJobDescriptor::get()->count();
        WebhookService::create()->dispatch('order.placed', ['reference' => 'ABC']);
        $after = QueuedJobDescriptor::get()->filter('Implementation', WebhookDeliveryJob::class);

        $this->assertSame($before + 1, QueuedJobDescriptor::get()->count());
        $this->assertSame(1, $after->count());
    }

    public function testDispatchWithNoTargetsIsNoop(): void
    {
        $before = QueuedJobDescriptor::get()->count();
        WebhookService::create()->dispatch('order.paid', ['reference' => 'none']);
        $this->assertSame($before, QueuedJobDescriptor::get()->count());
    }

    public function testDisabledServiceDoesNotDispatch(): void
    {
        Config::modify()->set(WebhookService::class, 'enabled', false);
        $this->makeSubscription('order.paid', 'https://x.example.com/hook', '', true);

        $before = QueuedJobDescriptor::get()->count();
        WebhookService::create()->dispatch('order.paid', ['reference' => 'ABC']);
        $this->assertSame($before, QueuedJobDescriptor::get()->count());
    }

    private function makeSubscription(string $event, string $url, string $secret, bool $active): void
    {
        WebhookSubscription::create([
            'Title' => $event,
            'Event' => $event,
            'URL' => $url,
            'Secret' => $secret,
            'IsActive' => $active,
        ])->write();
    }
}
