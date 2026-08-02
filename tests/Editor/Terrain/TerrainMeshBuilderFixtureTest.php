<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Terrain;

use PHPolygon\Terrain\HeightmapData;
use PHPolygon\Terrain\TerrainMeshBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Pins the engine's terrain geometry to the shared fixture.
 *
 * The editor previews terrain with a TypeScript mesh builder and the game
 * renders it with this PHP one. Both are asserted against the same generated
 * file (`resources/js/terrain/engineParity.test.ts` covers the other side), so
 * a change to either implementation fails a test rather than silently letting
 * the preview and the runtime diverge.
 *
 * Intentional geometry changes: re-run `php scripts/generate-terrain-fixture.php`
 * and review the diff.
 */
class TerrainMeshBuilderFixtureTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $fixture;

    protected function setUp(): void
    {
        $path = __DIR__.'/../../fixtures/terrain-geometry.json';
        $raw = file_get_contents($path);
        $this->assertIsString($raw, "Missing fixture: {$path}");

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);
        $this->fixture = $decoded;
    }

    private function heightmap(): HeightmapData
    {
        $spec = $this->fixture['heightmap'];

        return HeightmapData::decode(
            $spec['heights'],
            $spec['gridWidth'],
            $spec['gridDepth'],
            $spec['sizeX'],
            $spec['sizeZ'],
            $spec['minHeight'],
            $spec['maxHeight'],
        );
    }

    public function test_single_mesh_matches_the_fixture(): void
    {
        $mesh = (new TerrainMeshBuilder)->buildSingle($this->heightmap());

        $this->assertEqualsWithDelta($this->fixture['single']['positions'], $mesh->vertices, 1e-6);
        $this->assertEqualsWithDelta($this->fixture['single']['normals'], $mesh->normals, 1e-6);
        $this->assertEqualsWithDelta($this->fixture['single']['uvs'], $mesh->uvs, 1e-6);
        $this->assertSame($this->fixture['single']['indices'], $mesh->indices);
    }

    public function test_chunk_meshes_match_the_fixture(): void
    {
        $chunks = (new TerrainMeshBuilder)->buildChunks($this->heightmap(), 4);

        $this->assertCount(count($this->fixture['chunks']), $chunks);

        foreach ($chunks as $index => $chunk) {
            $expected = $this->fixture['chunks'][$index];
            $label = "chunk {$chunk->chunkX},{$chunk->chunkZ}";

            $this->assertSame($expected['chunkX'], $chunk->chunkX, $label);
            $this->assertSame($expected['chunkZ'], $chunk->chunkZ, $label);
            $this->assertSame($expected['meshId'], $chunk->meshId('fixture'), $label);
            $this->assertEqualsWithDelta($expected['positions'], $chunk->mesh->vertices, 1e-6, $label);
            $this->assertEqualsWithDelta($expected['normals'], $chunk->mesh->normals, 1e-6, $label);
            $this->assertEqualsWithDelta($expected['uvs'], $chunk->mesh->uvs, 1e-6, $label);
            $this->assertSame($expected['indices'], $chunk->mesh->indices, $label);
        }
    }

    public function test_heightmap_payload_round_trips_unchanged(): void
    {
        // Lossless requantisation is what lets the editor hand a sculpted
        // terrain straight to the engine without resampling.
        $this->assertSame($this->fixture['heightmap']['heights'], $this->heightmap()->encode());
    }
}
