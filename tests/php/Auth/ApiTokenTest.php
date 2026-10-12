<?php

declare(strict_types=1);

namespace SilverShop\Api\Tests\Auth;

use SilverShop\Api\Auth\ApiScope;
use SilverShop\Api\Auth\ApiToken;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;

class ApiTokenTest extends SapphireTest
{
    protected $usesDatabase = true;

    public function testGenerationStoresHashNotPlaintext(): void
    {
        $token = ApiToken::create(['Title' => 'Key', 'Scopes' => ApiScope::READ]);
        $token->write();

        $plain = $token->plainTextToken;
        $this->assertNotEmpty($plain, 'Plaintext is available once, right after creation');
        $this->assertStringStartsWith('sk_', (string) $plain);
        $this->assertNotEquals($plain, $token->TokenHash, 'Only the hash is persisted');
        $this->assertSame(ApiToken::hashToken((string) $plain), $token->TokenHash);
        $this->assertSame(substr((string) $plain, 0, 11), $token->TokenPrefix);
    }

    public function testFindByToken(): void
    {
        $token = ApiToken::create(['Title' => 'Key', 'Scopes' => ApiScope::READ]);
        $token->write();
        $plain = (string) $token->plainTextToken;

        $this->assertNotNull(ApiToken::findByToken($plain));
        $this->assertSame($token->ID, ApiToken::findByToken($plain)->ID);
        $this->assertNull(ApiToken::findByToken('sk_wrong'));
        $this->assertNull(ApiToken::findByToken(''));
    }

    public function testExpiredTokenIsNotResolved(): void
    {
        $token = ApiToken::create(['Title' => 'Key', 'Scopes' => ApiScope::READ]);
        $token->Expiry = '2000-01-01 00:00:00';
        $token->write();
        $plain = (string) $token->plainTextToken;

        $this->assertTrue($token->isExpired());
        $this->assertNull(ApiToken::findByToken($plain), 'An expired token resolves to null');
    }

    public function testScopeImplication(): void
    {
        $admin = ApiToken::create(['Title' => 'a', 'Scopes' => ApiScope::ADMIN]);
        $this->assertTrue($admin->hasScope(ApiScope::ADMIN));
        $this->assertTrue($admin->hasScope(ApiScope::WRITE), 'admin implies write');
        $this->assertTrue($admin->hasScope(ApiScope::READ), 'admin implies read');

        $read = ApiToken::create(['Title' => 'r', 'Scopes' => ApiScope::READ]);
        $this->assertTrue($read->hasScope(ApiScope::READ));
        $this->assertFalse($read->hasScope(ApiScope::WRITE));
        $this->assertFalse($read->hasScope(ApiScope::ADMIN));
    }

    public function testRecordUseThrottled(): void
    {
        $token = ApiToken::create(['Title' => 'Key', 'Scopes' => ApiScope::READ]);
        $token->write();

        $this->assertEmpty($token->LastUsed);
        $token->recordUse();
        $firstUse = $token->LastUsed;
        $this->assertNotEmpty($firstUse, 'First use is stamped');

        // A second immediate use is throttled (no change).
        $token->recordUse();
        $this->assertSame($firstUse, $token->LastUsed);

        // Beyond the throttle window it updates again.
        DBDatetime::set_mock_now(date('Y-m-d H:i:s', strtotime($firstUse) + 3600));
        $token->recordUse();
        $this->assertNotSame($firstUse, $token->LastUsed);
        DBDatetime::clear_mock_now();
    }
}
