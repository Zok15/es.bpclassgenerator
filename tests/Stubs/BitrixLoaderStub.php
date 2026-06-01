<?php
declare(strict_types=1);

namespace Es\Bpclassgenerator\Tests\Stubs;

final class BitrixLoaderStub
{
    private static array $moduleState = [];

    public static function includeModule(string $moduleId): bool
    {
        return (bool)(self::$moduleState[$moduleId] ?? false);
    }

    public static function setModuleState(string $moduleId, bool $isEnabled): void
    {
        self::$moduleState[$moduleId] = $isEnabled;
    }

    public static function reset(): void
    {
        self::$moduleState = [];
    }
}
