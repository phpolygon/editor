<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Command;

use PHPUnit\Framework\TestCase;
use PHPolygon\Editor\Command\SavePrefabCommand;
use PHPolygon\Editor\Command\SpawnPrefabCommand;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Editor\SceneDocument;
use PHPolygon\Scene\Transpiler\SceneTranspiler;

class PrefabCommandsTest extends TestCase
{
    private string $projectDir;
    private EditorContext $context;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/phpolygon-editor-prefab-' . uniqid();
        mkdir($this->projectDir);
        mkdir($this->projectDir . '/assets');

        $this->context = new EditorContext(
            manifest: new ProjectManifest(
                name: 'Test',
                version: '0.1.0',
                engineVersion: '*',
                scenesPath: 'src/Scene',
                assetsPath: 'assets',
                psr4Roots: [],
                entryScene: '',
            ),
            components: new ComponentRegistry(),
            systems: new SystemRegistry(),
            transpiler: new SceneTranspiler(),
            projectDir: $this->projectDir,
        );

        $this->context->activeDocument = new SceneDocument([
            'name' => 'test',
            'entities' => [
                [
                    'name' => 'Car',
                    'components' => [
                        ['_class' => 'PHPolygon\\Component\\Transform3D'],
                    ],
                    'children' => [
                        [
                            'name' => 'Body',
                            'components' => [
                                ['_class' => 'PHPolygon\\Component\\MeshRenderer', 'meshId' => 'car_body'],
                            ],
                        ],
                        [
                            'name' => 'WheelFL',
                            'components' => [
                                ['_class' => 'PHPolygon\\Component\\MeshRenderer', 'meshId' => 'wheel'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->projectDir);
    }

    public function testSavePrefabWritesJsonFile(): void
    {
        $result = (new SavePrefabCommand(['entityName' => 'Car']))->execute($this->context);

        $this->assertTrue($result['saved']);
        $this->assertSame('Car', $result['name']);
        $this->assertFileExists($result['path']);
        $this->assertSame('prefabs/Car.prefab.json', $result['relativePath']);

        $decoded = json_decode((string)file_get_contents($result['path']), true);
        $this->assertSame('Car', $decoded['root']['name']);
        $this->assertCount(2, $decoded['root']['children']);
    }

    public function testSavePrefabSanitizesName(): void
    {
        $result = (new SavePrefabCommand([
            'entityName' => 'Car',
            'prefabName' => 'My Cool/Car!',
        ]))->execute($this->context);

        $this->assertSame('My_Cool_Car_', $result['name']);
    }

    public function testSavePrefabThrowsForMissingEntity(): void
    {
        $this->expectException(\RuntimeException::class);
        (new SavePrefabCommand(['entityName' => 'NonExistent']))->execute($this->context);
    }

    public function testSpawnRoundtripPreservesHierarchy(): void
    {
        $saved = (new SavePrefabCommand(['entityName' => 'Car']))->execute($this->context);

        // Clear scene and spawn
        $this->context->activeDocument = new SceneDocument([
            'name' => 'test',
            'entities' => [],
        ]);

        $spawn = (new SpawnPrefabCommand(['path' => $saved['relativePath']]))->execute($this->context);
        $this->assertSame('Car', $spawn['spawned']);

        $entity = $this->context->activeDocument->getEntity('Car');
        $this->assertNotNull($entity);
        $this->assertCount(2, $entity['children']);
        $this->assertSame('Body', $entity['children'][0]['name']);
        $this->assertSame('PHPolygon\\Component\\MeshRenderer', $entity['children'][0]['components'][0]['_class']);
    }

    public function testSpawnRenamesWhenNameCollides(): void
    {
        $saved = (new SavePrefabCommand(['entityName' => 'Car']))->execute($this->context);
        $spawn = (new SpawnPrefabCommand(['path' => $saved['relativePath']]))->execute($this->context);

        $this->assertSame('Car_2', $spawn['spawned']);
        $this->assertNotNull($this->context->activeDocument->getEntity('Car'));
        $this->assertNotNull($this->context->activeDocument->getEntity('Car_2'));
    }

    public function testSpawnUnderParent(): void
    {
        $saved = (new SavePrefabCommand(['entityName' => 'Car']))->execute($this->context);

        $this->context->activeDocument = new SceneDocument([
            'name' => 'test',
            'entities' => [['name' => 'World', 'components' => []]],
        ]);
        (new SpawnPrefabCommand(['path' => $saved['relativePath'], 'parent' => 'World']))->execute($this->context);

        $world = $this->context->activeDocument->getEntity('World');
        $this->assertSame('Car', $world['children'][0]['name']);
    }

    public function testSpawnRejectsPathOutsideAssets(): void
    {
        $this->expectException(\RuntimeException::class);
        (new SpawnPrefabCommand(['path' => '../etc/passwd']))->execute($this->context);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $f;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
