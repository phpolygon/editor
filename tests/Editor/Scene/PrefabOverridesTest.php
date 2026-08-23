<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Scene;

use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\PointLight;
use PHPolygon\Component\Transform3D;
use PHPolygon\Editor\Command\RevertPrefabOverrideCommand;
use PHPolygon\Editor\Command\UpdatePropertiesCommand;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Editor\Scene\PrefabBaseline;
use PHPolygon\Editor\SceneDocument;
use PHPolygon\Rendering\Color;
use PHPolygon\Scene\EntityDeclaration;
use PHPolygon\Scene\Prefab;
use PHPolygon\Scene\SceneBuilder;
use PHPolygon\Scene\Transpiler\SceneTranspiler;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** A prefab with known values, so "override" and "inherited" are unambiguous. */
class OverrideProbePrefab extends Prefab
{
    public function build(SceneBuilder $builder): EntityDeclaration
    {
        return $builder->entity($this->getInstanceName())
            ->with(new Transform3D(position: $this->getPosition(), rotation: $this->getRotation(), scale: $this->getScale()))
            ->with(new MeshRenderer(meshId: 'lantern_post', materialId: 'metal'))
            ->with(new PointLight(color: new Color(1.0, 1.0, 1.0, 1.0), intensity: 2.0, radius: 8.0));
    }
}

class PrefabOverridesTest extends TestCase
{
    private const PREFAB = OverrideProbePrefab::class;

    private const TRANSFORM = 'PHPolygon\\Component\\Transform3D';

    private const MESH = 'PHPolygon\\Component\\MeshRenderer';

    private const LIGHT = 'PHPolygon\\Component\\PointLight';

    private EditorContext $context;

    protected function setUp(): void
    {
        PrefabBaseline::forget();

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
    }

