<?php

use Bitrix\Main\Application;
use Bitrix\Main\HttpApplication;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

global $APPLICATION;

$request = HttpApplication::getInstance()->getContext()->getRequest();
$session = Application::getInstance()->getSession();
$moduleId = htmlspecialchars((string)($request['mid'] ?: $request['id']));
$flashReportKey = 'es_bpclassgen_report';
$flashStatusMapKey = 'es_bpclassgen_status_map';

if (!Loader::includeModule($moduleId)) {
    return;
}

$service = new \Es\Bpclassgenerator\Service\TemplateService();
$service->syncClassMapFromFilesystem();
$allEntities = $service->getEntities();
$entities = [];
foreach ($allEntities as $entityId => $entity) {
    $templates = $service->getTemplatesByEntity((int)$entityId);
    if (!empty($templates)) {
        $entities[(int)$entityId] = $entity;
    }
}
if (empty($entities)) {
    $entities = $allEntities;
}

$selectedEntityIdRaw = $request->getPost('ENTITY_TYPE_ID');
if ($selectedEntityIdRaw === null || $selectedEntityIdRaw === '') {
    $selectedEntityIdRaw = $request->getQuery('ENTITY_TYPE_ID');
}

$selectedEntityId = ($selectedEntityIdRaw === null || $selectedEntityIdRaw === '')
    ? (int)(array_key_first($entities) ?? 0)
    : (int)$selectedEntityIdRaw;

$entityGroups = [
    'crm' => [
        'TITLE' => Loc::getMessage('ES_BPCLASSGEN_OPTIONS_GROUP_CRM'),
        'ENTITIES' => [],
    ],
    'iblock' => [
        'TITLE' => Loc::getMessage('ES_BPCLASSGEN_OPTIONS_GROUP_IBLOCK'),
        'ENTITIES' => [],
    ],
];

foreach ($entities as $entity) {
    $groupKey = ((string)($entity['MODULE_ID'] ?? '') === 'lists') ? 'iblock' : 'crm';
    $entityGroups[$groupKey]['ENTITIES'][] = $entity;
}

$entityHasDiffMap = [];
$groupHasDiffMap = [
    'crm' => false,
    'iblock' => false,
];
foreach ($entities as $entityId => $entity) {
    $entityId = (int)$entityId;
    $templates = $service->getTemplatesByEntity($entityId);
    $hasDiff = false;
    foreach ($templates as $template) {
        if (($template['CLASS_TEMPLATE_DIFFERS'] ?? null) === true) {
            $hasDiff = true;
            break;
        }
    }

    $entityHasDiffMap[$entityId] = $hasDiff;
    $groupKey = ((string)($entity['MODULE_ID'] ?? '') === 'lists') ? 'iblock' : 'crm';
    if ($hasDiff) {
        $groupHasDiffMap[$groupKey] = true;
    }
}

$selectedGroupKey = 'crm';
if (isset($entities[$selectedEntityId])) {
    $selectedGroupKey = ((string)($entities[$selectedEntityId]['MODULE_ID'] ?? '') === 'lists') ? 'iblock' : 'crm';
} else {
    foreach ($entityGroups as $groupKey => $group) {
        if (!empty($group['ENTITIES'])) {
            $selectedGroupKey = $groupKey;
            break;
        }
    }
}

$createEmptyReport = static function (): array {
    return ['generated' => [], 'created' => [], 'updated' => [], 'errors' => []];
};

$mergeReport = static function (array &$target, array $source): void {
    foreach (['generated', 'created', 'updated', 'errors'] as $key) {
        $target[$key] = array_merge((array)($target[$key] ?? []), (array)($source[$key] ?? []));
    }
};

