<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Command;

use PHPUnit\Framework\TestCase;
use PHPolygon\Editor\Command\GetMaterialCommand;
use PHPolygon\Editor\Command\ListMaterialAssetsCommand;
use PHPolygon\Editor\Command\SaveMaterialCommand;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Scene\Transpiler\SceneTranspiler;

class MaterialCommandsTest extends TestCase
{
    private string $projectDir;

    private EditorContext $context;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/phpolygon-editor-mat-'.uniqid();
        mkdir($this->projectDir);
        mkdir($this->projectDir.'/assets');

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
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->projectDir);
    }

    /** @return array<string, mixed> */
    private function material(string $id): array
    {
        return [
            'id' => $id,
            'albedo' => ['r' => 0.8, 'g' => 0.2, 'b' => 0.1],
            'roughness' => 0.4,
            'metallic' => 0.0,
            'shader' => 'default',
        ];
    }

    public function testSaveWritesMaterialJson(): void
    {
        $result = (new SaveMaterialCommand(['material' => $this->material('rust')]))->execute($this->context);

        $this->assertTrue($result['saved']);
        $this->assertSame('rust', $result['id']);
        $this->assertSame('materials/rust.material.json', $result['relativePath']);
        $this->assertFileExists($result['path']);

        $decoded = json_decode((string) file_get_contents($result['path']), true);
        $this->assertSame('rust', $decoded['id']);
        $this->assertSame(0.4, $decoded['roughness']);
    }

    public function testSaveSanitizesId(): void
    {
        $result = (new SaveMaterialCommand(['material' => $this->material('My Metal!')]))->execute($this->context);
        $this->assertSame('My_Metal_', $result['id']);
    }

    public function testSaveThrowsWithoutValidId(): void
    {
        $this->expectException(\RuntimeException::class);
        (new SaveMaterialCommand(['material' => ['roughness' => 0.5]]))->execute($this->context);
    }

    public function testListReturnsSavedMaterialsSorted(): void
    {
        (new SaveMaterialCommand(['material' => $this->material('rust')]))->execute($this->context);
        (new SaveMaterialCommand(['material' => $this->material('brass')]))->execute($this->context);

        $result = (new ListMaterialAssetsCommand())->execute($this->context);

        $this->assertCount(2, $result['materials']);
        $this->assertSame('brass', $result['materials'][0]['id']);
        $this->assertSame('materials/brass.material.json', $result['materials'][0]['path']);
    }

    public function testGetMaterialFallsBackToDisk(): void
    {
        (new SaveMaterialCommand(['material' => $this->material('rust')]))->execute($this->context);

        // Not in the runtime registry or snapshot → resolved from disk.
        $loaded = (new GetMaterialCommand(['id' => 'rust']))->execute($this->context);

        $this->assertSame('rust', $loaded['id']);
        $this->assertSame(0.4, $loaded['roughness']);
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$f;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
