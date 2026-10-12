<?php

declare(strict_types=1);

namespace SilverShop\Api\Tests\Auth;

use SilverShop\Api\Auth\ApiScope;
use SilverShop\Api\Auth\TokenAuthenticator;
use SilverShop\Api\Tests\CatalogFixtures;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Dev\SapphireTest;

class TokenAuthenticatorTest extends SapphireTest
{
    use CatalogFixtures;

    protected $usesDatabase = true;

    private function request(string $authorization = ''): HTTPRequest
    {
        $request = new HTTPRequest('GET', 'api/v1/products');
        if ($authorization !== '') {
            $request->addHeader('Authorization', $authorization);
        }

        return $request;
    }

    public function testValidTokenResolvesMemberAndScopes(): void
    {
        $member = $this->makeMember('buyer@example.com');
        [$token, $plain] = $this->makeToken($member, [ApiScope::WRITE]);

        $context = TokenAuthenticator::singleton()->authenticate($this->request('Bearer ' . $plain));

        $this->assertTrue($context->isAuthenticated());
        $this->assertNotNull($context->getMember());
        $this->assertSame($member->ID, $context->getMember()->ID);
        $this->assertSame($token->ID, $context->getToken()->ID);
        $this->assertTrue($context->hasScope(ApiScope::WRITE));
        $this->assertTrue($context->hasScope(ApiScope::READ), 'write implies read');
        $this->assertFalse($context->hasScope(ApiScope::ADMIN));
    }

    public function testNoHeaderIsAnonymous(): void
    {
        $context = TokenAuthenticator::singleton()->authenticate($this->request());

        $this->assertFalse($context->isAuthenticated());
        $this->assertNull($context->getMember());
        $this->assertFalse($context->hasScope(ApiScope::READ));
    }

    public function testInvalidTokenIsAnonymous(): void
    {
        $context = TokenAuthenticator::singleton()->authenticate($this->request('Bearer sk_bogus'));
        $this->assertFalse($context->isAuthenticated());
    }

    public function testExpiredTokenIsAnonymous(): void
    {
        $member = $this->makeMember('buyer@example.com');
        [, $plain] = $this->makeToken($member, [ApiScope::READ], '2000-01-01 00:00:00');

        $context = TokenAuthenticator::singleton()->authenticate($this->request('Bearer ' . $plain));
        $this->assertFalse($context->isAuthenticated());
    }

    public function testExtractBearerTokenIgnoresNonBearer(): void
    {
        $authenticator = TokenAuthenticator::singleton();
        $this->assertSame('abc', $authenticator->extractBearerToken($this->request('Bearer abc')));
        $this->assertNull($authenticator->extractBearerToken($this->request('Basic abc')));
        $this->assertNull($authenticator->extractBearerToken($this->request()));
    }
}