$decodeLegacyQueryArray = static function (string $encoded): ?array {
    if ($encoded === '') {
        return null;
    }

    $decoded = base64_decode($encoded, true);
    if (!is_string($decoded) || $decoded === '') {
        return null;
    }

    $error = null;
    set_error_handler(static function (int $severity, string $message) use (&$error): bool {
        $error = $message;
        return true;
    });

    try {
        $value = unserialize($decoded, ['allowed_classes' => false]);
    } finally {
        restore_error_handler();
    }

    if ($error !== null || !is_array($value)) {
        return null;
    }

    return $value;
};

$report = [];
$statusMap = [];
if ($request->isPost() && check_bitrix_sessid()) {
    $needRedirect = false;
    $selectedTemplateIds = $request->getPost('TEMPLATE_IDS');
    if (!is_array($selectedTemplateIds)) {
        $selectedTemplateIds = [];
    }
    $selectedTemplateIds = array_values(array_unique(array_map('intval', $selectedTemplateIds)));
    $selectedTemplateIds = array_filter($selectedTemplateIds, static fn(int $id) => $id > 0);
    $actionCode = (string)$request->getPost('ACTION_CODE');

    if ($actionCode === 'generate_selected') {
        $report = $createEmptyReport();
        if (empty($selectedTemplateIds)) {
            $report['errors'][] = Loc::getMessage('ES_BPCLASSGEN_OPTIONS_SELECT_TEMPLATES_ERROR');
        } else {
            foreach ($selectedTemplateIds as $templateId) {
                $res = $service->generateByTemplateId($selectedEntityId, $templateId);
                $mergeReport($report, $res);
                if (!empty($res['errors'])) {
                    $statusMap[$templateId] = Loc::getMessage('ES_BPCLASSGEN_OPTIONS_STATUS_ERROR') . ': ' . implode('; ', $res['errors']);
                } elseif (!empty($res['generated'])) {
                    $statusMap[$templateId] = Loc::getMessage('ES_BPCLASSGEN_OPTIONS_STATUS_GENERATED');
                } else {
                    $statusMap[$templateId] = Loc::getMessage('ES_BPCLASSGEN_OPTIONS_STATUS_NO_RESULT');
                }
            }
        }
        $needRedirect = true;
    } elseif ($actionCode === 'install_selected') {
        $report = $createEmptyReport();
        if (empty($selectedTemplateIds)) {
            $report['errors'][] = Loc::getMessage('ES_BPCLASSGEN_OPTIONS_SELECT_TEMPLATES_ERROR');
        } else {
            foreach ($selectedTemplateIds as $templateId) {
                $res = $service->installByTemplateId($templateId);
                $mergeReport($report, $res);
                if (!empty($res['errors'])) {
                    $statusMap[$templateId] = Loc::getMessage('ES_BPCLASSGEN_OPTIONS_STATUS_ERROR') . ': ' . implode('; ', $res['errors']);
                } elseif (!empty($res['created'])) {
                    $statusMap[$templateId] = Loc::getMessage('ES_BPCLASSGEN_OPTIONS_STATUS_CREATED');
                } elseif (!empty($res['updated'])) {
                    $statusMap[$templateId] = Loc::getMessage('ES_BPCLASSGEN_OPTIONS_STATUS_UPDATED');
                } else {
                    $statusMap[$templateId] = Loc::getMessage('ES_BPCLASSGEN_OPTIONS_STATUS_NO_RESULT');
                }
            }
        }
        $needRedirect = true;
    }

    if ($needRedirect) {
        $session->set($flashReportKey, $report);
        $session->set($flashStatusMapKey, $statusMap);

        LocalRedirect(
            $APPLICATION->GetCurPage() .
            '?mid=' . urlencode($moduleId) .
            '&lang=' . LANGUAGE_ID .
            '&ENTITY_TYPE_ID=' . $selectedEntityId .
            '&flash=Y'
        );
    }
}

if ((string)$request->getQuery('flash') === 'Y') {
    $sessionReport = $session->get($flashReportKey);
    if (is_array($sessionReport)) {
        $report = $sessionReport;
    }

    $sessionStatusMap = $session->get($flashStatusMapKey);
    if (is_array($sessionStatusMap)) {
        $statusMap = $sessionStatusMap;
    }

    $session->remove($flashReportKey);
    $session->remove($flashStatusMapKey);
}

