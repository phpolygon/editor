<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Command;

use PHPolygon\Component\Transform3D;
use PHPolygon\Editor\Command\SpawnCodePrefabCommand;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Editor\SceneDocument;
use PHPolygon\Scene\Transpiler\SceneTranspiler;
use PHPUnit\Framework\TestCase;

class SpawnCodePrefabCommandTest extends TestCase
{
    private EditorContext $context;

    protected function setUp(): void
    {
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
            projectDir: sys_get_temp_dir(),
        );
        $this->context->activeDocument = new SceneDocument(['name' => 'test', 'entities' => []]);
    }

    public function test_spawns_prefab_reference_with_authored_components(): void
    {
        $result = (new SpawnCodePrefabCommand([
            'class' => 'CodeRescue\\Prefab\\TerminalPrefabDef',
            'name' => 'Terminal',
            'components' => [
                ['_class' => 'CodeRescue\\Component\\TerminalDesign', 'variant' => 'rust'],
                ['_class' => 'PHPolygon\\Component\\Transform3D', 'position' => ['x' => 3.0, 'y' => 0.0, 'z' => -4.0]],
            ],
        ]))->execute($this->context);

        self::assertSame('Terminal', $result['spawned']);
        self::assertSame('CodeRescue\\Prefab\\TerminalPrefabDef', $result['prefab']);

        $entity = $this->context->activeDocument->getEntity('Terminal');
        self::assertNotNull($entity);
        // Stored as a REFERENCE + authored overrides, NOT inlined geometry.
        self::assertSame('CodeRescue\\Prefab\\TerminalPrefabDef', $entity['prefab']);
        $classes = array_map(static fn (array $c): mixed => $c['_class'] ?? null, $entity['components']);
        self::assertContains('CodeRescue\\Component\\TerminalDesign', $classes);
        self::assertContains('PHPolygon\\Component\\Transform3D', $classes);
        self::assertNotContains('PHPolygon\\Component\\MeshRenderer', $classes);
    }

    public function test_prefab_field_survives_to_array_for_saving(): void
    {
        (new SpawnCodePrefabCommand([
            'class' => 'CodeRescue\\Prefab\\TerminalPrefabDef',
            'name' => 'Terminal',
            'components' => [['_class' => 'CodeRescue\\Component\\TerminalDesign', 'variant' => 'php']],
        ]))->execute($this->context);

        $data = $this->context->activeDocument->toArray();
        self::assertSame('CodeRescue\\Prefab\\TerminalPrefabDef', $data['entities'][0]['prefab']);
    }

    public function test_unique_names_on_repeated_spawns(): void
    {
        $args = ['class' => 'CodeRescue\\Prefab\\TerminalPrefabDef', 'name' => 'Terminal'];
        $a = (new SpawnCodePrefabCommand($args))->execute($this->context);
        $b = (new SpawnCodePrefabCommand($args))->execute($this->context);

        self::assertSame('Terminal', $a['spawned']);
        self::assertSame('Terminal_2', $b['spawned']);
    }

    public function test_missing_class_throws(): void
    {
        $this->expectExceptionMessage("Missing 'class'");
        (new SpawnCodePrefabCommand([]))->execute($this->context);
    }

    /**
     * An instance with no transform cannot be placed: the gizmo has nothing to
     * attach to, and `update_property` only writes into components that already
     * exist — so setting a position silently did nothing and every instance
     * stacked at the origin.
     */
    public function test_an_instance_without_components_still_gets_a_transform(): void
    {
        (new SpawnCodePrefabCommand([
            'class' => 'CodeRescue\\Prefab\\TerminalPrefabDef',
            'name' => 'Terminal',
        ]))->execute($this->context);

        $entity = $this->context->activeDocument->getEntity('Terminal');
        $classes = array_map(static fn (array $c): mixed => $c['_class'] ?? null, $entity['components']);

        self::assertContains('PHPolygon\\Component\\Transform3D', $classes);
    }

    public function test_an_authored_transform_is_not_duplicated(): void
    {
        (new SpawnCodePrefabCommand([
            'class' => 'CodeRescue\\Prefab\\TerminalPrefabDef',
            'name' => 'Terminal',
            'components' => [
                ['_class' => 'PHPolygon\\Component\\Transform3D', 'position' => ['x' => 3.0, 'y' => 0.0, 'z' => -4.0]],
            ],
        ]))->execute($this->context);

        $entity = $this->context->activeDocument->getEntity('Terminal');
        $transforms = array_filter(
            $entity['components'],
            static fn (array $c): bool => ($c['_class'] ?? '') === 'PHPolygon\\Component\\Transform3D',
        );

        self::assertCount(1, $transforms);
        self::assertSame(['x' => 3.0, 'y' => 0.0, 'z' => -4.0], reset($transforms)['position']);
    }

    public function test_a2d_project_gets_a_transform2d(): void
    {
        $this->context = new EditorContext(
            manifest: new ProjectManifest(
                name: 'Test',
                version: '0.1.0',
                engineVersion: '*',
                scenesPath: 'src/Scene',
                assetsPath: 'assets',
                psr4Roots: [],
                entryScene: '',
                defaultMode: '2d',
            ),
            components: new ComponentRegistry,
            systems: new SystemRegistry,
            transpiler: new SceneTranspiler,
            projectDir: sys_get_temp_dir(),
        );
        $this->context->activeDocument = new SceneDocument(['name' => 'test', 'entities' => []]);

        (new SpawnCodePrefabCommand([
            'class' => 'Game\\Prefab\\Coin',
            'name' => 'Coin',
        ]))->execute($this->context);

        $entity = $this->context->activeDocument->getEntity('Coin');
        $classes = array_map(static fn (array $c): mixed => $c['_class'] ?? null, $entity['components']);

        self::assertContains('PHPolygon\\Component\\Transform2D', $classes);
        self::assertNotContains('PHPolygon\\Component\\Transform3D', $classes);
    }

    public function test_the_added_transform_carries_schema_defaults(): void
    {
        // The inspector shows what the document holds, so a bare `_class` would
        // render an empty transform instead of an editable one.
        $components = new ComponentRegistry;
        $components->register(Transform3D::class);
        $this->context = new EditorContext(
            manifest: $this->context->manifest,
            components: $components,
            systems: new SystemRegistry,
            transpiler: new SceneTranspiler,
            projectDir: sys_get_temp_dir(),
        );
        $this->context->activeDocument = new SceneDocument(['name' => 'test', 'entities' => []]);

        (new SpawnCodePrefabCommand([
            'class' => 'CodeRescue\\Prefab\\TerminalPrefabDef',
            'name' => 'Terminal',
        ]))->execute($this->context);

        $entity = $this->context->activeDocument->getEntity('Terminal');
        $transform = $entity['components'][0];

        self::assertSame('PHPolygon\\Component\\Transform3D', $transform['_class']);
        self::assertArrayHasKey('position', $transform);
        self::assertArrayHasKey('scale', $transform);
    }
}
