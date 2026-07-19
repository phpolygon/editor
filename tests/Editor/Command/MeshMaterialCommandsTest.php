<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Command;

use PHPUnit\Framework\TestCase;
use PHPolygon\Editor\Command\GetMaterialCommand;
use PHPolygon\Editor\Command\GetMaterialsCommand;
use PHPolygon\Editor\Command\GetMeshCommand;
use PHPolygon\Editor\Command\GetMeshesCommand;
use PHPolygon\Editor\Command\ListMaterialsCommand;
use PHPolygon\Editor\Command\ListMeshesCommand;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\SphereMesh;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Scene\Transpiler\SceneTranspiler;

class MeshMaterialCommandsTest extends TestCase
{
    private EditorContext $context;

    protected function setUp(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();

        MeshRegistry::register('test_box', BoxMesh::generate(1.0, 1.0, 1.0));
        MeshRegistry::registerLazy('test_sphere', static fn() => SphereMesh::generate(0.5, 8, 12));

        MaterialRegistry::register('test_red', new Material(
            albedo: new Color(1.0, 0.0, 0.0),
            roughness: 0.4,
            metallic: 0.2,
        ));

        $manifest = new ProjectManifest(
            name: 'Test',
            version: '0.1.0',
            engineVersion: '*',
            scenesPath: 'src/Scene',
            assetsPath: 'assets',
            psr4Roots: [],
            entryScene: '',
        );

        $this->context = new EditorContext(
            manifest: $manifest,
            components: new ComponentRegistry(),
            systems: new SystemRegistry(),
            transpiler: new SceneTranspiler(),
            projectDir: '/tmp/test-project',
        );
    }

    protected function tearDown(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
    }

    public function testListMeshesReturnsRegisteredIds(): void
    {
        $result = (new ListMeshesCommand())->execute($this->context);

        $this->assertArrayHasKey('meshes', $result);
        $ids = array_column($result['meshes'], 'id');
        $this->assertContains('test_box', $ids);
        $this->assertContains('test_sphere', $ids);
    }

    public function testListMeshesMarksPendingFactories(): void
    {
        $result = (new ListMeshesCommand())->execute($this->context);
        $byId = [];
        foreach ($result['meshes'] as $m) {
            $byId[$m['id']] = $m;
        }

        $this->assertFalse($byId['test_box']['pending']);
        $this->assertTrue($byId['test_sphere']['pending']);
    }

    public function testGetMeshReturnsBuffers(): void
    {
        $result = (new GetMeshCommand(['id' => 'test_box']))->execute($this->context);

        $this->assertSame('test_box', $result['id']);
        $this->assertGreaterThan(0, $result['vertexCount']);
        $this->assertGreaterThan(0, $result['triangleCount']);
        $this->assertSame($result['vertexCount'] * 3, count($result['vertices']));
        $this->assertSame($result['vertexCount'] * 3, count($result['normals']));
        $this->assertSame($result['vertexCount'] * 2, count($result['uvs']));
        $this->assertSame($result['triangleCount'] * 3, count($result['indices']));
    }

    public function testGetMeshMaterialisesLazyFactory(): void
    {
        $result = (new GetMeshCommand(['id' => 'test_sphere']))->execute($this->context);

        $this->assertSame('test_sphere', $result['id']);
        $this->assertGreaterThan(0, $result['vertexCount']);
        $this->assertNotContains('test_sphere', MeshRegistry::pendingIds());
    }

    public function testGetMeshUnknownThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unknown mesh/');
        (new GetMeshCommand(['id' => 'does_not_exist']))->execute($this->context);
    }

    public function testListMaterialsReturnsIds(): void
    {
        $result = (new ListMaterialsCommand())->execute($this->context);

        $this->assertSame(['materials' => ['test_red']], $result);
    }

    public function testGetMaterialReturnsPbrFields(): void
    {
        $result = (new GetMaterialCommand(['id' => 'test_red']))->execute($this->context);

        $this->assertSame('test_red', $result['id']);
        $this->assertSame(1.0, $result['albedo']['r']);
        $this->assertSame(0.0, $result['albedo']['g']);
        $this->assertSame(0.0, $result['albedo']['b']);
        $this->assertSame(0.4, $result['roughness']);
        $this->assertSame(0.2, $result['metallic']);
        $this->assertNull($result['albedoTexture']);
    }

    public function testGetMaterialUnknownThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        (new GetMaterialCommand(['id' => 'missing']))->execute($this->context);
    }

    public function testGetMeshesReturnsEveryMeshInOneCall(): void
    {
        $result = (new GetMeshesCommand())->execute($this->context);

        $this->assertArrayHasKey('meshes', $result);
        $byId = [];
        foreach ($result['meshes'] as $m) {
            $byId[$m['id']] = $m;
        }
        // Bulk endpoint materialises lazy factories too, and carries buffers.
        $this->assertArrayHasKey('test_box', $byId);
        $this->assertArrayHasKey('test_sphere', $byId);
        $this->assertGreaterThan(0, $byId['test_box']['vertexCount']);
        $this->assertSame($byId['test_box']['vertexCount'] * 3, count($byId['test_box']['vertices']));
    }

    public function testGetMaterialsReturnsEveryMaterialInOneCall(): void
    {
        $result = (new GetMaterialsCommand())->execute($this->context);

        $this->assertArrayHasKey('materials', $result);
        $byId = [];
        foreach ($result['materials'] as $m) {
            $byId[$m['id']] = $m;
        }
        $this->assertArrayHasKey('test_red', $byId);
        $this->assertSame(1.0, $byId['test_red']['albedo']['r']);
        $this->assertSame(0.4, $byId['test_red']['roughness']);
    }
}