if (empty($report)) {
    $rawReport = (string)$request->getQuery('report');
    $decodedReport = $decodeLegacyQueryArray($rawReport);
    if (is_array($decodedReport)) {
        $report = $decodedReport;
    }
}

if (empty($statusMap)) {
    $rawStatusMap = (string)$request->getQuery('status_map');
    $decodedStatusMap = $decodeLegacyQueryArray($rawStatusMap);
    if (is_array($decodedStatusMap)) {
        $statusMap = $decodedStatusMap;
    }
}

$entityTemplates = isset($entities[$selectedEntityId]) ? $service->getTemplatesByEntity($selectedEntityId) : [];
$gridId = 'es_bpclassgen_grid_' . $selectedEntityId;

$gridColumns = [
    ['id' => 'ID', 'name' => 'ID', 'sort' => 'ID', 'default' => true],
    ['id' => 'NAME', 'name' => Loc::getMessage('ES_BPCLASSGEN_OPTIONS_COL_NAME'), 'default' => true],
    ['id' => 'CLASS_NAME', 'name' => Loc::getMessage('ES_BPCLASSGEN_OPTIONS_COL_CLASS'), 'default' => true],
    ['id' => 'CODE_DIFFERS', 'name' => Loc::getMessage('ES_BPCLASSGEN_OPTIONS_COL_CODE_DIFFERS'), 'default' => true],
    ['id' => 'TYPE', 'name' => Loc::getMessage('ES_BPCLASSGEN_OPTIONS_COL_TYPE'), 'default' => true],
    ['id' => 'SORT', 'name' => 'SORT', 'sort' => 'SORT', 'default' => true],
    ['id' => 'STAGE', 'name' => 'STAGE', 'default' => true],
    ['id' => 'STATUS', 'name' => Loc::getMessage('ES_BPCLASSGEN_OPTIONS_COL_STATUS'), 'default' => true],
];

