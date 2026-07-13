<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Command;

use PHPolygon\Editor\Command\CreateSpriteCommand;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Editor\SceneDocument;
use PHPolygon\Scene\Transpiler\SceneTranspiler;
use PHPUnit\Framework\TestCase;

class CreateSpriteCommandTest extends TestCase
{
    private EditorContext $context;

    protected function setUp(): void
    {
        $this->context = new EditorContext(
            manifest: new ProjectManifest(
                name: 'Test',
                version: '0.1.0',
                engineVersion: '*',
                scenesPath: 'src/Scenes',
                assetsPath: 'assets',
                psr4Roots: [],
                entryScene: '',
                defaultMode: '2d',
            ),
            components: new ComponentRegistry,
            systems: new SystemRegistry,
            transpiler: new SceneTranspiler,
            projectDir: '/tmp/test-project',
        );
        $this->context->activeDocument = new SceneDocument([
            'name' => 'test',
            'entities' => [],
        ]);
    }

    public function test_creates_entity_with_transform2d_and_sprite_renderer(): void
    {
        $result = (new CreateSpriteCommand)->execute($this->context);

        $this->assertSame('Sprite', $result['created']);

        $entity = $this->context->activeDocument->getEntity('Sprite');
        $this->assertNotNull($entity);

        $classes = array_column($entity['components'], '_class');
        $this->assertContains('PHPolygon\\Component\\Transform2D', $classes);
        $this->assertContains('PHPolygon\\Component\\SpriteRenderer', $classes);
    }

    public function test_sprite_has_sensible_defaults(): void
    {
        (new CreateSpriteCommand)->execute($this->context);
        $entity = $this->context->activeDocument->getEntity('Sprite');

        $sprite = null;
        foreach ($entity['components'] as $c) {
            if ($c['_class'] === 'PHPolygon\\Component\\SpriteRenderer') {
                $sprite = $c;
            }
        }

        $this->assertSame(64, $sprite['width']);
        $this->assertSame(64, $sprite['height']);
        $this->assertSame(1.0, $sprite['opacity']);
    }

    public function test_name_collision_gets_suffix(): void
    {
        (new CreateSpriteCommand)->execute($this->context);
        $second = (new CreateSpriteCommand)->execute($this->context);

        $this->assertSame('Sprite_2', $second['created']);
    }

    public function test_creates_under_parent(): void
    {
        (new CreateSpriteCommand(['name' => 'Root']))->execute($this->context);
        (new CreateSpriteCommand(['parent' => 'Root']))->execute($this->context);

        $root = $this->context->activeDocument->getEntity('Root');
        $this->assertNotEmpty($root['children']);
        $this->assertSame('Sprite', $root['children'][0]['name']);
    }

    public function test_throws_without_active_document(): void
    {
        $this->context->activeDocument = null;
        $this->expectException(\RuntimeException::class);
        (new CreateSpriteCommand)->execute($this->context);
    }
}
