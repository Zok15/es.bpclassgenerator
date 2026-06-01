<?php

namespace Es\Bpclassgenerator\Service;

use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

class TemplateService
{
    private const MODULE_ID = 'es.bpclassgenerator';
    private const CLASS_MAP_OPTION = 'CLASS_MAP';
    private const SMART_PROCESS_MODULE_ID = 'crm';
    private const SMART_PROCESS_ENTITY = 'Bitrix\Crm\Integration\BizProc\Document\Dynamic';
    private const TIMELINE_SETTING_NAME = 'SHOW_IN_TIMELINE';
    private const TIMELINE_SETTING_DISABLED = 'N';

    private CrmEntityProvider $entityProvider;

    public function __construct()
    {
        $this->entityProvider = new CrmEntityProvider();
    }

    public function getEntities(): array
    {
        return $this->entityProvider->getAll();
    }

    public function getTemplatesByEntity(int $entityTypeId): array
    {
        $entities = $this->entityProvider->getAll();
        if (!isset($entities[$entityTypeId])) {
            return [];
        }

        $resultById = [];
        $installedRowsById = [];

        if (Loader::includeModule('bizproc')) {
            $entity = $entities[$entityTypeId];
            $rows = WorkflowTemplateTable::getList([
                'select' => [
                    'ID',
                    'NAME',
                    'SORT',
                    'DOCUMENT_STATUS',
                    'DOCUMENT_TYPE',
                ],
                'filter' => [
                    '=MODULE_ID' => (string)($entity['MODULE_ID'] ?? ''),
                    '=ENTITY' => (string)($entity['ENTITY_CLASS'] ?? ''),
                    '=DOCUMENT_TYPE' => (string)($entity['TYPE'] ?? ''),
                ],
                'order' => ['ID' => 'ASC'],
            ])->fetchAll();

            foreach ($rows as $row) {
                $templateId = (int)$row['ID'];
                $installedRowsById[$templateId] = $row;
                $resultById[$templateId] = [
                    'ID' => $templateId,
                    'NAME' => (string)$row['NAME'],
                    'SORT' => (int)$row['SORT'],
                    'DOCUMENT_STATUS' => (string)$row['DOCUMENT_STATUS'],
                    'IS_ROBOT' => (string)$row['DOCUMENT_STATUS'] !== '',
                    'INSTALLED' => true,
                    'CLASS_EXISTS' => false,
                    'CLASS_NAME' => '',
                ];
            }
        }

        $map = $this->getClassMap();
        foreach ($map as $templateId => $item) {
            $templateId = (int)$templateId;
            if ((int)($item['entityTypeId'] ?? 0) !== $entityTypeId) {
                continue;
            }

            $resolvedClass = $this->resolveClassReference(
                $entityTypeId,
                $templateId,
                (string)($item['class'] ?? ''),
                (string)($item['file'] ?? '')
            );

            $className = $resolvedClass['className'];
            $classFile = $resolvedClass['classFile'];
            $classExists = ($classFile !== '' && is_file($classFile));

            $classMeta = [];
            if ($classExists && $className !== '') {
                if (!class_exists($className, false)) {
                    include_once $classFile;
                }
            }
            if ($className !== '' && class_exists($className, false)) {
                $classMeta = $this->extractClassMeta($className);
            }

            if (!isset($resultById[$templateId])) {
                $resultById[$templateId] = [
                    'ID' => $templateId,
                    'NAME' => (string)($classMeta['name'] ?? ($item['name'] ?? ('Template #' . $templateId))),
                    'SORT' => (int)($classMeta['sort'] ?? ($item['sort'] ?? 0)),
                    'DOCUMENT_STATUS' => (string)($classMeta['documentStatus'] ?? ($item['documentStatus'] ?? '')),
                    'IS_ROBOT' => array_key_exists('isRobot', $classMeta)
                        ? (bool)$classMeta['isRobot']
                        : ((string)($item['isRobot'] ?? 'N') === 'Y'),
                    'INSTALLED' => false,
                    'CLASS_EXISTS' => $classExists,
                    'CLASS_NAME' => $classExists ? $className : '',
                ];
            } else {
                $resultById[$templateId]['CLASS_EXISTS'] = $classExists;
                if (!empty($classMeta['name'])) {
                    $resultById[$templateId]['NAME'] = (string)$classMeta['name'];
                }
                if (array_key_exists('sort', $classMeta)) {
                    $resultById[$templateId]['SORT'] = (int)$classMeta['sort'];
                }
                if (array_key_exists('documentStatus', $classMeta)) {
                    $resultById[$templateId]['DOCUMENT_STATUS'] = (string)$classMeta['documentStatus'];
                }
                if (array_key_exists('isRobot', $classMeta)) {
                    $resultById[$templateId]['IS_ROBOT'] = (bool)$classMeta['isRobot'];
                }
                if ($classExists && $className !== '') {
                    $resultById[$templateId]['CLASS_NAME'] = $className;
                } elseif (!$classExists) {
                    $resultById[$templateId]['CLASS_NAME'] = '';
                }
            }
        }

        $comparisonCandidateIds = [];
        foreach ($resultById as $templateId => $templateInfo) {
            $hasInstalledTemplate = (bool)($templateInfo['INSTALLED'] ?? false);
            $hasClass = (bool)($templateInfo['CLASS_EXISTS'] ?? false) && (string)($templateInfo['CLASS_NAME'] ?? '') !== '';
            if ($hasInstalledTemplate && $hasClass) {
                $comparisonCandidateIds[] = (int)$templateId;
            }
        }
        if (!empty($comparisonCandidateIds)) {
            $installedRowsById = $this->loadInstalledTemplatesByIds($comparisonCandidateIds);
        }

        foreach ($resultById as $templateId => &$templateInfo) {
            $templateInfo['CLASS_TEMPLATE_DIFFERS'] = null;
            $templateInfo['CLASS_TEMPLATE_DIFF_ERROR'] = '';

            $hasInstalledTemplate = (bool)($templateInfo['INSTALLED'] ?? false);
            $hasClass = (bool)($templateInfo['CLASS_EXISTS'] ?? false) && (string)($templateInfo['CLASS_NAME'] ?? '') !== '';
            if (!$hasInstalledTemplate || !$hasClass) {
                continue;
            }

            $comparison = $this->evaluateClassTemplateDifference(
                $entityTypeId,
                (int)$templateId,
                $templateInfo,
                (array)($map[$templateId] ?? []),
                $installedRowsById
            );

            $templateInfo['CLASS_TEMPLATE_DIFFERS'] = $comparison['differs'];
            $templateInfo['CLASS_TEMPLATE_DIFF_ERROR'] = $comparison['error'];
        }
        unset($templateInfo);

        ksort($resultById);
        return array_values($resultById);
    }

