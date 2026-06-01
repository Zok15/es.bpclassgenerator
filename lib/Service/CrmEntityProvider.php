<?php

namespace Es\Bpclassgenerator\Service;

use Bitrix\Crm\Integration\BizProc\Document\Dynamic;
use Bitrix\Crm\Model\Dynamic\TypeTable;
use Bitrix\Main\Loader;

class CrmEntityProvider
{
    public function getAll(): array
    {
        $entities = [];

        if (Loader::includeModule('crm')) {
            $entities += $this->getCrmEntities();
        }

        if (Loader::includeModule('iblock')) {
            $entities += $this->getIblockEntities();
        }

        return $entities;
    }

    public function getDocumentTypeArray(int $entityTypeId): array
    {
        $entities = $this->getAll();
        if (!isset($entities[$entityTypeId])) {
            return [];
        }

        $entity = $entities[$entityTypeId];

        return [
            (string)$entity['MODULE_ID'],
            (string)$entity['ENTITY_CLASS'],
            (string)$entity['TYPE'],
        ];
    }

    private function getCrmEntities(): array
    {
        $entities = [
            1 => [
                'ID' => 1,
                'TITLE' => 'Лид',
                'TYPE' => 'LEAD',
                'MODULE_ID' => 'crm',
                'ENTITY_CLASS' => \CCrmDocumentLead::class,
                'DIR' => 'Lead',
            ],
            2 => [
                'ID' => 2,
                'TITLE' => 'Сделка',
                'TYPE' => 'DEAL',
                'MODULE_ID' => 'crm',
                'ENTITY_CLASS' => \CCrmDocumentDeal::class,
                'DIR' => 'Deal',
            ],
            3 => [
                'ID' => 3,
                'TITLE' => 'Контакт',
                'TYPE' => 'CONTACT',
                'MODULE_ID' => 'crm',
                'ENTITY_CLASS' => \CCrmDocumentContact::class,
                'DIR' => 'Contact',
            ],
            4 => [
                'ID' => 4,
                'TITLE' => 'Компания',
                'TYPE' => 'COMPANY',
                'MODULE_ID' => 'crm',
                'ENTITY_CLASS' => \CCrmDocumentCompany::class,
                'DIR' => 'Company',
            ],
        ];

        if (!class_exists(TypeTable::class)) {
            return $entities;
        }

        $rows = TypeTable::getList([
            'select' => ['ENTITY_TYPE_ID', 'TITLE'],
            'order' => ['ENTITY_TYPE_ID' => 'ASC'],
        ])->fetchAll();

        foreach ($rows as $row) {
            $entityTypeId = (int)$row['ENTITY_TYPE_ID'];
            $entities[$entityTypeId] = [
                'ID' => $entityTypeId,
                'TITLE' => sprintf('%s (%d)', (string)$row['TITLE'], $entityTypeId),
                'TYPE' => 'DYNAMIC_' . $entityTypeId,
                'MODULE_ID' => 'crm',
                'ENTITY_CLASS' => Dynamic::class,
                'DIR' => 'Dynamic' . $entityTypeId,
            ];
        }

        return $entities;
    }

    private function getIblockEntities(): array
    {
        if (!class_exists(\CIBlock::class)) {
            return [];
        }

        $result = [];
        $rows = \CIBlock::GetList(
            ['ID' => 'ASC'],
            ['ACTIVE' => 'Y', 'BIZPROC' => 'Y']
        );

        while ($row = $rows->Fetch()) {
            $iblockId = (int)($row['ID'] ?? 0);
            if ($iblockId <= 0) {
                continue;
            }

            $entityId = $this->buildIblockEntityId($iblockId);
            $result[$entityId] = [
                'ID' => $entityId,
                'TITLE' => sprintf('%s (%d)', (string)($row['NAME'] ?? ''), $iblockId),
                'TYPE' => 'iblock_' . $iblockId,
                'MODULE_ID' => 'lists',
                'ENTITY_CLASS' => \BizprocDocument::class,
                'DIR' => 'Iblock' . $iblockId,
                'SOURCE_ID' => $iblockId,
            ];
        }

        return $result;
    }

    private function buildIblockEntityId(int $iblockId): int
    {
        return $iblockId > 0 ? -$iblockId : 0;
    }
}
