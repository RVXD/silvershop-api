<?php

declare(strict_types=1);

namespace SilverShop\Api\Auth;

use SilverStripe\Security\Member;

/**
 * The resolved authentication state for a single API request: the authenticating {@link Member} (if any), the scopes
 * granted by the presented token, and the token itself. An anonymous request is represented by an authenticated=false
 * context with no member and no scopes.
 *
 * Populated by {@link BearerTokenMiddleware} for the duration of the request and read back via {@link self::current()};
 * also constructed directly by {@link TokenAuthenticator} and in tests.
 */
class ApiContext
{
    private static ?ApiContext $current = null;

    private bool $authenticated;

    private ?Member $member;

    /**
     * @var array<int, string>
     */
    private array $scopes;

    private ?ApiToken $token;

    /**
     * @param array<int, string> $scopes
     */
    public function __construct(
        bool $authenticated = false,
        ?Member $member = null,
        array $scopes = [],
        ?ApiToken $token = null
    ) {
        $this->authenticated = $authenticated;
        $this->member = $member;
        $this->scopes = array_values(array_unique($scopes));
        $this->token = $token;
    }

    /**
     * An anonymous (unauthenticated) context.
     */
    public static function anonymous(): self
    {
        return new self(false, null, [], null);
    }

    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    public function getMember(): ?Member
    {
        return $this->member;
    }

    public function getToken(): ?ApiToken
    {
        return $this->token;
    }

    /**
     * @return array<int, string>
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    /**
     * Whether this context grants the given scope. A token's higher scope implies the lower ones
     * (admin ⇒ write ⇒ read). An unauthenticated context grants nothing.
     */
    public function hasScope(string $scope): bool
    {
        foreach ($this->scopes as $held) {
            if (in_array($scope, ApiScope::implied($held), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The request-scoped current context (set by the middleware). Null before the middleware has run.
     */
    public static function current(): ?ApiContext
    {
        return self::$current;
    }

    public static function set(?ApiContext $context): void
    {
        self::$current = $context;
    }
}
