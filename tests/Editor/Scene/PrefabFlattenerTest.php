<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Scene;

use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\Editor\Scene\PrefabBaseline;
use PHPolygon\Editor\Scene\PrefabFlattener;
use PHPolygon\Scene\EntityDeclaration;
use PHPolygon\Scene\Prefab;
use PHPolygon\Scene\SceneBuilder;
use PHPUnit\Framework\TestCase;

/** A prefab with a child, so flattening has a subtree to expand. */
class FlattenProbePrefab extends Prefab
{
    public function build(SceneBuilder $builder): EntityDeclaration
    {
        return $builder->entity($this->getInstanceName())
            ->with(new Transform3D(position: $this->getPosition(), rotation: $this->getRotation(), scale: $this->getScale()))
            ->with(new MeshRenderer(meshId: 'post', materialId: 'metal'))
            ->child('Bulb')
            ->with(new MeshRenderer(meshId: 'bulb', materialId: 'glass'))
            ->end();
    }
}

class PrefabFlattenerTest extends TestCase
{
    private const PREFAB = FlattenProbePrefab::class;

    private const MESH = 'PHPolygon\\Component\\MeshRenderer';

    private const TRANSFORM = 'PHPolygon\\Component\\Transform3D';

    protected function setUp(): void
    {
        PrefabBaseline::forget();
    }

    /** @param list<array<string, mixed>> $components */
    private function instance(string $name, array $components = []): array
    {
        return ['name' => $name, 'prefab' => self::PREFAB, 'components' => $components];
    }

    public function test_a_reference_becomes_the_entities_it_stands_for(): void
    {
        $result = PrefabFlattener::flatten([$this->instance('L1')]);

        $this->assertSame(1, $result['expanded']);
        $entity = $result['entities'][0];
        $this->assertArrayNotHasKey('prefab', $entity, 'the reference is gone');
        $this->assertSame('L1', $entity['name']);
        $this->assertCount(1, $entity['children']);
    }

    public function test_overrides_are_merged_onto_the_prefabs_components(): void
    {
        $result = PrefabFlattener::flatten([
            $this->instance('L1', [['_class' => self::MESH, 'materialId' => 'gold']]),
        ]);

        $mesh = $this->componentOf($result['entities'][0], self::MESH);
        $this->assertSame('gold', $mesh['materialId'], 'the override wins');
        $this->assertSame('post', $mesh['meshId'], 'and the rest still comes from the prefab');
    }

    public function test_placement_reaches_the_flattened_transform(): void
    {
        $result = PrefabFlattener::flatten([
            $this->instance('L1', [[
                '_class' => self::TRANSFORM,
                'position' => ['x' => 4.0, 'y' => 0.0, 'z' => 1.0],
            ]]),
        ]);

        $transform = $this->componentOf($result['entities'][0], self::TRANSFORM);
        $this->assertSame(4.0, $transform['position']['x']);
    }

    public function test_two_instances_do_not_collide_on_child_names(): void
    {
        // Both prefabs name their part "Bulb"; a flattened scene with two of
        // them would otherwise have two entities of the same name.
        $result = PrefabFlattener::flatten([$this->instance('L1'), $this->instance('L2')]);

        $names = [
            $result['entities'][0]['children'][0]['name'],
            $result['entities'][1]['children'][0]['name'],
        ];

        $this->assertSame(['L1_Bulb', 'L2_Bulb'], $names);
        $this->assertCount(2, array_unique($names));
    }

    public function test_plain_entities_pass_through_untouched(): void
    {
        $plain = ['name' => 'Ground', 'components' => [['_class' => self::MESH, 'meshId' => 'plane']]];

        $result = PrefabFlattener::flatten([$plain]);

        $this->assertSame(0, $result['expanded']);
        $this->assertSame($plain, $result['entities'][0]);
    }

    public function test_nested_references_are_expanded_too(): void
    {
        $result = PrefabFlattener::flatten([
            ['name' => 'Street', 'components' => [], 'children' => [$this->instance('L1')]],
        ]);

        $this->assertSame(1, $result['expanded']);
        $this->assertArrayNotHasKey('prefab', $result['entities'][0]['children'][0]);
    }

    public function test_an_instances_own_children_survive_expansion(): void
    {
        $instance = $this->instance('L1');
        $instance['children'] = [['name' => 'Sign', 'components' => []]];

        $result = PrefabFlattener::flatten([$instance]);

        $names = array_column($result['entities'][0]['children'], 'name');
        $this->assertContains('L1_Bulb', $names, "the prefab's own part");
        $this->assertContains('Sign', $names, 'and what the scene attached to it');
    }

    public function test_an_unbuildable_prefab_stays_a_reference_and_is_reported(): void
    {
        // Silently dropping the object would be far worse than a scene that
        // still says what it meant.
        $result = PrefabFlattener::flatten([
            ['name' => 'Mystery', 'prefab' => 'Nope\\NotAClass', 'components' => []],
        ]);

        $this->assertSame(0, $result['expanded']);
        $this->assertSame(['Mystery'], $result['skipped']);
        $this->assertSame('Nope\\NotAClass', $result['entities'][0]['prefab']);
    }

    /**
     * @param  array<string, mixed>  $entity
     * @return array<string, mixed>
     */
    private function componentOf(array $entity, string $class): array
    {
        foreach ($entity['components'] as $component) {
            if (($component['_class'] ?? '') === $class) {
                return $component;
            }
        }

        $this->fail("component {$class} not found");
    }
}