$gridRows = [];
foreach ($entityTemplates as $template) {
    $templateId = (int)$template['ID'];
    $fullClassName = (string)($template['CLASS_NAME'] ?? '');
    $classNameOnly = $fullClassName;
    if ($fullClassName !== '' && strpos($fullClassName, '\\') !== false) {
        $parts = explode('\\', $fullClassName);
        $classNameOnly = (string)end($parts);
    }
    $hasInstalledTemplate = !isset($template['INSTALLED']) || (bool)$template['INSTALLED'];
    $hasClass = !isset($template['CLASS_EXISTS']) || (bool)$template['CLASS_EXISTS'];
    $codeDiffersText = Loc::getMessage('ES_BPCLASSGEN_OPTIONS_CODE_DIFFERS_NA');
    if ($hasInstalledTemplate && $hasClass) {
        $compareError = trim((string)($template['CLASS_TEMPLATE_DIFF_ERROR'] ?? ''));
        if ($compareError !== '') {
            $codeDiffersText = Loc::getMessage('ES_BPCLASSGEN_OPTIONS_CODE_DIFFERS_ERROR');
        } else {
            $differs = $template['CLASS_TEMPLATE_DIFFERS'] ?? null;
            if ($differs === true) {
                $codeDiffersText = Loc::getMessage('ES_BPCLASSGEN_OPTIONS_CODE_DIFFERS_YES');
            } elseif ($differs === false) {
                $codeDiffersText = Loc::getMessage('ES_BPCLASSGEN_OPTIONS_CODE_DIFFERS_NO');
            }
        }
    }

    $codeDiffersHtml = htmlspecialcharsbx((string)$codeDiffersText);
    if (($template['CLASS_TEMPLATE_DIFFERS'] ?? null) === true) {
        $codeDiffersHtml = '<span class="es-diff-yes">' . $codeDiffersHtml . '</span>';
    }

    $rowActions = [];
    if (!$hasInstalledTemplate && $hasClass) {
        // Только класс, шаблона в БД нет -> только установка
        $rowActions[] = [
            'text' => Loc::getMessage('ES_BPCLASSGEN_OPTIONS_INSTALL_SINGLE'),
            'onclick' => "esRunSingleAction('install_selected', {$templateId});",
        ];
    } elseif ($hasInstalledTemplate && !$hasClass) {
        // Есть шаблон, но нет класса -> только генерация
        $rowActions[] = [
            'text' => Loc::getMessage('ES_BPCLASSGEN_OPTIONS_GENERATE_SINGLE'),
            'onclick' => "esRunSingleAction('generate_selected', {$templateId});",
        ];
    } else {
        // Есть и шаблон, и класс -> обе опции
        $rowActions[] = [
            'text' => Loc::getMessage('ES_BPCLASSGEN_OPTIONS_GENERATE_SINGLE'),
            'onclick' => "esRunSingleAction('generate_selected', {$templateId});",
        ];
        $rowActions[] = [
            'text' => Loc::getMessage('ES_BPCLASSGEN_OPTIONS_INSTALL_SINGLE'),
            'onclick' => "esRunSingleAction('install_selected', {$templateId});",
        ];
    }

    $gridRows[] = [
        'id' => (string)$templateId,
        'columns' => [
            'ID' => $templateId,
            'NAME' => htmlspecialcharsbx((string)$template['NAME']),
            'CLASS_NAME' => htmlspecialcharsbx($classNameOnly),
            'CODE_DIFFERS' => $codeDiffersHtml,
            'TYPE' => $template['IS_ROBOT'] ? 'Robot' : 'Bizproc',
            'SORT' => (int)$template['SORT'],
            'STAGE' => htmlspecialcharsbx((string)$template['DOCUMENT_STATUS']),
            'STATUS' => htmlspecialcharsbx((string)($statusMap[$templateId] ?? '')),
        ],
        'actions' => $rowActions,
    ];
}

