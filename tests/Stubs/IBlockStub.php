<?php
declare(strict_types=1);

namespace Es\Bpclassgenerator\Tests\Stubs;

final class IBlockStub
{
    private static array $rows = [];

    public static function setRows(array $rows): void
    {
        self::$rows = array_values($rows);
    }

    public static function reset(): void
    {
        self::$rows = [];
    }

    public static function GetList(array $order = [], array $filter = []): IBlockListResultStub
    {
        $rows = array_values(array_filter(self::$rows, static function (array $row) use ($filter): bool {
            foreach ($filter as $key => $expected) {
                if ((string)($row[$key] ?? '') !== (string)$expected) {
                    return false;
                }
            }

            return true;
        }));

        usort($rows, static function (array $left, array $right): int {
            return (int)($left['ID'] ?? 0) <=> (int)($right['ID'] ?? 0);
        });

        return new IBlockListResultStub($rows);
    }
}

final class IBlockListResultStub
{
    private int $position = 0;

    public function __construct(private array $rows)
    {
    }

    public function Fetch(): ?array
    {
        if (!isset($this->rows[$this->position])) {
            return null;
        }

        return $this->rows[$this->position++];
    }
}
