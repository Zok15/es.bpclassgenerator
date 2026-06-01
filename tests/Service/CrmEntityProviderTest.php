<?php
declare(strict_types=1);

use Es\Bpclassgenerator\Service\CrmEntityProvider;
use Es\Bpclassgenerator\Tests\Stubs\BitrixLoaderStub;
use Es\Bpclassgenerator\Tests\Stubs\IBlockStub;
use PHPUnit\Framework\TestCase;

final class CrmEntityProviderTest extends TestCase
{
    protected function setUp(): void
    {
        BitrixLoaderStub::reset();
        IBlockStub::reset();
    }

    public function testGetAllReturnsBizprocIblocksWhenIblockModuleEnabled(): void
    {
        BitrixLoaderStub::setModuleState('iblock', true);
        IBlockStub::setRows([
            ['ID' => 15, 'NAME' => 'Regular IBlock', 'ACTIVE' => 'Y', 'BIZPROC' => 'N'],
            ['ID' => 16, 'NAME' => 'Lease agreement approval', 'ACTIVE' => 'Y', 'BIZPROC' => 'Y'],
            ['ID' => 30, 'NAME' => 'Non-standard contracts approval', 'ACTIVE' => 'Y', 'BIZPROC' => 'Y'],
            ['ID' => 31, 'NAME' => 'Inactive IBlock', 'ACTIVE' => 'N', 'BIZPROC' => 'Y'],
        ]);

        $provider = new CrmEntityProvider();
        $entities = $provider->getAll();

        self::assertArrayHasKey(-16, $entities);
        self::assertArrayHasKey(-30, $entities);
        self::assertArrayNotHasKey(-15, $entities);
        self::assertArrayNotHasKey(-31, $entities);
        self::assertSame('Lease agreement approval (16)', $entities[-16]['TITLE']);
        self::assertSame('iblock_16', $entities[-16]['TYPE']);
        self::assertSame('lists', $entities[-16]['MODULE_ID']);
        self::assertSame('BizprocDocument', $entities[-16]['ENTITY_CLASS']);
        self::assertSame('Iblock16', $entities[-16]['DIR']);
    }

    public function testGetDocumentTypeArrayReturnsListsTripletForIblockEntity(): void
    {
        BitrixLoaderStub::setModuleState('iblock', true);
        IBlockStub::setRows([
            ['ID' => 16, 'NAME' => 'Lease agreement approval', 'ACTIVE' => 'Y', 'BIZPROC' => 'Y'],
        ]);

        $provider = new CrmEntityProvider();

        self::assertSame(
            ['lists', 'BizprocDocument', 'iblock_16'],
            $provider->getDocumentTypeArray(-16)
        );
    }
}
