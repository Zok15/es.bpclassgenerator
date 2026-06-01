<?php
declare(strict_types=1);

use Es\Bpclassgenerator\Service\TemplateService;
use Es\Bpclassgenerator\Tests\Stubs\BitrixOptionStub;
use Es\Bpclassgenerator\Tests\Stubs\WorkflowTemplateTableStub;
use PHPUnit\Framework\TestCase;

final class TemplateServiceTest extends TestCase
{
    private array $tempDirs = [];

    protected function setUp(): void
    {
        BitrixOptionStub::reset();
        WorkflowTemplateTableStub::reset();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dirPath) {
            $this->removeDirectory($dirPath);
        }

        $this->tempDirs = [];
    }

    public function testGetClassMapReadsJsonValue(): void
    {
        BitrixOptionStub::set('es.bpclassgenerator', 'CLASS_MAP', '{"42":{"entityTypeId":1078,"class":"Demo"}}');

        $service = $this->newService();
        $result = $service->getClassMap();

        self::assertSame(['42' => ['entityTypeId' => 1078, 'class' => 'Demo']], $result);
    }

    public function testGetClassMapFallsBackToLegacySerializedValue(): void
    {
        $legacy = [
            42 => [
                'templateId' => 42,
                'entityTypeId' => 1078,
                'class' => 'LegacyClass',
            ],
        ];
        BitrixOptionStub::set('es.bpclassgenerator', 'CLASS_MAP', serialize($legacy));

        $service = $this->newService();
        $result = $service->getClassMap();

        self::assertSame($legacy, $result);
    }

    public function testGetClassMapReturnsEmptyArrayForInvalidValue(): void
    {
        BitrixOptionStub::set('es.bpclassgenerator', 'CLASS_MAP', 'not-a-map');

        $service = $this->newService();

        self::assertSame([], $service->getClassMap());
    }

    public function testSafeUnserializeArrayHandlesValidAndInvalidPayloads(): void
    {
        $service = $this->newService();

        self::assertSame(
            ['ID' => 1],
            $this->invokePrivate($service, 'safeUnserializeArray', [serialize(['ID' => 1])])
        );
        self::assertNull($this->invokePrivate($service, 'safeUnserializeArray', [serialize('text')]));
        self::assertNull($this->invokePrivate($service, 'safeUnserializeArray', ['']));
        self::assertNull($this->invokePrivate($service, 'safeUnserializeArray', ['invalid-serialized']));
    }

    public function testNormalizeDocumentTypeForComparisonUsesCodePartFromArray(): void
    {
        $service = $this->newService();

        self::assertSame(
            'DYNAMIC_1078',
            $this->invokePrivate($service, 'normalizeDocumentTypeForComparison', [['crm', 'CCrmDocumentDynamic', 'DYNAMIC_1078']])
        );
        self::assertSame(
            'LEAD',
            $this->invokePrivate($service, 'normalizeDocumentTypeForComparison', [['LEAD']])
        );
    }

    public function testIsTemplateEquivalentIgnoringFormattingForEqualValues(): void
    {
        $service = $this->newService();

        $expected = [
            'NAME' => 'Rental payment',
            'SORT' => 100,
            'DOCUMENT_TYPE' => ['crm', 'CCrmDocumentDynamic', 'DYNAMIC_1078'],
            'DESCRIPTION' => "Line 1\r\nLine 2",
            'TEMPLATE' => ['b' => 2, 'a' => 1],
            'PARAMETERS' => ['A' => ['2' => 'second', '1' => 'first']],
            'VARIABLES' => [],
            'CONSTANTS' => [],
            'ACTIVE' => 'Y',
        ];

        $actual = [
            'NAME' => '  Rental payment ',
            'SORT' => '100',
            'DOCUMENT_TYPE' => 'DYNAMIC_1078',
            'DESCRIPTION' => "Line 1\nLine 2",
            'TEMPLATE' => serialize(['a' => 1, 'b' => 2]),
            'PARAMETERS' => serialize(['A' => ['1' => 'first', '2' => 'second']]),
            'VARIABLES' => serialize([]),
            'CONSTANTS' => [],
            'ACTIVE' => 'Y',
        ];

        self::assertTrue($this->invokePrivate($service, 'isTemplateEquivalentIgnoringFormatting', [$expected, $actual]));
    }

    public function testIsTemplateEquivalentIgnoringFormattingDetectsMeaningfulDifference(): void
    {
        $service = $this->newService();

        $expected = ['NAME' => 'A  B'];
        $actual = ['NAME' => 'A B'];

        self::assertFalse($this->invokePrivate($service, 'isTemplateEquivalentIgnoringFormatting', [$expected, $actual]));
    }

    public function testLoadInstalledTemplatesByIdsFiltersInvalidIdsAndIndexesRows(): void
    {
        WorkflowTemplateTableStub::setRows([
            ['ID' => 2, 'NAME' => 'Template 2'],
            ['ID' => 3, 'NAME' => 'Template 3'],
        ]);

        $service = $this->newService();
        $result = $this->invokePrivate($service, 'loadInstalledTemplatesByIds', [[3, 0, -4, 2, 2]]);

        self::assertEqualsCanonicalizing([2, 3], array_keys($result));
        self::assertSame('Template 2', $result[2]['NAME']);
        self::assertSame('Template 3', $result[3]['NAME']);
    }

    public function testSaveClassMapStoresJsonByDefault(): void
    {
        $service = $this->newService();
        $map = [
            42 => [
                'entityTypeId' => 1078,
                'class' => 'Class42',
            ],
        ];

        $this->invokePrivate($service, 'saveClassMap', [$map]);
        $raw = BitrixOptionStub::get('es.bpclassgenerator', 'CLASS_MAP', '');

        self::assertStringStartsWith('{"42"', $raw);
    }

    public function testSaveClassMapFallsBackToSerializeWhenJsonEncodeFails(): void
    {
        $service = $this->newService();
        $recursive = [];
        $recursive['self'] = &$recursive;

        $this->invokePrivate($service, 'saveClassMap', [$recursive]);
        $raw = BitrixOptionStub::get('es.bpclassgenerator', 'CLASS_MAP', '');

        self::assertStringStartsWith('a:', $raw);
    }

    public function testBuildTemplatePayloadIncludesTemplateSettings(): void
    {
        $service = $this->newService();

        $payload = $this->invokePrivate($service, 'buildTemplatePayload', [[
            'ID' => 159,
            'NAME' => 'Lease agreement approval process',
            'TEMPLATE_SETTINGS' => ['SHOW_IN_TIMELINE' => 'N'],
        ]]);

        self::assertSame("array (\n  'SHOW_IN_TIMELINE' => 'N',\n)", $payload['TEMPLATE_SETTINGS']);
    }

    public function testBuildTemplatePayloadForcesTimelineSettingForSmartProcessTemplates(): void
    {
        $service = $this->newService();

        $payload = $this->invokePrivate($service, 'buildTemplatePayload', [[
            'ID' => 151,
            'NAME' => 'Naming and creating separate payment records',
            'MODULE_ID' => 'crm',
            'ENTITY' => 'Bitrix\Crm\Integration\BizProc\Document\Dynamic',
            'TEMPLATE_SETTINGS' => [],
        ]]);

        self::assertSame("array (\n  'SHOW_IN_TIMELINE' => 'N',\n)", $payload['TEMPLATE_SETTINGS']);
    }

    public function testSyncClassMapFromFilesystemAddsMissingEntriesForPositiveAndNegativeEntityIds(): void
    {
        $crmDir = $this->createStubBizprocClass(
            'SyncDynamic9000',
            'BizprocSyncdynamiccrm501',
            501,
            'CRM Sync Template',
            false
        );
        $iblockDir = $this->createStubBizprocClass(
            'SyncIblock9000',
            'BizprocSynciblock502',
            502,
            'IBlock Sync Template',
            false
        );

        $service = $this->newServiceWithEntities([
            9000 => [
                'ID' => 9000,
                'DIR' => basename($crmDir),
                'MODULE_ID' => 'crm',
            ],
            -9000 => [
                'ID' => -9000,
                'DIR' => basename($iblockDir),
                'MODULE_ID' => 'lists',
            ],
        ]);

        $result = $service->syncClassMapFromFilesystem();
        $map = $service->getClassMap();

        self::assertSame(['added' => [501, 502]], $result);
        self::assertSame(9000, $map[501]['entityTypeId']);
        self::assertSame(-9000, $map[502]['entityTypeId']);
        self::assertSame('crm', $map[501]['moduleId']);
        self::assertSame('lists', $map[502]['moduleId']);
        self::assertSame(
            'Es\\Bpclassgenerator\\Bizprocs\\' . basename($crmDir) . '\\BizprocSyncdynamiccrm501',
            $map[501]['class']
        );
        self::assertSame(
            'Es\\Bpclassgenerator\\Bizprocs\\' . basename($iblockDir) . '\\BizprocSynciblock502',
            $map[502]['class']
        );
    }

    public function testSyncClassMapFromFilesystemDoesNotOverrideExistingEntries(): void
    {
        $dirPath = $this->createStubBizprocClass(
            'SyncDynamic9001',
            'BizprocSyncexisting503',
            503,
            'Generated Name',
            false
        );
        BitrixOptionStub::set('es.bpclassgenerator', 'CLASS_MAP', json_encode([
            503 => [
                'templateId' => 503,
                'entityTypeId' => 777,
                'name' => 'Existing Name',
                'sort' => 99,
                'documentStatus' => '',
                'isRobot' => 'N',
                'class' => 'Existing\\ClassName',
                'file' => '/tmp/existing.php',
                'moduleId' => 'crm',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $service = $this->newServiceWithEntities([
            9001 => [
                'ID' => 9001,
                'DIR' => basename($dirPath),
                'MODULE_ID' => 'crm',
            ],
        ]);

        $result = $service->syncClassMapFromFilesystem();
        $map = $service->getClassMap();

        self::assertSame(['added' => []], $result);
        self::assertSame('Existing Name', $map[503]['name']);
        self::assertSame('Existing\\ClassName', $map[503]['class']);
        self::assertSame('/tmp/existing.php', $map[503]['file']);
    }

    private function newService(): TemplateService
    {
        $reflection = new ReflectionClass(TemplateService::class);
        return $reflection->newInstanceWithoutConstructor();
    }

    private function newServiceWithEntities(array $entities): TemplateService
    {
        $service = $this->newService();
        $provider = new class($entities) extends \Es\Bpclassgenerator\Service\CrmEntityProvider {
            public function __construct(private array $entities)
            {
            }

            public function getAll(): array
            {
                return $this->entities;
            }
        };

        $property = new ReflectionProperty(TemplateService::class, 'entityProvider');
        $property->setAccessible(true);
        $property->setValue($service, $provider);

        return $service;
    }

    private function createStubBizprocClass(
        string $dirName,
        string $className,
        int $templateId,
        string $templateName,
        bool $isRobot
    ): string {
        $dirPath = $_SERVER['DOCUMENT_ROOT'] . '/local/modules/es.bpclassgenerator/lib/Bizprocs/' . $dirName;
        if (!is_dir($dirPath)) {
            mkdir($dirPath, 0775, true);
        }

        $filePath = $dirPath . '/' . $className . '.php';
        $content = <<<PHP
<?php

namespace Es\\Bpclassgenerator\\Bizprocs\\{$dirName};

class {$className}
{
    public const TEMPLATE_ID = {$templateId};
    public const TEMPLATE_NAME = '{$templateName}';
    public const TEMPLATE_SORT = 10;
    public const TEMPLATE_DOCUMENT_STATUS = '';
    public const TEMPLATE_IS_ROBOT = {$this->exportBool($isRobot)};
}
PHP;

        file_put_contents($filePath, $content);
        $this->tempDirs[] = $dirPath;

        return $dirPath;
    }

    private function exportBool(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    private function removeDirectory(string $dirPath): void
    {
        if (!is_dir($dirPath)) {
            return;
        }

        $files = glob($dirPath . '/*');
        if (is_array($files)) {
            foreach ($files as $filePath) {
                if (is_dir($filePath)) {
                    $this->removeDirectory($filePath);
                    continue;
                }

                @unlink($filePath);
            }
        }

        @rmdir($dirPath);
    }

    private function invokePrivate(object $object, string $methodName, array $arguments = [])
    {
        $method = new ReflectionMethod($object, $methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $arguments);
    }
}
