<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Command;

use PHPolygon\Component\Transform2D;
use PHPolygon\Editor\Command\AddComponentCommand;
use PHPolygon\Editor\Command\CreateEntityCommand;
use PHPolygon\Editor\Command\DeleteEntityCommand;
use PHPolygon\Editor\Command\EditorCommandBus;
use PHPolygon\Editor\Command\GetEntityHierarchyCommand;
use PHPolygon\Editor\Command\ListComponentsCommand;
use PHPolygon\Editor\Command\UpdatePropertiesCommand;
use PHPolygon\Editor\Command\UpdatePropertyCommand;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Editor\SceneDocument;
use PHPolygon\Scene\Transpiler\SceneTranspiler;
use PHPUnit\Framework\TestCase;

class EditorCommandsTest extends TestCase
{
    private EditorContext $context;

    protected function setUp(): void
    {
        $manifest = new ProjectManifest(
            name: 'TestProject',
            version: '0.1.0',
            engineVersion: '*',
            scenesPath: 'src/Scene',
            assetsPath: 'assets',
            psr4Roots: ['TestProject\\' => 'src/'],
            entryScene: 'MainMenu',
        );

        $components = new ComponentRegistry;
        $components->register(Transform2D::class);

        $this->context = new EditorContext(
            manifest: $manifest,
            components: $components,
            systems: new SystemRegistry,
            transpiler: new SceneTranspiler,
            projectDir: '/tmp/test-project',
        );

        $this->context->activeDocument = new SceneDocument([
            'name' => 'test',
            'entities' => [
                [
                    'name' => 'Camera',
                    'components' => [
                        ['_class' => Transform2D::class, 'position' => ['x' => 0, 'y' => 0]],
                    ],
                ],
            ],
        ]);
    }

    public function test_list_components(): void
    {
        $cmd = new ListComponentsCommand;
        $result = $cmd->execute($this->context);

        $this->assertArrayHasKey('components', $result);
        $this->assertArrayHasKey(Transform2D::class, $result['components']);
    }

    public function test_list_components_grouped(): void
    {
        $cmd = new ListComponentsCommand(['grouped' => true]);
        $result = $cmd->execute($this->context);

        $this->assertArrayHasKey('categories', $result);
        $this->assertArrayHasKey('Core', $result['categories']);
    }

    public function test_create_entity(): void
    {
        $cmd = new CreateEntityCommand(['name' => 'Player']);
        $result = $cmd->execute($this->context);

        $this->assertSame('Player', $result['created']);
        $this->assertNotNull($this->context->activeDocument->getEntity('Player'));
    }

    public function test_delete_entity(): void
    {
        $cmd = new DeleteEntityCommand(['name' => 'Camera']);
        $cmd->execute($this->context);

        $this->assertNull($this->context->activeDocument->getEntity('Camera'));
    }

    public function test_add_component(): void
    {
        $cmd = new AddComponentCommand([
            'entity' => 'Camera',
            'component' => Transform2D::class,
        ]);
        $cmd->execute($this->context);

        $camera = $this->context->activeDocument->getEntity('Camera');
        $this->assertCount(2, $camera['components']);
    }

    public function test_update_property(): void
    {
        $cmd = new UpdatePropertyCommand([
            'entity' => 'Camera',
            'component' => Transform2D::class,
            'property' => 'position',
            'value' => ['x' => 50, 'y' => 100],
        ]);
        $cmd->execute($this->context);

        $camera = $this->context->activeDocument->getEntity('Camera');
        $this->assertSame(['x' => 50, 'y' => 100], $camera['components'][0]['position']);
    }

    public function test_update_properties_writes_every_field(): void
    {
        $cmd = new UpdatePropertiesCommand([
            'edits' => [
                [
                    'entity' => 'Camera',
                    'component' => Transform2D::class,
                    'properties' => ['position' => ['x' => 7, 'y' => 8], 'rotation' => 1.5],
                ],
            ],
        ]);
        $result = $cmd->execute($this->context);

        $this->assertSame(1, $result['updated']);
        $camera = $this->context->activeDocument->getEntity('Camera');
        $this->assertSame(['x' => 7, 'y' => 8], $camera['components'][0]['position']);
        $this->assertSame(1.5, $camera['components'][0]['rotation']);
    }

    /**
     * The whole point of the batched command: a gizmo drag rewrites several
     * fields, and one ctrl+Z has to take all of them back.
     */
    public function test_update_properties_is_one_undo_step(): void
    {
        $doc = $this->context->activeDocument;
        (new UpdatePropertiesCommand([
            'edits' => [
                [
                    'entity' => 'Camera',
                    'component' => Transform2D::class,
                    'properties' => ['position' => ['x' => 7, 'y' => 8], 'rotation' => 1.5],
                ],
            ],
        ]))->execute($this->context);

        $doc->undo();

        $camera = $doc->getEntity('Camera');
        $this->assertSame(['x' => 0, 'y' => 0], $camera['components'][0]['position']);
        $this->assertArrayNotHasKey('rotation', $camera['components'][0]);
        $this->assertFalse($doc->canUndo(), 'one batched edit must leave exactly one undo entry');
    }

    public function test_update_properties_spans_entities_in_one_step(): void
    {
        $doc = $this->context->activeDocument;
        $doc->addEntity('Player');
        $doc->addComponent('Player', Transform2D::class, ['position' => ['x' => 0, 'y' => 0]]);

        (new UpdatePropertiesCommand([
            'edits' => [
                ['entity' => 'Camera', 'component' => Transform2D::class, 'properties' => ['position' => ['x' => 1, 'y' => 1]]],
                ['entity' => 'Player', 'component' => Transform2D::class, 'properties' => ['position' => ['x' => 2, 'y' => 2]]],
            ],
        ]))->execute($this->context);

        $this->assertSame(['x' => 1, 'y' => 1], $doc->getEntity('Camera')['components'][0]['position']);
        $this->assertSame(['x' => 2, 'y' => 2], $doc->getEntity('Player')['components'][0]['position']);

        $doc->undo();

        $this->assertSame(['x' => 0, 'y' => 0], $doc->getEntity('Camera')['components'][0]['position']);
        $this->assertSame(['x' => 0, 'y' => 0], $doc->getEntity('Player')['components'][0]['position']);
    }

    public function test_update_properties_rejects_missing_edits(): void
    {
        $this->expectException(\RuntimeException::class);
        (new UpdatePropertiesCommand([]))->execute($this->context);
    }

    public function test_get_entity_hierarchy(): void
    {
        $cmd = new GetEntityHierarchyCommand;
        $result = $cmd->execute($this->context);

        $this->assertArrayHasKey('entities', $result);
        $this->assertCount(1, $result['entities']);
        $this->assertSame('Camera', $result['entities'][0]['name']);
    }

    public function test_command_bus_dispatch(): void
    {
        $bus = new EditorCommandBus($this->context);
        $bus->register('ListComponents', ListComponentsCommand::class);

        $result = $bus->dispatch('ListComponents');
        $this->assertArrayHasKey('components', $result);
    }

    public function test_command_bus_unknown_throws(): void
    {
        $bus = new EditorCommandBus($this->context);
        $this->expectException(\RuntimeException::class);
        $bus->dispatch('NonExistent');
    }
}
