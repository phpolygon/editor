<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Command;

use PHPolygon\Editor\Command\ConvertPrefabAssetCommand;
use PHPolygon\Editor\Command\CreatePrefabClassCommand;
use PHPolygon\Editor\Command\LoadPrefabClassCommand;
use PHPolygon\Editor\Command\SaveSceneCommand;
use PHPolygon\Editor\Command\SpawnCodePrefabCommand;
use PHPolygon\Editor\Command\UpdatePropertiesCommand;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Editor\Scene\PrefabBaseline;
use PHPolygon\Editor\SceneDocument;
use PHPolygon\Scene\Transpiler\SceneTranspiler;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The round trip a prefab makes through the editor: authored from a scene into
 * a class, opened again for editing, written back, and — for legacy JSON
 * prefabs — converted into the same form.
 */
class PrefabLifecycleTest extends TestCase
{
    private const MESH = 'PHPolygon\\Component\\MeshRenderer';

    private const TRANSFORM = 'PHPolygon\\Component\\Transform3D';

    private string $projectDir;

    private EditorContext $context;

    protected function setUp(): void
    {
        PrefabBaseline::forget();

        $this->projectDir = sys_get_temp_dir().'/phpolygon-editor-lifecycle-'.uniqid();
        mkdir($this->projectDir.'/assets/prefabs', 0o755, true);

        $this->context = new EditorContext(
            manifest: new ProjectManifest(
                name: 'Test',
                version: '0.1.0',
                engineVersion: '*',
                scenesPath: 'src/Scene',
                assetsPath: 'assets',
                psr4Roots: ['Lifecycle\\' => 'src'],
                entryScene: '',
            ),
            components: new ComponentRegistry,
            systems: new SystemRegistry,
            transpiler: new SceneTranspiler,
            projectDir: $this->projectDir,
        );

        $this->context->activeDocument = new SceneDocument([
            'name' => 'source',
            'entities' => [[
                'name' => 'Lamp',
                'components' => [
                    ['_class' => self::TRANSFORM],
                    ['_class' => self::MESH, 'meshId' => 'post', 'materialId' => 'metal'],
                ],
                'children' => [[
                    'name' => 'Bulb',
                    'components' => [['_class' => self::MESH, 'meshId' => 'bulb', 'materialId' => 'glass']],
                ]],
            ]],
        ]);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->projectDir);
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
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /** Distinct per test: a PHP class can only be declared once per process. */
    private static int $sequence = 0;

    /**
     * Author the fixture entity into a prefab class and load it.
     *
     * @return array<string, mixed>
     */
    private function createPrefab(): array
    {
        self::$sequence++;
        $result = (new CreatePrefabClassCommand([
            'entityName' => 'Lamp',
            'className' => 'Lamp'.self::$sequence,
            'overwrite' => true,
        ]))->execute($this->context);
        require_once $result['path'];

        return $result;
    }

    // --- 2.3: editing a prefab -------------------------------------------

    public function test_a_prefab_opens_as_an_editable_document(): void
    {
        $prefab = $this->createPrefab();

        $loaded = (new LoadPrefabClassCommand(['class' => $prefab['class']]))->execute($this->context);

        $this->assertSame($prefab['class'], $loaded['editingPrefab']);
        $this->assertSame($prefab['className'], $loaded['entities'][0]['name']);
        // Its parts come along, or there would be nothing to edit but the root.
        $this->assertSame('Bulb', $loaded['entities'][0]['children'][0]['name']);
        // And it became the active document, so the usual commands apply.
        $this->assertNotNull($this->context->activeDocument->getEntity($prefab['className']));
    }

    public function test_editing_a_prefab_and_saving_it_back_changes_what_instances_get(): void
    {
        $prefab = $this->createPrefab();
        (new LoadPrefabClassCommand(['class' => $prefab['class']]))->execute($this->context);

        (new UpdatePropertiesCommand([
            'edits' => [[
                'entity' => $prefab['className'],
                'component' => self::MESH,
                'properties' => ['materialId' => 'brass'],
            ]],
        ]))->execute($this->context);
        $again = (new CreatePrefabClassCommand([
            'entityName' => $prefab['className'],
            'className' => $prefab['className'],
            'overwrite' => true,
        ]))->execute($this->context);

        $this->assertTrue($again['replaced']);
        $this->assertStringContainsString("materialId: 'brass'", (string) file_get_contents($again['path']));
    }

    public function test_a_hand_edited_prefab_opens_but_reports_it_cannot_be_rewritten(): void
    {
        // Opening to look is fine; the warning is what stops the save from
        // quietly eating someone's code.
        $prefab = $this->createPrefab();
        file_put_contents(
            $prefab['path'],
            str_replace('extends Prefab', 'extends Prefab /* mine */', (string) file_get_contents($prefab['path'])),
        );

        $loaded = (new LoadPrefabClassCommand(['class' => $prefab['class']]))->execute($this->context);

        $this->assertFalse($loaded['writable']);
    }

    public function test_a_freshly_generated_prefab_is_writable(): void
    {
        $prefab = $this->createPrefab();

        $loaded = (new LoadPrefabClassCommand(['class' => $prefab['class']]))->execute($this->context);

        $this->assertTrue($loaded['writable']);
    }

