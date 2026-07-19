<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Command;

use PHPolygon\Editor\Command\DeleteMeshAssetCommand;
use PHPolygon\Editor\Command\ListMeshAssetsCommand;
use PHPolygon\Editor\Command\LoadMeshAssetCommand;
use PHPolygon\Editor\Command\RenameMeshAssetCommand;
use PHPolygon\Editor\Command\SaveMeshCommand;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Scene\Transpiler\SceneTranspiler;
use PHPUnit\Framework\TestCase;

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
            components: new ComponentRegistry,
            systems: new SystemRegistry,
            transpiler: new SceneTranspiler,
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

    public function test_save_writes_graph_json(): void
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

    public function test_save_throws_without_nodes_or_output(): void
    {
        $this->expectException(\RuntimeException::class);
        (new SaveMeshCommand(['name' => 'x', 'nodes' => [], 'output' => '']))->execute($this->context);
    }

    public function test_list_returns_saved_meshes_sorted_by_name(): void
    {
        (new SaveMeshCommand($this->graphArgs('gear')))->execute($this->context);
        (new SaveMeshCommand($this->graphArgs('arch')))->execute($this->context);

        $result = (new ListMeshAssetsCommand)->execute($this->context);

        $this->assertCount(2, $result['meshes']);
        $this->assertSame('arch', $result['meshes'][0]['name']);
        $this->assertSame('gear', $result['meshes'][1]['name']);
        $this->assertSame('meshes/arch.mesh.json', $result['meshes'][0]['path']);
    }

    public function test_load_roundtrips_the_graph(): void
    {
        (new SaveMeshCommand($this->graphArgs('gear')))->execute($this->context);

        $loaded = (new LoadMeshAssetCommand(['name' => 'gear']))->execute($this->context);

        $this->assertSame('gear', $loaded['name']);
        $this->assertSame('t', $loaded['output']);
        $this->assertSame('box', $loaded['nodes'][0]['type']);
    }

    public function test_save_and_load_raw_geometry(): void
    {
        $saved = (new SaveMeshCommand([
            'name' => 'edited',
            'raw' => [
                'vertices' => [0, 0, 0, 1, 0, 0, 0, 1, 0],
                'normals' => [],
                'uvs' => [],
                'indices' => [0, 1, 2],
            ],
        ]))->execute($this->context);

        $this->assertSame('meshes/edited.mesh.json', $saved['relativePath']);

        $loaded = (new LoadMeshAssetCommand(['name' => 'edited']))->execute($this->context);
        $this->assertNotNull($loaded['raw']);
        $this->assertSame([0, 1, 2], $loaded['raw']['indices']);
        $this->assertSame([0, 0, 0, 1, 0, 0, 0, 1, 0], $loaded['raw']['vertices']);
    }

    public function test_load_throws_for_missing_mesh(): void
    {
        $this->expectException(\RuntimeException::class);
        (new LoadMeshAssetCommand(['name' => 'nope']))->execute($this->context);
    }

    public function test_delete_removes_the_asset(): void
    {
        (new SaveMeshCommand($this->graphArgs('crate')))->execute($this->context);

        $result = (new DeleteMeshAssetCommand(['name' => 'crate']))->execute($this->context);
        $this->assertTrue($result['deleted']);

        $names = array_column((new ListMeshAssetsCommand)->execute($this->context)['meshes'], 'name');
        $this->assertNotContains('crate', $names);
    }

    public function test_delete_throws_for_missing_mesh(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/');
        (new DeleteMeshAssetCommand(['name' => 'nope']))->execute($this->context);
    }

    public function test_rename_moves_the_asset_and_rewrites_name(): void
    {
        (new SaveMeshCommand($this->graphArgs('old')))->execute($this->context);

        $result = (new RenameMeshAssetCommand(['name' => 'old', 'newName' => 'new']))->execute($this->context);
        $this->assertTrue($result['renamed']);
        $this->assertSame('new', $result['name']);
        $this->assertSame('meshes/new.mesh.json', $result['path']);

        $names = array_column((new ListMeshAssetsCommand)->execute($this->context)['meshes'], 'name');
        $this->assertNotContains('old', $names);
        $this->assertContains('new', $names);

        // The payload's own name field is rewritten so it round-trips.
        $loaded = (new LoadMeshAssetCommand(['name' => 'new']))->execute($this->context);
        $this->assertSame('new', $loaded['name']);
    }

    public function test_rename_onto_existing_throws(): void
    {
        (new SaveMeshCommand($this->graphArgs('a')))->execute($this->context);
        (new SaveMeshCommand($this->graphArgs('b')))->execute($this->context);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/already exists/');
        (new RenameMeshAssetCommand(['name' => 'a', 'newName' => 'b']))->execute($this->context);
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
