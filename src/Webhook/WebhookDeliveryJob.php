<?php

declare(strict_types=1);

namespace SilverShop\Api\Webhook;

use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;

// queuedjobs is an OPTIONAL dependency. Guard the class definition so this file is safe to autoload without it —
// SilverStripe's config scanner autoloads every class in the manifest, which would otherwise fatally fail on the
// missing parent. When queuedjobs is absent the job is simply not declared, class_exists() returns false and the
// scanner skips it; WebhookService::deliver() then falls back to delivering the webhook inline.
if (!class_exists(AbstractQueuedJob::class)) {
    return;
}

/**
 * Delivers a single webhook POST as a background job, extending symbiote/queuedjobs' {@link AbstractQueuedJob}.
 * queuedjobs is optional (see the guard above): the base module runs without it and {@link WebhookService::deliver()}
 * then delivers inline, guarding its use of the queue behind a `class_exists(QueuedJobService::class)` check.
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
