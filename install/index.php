<?php

use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;

Loc::loadMessages(__FILE__);

if (class_exists('es_bpclassgenerator')) {
    return;
}

class es_bpclassgenerator extends CModule
{
    public $MODULE_ID = 'es.bpclassgenerator';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;
    public $PARTNER_NAME;
    public $PARTNER_URI;

    public function __construct()
    {
        $arModuleVersion = [];
        include __DIR__ . '/version.php';

        if (is_array($arModuleVersion) && array_key_exists('VERSION', $arModuleVersion)) {
            $this->MODULE_VERSION = $arModuleVersion['VERSION'];
            $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
        }

        $this->MODULE_NAME = Loc::getMessage('ES_BPCLASSGEN_MODULE_NAME');
        $this->MODULE_DESCRIPTION = Loc::getMessage('ES_BPCLASSGEN_MODULE_DESCRIPTION');
        $this->PARTNER_NAME = Loc::getMessage('ES_BPCLASSGEN_PARTNER_NAME');
        $this->PARTNER_URI = Loc::getMessage('ES_BPCLASSGEN_PARTNER_URI');
    }

    public function DoInstall()
    {
        ModuleManager::registerModule($this->MODULE_ID);
        return true;
    }

    public function DoUninstall()
    {
        Option::delete($this->MODULE_ID, ['name' => 'CLASS_MAP']);
        ModuleManager::unRegisterModule($this->MODULE_ID);
        return true;
    }
}