$actionPanel = [
    'GROUPS' => [
        'es_bpclassgen' => [
            'ITEMS' => [
                [
                    'ID' => 'es_generate_selected',
                    'TYPE' => 'BUTTON',
                    'TEXT' => Loc::getMessage('ES_BPCLASSGEN_OPTIONS_GENERATE_SELECTED'),
                    'CLASS' => 'icon edit',
                    'ONCHANGE' => [
                        [
                            'ACTION' => 'CALLBACK',
                            'DATA' => [
                                ['JS' => "esRunGridAction('generate_selected');"],
                            ],
                        ],
                    ],
                ],
                [
                    'ID' => 'es_install_selected',
                    'TYPE' => 'BUTTON',
                    'TEXT' => Loc::getMessage('ES_BPCLASSGEN_OPTIONS_INSTALL_SELECTED'),
                    'CLASS' => 'icon edit',
                    'ONCHANGE' => [
                        [
                            'ACTION' => 'CALLBACK',
                            'DATA' => [
                                ['JS' => "esRunGridAction('install_selected');"],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
];

?>
<style>
    .es-primary-tabs {
        margin: 12px 0;
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        border-bottom: 1px solid #d5d9dd;
        padding-bottom: 6px;
    }

    .es-secondary-tabs {
        margin: 8px 0 16px;
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }

    .es-tab-link {
        display: inline-flex;
        padding: 7px 10px;
        border: 1px solid #cfd6dc;
        border-radius: 4px;
        background: #f6f8f9;
        color: #1f2d3d;
        text-decoration: none;
        font-size: 13px;
        line-height: 1.2;
    }

    .es-tab-link.active {
        background: #e7f1f8;
        border-color: #8bb9d9;
        font-weight: 600;
    }

    .es-primary-tab {
        padding: 9px 14px;
        font-size: 14px;
    }

    .es-tab-link.has-diff {
        color: #1565c0;
        border-color: #90caf9;
        background: #f3f9ff;
    }

    .es-diff-yes {
        color: #1565c0;
        font-weight: 600;
    }

</style>

<div class="es-primary-tabs">
    <?php foreach ($entityGroups as $groupKey => $group): ?>
        <?php
        if (empty($group['ENTITIES'])) {
            continue;
        }

        $groupEntityId = $selectedGroupKey === $groupKey
            ? $selectedEntityId
            : (int)$group['ENTITIES'][0]['ID'];

        $groupUrl = $APPLICATION->GetCurPage()
            . '?mid=' . urlencode($moduleId)
            . '&lang=' . LANGUAGE_ID
            . '&ENTITY_TYPE_ID=' . $groupEntityId;
        ?>
        <a href="<?= htmlspecialcharsbx($groupUrl); ?>"
           class="es-tab-link es-primary-tab<?= $groupKey === $selectedGroupKey ? ' active' : ''; ?><?= !empty($groupHasDiffMap[$groupKey]) ? ' has-diff' : ''; ?>">
            <?= htmlspecialcharsbx((string)$group['TITLE']); ?>
        </a>
    <?php endforeach; ?>
</div>

<?php if (!empty($entityGroups[$selectedGroupKey]['ENTITIES'])): ?>
    <div class="es-secondary-tabs">
        <?php foreach ($entityGroups[$selectedGroupKey]['ENTITIES'] as $entity): ?>
            <?php
            $entityId = (int)$entity['ID'];
            $url = $APPLICATION->GetCurPage()
                . '?mid=' . urlencode($moduleId)
                . '&lang=' . LANGUAGE_ID
                . '&ENTITY_TYPE_ID=' . $entityId;
            ?>
            <a href="<?= htmlspecialcharsbx($url); ?>"
               class="es-tab-link<?= $entityId === $selectedEntityId ? ' active' : ''; ?><?= !empty($entityHasDiffMap[$entityId]) ? ' has-diff' : ''; ?>">
                <?= htmlspecialcharsbx((string)$entity['TITLE']); ?>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<form action="<?= $APPLICATION->GetCurPage(); ?>?mid=<?= htmlspecialcharsbx($moduleId); ?>&lang=<?= LANGUAGE_ID; ?>" method="post" id="es-bpclassgen-action-form">
    <?= bitrix_sessid_post(); ?>
    <input type="hidden" name="ENTITY_TYPE_ID" value="<?= $selectedEntityId; ?>">
    <input type="hidden" name="ACTION_CODE" id="es-action-code" value="">
    <div id="es-template-ids-container"></div>
</form>

<h3><?= Loc::getMessage('ES_BPCLASSGEN_OPTIONS_TITLE'); ?></h3>
<?php if (isset($entities[$selectedEntityId])): ?>
    <p><b><?= Loc::getMessage('ES_BPCLASSGEN_OPTIONS_ENTITY'); ?>:</b> <?= htmlspecialcharsbx((string)$entities[$selectedEntityId]['TITLE']); ?></p>
<?php endif; ?>

<p><?= Loc::getMessage('ES_BPCLASSGEN_OPTIONS_TEMPLATES_FOUND'); ?>: <b><?= count($entityTemplates); ?></b></p>

<?php
$APPLICATION->IncludeComponent('bitrix:main.ui.grid', '', [
    'GRID_ID' => $gridId,
    'COLUMNS' => $gridColumns,
    'ROWS' => $gridRows,
    'SHOW_ROW_CHECKBOXES' => true,
    'SHOW_GRID_SETTINGS_MENU' => true,
    'SHOW_NAVIGATION_PANEL' => false,
    'SHOW_PAGINATION' => false,
    'SHOW_SELECTED_COUNTER' => true,
    'SHOW_TOTAL_COUNTER' => true,
    'SHOW_PAGESIZE' => false,
    'SHOW_ACTION_PANEL' => true,
    'ACTION_PANEL' => $actionPanel,
    'ALLOW_COLUMNS_SORT' => true,
    'ALLOW_COLUMNS_RESIZE' => true,
    'ALLOW_HORIZONTAL_SCROLL' => true,
    'ALLOW_SORT' => false,
    'ALLOW_PIN_HEADER' => true,
]);
?>

<h3 style="margin-top: 16px;"><?= Loc::getMessage('ES_BPCLASSGEN_OPTIONS_REPORT'); ?></h3>
<?php if (!empty($report)): ?>
    <div style="padding: 8px 12px; background: #f8fff8; border: 1px solid #b5ddb5; margin-bottom: 12px;">
        <?php if (!empty($report['generated'])): ?>
            <div><?= Loc::getMessage('ES_BPCLASSGEN_OPTIONS_REPORT_GENERATED_IDS'); ?>: <?= implode(', ', array_map('intval', $report['generated'])); ?></div>
        <?php endif; ?>
        <?php if (!empty($report['created'])): ?>
            <div><?= Loc::getMessage('ES_BPCLASSGEN_OPTIONS_REPORT_CREATED_IDS'); ?>: <?= implode(', ', array_map('intval', $report['created'])); ?></div>
        <?php endif; ?>
        <?php if (!empty($report['updated'])): ?>
            <div><?= Loc::getMessage('ES_BPCLASSGEN_OPTIONS_REPORT_UPDATED_IDS'); ?>: <?= implode(', ', array_map('intval', $report['updated'])); ?></div>
        <?php endif; ?>
        <?php if (!empty($report['errors'])): ?>
            <div style="color: #c10000; margin-top: 8px;">
                <?= implode('<br>', array_map('htmlspecialcharsbx', $report['errors'])); ?>
            </div>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div style="color: #666;"><?= Loc::getMessage('ES_BPCLASSGEN_OPTIONS_REPORT_EMPTY'); ?></div>
<?php endif; ?>

<script>
    (function() {
        if (typeof window.history !== 'undefined' && typeof window.history.replaceState === 'function') {
            try {
                var currentUrl = new URL(window.location.href);
                var hasReportParams = currentUrl.searchParams.has('report') || currentUrl.searchParams.has('status_map');
                if (hasReportParams) {
                    currentUrl.searchParams.delete('report');
                    currentUrl.searchParams.delete('status_map');
                    window.history.replaceState(null, document.title, currentUrl.pathname + currentUrl.search + currentUrl.hash);
                }
            } catch (e) {
                // noop
            }
        }

        var gridId = '<?= CUtil::JSEscape($gridId); ?>';
        var form = document.getElementById('es-bpclassgen-action-form');
        var actionInput = document.getElementById('es-action-code');
        var idsContainer = document.getElementById('es-template-ids-container');

        function setTemplateIds(ids) {
            idsContainer.innerHTML = '';
            for (var i = 0; i < ids.length; i++) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'TEMPLATE_IDS[]';
                input.value = String(ids[i]);
                idsContainer.appendChild(input);
            }
        }

        function getSelectedIdsFromGrid() {
            if (typeof BX === 'undefined' || !BX.Main || !BX.Main.gridManager) {
                return [];
            }

            var gridObject = BX.Main.gridManager.getById(gridId);
            if (!gridObject || !gridObject.instance) {
                return [];
            }

            return gridObject.instance.getRows().getSelectedIds();
        }

        window.esRunGridAction = function(actionCode) {
            var ids = getSelectedIdsFromGrid();
            if (!ids || ids.length === 0) {
                alert('<?= CUtil::JSEscape(Loc::getMessage('ES_BPCLASSGEN_OPTIONS_SELECT_TEMPLATES_ERROR')); ?>');
                return;
            }

            actionInput.value = actionCode;
            setTemplateIds(ids);
            form.submit();
        };

        window.esRunSingleAction = function(actionCode, templateId) {
            actionInput.value = actionCode;
            setTemplateIds([templateId]);
            form.submit();
        };

    })();
</script>
