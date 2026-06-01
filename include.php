<?php

use Bitrix\Main\Loader;

Loader::registerAutoLoadClasses('es.bpclassgenerator', [
    \Es\Bpclassgenerator\Service\CrmEntityProvider::class => 'lib/Service/CrmEntityProvider.php',
    \Es\Bpclassgenerator\Service\TemplateService::class => 'lib/Service/TemplateService.php',
]);
