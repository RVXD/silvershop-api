<?php

declare(strict_types=1);

namespace SilverShop\Api\Webhook;

use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;

/**
 * Delivers a single webhook POST as a background job. Extends symbiote/queuedjobs' {@link AbstractQueuedJob}, so this
 * class is only ever autoloaded when queuedjobs is installed — {@link WebhookService::deliver()} guards its
 * instantiation behind a `class_exists(QueuedJobService::class)` check, keeping the base module graphql/queuedjobs-free
 * and safe to run without the optional dependency.
 *
 * @property string $event
 * @property string $url
 * @property string $secret
 * @property string $body
 */
class WebhookDeliveryJob extends AbstractQueuedJob
{
    public function __construct(string $event = '', string $url = '', string $secret = '', string $body = '')
    {
        if ($event !== '') {
            $this->event = $event;
            $this->url = $url;
            $this->secret = $secret;
            $this->body = $body;
        }
    }

    public function getTitle(): string
    {
        return sprintf('Deliver webhook "%s" to %s', (string) $this->event, (string) $this->url);
    }

    public function getJobType()
    {
        return QueuedJob::QUEUED;
    }

    public function process(): void
    {
        WebhookService::postJson(
            (string) $this->url,
            (string) $this->body,
            (string) $this->event,
            (string) $this->secret
        );

        $this->isComplete = true;
    }
}