    public function generateForAll(): array
    {
        $report = ['generated' => [], 'errors' => []];

        foreach ($this->getEntities() as $entity) {
            $res = $this->generateForEntity((int)$entity['ID']);
            $report['generated'] = array_merge($report['generated'], $res['generated']);
            $report['errors'] = array_merge($report['errors'], $res['errors']);
        }

        return $report;
    }

    public function generateForEntity(int $entityTypeId): array
    {
        $report = ['generated' => [], 'errors' => []];

        $templates = $this->getTemplatesByEntity($entityTypeId);
        if (!$templates) {
            return $report;
        }

        foreach ($templates as $template) {
            if (isset($template['INSTALLED']) && !$template['INSTALLED']) {
                continue;
            }
            $res = $this->generateByTemplateId($entityTypeId, (int)$template['ID']);
            $report['generated'] = array_merge($report['generated'], $res['generated']);
            $report['errors'] = array_merge($report['errors'], $res['errors']);
        }

        return $report;
    }

    public function installForAll(): array
    {
        $report = ['created' => [], 'updated' => [], 'errors' => []];

        $map = $this->getClassMap();
        foreach ($map as $templateId => $item) {
            $res = $this->installByTemplateId((int)$templateId);
            $report['created'] = array_merge($report['created'], $res['created']);
            $report['updated'] = array_merge($report['updated'], $res['updated']);
            $report['errors'] = array_merge($report['errors'], $res['errors']);
        }

        return $report;
    }

    public function installForEntity(int $entityTypeId): array
    {
        $report = ['created' => [], 'updated' => [], 'errors' => []];

        $map = $this->getClassMap();
        foreach ($map as $templateId => $item) {
            if ((int)($item['entityTypeId'] ?? 0) !== $entityTypeId) {
                continue;
            }

            $res = $this->installByTemplateId((int)$templateId);
            $report['created'] = array_merge($report['created'], $res['created']);
            $report['updated'] = array_merge($report['updated'], $res['updated']);
            $report['errors'] = array_merge($report['errors'], $res['errors']);
        }

        return $report;
    }

    public function getClassMap(): array
    {
        $raw = Option::get(self::MODULE_ID, self::CLASS_MAP_OPTION, '');
        if ($raw === '') {
            return [];
        }

        $jsonData = json_decode($raw, true);
        if (is_array($jsonData)) {
            return $jsonData;
        }

        $legacyData = $this->safeUnserializeArray($raw);
        return is_array($legacyData) ? $legacyData : [];
    }

    public function syncClassMapFromFilesystem(): array
    {
        $map = $this->getClassMap();
        $added = [];

        foreach ($this->getEntities() as $entity) {
            $entityTypeId = (int)($entity['ID'] ?? 0);
            $dirName = (string)($entity['DIR'] ?? '');
            if ($dirName === '') {
                continue;
            }

            $dirPath = $_SERVER['DOCUMENT_ROOT'] . '/local/modules/' . self::MODULE_ID . '/lib/Bizprocs/' . $dirName;
            if (!is_dir($dirPath)) {
                continue;
            }

            $files = glob($dirPath . '/*.php');
            if (!is_array($files)) {
                continue;
            }

            foreach ($files as $filePath) {
                $classBase = pathinfo($filePath, PATHINFO_FILENAME);
                if ($classBase === '') {
                    continue;
                }

                $className = 'Es\\Bpclassgenerator\\Bizprocs\\' . $dirName . '\\' . $classBase;
                if (!class_exists($className, false)) {
                    include_once $filePath;
                }

                if (!class_exists($className, false)) {
                    continue;
                }

                $meta = $this->extractClassMeta($className);
                $templateId = (int)($meta['id'] ?? 0);
                if ($templateId <= 0 || isset($map[$templateId])) {
                    continue;
                }

                $map[$templateId] = [
                    'templateId' => $templateId,
                    'entityTypeId' => $entityTypeId,
                    'name' => (string)($meta['name'] ?? ('Template #' . $templateId)),
                    'sort' => (int)($meta['sort'] ?? 0),
                    'documentStatus' => (string)($meta['documentStatus'] ?? ''),
                    'isRobot' => (!empty($meta['isRobot']) ? 'Y' : 'N'),
                    'class' => $className,
                    'file' => $filePath,
                    'moduleId' => (string)($entity['MODULE_ID'] ?? ''),
                ];
                $added[] = $templateId;
            }
        }

        if (!empty($added)) {
            ksort($map);
            sort($added, SORT_NUMERIC);
            $this->saveClassMap($map);
        }

        return ['added' => $added];
    }

