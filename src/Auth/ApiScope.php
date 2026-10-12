<?php

declare(strict_types=1);

namespace SilverShop\Api\Auth;

/**
 * The scopes an {@link ApiToken} can grant. `admin` implies `write` implies `read`.
 */
final class ApiScope
{
    public const READ = 'read';

    public const WRITE = 'write';

    public const ADMIN = 'admin';

    /**
     * All scopes, in increasing order of privilege.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [self::READ, self::WRITE, self::ADMIN];
    }

    /**
     * The scopes implied by holding the given scope (a higher scope implies the lower ones).
     *
     * @return array<int, string>
     */
    public static function implied(string $scope): array
    {
        return match ($scope) {
            self::ADMIN => [self::ADMIN, self::WRITE, self::READ],
            self::WRITE => [self::WRITE, self::READ],
            self::READ => [self::READ],
            default => [],
        };
    }
}
