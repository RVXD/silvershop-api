<?php

declare(strict_types=1);

namespace SilverShop\Api\Auth;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injectable;

/**
 * Resolves a bearer token on a request to an {@link ApiContext}. Stateless and side-effect-light (it only stamps the
 * token's throttled last-used time), so it can be called directly from the middleware or, as a fallback, from the
 * controller — and exercised directly in tests.
 */
class TokenAuthenticator
{
    use Injectable;

    /**
     * Always returns a context: an authenticated one for a valid, unexpired token, otherwise anonymous.
     */
    public function authenticate(HTTPRequest $request): ApiContext
    {
        $plain = $this->extractBearerToken($request);
        if ($plain === null) {
            return ApiContext::anonymous();
        }

        $token = ApiToken::findByToken($plain);
        if (!$token) {
            return ApiContext::anonymous();
        }

        $token->recordUse();

        $member = $token->Member();
        $member = ($member && $member->exists()) ? $member : null;

        return new ApiContext(true, $member, $token->getScopeList(), $token);
    }

    /**
     * Extract the bearer credential from the Authorization header. Tokens are never read from the query string
     * (they must not appear in URLs/logs).
     */
    public function extractBearerToken(HTTPRequest $request): ?string
    {
        $header = trim((string) $request->getHeader('Authorization'));
        if ($header !== '' && preg_match('/^Bearer\s+(.+)$/i', $header, $matches) === 1) {
            $value = trim($matches[1]);
            return $value !== '' ? $value : null;
        }

        return null;
    }
}
