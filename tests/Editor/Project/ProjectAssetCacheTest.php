<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Project;

use PHPolygon\Editor\Project\ProjectAssetCache;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPUnit\Framework\TestCase;

class ProjectAssetCacheTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        // A distinct virtual project dir per test → distinct cache bucket.
        $this->projectDir = '/virtual/project-'.uniqid();
        MaterialRegistry::clear();
        MeshRegistry::clear();
    }

    public function test_captured_material_survives_a_registry_clear(): void
    {
        MaterialRegistry::register('brass', new Material(
            albedo: new Color(0.8, 0.6, 0.2),
            roughness: 0.3,
            metallic: 0.9,
        ));

        ProjectAssetCache::capture($this->projectDir);
        MaterialRegistry::clear(); // simulate a fresh request

        $mat = ProjectAssetCache::material($this->projectDir, 'brass');
        $this->assertNotNull($mat);
        $this->assertSame('brass', $mat['id']);
        $this->assertEqualsWithDelta(0.9, $mat['metallic'], 1e-6);
        $this->assertEqualsWithDelta(0.8, $mat['albedo']['r'], 1e-6);

        $this->assertContains('brass', ProjectAssetCache::materialIds($this->projectDir));
    }

    public function test_captured_mesh_survives_a_registry_clear(): void
    {
        MeshRegistry::register('tri', new MeshData(
            [0, 0, 0, 1, 0, 0, 0, 1, 0],
            [0, 0, 1, 0, 0, 1, 0, 0, 1],
            [0, 0, 1, 0, 0, 1],
            [0, 1, 2],
        ));

        ProjectAssetCache::capture($this->projectDir);
        MeshRegistry::clear();

        $mesh = ProjectAssetCache::mesh($this->projectDir, 'tri');
        $this->assertNotNull($mesh);
        $this->assertSame('tri', $mesh['id']);
        $this->assertSame(3, $mesh['vertexCount']);
        $this->assertSame(1, $mesh['triangleCount']);
        $this->assertContains('tri', ProjectAssetCache::meshIds($this->projectDir));
    }

    public function test_unknown_id_returns_null(): void
    {
        ProjectAssetCache::capture($this->projectDir);
        $this->assertNull(ProjectAssetCache::material($this->projectDir, 'nope'));
        $this->assertNull(ProjectAssetCache::mesh($this->projectDir, 'nope'));
    }
}
