<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Scene;

use PHPolygon\Editor\Scene\DroppedPropertyDetector;
use PHPUnit\Framework\TestCase;

class DroppedPropertyDetectorTest extends TestCase
{
    /** @param list<array<string, mixed>> $entities */
    private function scene(array $entities): array
    {
        return ['name' => 'Test', 'entities' => $entities];
    }

    public function testReportsValuesTheGeneratorLeftOut(): void
    {
        $scene = $this->scene([
            [
                'name' => 'Box',
                'components' => [
                    [
                        '_class' => 'PHPolygon\\Component\\ProceduralMesh',
                        'nodes' => [['id' => 'box', 'type' => 'box']],
                        'output' => 'box',
                    ],
                ],
                'children' => [],
            ],
        ]);

        $dropped = DroppedPropertyDetector::detect($scene, '->with(new ProceduralMesh());');

        $this->assertSame([
            ['entity' => 'Box', 'component' => 'PHPolygon\\Component\\ProceduralMesh', 'properties' => ['nodes', 'output']],
        ], $dropped);
        $this->assertStringContainsString('Box · ProceduralMesh (nodes, output)', (string) DroppedPropertyDetector::describe($dropped));
    }

    public function testSaysNothingWhenTheGeneratorWroteTheValues(): void
    {
        $scene = $this->scene([
            [
                'name' => 'Box',
                'components' => [
                    ['_class' => 'PHPolygon\\Component\\ProceduralMesh', 'output' => 'box'],
                ],
                'children' => [],
            ],
        ]);

        // What an engine that supports property components emits.
        $generated = '->with((static function (): ProceduralMesh { $c = new ProceduralMesh(); $c->output = \'box\'; return $c; })());';

        $this->assertSame([], DroppedPropertyDetector::detect($scene, $generated));
        $this->assertNull(DroppedPropertyDetector::describe([]));
    }

    public function testAComponentWithNoValuesIsNotWorthAWarning(): void
    {
        $scene = $this->scene([
            [
                'name' => 'Box',
                'components' => [
                    ['_class' => 'PHPolygon\\Component\\ProceduralMesh', 'nodes' => [], 'output' => '', 'meshId' => ''],
                ],
                'children' => [],
            ],
        ]);

        $this->assertSame([], DroppedPropertyDetector::detect($scene, '->with(new ProceduralMesh());'));
    }

    public function testConstructorComponentsAreNeverReported(): void
    {
        $scene = $this->scene([
            [
                'name' => 'Box',
                'components' => [
                    ['_class' => 'PHPolygon\\Component\\MeshRenderer', 'meshId' => 'crate'],
                ],
                'children' => [],
            ],
        ]);

        $generated = "->with(new MeshRenderer(meshId: 'crate'));";

        $this->assertSame([], DroppedPropertyDetector::detect($scene, $generated));
    }

    public function testChildEntitiesAreCheckedToo(): void
    {
        $scene = $this->scene([
            [
                'name' => 'Root',
                'components' => [],
                'children' => [
                    [
                        'name' => 'Ground',
                        'components' => [
                            ['_class' => 'PHPolygon\\Component\\Terrain', 'heights' => 'BASE64'],
                        ],
                        'children' => [],
                    ],
                ],
            ],
        ]);

        $dropped = DroppedPropertyDetector::detect($scene, '->with(new Terrain());');

        $this->assertSame([
            ['entity' => 'Ground', 'component' => 'PHPolygon\\Component\\Terrain', 'properties' => ['heights']],
        ], $dropped);
    }

    public function testDescribeSummarisesALongList(): void
    {
        $dropped = [];
        foreach (['A', 'B', 'C', 'D', 'E'] as $name) {
            $dropped[] = ['entity' => $name, 'component' => 'PHPolygon\\Component\\Terrain', 'properties' => ['heights']];
        }

        $message = (string) DroppedPropertyDetector::describe($dropped);

        $this->assertStringContainsString('and 2 more', $message);
    }
}
