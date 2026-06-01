<?php

namespace Es\Bpclassgenerator\Bizprocs\##DIR_NAME##;

use Bitrix\Main\ObjectException;
use Bitrix\Main\Type\DateTime;

class ##CLASS_NAME##
{
    public const TEMPLATE_ID = ##TEMPLATE_ID##;
    public const TEMPLATE_NAME = '##NAME##';
    public const TEMPLATE_SORT = ##SORT##;
    public const TEMPLATE_DOCUMENT_STATUS = '##DOCUMENT_STATUS##';
    public const TEMPLATE_IS_ROBOT = true;
    public const TEMPLATE_MODULE_ID = '##MODULE_ID##';
    public const TEMPLATE_ENTITY = '##ENTITY##';
    public const TEMPLATE_AUTO_EXECUTE = '##AUTO_EXECUTE##';
    public const TEMPLATE_IS_SYSTEM = '##IS_SYSTEM##';
    public const TEMPLATE_ACTIVE = '##ACTIVE##';

    /**
     * @param int $id
     * @param string|string[] $documentType
     * @param string $stageId
     * @param string $name
     * @param int $order
     * @return array
     * @throws ObjectException
     */
    public static function getData(int $id = 0, $documentType = [], string $stageId = '', string $name = '', int $order = 0)
    {
        $meta = self::getMeta();
        $id = $id > 0 ? $id : (int)$meta['id'];
        $name = $name !== '' ? $name : (string)$meta['name'];
        $order = $order > 0 ? $order : (int)$meta['sort'];
        $stageId = $stageId !== '' ? $stageId : (string)$meta['documentStatus'];

        return [
            'ID' => $id,
            'DOCUMENT_TYPE' => $documentType,
            'NAME' => $name,
            'TEMPLATE' => self::getTemplate(),
            'MODIFIED' => new DateTime(),
            'IS_MODIFIED' => 'Y',
            'USER_ID' => 1,
            'SYSTEM_CODE' => 'bitrix_bizproc_automation',
            'ORIGINATOR_ID' => null,
            'ORIGIN_ID' => null,
            'IS_SYSTEM' => '##IS_SYSTEM##',
            'SORT' => $order,
            'MODULE_ID' => '##MODULE_ID##',
            'ENTITY' => '##ENTITY##',
            'DOCUMENT_STATUS' => $stageId,
            'AUTO_EXECUTE' => '##AUTO_EXECUTE##',
            'DESCRIPTION' => '##DESCRIPTION##',
            'PARAMETERS' => self::getParams(),
            'VARIABLES' => ##VARIABLES##,
            'TEMPLATE_SETTINGS' => self::getSettings(),
            'CONSTANTS' => ##CONSTANTS##,
            'ACTIVE' => '##ACTIVE##',
        ];
    }

    public static function getMeta(): array
    {
        return [
            'id' => self::TEMPLATE_ID,
            'name' => self::TEMPLATE_NAME,
            'sort' => self::TEMPLATE_SORT,
            'documentStatus' => self::TEMPLATE_DOCUMENT_STATUS,
            'isRobot' => self::TEMPLATE_IS_ROBOT,
            'moduleId' => self::TEMPLATE_MODULE_ID,
            'entity' => self::TEMPLATE_ENTITY,
            'autoExecute' => self::TEMPLATE_AUTO_EXECUTE,
            'isSystem' => self::TEMPLATE_IS_SYSTEM,
            'active' => self::TEMPLATE_ACTIVE,
        ];
    }

    public static function getParams(): array
    {
        return ##PARAMETERS##;
    }

    public static function getSettings(): array
    {
        return ##TEMPLATE_SETTINGS##;
    }

    public static function getTemplate(): array
    {
        return ##TEMPLATE##;
    }
}
