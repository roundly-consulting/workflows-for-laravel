<?php

declare(strict_types=1);

namespace RoundlyConsulting\Workflows\Support;

/**
 * Whether an optional Roundly package is installed: its marker class exists (a string literal,
 * so nothing outside a bridge folder names that package's namespace). Tests pretend a package
 * is missing through `pretendMissing()`.
 *
 * @internal
 */
final class Bridges
{
    public const array MARKERS = [
        'approvals' => 'RoundlyConsulting\\Approvals\\ApprovalsManager',
        'lifecycle' => 'RoundlyConsulting\\Lifecycle\\LifecycleManager',
        'notifications' => 'RoundlyConsulting\\Notifications\\NotificationsManager',
        'opening-hours' => 'RoundlyConsulting\\OpeningHours\\OpeningHoursManager',
        'forms' => 'RoundlyConsulting\\Forms\\FormsManager',
        'teams' => 'RoundlyConsulting\\Teams\\TeamsManager',
        'webhooks' => 'RoundlyConsulting\\Webhooks\\WebhooksManager',
    ];

    /** @var array<string, true> */
    private static array $missing = [];

    public static function available(string $package): bool
    {
        if (isset(self::$missing[$package])) {
            return false;
        }

        $marker = self::MARKERS[$package] ?? null;

        return $marker !== null && class_exists($marker);
    }

    /**
     * @return list<string>
     */
    public static function installed(): array
    {
        return array_values(array_filter(array_keys(self::MARKERS), self::available(...)));
    }

    /**
     * @param  list<string>  $packages  empty = every bridge
     */
    public static function pretendMissing(array $packages = []): void
    {
        foreach ($packages === [] ? array_keys(self::MARKERS) : $packages as $package) {
            self::$missing[$package] = true;
        }
    }

    public static function reset(): void
    {
        self::$missing = [];
    }
}
