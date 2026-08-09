<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Project;

use PHPolygon\Editor\Command\GetMeshCommand;
use PHPolygon\Editor\Command\GetMeshesCommand;
use PHPolygon\Editor\Command\SaveMaterialCommand;
use PHPolygon\Editor\Command\SaveMeshCommand;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectAssetCache;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Scene\Transpiler\SceneTranspiler;
use PHPUnit\Framework\TestCase;

/**
 * Editing a mesh in the editor writes it to `assets/`, but the getters prefer
 * the per-project snapshot of a past scene build over that file. A mesh that
 * had ever been snapshotted — anything a scene build produced, and everything a
 * GLB import captured — therefore kept serving its pre-edit shape every time
 * the scene was reopened, while the edit sat on disk unread.
 */
class StaleSnapshotTest extends TestCase
{
    private string $projectDir;

    private EditorContext $context;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/phpolygon-editor-snapshot-'.uniqid();
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
        $this->rrmdir(sys_get_temp_dir().'/phpolygon-editor-assets/'.md5($this->context->projectDir));
    }

    /** A snapshot entry standing in for "the shape before the edit". */
    private function snapshotMesh(string $id, int $vertexCount): void
    {
        ProjectAssetCache::write($this->context->projectDir, [
            $id => [
                'id' => $id,
                'version' => 1,
                'vertices' => array_fill(0, $vertexCount * 3, 0.0),
                'normals' => array_fill(0, $vertexCount * 3, 0.0),
                'uvs' => array_fill(0, $vertexCount * 2, 0.0),
                'indices' => array_fill(0, $vertexCount, 0),
                'vertexCount' => $vertexCount,
                'triangleCount' => intdiv($vertexCount, 3),
            ],
        ], []);
    }

    private function saveEditedMesh(string $id): void
    {
        (new SaveMeshCommand([
            'name' => $id,
            'raw' => [
                'vertices' => [0, 0, 0, 1, 0, 0, 0, 1, 0],
                'normals' => [0, 0, 1, 0, 0, 1, 0, 0, 1],
                'uvs' => [0, 0, 1, 0, 0, 1],
                'indices' => [0, 1, 2],
            ],
        ]))->execute($this->context);
    }

    public function testSnapshotHidesTheEditBeforeSaving(): void
    {
        $this->snapshotMesh('char_arm', 24);

        $mesh = (new GetMeshCommand(['id' => 'char_arm']))->execute($this->context);

        $this->assertSame(24, $mesh['vertexCount'], 'precondition: the snapshot is what get_mesh serves');
    }

    public function testSavingAnEditedMeshRetiresTheSnapshot(): void
    {
        $this->snapshotMesh('char_arm', 24);
        $this->saveEditedMesh('char_arm');

        $mesh = (new GetMeshCommand(['id' => 'char_arm']))->execute($this->context);

        $this->assertSame(3, $mesh['vertexCount']);
        $this->assertSame(1, $mesh['triangleCount']);
    }

    public function testTheBulkFetchTheViewportUsesOnReloadAgrees(): void
    {
        $this->snapshotMesh('char_arm', 24);
        $this->saveEditedMesh('char_arm');

        $meshes = (new GetMeshesCommand())->execute($this->context)['meshes'];
        $arm = null;
        foreach ($meshes as $mesh) {
            if (($mesh['id'] ?? null) === 'char_arm') {
                $arm = $mesh;
            }
        }

        $this->assertNotNull($arm, 'the mesh must still be served, just not from the snapshot');
        $this->assertSame(3, $arm['vertexCount']);
        $this->assertCount(1, array_filter($meshes, static fn (array $m): bool => ($m['id'] ?? null) === 'char_arm'));
    }

    public function testOtherSnapshottedMeshesAreLeftAlone(): void
    {
        ProjectAssetCache::write($this->context->projectDir, [
            'char_arm' => ['id' => 'char_arm', 'version' => 1, 'vertices' => [], 'normals' => [], 'uvs' => [], 'indices' => [], 'vertexCount' => 24, 'triangleCount' => 12],
            'char_head' => ['id' => 'char_head', 'version' => 1, 'vertices' => [], 'normals' => [], 'uvs' => [], 'indices' => [], 'vertexCount' => 42, 'triangleCount' => 14],
        ], []);

        $this->saveEditedMesh('char_arm');

        $head = (new GetMeshCommand(['id' => 'char_head']))->execute($this->context);
        $this->assertSame(42, $head['vertexCount']);
        $this->assertSame(['char_head'], ProjectAssetCache::meshIds($this->context->projectDir));
    }

    public function testAProceduralGraphAssetLeavesTheSnapshotInPlace(): void
    {
        // A graph asset is not geometry get_mesh can serve, so the snapshot is
        // still the only thing that can answer for this id.
        $this->snapshotMesh('char_arm', 24);

        (new SaveMeshCommand([
            'name' => 'char_arm',
            'nodes' => [['id' => 'box', 'type' => 'box']],
            'output' => 'box',
        ]))->execute($this->context);

        $mesh = (new GetMeshCommand(['id' => 'char_arm']))->execute($this->context);
        $this->assertSame(24, $mesh['vertexCount']);
    }

    public function testSavingAMaterialRetiresItsSnapshot(): void
    {
        ProjectAssetCache::write($this->context->projectDir, [], [
            'skin' => ['id' => 'skin', 'roughness' => 0.1],
        ]);

        (new SaveMaterialCommand(['material' => ['id' => 'skin', 'roughness' => 0.9]]))->execute($this->context);

        $this->assertNull(ProjectAssetCache::material($this->context->projectDir, 'skin'));
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
