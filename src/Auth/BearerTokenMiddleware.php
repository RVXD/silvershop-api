<?php

declare(strict_types=1);

namespace SilverShop\Api\Auth;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Middleware\HTTPMiddleware;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Security\Security;

/**
 * Global middleware that, for requests under the API base path, resolves a bearer {@link ApiToken} to an
 * {@link ApiContext} (published via {@link ApiContext::set()}) and logs the token's {@link Member} in for the
 * duration of the request so that resource `canView()`/`canEdit()` checks work naturally. Requests without a
 * bearer token, or outside the API base path, pass through untouched — the catalog can still be read anonymously
 * when `public_read` is enabled.
 */
class BearerTokenMiddleware implements HTTPMiddleware
{
    use Configurable;

    /**
     * The URL prefix this middleware acts on. Must match the route mounted for {@link \SilverShop\Api\Control\ApiController}.
     */
    private static string $base_path = 'api/v1';

    public function process(HTTPRequest $request, callable $delegate)
    {
        if (!$this->handles($request)) {
            return $delegate($request);
        }

        $context = Injector::inst()->create(TokenAuthenticator::class)->authenticate($request);
        ApiContext::set($context);

        $restore = false;
        $previous = Security::getCurrentUser();

        if ($context->isAuthenticated() && $context->getMember() !== null) {
            Security::setCurrentUser($context->getMember());
            $restore = true;
        }

        try {
            return $delegate($request);
        } finally {
            if ($restore) {
                Security::setCurrentUser($previous);
            }
            ApiContext::set(null);
        }
    }

    private function handles(HTTPRequest $request): bool
    {
        $base = trim((string) $this->config()->get('base_path'), '/');
        if ($base === '') {
            return false;
        }

        $url = trim((string) $request->getURL(), '/');

        return $url === $base || str_starts_with($url, $base . '/');
    }
}
