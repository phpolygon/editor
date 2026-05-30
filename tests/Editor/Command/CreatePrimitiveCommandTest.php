<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Command;

use PHPUnit\Framework\TestCase;
use PHPolygon\Editor\Command\CreatePrimitiveCommand;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Editor\SceneDocument;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Scene\Transpiler\SceneTranspiler;

class CreatePrimitiveCommandTest extends TestCase
{
    private EditorContext $context;

    protected function setUp(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();

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
            projectDir: '/tmp/test-project',
        );
        $this->context->activeDocument = new SceneDocument([
            'name' => 'test',
            'entities' => [],
        ]);
    }

    protected function tearDown(): void
    {
        MeshRegistry::clear();
        MaterialRegistry::clear();
    }

    public function testCreateBoxRegistersMeshAndMaterial(): void
    {
        $result = (new CreatePrimitiveCommand(['type' => 'box']))->execute($this->context);

        $this->assertSame('Box', $result['created']);
        $this->assertSame('editor_primitive_box', $result['meshId']);
        $this->assertTrue(MeshRegistry::has('editor_primitive_box'));
        $this->assertTrue(MaterialRegistry::has('editor_default_material'));
    }

    public function testCreatedEntityHasTransformAndMeshRenderer(): void
    {
        (new CreatePrimitiveCommand(['type' => 'sphere']))->execute($this->context);

        $entity = $this->context->activeDocument->getEntity('Sphere');
        $this->assertNotNull($entity);

        $classes = array_column($entity['components'], '_class');
        $this->assertContains('PHPolygon\\Component\\Transform3D', $classes);
        $this->assertContains('PHPolygon\\Component\\MeshRenderer', $classes);

        $meshComp = null;
        foreach ($entity['components'] as $c) {
            if ($c['_class'] === 'PHPolygon\\Component\\MeshRenderer') {
                $meshComp = $c;
                break;
            }
        }
        $this->assertSame('editor_primitive_sphere', $meshComp['meshId']);
        $this->assertSame('editor_default_material', $meshComp['materialId']);
    }

    public function testNameCollisionGetsSuffix(): void
    {
        (new CreatePrimitiveCommand(['type' => 'box']))->execute($this->context);
        $second = (new CreatePrimitiveCommand(['type' => 'box']))->execute($this->context);

        $this->assertSame('Box_2', $second['created']);
    }

    public function testCreateUnderParent(): void
    {
        (new CreatePrimitiveCommand(['type' => 'box', 'name' => 'Root']))->execute($this->context);
        (new CreatePrimitiveCommand(['type' => 'sphere', 'parent' => 'Root']))->execute($this->context);

        $root = $this->context->activeDocument->getEntity('Root');
        $this->assertNotEmpty($root['children']);
        $this->assertSame('Sphere', $root['children'][0]['name']);
    }

    public function testUnknownTypeThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        (new CreatePrimitiveCommand(['type' => 'pyramid']))->execute($this->context);
    }

    public function testMeshIsReusedAcrossCalls(): void
    {
        (new CreatePrimitiveCommand(['type' => 'cylinder']))->execute($this->context);
        $countAfterFirst = count(MeshRegistry::ids());
        (new CreatePrimitiveCommand(['type' => 'cylinder']))->execute($this->context);
        $this->assertCount($countAfterFirst, MeshRegistry::ids());
    }
}