    public function generateByTemplateId(int $entityTypeId, int $templateId): array
    {
        $report = ['generated' => [], 'errors' => []];

        if (!Loader::includeModule('bizproc')) {
            $report['errors'][] = 'Модуль bizproc недоступен.';
            return $report;
        }

        $entities = $this->getEntities();
        if (!isset($entities[$entityTypeId])) {
            $report['errors'][] = 'Не найдена сущность: ' . $entityTypeId;
            return $report;
        }

        $installedTemplates = $this->loadInstalledTemplatesByIds([$templateId]);
        $template = $installedTemplates[$templateId] ?? null;
        if (!$template) {
            $report['errors'][] = 'В БД не найден шаблон ID=' . $templateId;
            return $report;
        }

        $isRobot = (string)($template['DOCUMENT_STATUS'] ?? '') !== '';
        $className = $this->buildClassName((string)$template['NAME'], $templateId, $isRobot);
        $dirName = $entities[$entityTypeId]['DIR'];
        $templatePath = $_SERVER['DOCUMENT_ROOT'] . '/local/modules/' . self::MODULE_ID . '/code_templates/Bizprocs/' . ($isRobot ? 'Robot.php' : 'Bizproc.php');

        if (!is_file($templatePath)) {
            $report['errors'][] = 'Не найден шаблон кода: ' . $templatePath;
            return $report;
        }

        $content = (string)file_get_contents($templatePath);
        $params = $this->buildTemplatePayload($template);
        $params['DIR_NAME'] = $dirName;
        $params['CLASS_NAME'] = $className;

        foreach ($params as $key => $value) {
            $content = str_replace('##' . $key . '##', $value, $content);
        }

        $targetDir = $_SERVER['DOCUMENT_ROOT'] . '/local/modules/' . self::MODULE_ID . '/lib/Bizprocs/' . $dirName;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            $report['errors'][] = 'Не удалось создать папку: ' . $targetDir;
            return $report;
        }

        $targetFile = $targetDir . '/' . $className . '.php';
        if (file_put_contents($targetFile, $content) === false) {
            $report['errors'][] = 'Не удалось записать файл: ' . $targetFile;
            return $report;
        }

        $fqcn = 'Es\\Bpclassgenerator\\Bizprocs\\' . $dirName . '\\' . $className;

        $map = $this->getClassMap();
        $map[$templateId] = [
            'templateId' => $templateId,
            'entityTypeId' => $entityTypeId,
            'name' => (string)$template['NAME'],
            'sort' => (int)$template['SORT'],
            'documentStatus' => (string)$template['DOCUMENT_STATUS'],
            'isRobot' => $isRobot ? 'Y' : 'N',
            'class' => $fqcn,
            'file' => $targetFile,
            'moduleId' => (string)$template['MODULE_ID'],
        ];

        $this->saveClassMap($map);
        $report['generated'][] = $templateId;

