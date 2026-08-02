<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Command;

use PHPolygon\Editor\Command\BakeTerrainMeshCommand;
use PHPolygon\Editor\Command\CreateTerrainEntityCommand;
use PHPolygon\Editor\Command\DeleteTerrainAssetCommand;
use PHPolygon\Editor\Command\ListTerrainAssetsCommand;
use PHPolygon\Editor\Command\LoadTerrainCommand;
use PHPolygon\Editor\Command\SaveTerrainCommand;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Editor\SceneDocument;
use PHPolygon\Scene\Transpiler\SceneTranspiler;
use PHPolygon\Terrain\HeightmapData;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TerrainCommandsTest extends TestCase
{
    private string $projectDir;

    private EditorContext $context;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/phpolygon-editor-terrain-'.uniqid();
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

    /** @return array<string, mixed> */
    private function terrainArgs(string $name, int $grid = 9): array
    {
        return [
            'name' => $name,
            'gridWidth' => $grid,
            'gridDepth' => $grid,
            'sizeX' => 16.0,
            'sizeZ' => 16.0,
            'minHeight' => -10.0,
            'maxHeight' => 10.0,
            'chunkSize' => 4,
            'heights' => HeightmapData::fromFunction(
                static fn (float $x, float $z): float => $x * 0.5,
                gridWidth: $grid,
                gridDepth: $grid,
                sizeX: 16.0,
                sizeZ: 16.0,
                minHeight: -10.0,
                maxHeight: 10.0,
            )->encode(),
        ];
    }

    public function test_save_writes_terrain_json(): void
    {
        $result = (new SaveTerrainCommand($this->terrainArgs('island')))->execute($this->context);

        $this->assertTrue($result['saved']);
        $this->assertSame('island', $result['name']);
        $this->assertSame('terrains/island.terrain.json', $result['relativePath']);
        $this->assertFileExists($result['path']);

        $decoded = json_decode((string) file_get_contents($result['path']), true);
        $this->assertSame('island', $decoded['name']);
        $this->assertSame(9, $decoded['gridWidth']);
        $this->assertSame(1, $decoded['version']);
    }

    public function test_save_sanitizes_the_name(): void
    {
        $result = (new SaveTerrainCommand($this->terrainArgs('../evil name')))->execute($this->context);

        // "../evil name" → each of `.`, `.`, `/` and the space becomes `_`.
        $this->assertSame('___evil_name', $result['name']);
        $this->assertStringContainsString('terrains', $result['path']);
        $this->assertFileDoesNotExist($this->projectDir.'/evil name.terrain.json');
    }

    public function test_save_requires_a_name(): void
    {
        $this->expectException(RuntimeException::class);

        (new SaveTerrainCommand(['gridWidth' => 9]))->execute($this->context);
    }

    public function test_save_rejects_a_heightmap_that_does_not_fit_the_grid(): void
    {
        $args = $this->terrainArgs('bad');
        $args['gridWidth'] = 17; // payload is still 9x9

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not match/');

        (new SaveTerrainCommand($args))->execute($this->context);
    }

    public function test_save_rejects_an_inverted_height_range(): void
    {
        $args = $this->terrainArgs('bad');
        $args['minHeight'] = 50.0;
        $args['maxHeight'] = 10.0;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/maxHeight/');

        (new SaveTerrainCommand($args))->execute($this->context);
    }

    public function test_save_rejects_an_absurd_resolution(): void
    {
        // Guards against a mistyped resolution allocating gigabytes.
        $this->expectException(RuntimeException::class);

        (new SaveTerrainCommand(['name' => 'huge', 'gridWidth' => 40000, 'gridDepth' => 40000]))
            ->execute($this->context);
    }

    public function test_load_round_trips_a_saved_terrain(): void
    {
        $args = $this->terrainArgs('island');
        (new SaveTerrainCommand($args))->execute($this->context);

        $loaded = (new LoadTerrainCommand(['name' => 'island']))->execute($this->context);

        $this->assertSame('island', $loaded['name']);
        $this->assertSame(9, $loaded['gridWidth']);
        $this->assertSame(16.0, $loaded['sizeX']);
        $this->assertSame(-10.0, $loaded['minHeight']);
        $this->assertSame(4, $loaded['chunkSize']);
        $this->assertSame($args['heights'], $loaded['heights'], 'heightmap payload must survive byte-for-byte');
    }

    public function test_load_round_trips_layers_and_scatter(): void
    {
        $args = $this->terrainArgs('painted');
        $args['layers'] = [
            ['id' => 'grass', 'name' => 'Grass', 'materialId' => 'mat_grass', 'uvScale' => 8.0],
            ['id' => 'rock', 'name' => 'Rock', 'materialId' => 'mat_rock', 'minSlope' => 35.0],
        ];
        $args['splat'] = base64_encode(str_repeat("\xFF", 81).str_repeat("\x00", 81));
        $args['scatter'] = [[
            'id' => 'pines',
            'name' => 'Pines',
            'meshId' => 'pine',
            'seed' => 99,
            'density' => 0.2,
            'densityMap' => base64_encode(str_repeat("\x80", 81)),
            'maxSlope' => 25.0,
        ]];

        (new SaveTerrainCommand($args))->execute($this->context);
        $loaded = (new LoadTerrainCommand(['name' => 'painted']))->execute($this->context);

        $this->assertCount(2, $loaded['layers']);
        $this->assertSame('mat_rock', $loaded['layers'][1]['materialId']);
        $this->assertSame(35.0, $loaded['layers'][1]['minSlope']);
        $this->assertSame($args['splat'], $loaded['splat']);

        $this->assertCount(1, $loaded['scatter']);
        $this->assertSame(99, $loaded['scatter'][0]['seed']);
        $this->assertSame(25.0, $loaded['scatter'][0]['maxSlope']);
        $this->assertSame($args['densityMap'] ?? $args['scatter'][0]['densityMap'], $loaded['scatter'][0]['densityMap']);
    }

    public function test_save_rejects_a_splat_that_does_not_match_the_layer_count(): void
    {
        $args = $this->terrainArgs('painted');
        $args['layers'] = [['id' => 'grass', 'name' => 'Grass']];
        $args['splat'] = base64_encode(str_repeat("\xFF", 40)); // needs 81 bytes for 1 layer

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/splat payload/');

        (new SaveTerrainCommand($args))->execute($this->context);
    }

    public function test_save_rejects_a_density_map_that_does_not_match_the_grid(): void
    {
        $args = $this->terrainArgs('painted');
        $args['scatter'] = [['id' => 'pines', 'densityMap' => base64_encode(str_repeat("\x80", 12))]];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/density map/');

        (new SaveTerrainCommand($args))->execute($this->context);
    }

    public function test_load_reports_a_missing_terrain(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/');

        (new LoadTerrainCommand(['name' => 'nope']))->execute($this->context);
    }

    public function test_list_returns_saved_terrains_sorted(): void
    {
        (new SaveTerrainCommand($this->terrainArgs('valley')))->execute($this->context);
        (new SaveTerrainCommand($this->terrainArgs('alps')))->execute($this->context);

        $result = (new ListTerrainAssetsCommand)->execute($this->context);

        $this->assertSame(['alps', 'valley'], array_column($result['terrains'], 'name'));
        $this->assertSame('terrains/alps.terrain.json', $result['terrains'][0]['path']);
    }

    public function test_list_is_empty_without_a_terrains_directory(): void
    {
        $result = (new ListTerrainAssetsCommand)->execute($this->context);

        $this->assertSame([], $result['terrains']);
    }

    public function test_delete_removes_the_asset(): void
    {
        $saved = (new SaveTerrainCommand($this->terrainArgs('doomed')))->execute($this->context);

        $result = (new DeleteTerrainAssetCommand(['name' => 'doomed']))->execute($this->context);

        $this->assertTrue($result['deleted']);
        $this->assertFileDoesNotExist($saved['path']);
    }

    public function test_bake_produces_one_vertex_per_grid_sample(): void
    {
        (new SaveTerrainCommand($this->terrainArgs('island')))->execute($this->context);

        $result = (new BakeTerrainMeshCommand(['name' => 'island']))->execute($this->context);

        $this->assertFalse($result['chunked']);
        $this->assertCount(1, $result['meshes']);
        $this->assertSame(81, $result['meshes'][0]['vertexCount']);
        $this->assertSame(128, $result['meshes'][0]['triangleCount']);
    }

    public function test_bake_chunked_splits_into_the_configured_chunks(): void
    {
        (new SaveTerrainCommand($this->terrainArgs('island')))->execute($this->context);

        $result = (new BakeTerrainMeshCommand(['name' => 'island', 'chunked' => true]))->execute($this->context);

        $this->assertCount(4, $result['meshes'], '8 quads / chunk size 4 = 2x2 chunks');
        $this->assertSame('island_c0_0', $result['meshes'][0]['name']);
        $this->assertSame(25, $result['meshes'][0]['vertexCount']);
    }

    public function test_bake_accepts_an_inline_unsaved_terrain(): void
    {
        $result = (new BakeTerrainMeshCommand(['terrain' => $this->terrainArgs('scratch')]))
            ->execute($this->context);

        $this->assertSame(81, $result['meshes'][0]['vertexCount']);
    }

    public function test_bake_can_write_a_raw_mesh_asset(): void
    {
        (new SaveTerrainCommand($this->terrainArgs('island')))->execute($this->context);

        $result = (new BakeTerrainMeshCommand(['name' => 'island', 'save' => true]))->execute($this->context);

        $this->assertSame('meshes/island.mesh.json', $result['meshes'][0]['relativePath']);
        $file = $this->projectDir.'/assets/meshes/island.mesh.json';
        $this->assertFileExists($file);

        // Must be the same shape SaveMeshCommand writes, so the export opens in
        // the mesh editor like any other baked mesh.
        $decoded = json_decode((string) file_get_contents($file), true);
        $this->assertArrayHasKey('raw', $decoded);
        $this->assertCount(81 * 3, $decoded['raw']['vertices']);
        $this->assertCount(81 * 2, $decoded['raw']['uvs']);
    }

    public function test_bake_geometry_follows_the_heightmap(): void
    {
        (new SaveTerrainCommand($this->terrainArgs('island')))->execute($this->context);

        $result = (new BakeTerrainMeshCommand(['name' => 'island']))->execute($this->context);
        $vertices = $result['meshes'][0]['vertices'];

        // Terrain ramps along X: first vertex sits at the low-X corner and the
        // last at the high-X corner of the ramp.
        $this->assertEqualsWithDelta(-8.0, $vertices[0], 1e-6);
        $this->assertEqualsWithDelta(-4.0, $vertices[1], 1e-3, 'height at x=-8 on a 0.5*x ramp');
        $this->assertEqualsWithDelta(-8.0, $vertices[2], 1e-6);
    }

    public function test_place_in_scene_writes_terrain_and_collider_components(): void
    {
        (new SaveTerrainCommand($this->terrainArgs('island')))->execute($this->context);
        $this->context->activeDocument = new SceneDocument(['name' => 'test', 'entities' => []]);

        $result = (new CreateTerrainEntityCommand(['name' => 'island']))->execute($this->context);

        $doc = $this->context->getActiveDocument();
        $this->assertNotNull($doc);
        $entity = $doc->getEntity($result['created']);
        $this->assertNotNull($entity);

        $classes = array_column($entity['components'], '_class');
        $this->assertContains('PHPolygon\\Component\\Terrain', $classes);
        $this->assertContains('PHPolygon\\Component\\HeightmapCollider3D', $classes);
        $this->assertSame(0, $result['scatterSets']);
    }

    public function test_place_in_scene_carries_scatter_sets_into_the_component(): void
    {
        // The engine's TerrainScatter reads exactly this set shape, so the
        // editor's authoring format has to arrive verbatim.
        $args = $this->terrainArgs('wooded');
        $args['scatter'] = [[
            'id' => 'pines',
            'meshId' => 'pine',
            'materialId' => 'bark',
            'seed' => 77,
            'density' => 0.2,
            'densityMap' => base64_encode(str_repeat("\xFF", 81)),
            'maxSlope' => 25.0,
        ]];
        (new SaveTerrainCommand($args))->execute($this->context);
        $this->context->activeDocument = new SceneDocument(['name' => 'test', 'entities' => []]);

        $result = (new CreateTerrainEntityCommand(['name' => 'wooded']))->execute($this->context);

        $this->assertSame(1, $result['scatterSets']);

        $doc = $this->context->getActiveDocument();
        $this->assertNotNull($doc);
        $entity = $doc->getEntity($result['created']);
        $this->assertNotNull($entity);

        $scatter = null;
        foreach ($entity['components'] as $component) {
            if ($component['_class'] === 'PHPolygon\\Component\\TerrainScatter') {
                $scatter = $component;
            }
        }

        // SceneDocument stores component properties flat alongside `_class`.
        $this->assertNotNull($scatter, 'the scatter component must be attached');
        $this->assertCount(1, $scatter['sets']);
        $this->assertSame('pine', $scatter['sets'][0]['meshId']);
        $this->assertSame(77, $scatter['sets'][0]['seed']);
        $this->assertSame(25.0, $scatter['sets'][0]['maxSlope']);
    }

    public function test_place_in_scene_omits_the_scatter_component_when_there_is_none(): void
    {
        (new SaveTerrainCommand($this->terrainArgs('bare')))->execute($this->context);
        $this->context->activeDocument = new SceneDocument(['name' => 'test', 'entities' => []]);

        $result = (new CreateTerrainEntityCommand(['name' => 'bare']))->execute($this->context);

        $doc = $this->context->getActiveDocument();
        $this->assertNotNull($doc);
        $entity = $doc->getEntity($result['created']);
        $this->assertNotNull($entity);
        $this->assertNotContains(
            'PHPolygon\\Component\\TerrainScatter',
            array_column($entity['components'], '_class'),
        );
    }

    public function test_place_in_scene_requires_an_active_document(): void
    {
        (new SaveTerrainCommand($this->terrainArgs('island')))->execute($this->context);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/active scene document/');

        (new CreateTerrainEntityCommand(['name' => 'island']))->execute($this->context);
    }

    public function test_bake_requires_a_source(): void
    {
        $this->expectException(RuntimeException::class);

        (new BakeTerrainMeshCommand([]))->execute($this->context);
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
