<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Command;

use PHPUnit\Framework\TestCase;
use PHPolygon\Editor\Command\ListMeshAssetsCommand;
use PHPolygon\Editor\Command\LoadMeshAssetCommand;
use PHPolygon\Editor\Command\SaveMeshCommand;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Scene\Transpiler\SceneTranspiler;

class MeshAssetCommandsTest extends TestCase
{
    private string $projectDir;

    private EditorContext $context;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/phpolygon-editor-mesh-'.uniqid();
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

    private function graphArgs(string $name): array
    {
        return [
            'name' => $name,
            'nodes' => [
                ['id' => 'b', 'type' => 'box', 'params' => ['width' => 2.0]],
                ['id' => 't', 'type' => 'transform', 'inputs' => ['mesh' => 'b'], 'params' => ['ty' => 1.0]],
            ],
            'output' => 't',
        ];
    }

    public function testSaveWritesGraphJson(): void
    {
        $result = (new SaveMeshCommand($this->graphArgs('gear')))->execute($this->context);

        $this->assertTrue($result['saved']);
        $this->assertSame('gear', $result['name']);
        $this->assertSame('meshes/gear.mesh.json', $result['relativePath']);
        $this->assertFileExists($result['path']);

        $decoded = json_decode((string) file_get_contents($result['path']), true);
        $this->assertSame('gear', $decoded['name']);
        $this->assertSame('t', $decoded['output']);
        $this->assertCount(2, $decoded['nodes']);
    }

    public function testSaveThrowsWithoutNodesOrOutput(): void
    {
        $this->expectException(\RuntimeException::class);
        (new SaveMeshCommand(['name' => 'x', 'nodes' => [], 'output' => '']))->execute($this->context);
    }

    public function testListReturnsSavedMeshesSortedByName(): void
    {
        (new SaveMeshCommand($this->graphArgs('gear')))->execute($this->context);
        (new SaveMeshCommand($this->graphArgs('arch')))->execute($this->context);

        $result = (new ListMeshAssetsCommand())->execute($this->context);

        $this->assertCount(2, $result['meshes']);
        $this->assertSame('arch', $result['meshes'][0]['name']);
        $this->assertSame('gear', $result['meshes'][1]['name']);
        $this->assertSame('meshes/arch.mesh.json', $result['meshes'][0]['path']);
    }

    public function testLoadRoundtripsTheGraph(): void
    {
        (new SaveMeshCommand($this->graphArgs('gear')))->execute($this->context);

        $loaded = (new LoadMeshAssetCommand(['name' => 'gear']))->execute($this->context);

        $this->assertSame('gear', $loaded['name']);
        $this->assertSame('t', $loaded['output']);
        $this->assertSame('box', $loaded['nodes'][0]['type']);
    }

    public function testLoadThrowsForMissingMesh(): void
    {
        $this->expectException(\RuntimeException::class);
        (new LoadMeshAssetCommand(['name' => 'nope']))->execute($this->context);
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
