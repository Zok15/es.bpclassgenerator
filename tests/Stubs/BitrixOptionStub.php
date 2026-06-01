<?php
declare(strict_types=1);

namespace Es\Bpclassgenerator\Tests\Stubs;

final class BitrixOptionStub
{
    private static array $storage = [];

    public static function get(string $moduleId, string $name, string $default = ''): string
    {
        return (string)(self::$storage[$moduleId][$name] ?? $default);
    }

    public static function set(string $moduleId, string $name, string $value): void
    {
        self::$storage[$moduleId][$name] = $value;
    }

    public static function delete(string $moduleId, array $options = []): void
    {
        $name = (string)($options['name'] ?? '');
        if ($name !== '') {
            unset(self::$storage[$moduleId][$name]);
            return;
        }

        unset(self::$storage[$moduleId]);
    }

    public static function reset(): void
    {
        self::$storage = [];
    }
}
