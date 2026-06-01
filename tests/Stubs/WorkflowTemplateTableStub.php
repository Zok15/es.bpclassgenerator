<?php
declare(strict_types=1);

namespace Es\Bpclassgenerator\Tests\Stubs;

final class WorkflowTemplateTableStub
{
    private static array $rowsById = [];

    public static function setRows(array $rows): void
    {
        self::$rowsById = [];
        foreach ($rows as $row) {
            $id = (int)($row['ID'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            self::$rowsById[$id] = $row;
        }
    }

    public static function reset(): void
    {
        self::$rowsById = [];
    }

    public static function getList(array $params): WorkflowTemplateTableListResultStub
    {
        $ids = array_values(array_filter(
            array_map('intval', (array)($params['filter']['@ID'] ?? [])),
            static fn(int $id): bool => $id > 0
        ));

        $rows = [];
        if (empty($ids)) {
            $rows = array_values(self::$rowsById);
        } else {
            foreach ($ids as $id) {
                if (isset(self::$rowsById[$id])) {
                    $rows[] = self::$rowsById[$id];
                }
            }
        }

        return new WorkflowTemplateTableListResultStub($rows);
    }

    public static function getById(int $id): WorkflowTemplateTableRowResultStub
    {
        return new WorkflowTemplateTableRowResultStub(self::$rowsById[$id] ?? null);
    }
}

final class WorkflowTemplateTableListResultStub
{
    public function __construct(private array $rows)
    {
    }

    public function fetchAll(): array
    {
        return $this->rows;
    }
}

final class WorkflowTemplateTableRowResultStub
{
    public function __construct(private ?array $row)
    {
    }

    public function fetch(): ?array
    {
        return $this->row;
    }
}