    public function test_opening_a_prefab_that_cannot_be_built_is_an_error(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cannot be opened|Cannot build/');
        (new LoadPrefabClassCommand(['class' => 'Nope\\NotAClass']))->execute($this->context);
    }

    // --- 2.4: flattening on save -----------------------------------------

    public function test_saving_normally_keeps_the_reference(): void
    {
        $prefab = $this->createPrefab();
        $this->sceneWithInstance($prefab['class']);

        $saved = (new SaveSceneCommand)->execute($this->context);
        $php = (string) file_get_contents($saved['saved']);

        $this->assertStringContainsString('prefabInstance', $php);
        $this->assertArrayNotHasKey('flattened', $saved);
    }

    public function test_saving_flattened_writes_the_entities_instead(): void
    {
        $prefab = $this->createPrefab();
        $this->sceneWithInstance($prefab['class']);

        $saved = (new SaveSceneCommand(['flatten' => true]))->execute($this->context);
        $php = (string) file_get_contents($saved['saved']);

        $this->assertSame(1, $saved['flattened']);
        $this->assertStringNotContainsString('prefabInstance', $php);
        $this->assertStringContainsString("entity('L1')", $php);
        $this->assertStringContainsString('L1_Bulb', $php, "the prefab's parts are written out");
    }

    public function test_flattening_leaves_the_document_alone(): void
    {
        // It is a projection: the editor still shows an instance afterwards,
        // and the next plain save restores the compact form.
        $prefab = $this->createPrefab();
        $this->sceneWithInstance($prefab['class']);

        (new SaveSceneCommand(['flatten' => true]))->execute($this->context);
        $entity = $this->context->activeDocument->getEntity('L1');

        $this->assertSame($prefab['class'], $entity['prefab']);
    }

    public function test_a_reference_that_cannot_be_expanded_is_reported(): void
    {
        $this->context->activeDocument = new SceneDocument([
            'name' => 'scene',
            'entities' => [['name' => 'Mystery', 'prefab' => 'Nope\\NotAClass', 'components' => []]],
        ]);

        $saved = (new SaveSceneCommand(['flatten' => true]))->execute($this->context);

        $this->assertSame(['Mystery'], $saved['notFlattened']);
        $this->assertStringContainsString('Kept as prefab references', (string) $saved['warning']);
    }

    // --- 2.5: converting a JSON prefab -----------------------------------

    private function writeJsonPrefab(string $name = 'street_lamp'): string
    {
        $payload = [
            'name' => $name,
            'root' => [
                'name' => 'StreetLamp',
                'components' => [
                    ['_class' => self::TRANSFORM],
                    ['_class' => self::MESH, 'meshId' => 'legacy_post', 'materialId' => 'iron'],
                ],
                'children' => [[
                    'name' => 'Head',
                    'components' => [['_class' => self::MESH, 'meshId' => 'legacy_head', 'materialId' => 'glass']],
                ]],
            ],
        ];
        file_put_contents(
            $this->projectDir.'/assets/prefabs/'.$name.'.prefab.json',
            (string) json_encode($payload),
        );

        return 'prefabs/'.$name.'.prefab.json';
    }

    public function test_a_json_prefab_converts_into_a_class(): void
    {
        $path = $this->writeJsonPrefab();

        $result = (new ConvertPrefabAssetCommand(['path' => $path]))->execute($this->context);

        $this->assertTrue($result['converted']);
        $this->assertSame('Lifecycle\\Prefab\\StreetLamp', $result['class']);
        $source = (string) file_get_contents($result['path']);
        $this->assertStringContainsString('class StreetLamp extends Prefab', $source);
        $this->assertStringContainsString("meshId: 'legacy_post'", $source);
        $this->assertStringContainsString("->child('Head')", $source);
    }

    public function test_conversion_leaves_the_json_file_in_place(): void
    {
        // Scenes that already inlined a copy still resolve against it.
        $path = $this->writeJsonPrefab();

        (new ConvertPrefabAssetCommand(['path' => $path]))->execute($this->context);

        $this->assertFileExists($this->projectDir.'/assets/'.$path);
    }

    public function test_conversion_will_not_overwrite_a_hand_edited_class(): void
    {
        $path = $this->writeJsonPrefab();
        $first = (new ConvertPrefabAssetCommand(['path' => $path]))->execute($this->context);
        file_put_contents($first['path'], "<?php\n// mine\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/edited by hand/');
        (new ConvertPrefabAssetCommand(['path' => $path]))->execute($this->context);
    }

    public function test_conversion_rejects_a_path_outside_assets(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/');
        (new ConvertPrefabAssetCommand(['path' => '../../../etc/passwd']))->execute($this->context);
    }

    public function test_conversion_rejects_a_file_that_is_not_a_prefab(): void
    {
        file_put_contents($this->projectDir.'/assets/prefabs/broken.prefab.json', '{"nope": true}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Invalid prefab format/');
        (new ConvertPrefabAssetCommand(['path' => 'prefabs/broken.prefab.json']))->execute($this->context);
    }

    private function sceneWithInstance(string $prefabClass): void
    {
        $this->context->activeDocument = new SceneDocument(['name' => 'scene', 'entities' => []]);
        (new SpawnCodePrefabCommand(['class' => $prefabClass, 'name' => 'L1']))->execute($this->context);
    }
}
