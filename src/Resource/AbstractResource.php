<?php

declare(strict_types=1);

namespace SilverShop\Api\Resource;

use SilverShop\Api\ApiException;
use SilverShop\Api\Auth\ApiContext;
use SilverShop\Api\Auth\ApiScope;
use SilverShop\Api\Control\ApiController;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use SilverStripe\Security\SecurityToken;

/**
 * Convenience base for resources: scope/auth gating, read-access (public vs token-gated) and JSON body parsing.
 */
abstract class AbstractResource implements ApiResource
{
    use Injectable;

    /**
     * Gate a catalog read: allowed to anyone when `public_read` is on, otherwise needs a token with the read scope.
     */
    protected function requireReadAccess(ApiContext $context): void
    {
        if ((bool) Config::forClass(ApiController::class)->get('public_read')) {
            return;
        }

        if (!$context->hasScope(ApiScope::READ)) {
            throw ApiException::unauthorized('A token with the "read" scope is required to read the catalog.');
        }
    }

    /**
     * Require that the request carries the given scope.
     */
    protected function requireScope(ApiContext $context, string $scope): void
    {
        if (!$context->isAuthenticated()) {
            throw ApiException::unauthorized();
        }
        if (!$context->hasScope($scope)) {
            throw ApiException::forbidden(sprintf('This action requires the "%s" scope.', $scope));
        }
    }

    /**
     * Require an authenticated member and return it. A bearer token's member is preferred; failing that the
     * same-origin session user (so storefront JS works without a token) is used.
     */
    protected function requireMember(ApiContext $context): Member
    {
        $member = $context->getMember() ?? Security::getCurrentUser();
        if (!$member || !$member->exists()) {
            throw ApiException::unauthorized();
        }

        return $member;
    }

    /**
     * Gate a member-scoped state-changing request (profile/address write): a bearer token needs the given scope
     * (write by default); a same-origin logged-in session needs a valid CSRF token. A request with neither an
     * authenticated token nor a session member is unauthorised.
     */
    protected function authoriseMemberWrite(HTTPRequest $request, ApiContext $context, string $scope = ApiScope::WRITE): void
    {
        if ($context->isAuthenticated()) {
            $this->requireScope($context, $scope);
            return;
        }

        if (!Security::getCurrentUser()) {
            throw ApiException::unauthorized();
        }

        if (SecurityToken::is_enabled() && !SecurityToken::inst()->checkRequest($request)) {
            throw new ApiException(400, 'Invalid security token, possible CSRF attack.');
        }
    }

    /**
     * Decode a JSON request body to an array, or throw 400. Also accepts form-encoded POST vars as a fallback.
     *
     * @return array<string, mixed>
     */
    protected function jsonBody(HTTPRequest $request): array
    {
        $raw = trim((string) $request->getBody());
        if ($raw === '') {
            return $request->postVars();
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw ApiException::badRequest('Request body is not valid JSON: ' . $e->getMessage());
        }

        if (!is_array($decoded)) {
            throw ApiException::badRequest('Request body must be a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Write a record, converting a model validation failure into a 422 response instead of an uncaught 500.
     */
    protected function persist(DataObject $record): void
    {
        try {
            $record->write();
        } catch (ValidationException $e) {
            $messages = array_map(
                static fn (array $m): string => (string) ($m['message'] ?? ''),
                $e->getResult()->getMessages()
            );
            $messages = array_values(array_filter($messages));
            throw new ApiException(422, 'Validation failed: ' . implode('; ', $messages ?: ['invalid data']));
        }
    }

    /**
     * Assert the request uses one of the allowed HTTP methods, else 405.
     *
     * @param array<int, string> $methods
     */
    protected function assertMethod(HTTPRequest $request, array $methods): void
    {
        if (!in_array(strtoupper($request->httpMethod()), $methods, true)) {
            throw ApiException::methodNotAllowed(
                sprintf('Allowed methods: %s', implode(', ', $methods))
            );
        }
    }
}
