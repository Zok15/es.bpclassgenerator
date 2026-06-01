<?php
declare(strict_types=1);

use Es\Bpclassgenerator\Tests\Stubs\BitrixLoaderStub;
use Es\Bpclassgenerator\Tests\Stubs\BitrixOptionStub;
use Es\Bpclassgenerator\Tests\Stubs\IBlockStub;
use Es\Bpclassgenerator\Tests\Stubs\WorkflowTemplateTableStub;

require_once __DIR__ . '/Stubs/BitrixOptionStub.php';
require_once __DIR__ . '/Stubs/BitrixLoaderStub.php';
require_once __DIR__ . '/Stubs/IBlockStub.php';
require_once __DIR__ . '/Stubs/WorkflowTemplateTableStub.php';

if (!class_exists(\Bitrix\Main\Config\Option::class)) {
    class_alias(BitrixOptionStub::class, \Bitrix\Main\Config\Option::class);
}

if (!class_exists(\Bitrix\Main\Loader::class)) {
    class_alias(BitrixLoaderStub::class, \Bitrix\Main\Loader::class);
}

if (!class_exists(\CIBlock::class)) {
    class_alias(IBlockStub::class, \CIBlock::class);
}

if (!class_exists(\Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable::class)) {
    class_alias(
        WorkflowTemplateTableStub::class,
        \Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable::class
    );
}

if (empty($_SERVER['DOCUMENT_ROOT'])) {
    $_SERVER['DOCUMENT_ROOT'] = (string)realpath(__DIR__ . '/../../../../');
}

require_once __DIR__ . '/../lib/Service/CrmEntityProvider.php';
require_once __DIR__ . '/../lib/Service/TemplateService.php';
