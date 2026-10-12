<?php

declare(strict_types=1);

namespace SilverShop\Api\Auth;

use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;

/**
 * A personal-access / API key used for bearer authentication. The plaintext token is shown only once, at creation;
 * only its SHA-256 hash is stored, so a leaked database never exposes usable tokens. A token belongs to a
 * {@link Member} (whose permissions back `canView()`/`canEdit()` on resources) and carries one or more scopes
 * (read/write/admin) that gate the API surface.
 *
 * @property string $Title
 * @property string $TokenHash
 * @property string $TokenPrefix
 * @property string $Scopes
 * @property ?string $Expiry
 * @property ?string $LastUsed
 * @property int $MemberID
 * @method Member Member()
 */
class ApiToken extends DataObject
{
    private static string $table_name = 'SilverShop_ApiToken';

    private static string $singular_name = 'API token';

    private static string $plural_name = 'API tokens';

    private static array $db = [
        'Title' => 'Varchar(100)',
        'TokenHash' => 'Varchar(64)',
        'TokenPrefix' => 'Varchar(16)',
        'Scopes' => 'Varchar(255)',
        'Expiry' => 'Datetime',
        'LastUsed' => 'Datetime',
    ];

    private static array $has_one = [
        'Member' => Member::class,
    ];

    private static array $indexes = [
        'TokenHash' => [
            'type' => 'unique',
            'columns' => ['TokenHash'],
        ],
    ];

    private static array $summary_fields = [
        'Title' => 'Label',
        'TokenPrefix' => 'Prefix',
        'Scopes' => 'Scopes',
        'Member.Title' => 'Member',
        'Expiry' => 'Expires',
        'LastUsed' => 'Last used',
    ];

    private static string $default_sort = '"LastUsed" DESC, "Created" DESC';

    /**
     * How long (seconds) between {@link self::recordUse()} writes, to avoid a database write on every request.
     */
    private static int $last_used_throttle = 60;

    /**
     * The plaintext token, available only in the request that generated it (never persisted).
     */
    public ?string $plainTextToken = null;

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();

        $fields->removeByName(['TokenHash', 'TokenPrefix', 'LastUsed']);

        $scopeOptions = [
            ApiScope::READ => _t(self::class . '.ScopeRead', 'Read — read catalog, cart, own orders and profile'),
            ApiScope::WRITE => _t(self::class . '.ScopeWrite', 'Write — modify cart, profile and addresses'),
            ApiScope::ADMIN => _t(self::class . '.ScopeAdmin', 'Admin — write catalog (create/update products)'),
        ];
        $fields->replaceField(
            'Scopes',
            CheckboxSetField::create('Scopes', _t(self::class . '.Scopes', 'Scopes'), $scopeOptions)
        );

        // Surface the plaintext exactly once, right after generation.
        if ($this->plainTextToken !== null) {
            $fields->addFieldToTab('Root.Main', LiteralField::create(
                'PlainTokenNotice',
                sprintf(
                    '<div class="alert alert-success"><strong>%s</strong><br><code style="user-select:all">%s</code></div>',
                    _t(self::class . '.CopyNow', 'Copy this token now — it will not be shown again:'),
                    htmlspecialchars($this->plainTextToken, ENT_QUOTES)
                )
            ), 'Scopes');
        } elseif ($this->isInDB()) {
            $fields->addFieldToTab('Root.Main', ReadonlyField::create(
                'PrefixDisplay',
                _t(self::class . '.Token', 'Token'),
                $this->TokenPrefix . '… ' . _t(self::class . '.Hidden', '(hidden — create a new token to rotate)')
            ), 'Scopes');
        }

        return $fields;
    }

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();

        if (!$this->TokenHash) {
            $plain = self::generatePlainToken();
            $this->plainTextToken = $plain;
            $this->TokenHash = self::hashToken($plain);
            $this->TokenPrefix = substr($plain, 0, 11);
        }

        if (!$this->Scopes) {
            $this->Scopes = ApiScope::READ;
        }
    }

    /**
     * Generate a fresh, high-entropy plaintext token (prefixed so it is recognisable in logs/headers).
     */
    public static function generatePlainToken(): string
    {
        return 'sk_' . bin2hex(random_bytes(24));
    }

    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * Resolve a plaintext token to its (non-expired) record, or null.
     */
    public static function findByToken(string $plain): ?ApiToken
    {
        $plain = trim($plain);
        if ($plain === '') {
            return null;
        }

        /** @var ApiToken|null $token */
        $token = ApiToken::get()->filter('TokenHash', self::hashToken($plain))->first();

        if (!$token || $token->isExpired()) {
            return null;
        }

        return $token;
    }

    public function isExpired(): bool
    {
        if (!$this->Expiry) {
            return false;
        }

        return strtotime((string) $this->Expiry) < DBDatetime::now()->getTimestamp();
    }

    /**
     * @return array<int, string>
     */
    public function getScopeList(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->Scopes))));
    }

    public function hasScope(string $scope): bool
    {
        foreach ($this->getScopeList() as $held) {
            if (in_array($scope, ApiScope::implied($held), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Stamp the last-used time, throttled so it does not write on every request.
     */
    public function recordUse(): void
    {
        $throttle = (int) self::config()->get('last_used_throttle');
        $now = DBDatetime::now()->getTimestamp();

        if ($this->LastUsed && ($now - strtotime((string) $this->LastUsed)) < $throttle) {
            return;
        }

        $this->LastUsed = DBDatetime::now()->Rfc2822();
        $this->write();
    }

    public function canView($member = null): bool
    {
        return Permission::check('ADMIN', 'any', $member);
    }

    public function canEdit($member = null): bool
    {
        return Permission::check('ADMIN', 'any', $member);
    }

    public function canCreate($member = null, $context = []): bool
    {
        return Permission::check('ADMIN', 'any', $member);
    }

    public function canDelete($member = null): bool
    {
        return Permission::check('ADMIN', 'any', $member);
    }
}