    /** @param list<array<string, mixed>> $components */
    private function withInstance(array $components): void
    {
        $this->context->activeDocument = new SceneDocument([
            'name' => 'test',
            'entities' => [
                ['name' => 'Lantern_A', 'prefab' => self::PREFAB, 'components' => $components],
            ],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function authored(): array
    {
        return array_values($this->context->activeDocument->getEntity('Lantern_A')['components']);
    }

    /** @param array<string, mixed> $properties */
    private function write(string $component, array $properties): void
    {
        (new UpdatePropertiesCommand([
            'edits' => [['entity' => 'Lantern_A', 'component' => $component, 'properties' => $properties]],
        ]))->execute($this->context);
    }

    public function test_the_baseline_reports_what_build_produces(): void
    {
        $baseline = PrefabBaseline::for(self::PREFAB);

        $this->assertNotNull($baseline, 'a prefab this simple must be buildable in-process');
        $this->assertSame('lantern_post', $baseline[self::MESH]['meshId']);
        $this->assertSame(2.0, $baseline[self::LIGHT]['intensity']);
    }

    public function test_a_value_matching_the_prefab_is_not_stored_as_an_override(): void
    {
        $this->withInstance([['_class' => self::LIGHT, 'intensity' => 5.0]]);

        // Back to what the prefab already produces — the override should go,
        // not be recorded as "customised to the same value".
        $this->write(self::LIGHT, ['intensity' => 2.0]);

        $classes = array_column($this->authored(), '_class');
        $this->assertNotContains(self::LIGHT, $classes);
    }

    public function test_a_differing_value_is_kept(): void
    {
        $this->withInstance([]);

        $this->write(self::LIGHT, ['intensity' => 5.0]);

        $light = $this->componentOf(self::LIGHT);
        $this->assertNotNull($light);
        $this->assertSame(5.0, $light['intensity']);
    }

    public function test_only_the_matching_property_is_dropped(): void
    {
        $this->withInstance([]);

        $this->write(self::MESH, ['meshId' => 'lantern_post', 'materialId' => 'gold']);

        $mesh = $this->componentOf(self::MESH);
        $this->assertNotNull($mesh);
        $this->assertArrayNotHasKey('meshId', $mesh, 'meshId equals the prefab');
        $this->assertSame('gold', $mesh['materialId']);
    }

    public function test_an_int_matching_a_float_baseline_counts_as_equal(): void
    {
        // The document holds decoded JSON, the baseline freshly serialized PHP:
        // 2 and 2.0 are the same value and must not read as an override.
        $this->withInstance([]);

        $this->write(self::LIGHT, ['intensity' => 2]);

        $this->assertNull($this->componentOf(self::LIGHT));
    }

    public function test_placement_is_never_diffed_away(): void
    {
        // A transform equal to the prefab's origin still has to stay: it is the
        // instance's placement, and removing it leaves nothing to position by.
        $this->withInstance([]);

        $this->write(self::TRANSFORM, ['position' => ['x' => 0, 'y' => 0, 'z' => 0]]);

        $this->assertNotNull($this->componentOf(self::TRANSFORM));
    }

    public function test_pruning_shares_the_edits_undo_step(): void
    {
        $this->withInstance([['_class' => self::LIGHT, 'intensity' => 5.0]]);
        $doc = $this->context->activeDocument;

        $this->write(self::LIGHT, ['intensity' => 2.0]);
        $doc->undo();

        // One ctrl+Z restores the state before the edit, not a half-pruned one.
        // assertEquals, not assertSame: undo snapshots round-trip through JSON,
        // where a whole float can come back as an int.
        $light = $this->componentOf(self::LIGHT);
        $this->assertNotNull($light);
        $this->assertEquals(5.0, $light['intensity']);
    }

    public function test_revert_drops_the_override_so_the_instance_follows_the_prefab(): void
    {
        $this->withInstance([['_class' => self::LIGHT, 'intensity' => 5.0, 'radius' => 20.0]]);

        (new RevertPrefabOverrideCommand([
            'entity' => 'Lantern_A', 'component' => self::LIGHT,
        ]))->execute($this->context);

        $this->assertNull($this->componentOf(self::LIGHT));
    }

    public function test_revert_of_a_single_property_keeps_the_others(): void
    {
        $this->withInstance([['_class' => self::LIGHT, 'intensity' => 5.0, 'radius' => 20.0]]);

        (new RevertPrefabOverrideCommand([
            'entity' => 'Lantern_A', 'component' => self::LIGHT, 'property' => 'intensity',
        ]))->execute($this->context);

        $light = $this->componentOf(self::LIGHT);
        $this->assertNotNull($light);
        $this->assertArrayNotHasKey('intensity', $light);
        $this->assertSame(20.0, $light['radius']);
    }

    public function test_reverting_placement_restores_the_prefabs_values_and_keeps_the_component(): void
    {
        $this->withInstance([
            ['_class' => self::TRANSFORM, 'position' => ['x' => 9.0, 'y' => 9.0, 'z' => 9.0]],
        ]);

        (new RevertPrefabOverrideCommand([
            'entity' => 'Lantern_A', 'component' => self::TRANSFORM,
        ]))->execute($this->context);

        $transform = $this->componentOf(self::TRANSFORM);
        $this->assertNotNull($transform, 'placement must survive a revert');
        $this->assertSame(0.0, $transform['position']['x']);
    }

    public function test_reverting_on_a_plain_entity_is_an_error(): void
    {
        $this->context->activeDocument = new SceneDocument([
            'name' => 'test',
            'entities' => [['name' => 'Plain', 'components' => [['_class' => self::LIGHT, 'intensity' => 3.0]]]],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not a prefab instance/');
        (new RevertPrefabOverrideCommand([
            'entity' => 'Plain', 'component' => self::LIGHT,
        ]))->execute($this->context);
    }

    public function test_a_plain_entity_is_left_alone_by_pruning(): void
    {
        // Nothing to diff against: every value on a non-prefab entity is its own.
        $this->context->activeDocument = new SceneDocument([
            'name' => 'test',
            'entities' => [['name' => 'Plain', 'components' => [['_class' => self::LIGHT, 'intensity' => 1.0]]]],
        ]);

        (new UpdatePropertiesCommand([
            'edits' => [['entity' => 'Plain', 'component' => self::LIGHT, 'properties' => ['intensity' => 2.0]]],
        ]))->execute($this->context);

        $entity = $this->context->activeDocument->getEntity('Plain');
        $this->assertSame(2.0, $entity['components'][0]['intensity']);
    }

    public function test_an_unbuildable_prefab_still_records_the_override(): void
    {
        // No baseline means no basis for calling anything redundant — but the
        // edit the user asked for must still land, not vanish because the
        // editor could not build the prefab to check it against.
        $this->context->activeDocument = new SceneDocument([
            'name' => 'test',
            'entities' => [
                ['name' => 'Lantern_A', 'prefab' => 'Nope\\NotAClass', 'components' => []],
            ],
        ]);

        $this->write(self::LIGHT, ['intensity' => 2.0]);

        $light = $this->componentOf(self::LIGHT);
        $this->assertNotNull($light);
        $this->assertSame(2.0, $light['intensity']);
    }

    /** @return array<string, mixed>|null */
    private function componentOf(string $class): ?array
    {
        foreach ($this->authored() as $component) {
            if (($component['_class'] ?? '') === $class) {
                return $component;
            }
        }

        return null;
    }
}