        return $report;
    }

    public function installByTemplateId(int $templateId): array
    {
        $report = ['created' => [], 'updated' => [], 'errors' => []];

        if (!Loader::includeModule('bizproc')) {
            $report['errors'][] = 'Модуль bizproc недоступен.';
            return $report;
        }

        $map = $this->getClassMap();
        if (!isset($map[$templateId])) {
            $report['errors'][] = 'В карте классов нет шаблона ID=' . $templateId;
            return $report;
        }

        $item = $map[$templateId];
        $entityTypeId = (int)($item['entityTypeId'] ?? 0);
        $resolvedClass = $this->resolveClassReference(
            $entityTypeId,
            $templateId,
            (string)($item['class'] ?? ''),
            (string)($item['file'] ?? '')
        );
        $className = $resolvedClass['className'];

        if ($className === '') {
            $report['errors'][] = 'Не найден класс: ' . $className;
            return $report;
        }

        $classFile = $resolvedClass['classFile'];
        if ($classFile !== '' && is_file($classFile) && !class_exists($className)) {
            include_once $classFile;
        }

        if (!class_exists($className)) {
            $report['errors'][] = 'Не найден класс: ' . $className;
            return $report;
        }

        $classMeta = $this->extractClassMeta($className);
        $effectiveTemplateId = (int)($classMeta['id'] ?? 0);
        if ($effectiveTemplateId <= 0) {
            $effectiveTemplateId = $templateId;
        }

        $documentType = $this->entityProvider->getDocumentTypeArray($entityTypeId);
        if (!$documentType) {
            $report['errors'][] = 'Не удалось определить DOCUMENT_TYPE для сущности ' . $entityTypeId;
            return $report;
        }

        $name = (string)($classMeta['name'] ?? ($item['name'] ?? ('Template #' . $effectiveTemplateId)));
        $sort = (int)($classMeta['sort'] ?? ($item['sort'] ?? 100));
        $isRobot = array_key_exists('isRobot', $classMeta)
            ? (bool)$classMeta['isRobot']
            : ((string)($item['isRobot'] ?? 'N') === 'Y');
        $stageId = (string)($classMeta['documentStatus'] ?? ($item['documentStatus'] ?? ''));

        if ($isRobot) {
            try {
                $templateData = $className::getData($effectiveTemplateId, $documentType, $stageId, $name, $sort);
            } catch (\Throwable $e) {
                $report['errors'][] = 'Ошибка получения данных шаблона из класса ' . $className . ': ' . $e->getMessage();
                return $report;
            }
        } else {
            try {
                $templateData = $className::getData($effectiveTemplateId, $documentType, $name, $sort);
            } catch (\Throwable $e) {
                $report['errors'][] = 'Ошибка получения данных шаблона из класса ' . $className . ': ' . $e->getMessage();
                return $report;
            }
        }

        if (!is_array($templateData)) {
            $report['errors'][] = 'Некорректный результат getData() в классе ' . $className . ' (ожидается array)';
            return $report;
        }

        $existing = WorkflowTemplateTable::getById($effectiveTemplateId)->fetch();
        if ($existing) {
            $templateData['NAME'] = $name;
            $templateData['MODIFIER_USER'] = new \CBPWorkflowTemplateUser(1);

            try {
                \CBPWorkflowTemplateLoader::update($effectiveTemplateId, $templateData, false, false);
            } catch (\Throwable $e) {
                $report['errors'][] = 'Ошибка обновления шаблона ID=' . $effectiveTemplateId . ': ' . $e->getMessage();
                return $report;
            }

            $installedRowsById = $this->loadInstalledTemplatesByIds([$effectiveTemplateId]);
            $actual = $installedRowsById[$effectiveTemplateId] ?? null;
            if (!$actual) {
                $report['errors'][] = 'Шаблон не найден после обновления ID=' . $effectiveTemplateId;
                return $report;
            }

            if ($this->isTemplateEquivalentIgnoringFormatting($templateData, $actual)) {
                $report['updated'][] = $effectiveTemplateId;
            } else {
                $diffInfo = $this->buildTemplateFirstDiffInfo($templateData, $actual);
                $report['errors'][] = 'Переустановка не применила изменения для шаблона ID='
                    . $effectiveTemplateId
                    . ($diffInfo !== '' ? '; ' . $diffInfo : '');
            }
        } else {
            $addFields = $templateData;
            if (isset($addFields['DOCUMENT_TYPE']) && is_array($addFields['DOCUMENT_TYPE'])) {
                $docType = $addFields['DOCUMENT_TYPE'];
                if (count($docType) === 3) {
                    $addFields['MODULE_ID'] = (string)$docType[0];
                    $addFields['ENTITY'] = (string)$docType[1];
                    $addFields['DOCUMENT_TYPE'] = (string)$docType[2];
                }
            }
            unset($addFields['TEMPLATE_SETTINGS']);
            $addResult = WorkflowTemplateTable::add($addFields);
            if ($addResult->isSuccess()) {
                if (!empty($templateData['TEMPLATE_SETTINGS'])) {
                    try {
                        $loader = \CBPWorkflowTemplateLoader::GetLoader();
                        $loader->addTemplateSettings((int)$addResult->getId(), $templateData);
                    } catch (\Throwable $e) {
                        $report['errors'][] = 'Шаблон создан, но настройки не сохранены для ID=' . $effectiveTemplateId . ': ' . $e->getMessage();
                        return $report;
                    }
                }
                $report['created'][] = $effectiveTemplateId;
            } else {
                $report['errors'][] = implode('; ', $addResult->getErrorMessages());
            }
        }

        return $report;
    }

    private function saveClassMap(array $map): void
    {
        $encoded = json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($encoded) && $encoded !== '') {
            Option::set(self::MODULE_ID, self::CLASS_MAP_OPTION, $encoded);
            return;
        }

        // Fallback keeps compatibility if JSON encoding unexpectedly fails.
        Option::set(self::MODULE_ID, self::CLASS_MAP_OPTION, serialize($map));
    }

    private function evaluateClassTemplateDifference(
        int $entityTypeId,
        int $templateId,
        array $templateInfo,
        array $mapItem,
        array $installedRowsById
    ): array {
        $result = [
            'differs' => null,
            'error' => '',
        ];

        $prepared = $this->buildClassTemplateDataForComparison($entityTypeId, $templateId, $templateInfo, $mapItem);
        if (!empty($prepared['error'])) {
            $result['error'] = (string)$prepared['error'];
            return $result;
        }

        $effectiveTemplateId = (int)($prepared['effectiveTemplateId'] ?? $templateId);
        $installedTemplate = $installedRowsById[$effectiveTemplateId] ?? null;
        if (!is_array($installedTemplate)) {
            $installedTemplate = WorkflowTemplateTable::getById($effectiveTemplateId)->fetch() ?: null;
        }

        if (!is_array($installedTemplate)) {
            $result['error'] = 'Installed template is missing (ID=' . $effectiveTemplateId . ')';
            return $result;
        }

        $classTemplateData = (array)($prepared['templateData'] ?? []);
        $result['differs'] = !$this->isTemplateEquivalentIgnoringFormatting($classTemplateData, $installedTemplate);

        return $result;
    }

    private function buildClassTemplateDataForComparison(
        int $entityTypeId,
        int $templateId,
        array $templateInfo,
        array $mapItem
    ): array {
        $result = [
            'templateData' => null,
            'effectiveTemplateId' => $templateId,
            'error' => '',
        ];

        $mappedClassName = (string)($mapItem['class'] ?? '');
        $mappedClassFile = (string)($mapItem['file'] ?? '');
        $resolvedClass = $this->resolveClassReference(
            $entityTypeId,
            $templateId,
            $mappedClassName,
            $mappedClassFile
        );

        $className = (string)($resolvedClass['className'] ?? '');
        $classFile = (string)($resolvedClass['classFile'] ?? '');
        if ($className === '') {
            $className = (string)($templateInfo['CLASS_NAME'] ?? '');
        }

        if ($className === '') {
            $result['error'] = 'Class name is empty';
            return $result;
        }

        if ($classFile !== '' && is_file($classFile) && !class_exists($className, false)) {
            include_once $classFile;
        }

        if (!class_exists($className)) {
            $result['error'] = 'Class not found: ' . $className;
            return $result;
        }

        $classMeta = $this->extractClassMeta($className);
        $effectiveTemplateId = (int)($classMeta['id'] ?? 0);
        if ($effectiveTemplateId <= 0) {
            $effectiveTemplateId = $templateId;
        }
        $result['effectiveTemplateId'] = $effectiveTemplateId;

        $documentType = $this->entityProvider->getDocumentTypeArray($entityTypeId);
        if (empty($documentType)) {
            $result['error'] = 'Cannot resolve document type for entity ' . $entityTypeId;
            return $result;
        }

        $name = (string)($classMeta['name'] ?? ($templateInfo['NAME'] ?? ('Template #' . $effectiveTemplateId)));
        $sort = (int)($classMeta['sort'] ?? ($templateInfo['SORT'] ?? 100));
        $isRobot = array_key_exists('isRobot', $classMeta)
            ? (bool)$classMeta['isRobot']
            : (bool)($templateInfo['IS_ROBOT'] ?? false);
        $stageId = (string)($classMeta['documentStatus'] ?? ($templateInfo['DOCUMENT_STATUS'] ?? ''));

        try {
            if ($isRobot) {
                $templateData = $className::getData($effectiveTemplateId, $documentType, $stageId, $name, $sort);
            } else {
                $templateData = $className::getData($effectiveTemplateId, $documentType, $name, $sort);
            }
        } catch (\Throwable $e) {
            $result['error'] = 'Class getData error: ' . $e->getMessage();
            return $result;
        }

        if (!is_array($templateData)) {
            $result['error'] = 'Class getData must return array';
            return $result;
        }

        $result['templateData'] = $templateData;

        return $result;
    }

    private function isTemplateEquivalentIgnoringFormatting(array $expected, array $actual): bool
    {
        $normalizedExpected = $this->normalizeTemplateForComparison($expected);
        $normalizedActual = $this->normalizeTemplateForComparison($actual);

        return serialize($normalizedExpected) === serialize($normalizedActual);
    }

    private function buildTemplateFirstDiffInfo(array $expected, array $actual): string
    {
        $normalizedExpected = $this->normalizeTemplateForComparison($expected);
        $normalizedActual = $this->normalizeTemplateForComparison($actual);
        if (serialize($normalizedExpected) === serialize($normalizedActual)) {
            return '';
        }

        foreach (array_keys($normalizedExpected) as $key) {
            $left = $normalizedExpected[$key] ?? null;
            $right = $normalizedActual[$key] ?? null;
            if (serialize($left) === serialize($right)) {
                continue;
            }

            return 'diff key=' . $key
                . '; expected=' . $this->shortDebugValue($left)
                . '; actual=' . $this->shortDebugValue($right);
        }

        return 'diff keys are not directly traceable (normalized arrays differ)';
    }

    private function shortDebugValue($value): string
    {
        $encoded = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        if (!is_string($encoded)) {
            return '[unencodable]';
        }

        $encoded = str_replace(["\r", "\n"], ['\\r', '\\n'], $encoded);
        if (mb_strlen($encoded) > 250) {
            $encoded = mb_substr($encoded, 0, 250) . '...';
        }

        return $encoded;
    }

    private function normalizeTemplateForComparison(array $template): array
    {
        // Keep post-update verification on fields that are expected to be mutable
        // through CBPWorkflowTemplateLoader::update(). Service/identity fields like
        // MODULE_ID / ENTITY / IS_SYSTEM are not reliable for "update applied" checks.
        $keys = [
            'NAME',
            'SORT',
            'DOCUMENT_STATUS',
            'AUTO_EXECUTE',
            'DESCRIPTION',
            'TEMPLATE',
            'PARAMETERS',
            'VARIABLES',
            'TEMPLATE_SETTINGS',
            'CONSTANTS',
            'ACTIVE',
        ];

        $normalized = [];
        foreach ($keys as $key) {
            $normalized[$key] = $this->normalizeTemplateValueForComparison($key, $template[$key] ?? null);
        }

        return $normalized;
    }

    private function normalizeTemplateValueForComparison(string $key, $value)
    {
        if ($key === 'DOCUMENT_TYPE') {
            return $this->normalizeDocumentTypeForComparison($value);
        }

        if (in_array($key, ['TEMPLATE', 'PARAMETERS', 'VARIABLES', 'TEMPLATE_SETTINGS', 'CONSTANTS'], true)) {
            return $this->normalizeNestedValueForComparison($this->convertToArrayForComparison($value));
        }

        return $this->normalizeScalarForComparison($value);
    }

    private function normalizeDocumentTypeForComparison($value): string
    {
        if (is_array($value)) {
            $parts = array_values($value);
            if (isset($parts[2])) {
                return $this->normalizeScalarForComparison($parts[2]);
            }
            if (isset($parts[0])) {
                return $this->normalizeScalarForComparison($parts[0]);
            }
        }

        return $this->normalizeScalarForComparison($value);
    }

    private function convertToArrayForComparison($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof \Traversable) {
            return iterator_to_array($value);
        }

        if (is_string($value) && $value !== '') {
            $decoded = $this->safeUnserializeArray($value);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function normalizeNestedValueForComparison($value)
    {
        if (is_array($value)) {
            if ($this->hasOnlyNumericKeys($value)) {
                ksort($value, SORT_NUMERIC);
                $normalizedList = [];
                foreach ($value as $item) {
                    $normalizedList[] = $this->normalizeNestedValueForComparison($item);
                }

                return $normalizedList;
            }

            ksort($value, SORT_STRING);
            $normalizedMap = [];
            foreach ($value as $key => $item) {
                $normalizedMap[(string)$key] = $this->normalizeNestedValueForComparison($item);
            }

            return $normalizedMap;
        }

        return $this->normalizeScalarForComparison($value);
    }

    private function hasOnlyNumericKeys(array $value): bool
    {
        foreach (array_keys($value) as $key) {
            if (!(is_int($key) || ctype_digit((string)$key))) {
                return false;
            }
        }

        return true;
    }

    private function normalizeScalarForComparison($value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value === null) {
            return '';
        }

        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                $value = (string)$value;
            } else {
                return '';
            }
        }

        $text = str_replace(["\r\n", "\r"], "\n", trim((string)$value));
        return trim($text);
    }

    private function safeUnserializeArray(string $value): ?array
    {
        if ($value === '') {
            return null;
        }

        $error = null;
        set_error_handler(static function (int $severity, string $message) use (&$error): bool {
            $error = $message;
            return true;
        });

        try {
            $decoded = unserialize($value, ['allowed_classes' => false]);
        } finally {
            restore_error_handler();
        }

        if ($error !== null || !is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    private function loadInstalledTemplatesByIds(array $templateIds): array
    {
        $templateIds = array_values(array_unique(array_map('intval', $templateIds)));
        $templateIds = array_values(array_filter($templateIds, static fn(int $id): bool => $id > 0));
        if (empty($templateIds)) {
            return [];
        }

        $result = [];
        if (class_exists(\CBPWorkflowTemplateLoader::class)) {
            $dbResult = \CBPWorkflowTemplateLoader::getList(
                arFilter: ['ID' => $templateIds],
                arSelectFields: [
                    'ID',
                    'NAME',
                    'SORT',
                    'DOCUMENT_STATUS',
                    'DOCUMENT_TYPE',
                    'MODULE_ID',
                    'ENTITY',
                    'AUTO_EXECUTE',
                    'IS_SYSTEM',
                    'DESCRIPTION',
                    'PARAMETERS',
                    'VARIABLES',
                    'TEMPLATE_SETTINGS',
                    'CONSTANTS',
                    'ACTIVE',
                    'TEMPLATE',
                ]
            );

            while ($row = $dbResult->fetch()) {
                $rowId = (int)($row['ID'] ?? 0);
                if ($rowId <= 0) {
                    continue;
                }

                if (!isset($row['TEMPLATE_SETTINGS']) || !is_array($row['TEMPLATE_SETTINGS'])) {
                    $row['TEMPLATE_SETTINGS'] = [];
                }

                $result[$rowId] = $row;
            }
        }
        else {
            $rows = WorkflowTemplateTable::getList([
                'select' => [
                    'ID',
                    'NAME',
                    'SORT',
                    'DOCUMENT_STATUS',
                    'DOCUMENT_TYPE',
                    'MODULE_ID',
                    'ENTITY',
                    'AUTO_EXECUTE',
                    'IS_SYSTEM',
                    'DESCRIPTION',
                    'PARAMETERS',
                    'VARIABLES',
                    'CONSTANTS',
                    'ACTIVE',
                    'TEMPLATE',
                ],
                'filter' => [
                    '@ID' => $templateIds,
                ],
            ])->fetchAll();

            foreach ($rows as $row) {
                $rowId = (int)($row['ID'] ?? 0);
                if ($rowId <= 0) {
                    continue;
                }

                if (!isset($row['TEMPLATE_SETTINGS']) || !is_array($row['TEMPLATE_SETTINGS'])) {
                    $row['TEMPLATE_SETTINGS'] = [];
                }

                $result[$rowId] = $row;
            }
        }

        if (!empty($result) && class_exists(\Bitrix\Bizproc\Workflow\Template\WorkflowTemplateSettingsTable::class)) {
            $settingsRows = \Bitrix\Bizproc\Workflow\Template\WorkflowTemplateSettingsTable::getList([
                'select' => ['TEMPLATE_ID', 'NAME', 'VALUE'],
                'filter' => [
                    '@TEMPLATE_ID' => array_keys($result),
                ],
            ])->fetchAll();

            foreach ($settingsRows as $settingsRow) {
                $templateId = (int)($settingsRow['TEMPLATE_ID'] ?? 0);
                $settingName = (string)($settingsRow['NAME'] ?? '');
                if ($templateId <= 0 || $settingName === '' || !isset($result[$templateId])) {
                    continue;
                }

                if (!isset($result[$templateId]['TEMPLATE_SETTINGS']) || !is_array($result[$templateId]['TEMPLATE_SETTINGS'])) {
                    $result[$templateId]['TEMPLATE_SETTINGS'] = [];
                }

                $result[$templateId]['TEMPLATE_SETTINGS'][$settingName] = (string)($settingsRow['VALUE'] ?? '');
            }
        }

        return $result;
    }

    private function buildClassName(string $name, int $templateId, bool $isRobot): string
    {
        $name = mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
        $name = preg_replace('/[^A-Za-z0-9А-Яа-яЁё]+/u', ' ', $name);
        $name = preg_replace('/\s+/u', ' ', trim((string)$name));

        $parts = preg_split('/\s+/u', (string)$name) ?: [];
        $latin = [];
        foreach ($parts as $part) {
            $latin[] = $this->transliterate($part);
        }

        $base = implode('', $latin);
        $base = preg_replace('/[^A-Za-z0-9]/', '', $base);
        if ($base === '') {
            $base = $isRobot ? 'RobotTemplate' : 'BizprocTemplate';
        }

        return ($isRobot ? 'Robot' : 'Bizproc') . $base . $templateId;
    }

    private function transliterate(string $input): string
    {
        $table = [
            'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D', 'Е' => 'E', 'Ё' => 'E', 'Ж' => 'Zh',
            'З' => 'Z', 'И' => 'I', 'Й' => 'Y', 'К' => 'K', 'Л' => 'L', 'М' => 'M', 'Н' => 'N', 'О' => 'O',
            'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T', 'У' => 'U', 'Ф' => 'F', 'Х' => 'Kh', 'Ц' => 'Ts',
            'Ч' => 'Ch', 'Ш' => 'Sh', 'Щ' => 'Sch', 'Ъ' => '', 'Ы' => 'Y', 'Ь' => '', 'Э' => 'E', 'Ю' => 'Yu', 'Я' => 'Ya',
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e', 'ж' => 'zh',
            'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o',
            'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts',
            'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        ];

        $value = strtr($input, $table);
        return preg_replace('/[^A-Za-z0-9]/', '', $value);
    }

    private function buildTemplatePayload(array $template): array
    {
        $template = $this->applySmartProcessTemplateSettings($template);
        $templateArray = (array)($template['TEMPLATE'] ?? []);
        $templateDump = var_export($templateArray, true);
        $templateDump = preg_replace('/(\r\n|\n|\r) *\d+ => *(\r\n|\n|\r)/', PHP_EOL . '        ', (string)$templateDump);
        $templateDump = preg_replace('/=> *(\r\n|\n|\r) *array/', '=> array', (string)$templateDump);

        return [
            'TEMPLATE_ID' => (int)($template['ID'] ?? 0),
            'NAME' => addslashes((string)($template['NAME'] ?? '')),
            'SORT' => (int)($template['SORT'] ?? 0),
            'DIR_NAME' => 'Stub',
            'CLASS_NAME' => 'Stub',
            'MODULE_ID' => (string)($template['MODULE_ID'] ?? 'crm'),
            'ENTITY' => (string)($template['ENTITY'] ?? ''),
            'DOCUMENT_STATUS' => addslashes((string)($template['DOCUMENT_STATUS'] ?? '')),
            'AUTO_EXECUTE' => addslashes((string)($template['AUTO_EXECUTE'] ?? '')),
            'IS_SYSTEM' => addslashes((string)($template['IS_SYSTEM'] ?? 'Y')),
            'DESCRIPTION' => addslashes((string)($template['DESCRIPTION'] ?? '')),
            'PARAMETERS' => preg_replace('/=> *(\r\n|\n|\r) *array/', '=> array', var_export((array)($template['PARAMETERS'] ?? []), true)),
            'VARIABLES' => preg_replace('/=> *(\r\n|\n|\r) *array/', '=> array', var_export((array)($template['VARIABLES'] ?? []), true)),
            'TEMPLATE_SETTINGS' => preg_replace('/=> *(\r\n|\n|\r) *array/', '=> array', var_export((array)($template['TEMPLATE_SETTINGS'] ?? []), true)),
            'CONSTANTS' => preg_replace('/=> *(\r\n|\n|\r) *array/', '=> array', var_export((array)($template['CONSTANTS'] ?? []), true)),
            'ACTIVE' => addslashes((string)($template['ACTIVE'] ?? 'Y')),
            'TEMPLATE' => $templateDump,
        ];
    }

    private function applySmartProcessTemplateSettings(array $template): array
    {
        if (!$this->isSmartProcessTemplate($template)) {
            return $template;
        }

        $settings = isset($template['TEMPLATE_SETTINGS']) && is_array($template['TEMPLATE_SETTINGS'])
            ? $template['TEMPLATE_SETTINGS']
            : [];

        $settings[self::TIMELINE_SETTING_NAME] = self::TIMELINE_SETTING_DISABLED;
        $template['TEMPLATE_SETTINGS'] = $settings;

        return $template;
    }

    private function isSmartProcessTemplate(array $template): bool
    {
        $moduleId = trim((string)($template['MODULE_ID'] ?? ''));
        $entity = trim((string)($template['ENTITY'] ?? ''));

        return $moduleId === self::SMART_PROCESS_MODULE_ID
            && $entity === self::SMART_PROCESS_ENTITY;
    }

    private function extractClassMeta(string $className): array
    {
        if (method_exists($className, 'getMeta')) {
            $meta = $className::getMeta();
            if (is_array($meta)) {
                return $meta;
            }
        }

        $meta = [];
        if (defined($className . '::TEMPLATE_ID')) {
            $meta['id'] = constant($className . '::TEMPLATE_ID');
        }
        if (defined($className . '::TEMPLATE_NAME')) {
            $meta['name'] = constant($className . '::TEMPLATE_NAME');
        }
        if (defined($className . '::TEMPLATE_SORT')) {
            $meta['sort'] = constant($className . '::TEMPLATE_SORT');
        }
        if (defined($className . '::TEMPLATE_DOCUMENT_STATUS')) {
            $meta['documentStatus'] = constant($className . '::TEMPLATE_DOCUMENT_STATUS');
        }
        if (defined($className . '::TEMPLATE_IS_ROBOT')) {
            $meta['isRobot'] = constant($className . '::TEMPLATE_IS_ROBOT');
        }

        return $meta;
    }

    private function resolveClassReference(
        int $entityTypeId,
        int $templateId,
        string $mappedClassName,
        string $mappedClassFile
    ): array {
        $className = $mappedClassName;
        $classFile = $mappedClassFile;

        if ($classFile !== '' && is_file($classFile) && $className !== '' && !class_exists($className, false)) {
            include_once $classFile;
        }

        $meta = [];
        if ($className !== '' && class_exists($className, false)) {
            $meta = $this->extractClassMeta($className);
        }

        $isMappedClassSuitable = (
            $classFile !== '' &&
            is_file($classFile) &&
            (int)($meta['id'] ?? 0) === $templateId &&
            !empty($meta['name'])
        );

        if ($isMappedClassSuitable) {
            return [
                'className' => $className,
                'classFile' => $classFile,
            ];
        }

        $found = $this->findClassByTemplateId($entityTypeId, $templateId);
        if ($found !== null) {
            return $found;
        }

        return [
            'className' => $className,
            'classFile' => $classFile,
        ];
    }

    private function findClassByTemplateId(int $entityTypeId, int $templateId): ?array
    {
        if ($entityTypeId <= 0 || $templateId <= 0) {
            return null;
        }

        $entities = $this->entityProvider->getAll();
        if (!isset($entities[$entityTypeId]['DIR'])) {
            return null;
        }

        $dirName = (string)$entities[$entityTypeId]['DIR'];
        $dirPath = $_SERVER['DOCUMENT_ROOT'] . '/local/modules/' . self::MODULE_ID . '/lib/Bizprocs/' . $dirName;
        if (!is_dir($dirPath)) {
            return null;
        }

        $files = glob($dirPath . '/*.php');
        if (!is_array($files)) {
            return null;
        }

        $pattern = '/const\s+TEMPLATE_ID\s*=\s*' . preg_quote((string)$templateId, '/') . '\s*;/';
        foreach ($files as $filePath) {
            $contents = @file_get_contents($filePath);
            if (!is_string($contents) || !preg_match($pattern, $contents)) {
                continue;
            }

            $classBase = pathinfo($filePath, PATHINFO_FILENAME);
            $className = 'Es\\Bpclassgenerator\\Bizprocs\\' . $dirName . '\\' . $classBase;

            if (!class_exists($className, false)) {
                include_once $filePath;
            }

            if (!class_exists($className, false)) {
                continue;
            }

            $meta = $this->extractClassMeta($className);
            if ((int)($meta['id'] ?? 0) !== $templateId) {
                continue;
            }

            return [
                'className' => $className,
                'classFile' => $filePath,
            ];
        }

        return null;
    }
}
