<?php

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

return [
    [
        'parent_menu' => 'global_menu_dm',
        'section' => 'es_bpclassgenerator',
        'sort' => 100,
        'text' => Loc::getMessage('ES_BPCLASSGEN_MENU_TEXT'),
        'title' => Loc::getMessage('ES_BPCLASSGEN_MENU_TITLE'),
        'url' => 'settings.php?mid=es.bpclassgenerator&lang=' . LANGUAGE_ID,
        'icon' => 'sys_menu_icon',
        'page_icon' => 'sys_page_icon',
        'items_id' => 'menu_es_bpclassgenerator',
    ],
];
