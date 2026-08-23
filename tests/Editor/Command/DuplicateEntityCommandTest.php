<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Command;

use PHPolygon\Editor\Command\CreateEntityCommand;
use PHPolygon\Editor\Command\DuplicateEntityCommand;
use PHPolygon\Editor\Command\RenameEntityCommand;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Editor\SceneDocument;
use PHPolygon\Scene\Transpiler\SceneTranspiler;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DuplicateEntityCommandTest extends TestCase
{
    private const TRANSFORM = 'PHPolygon\\Component\\Transform3D';

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

        $this->context->activeDocument = new SceneDocument([
            'name' => 'test',
            'entities' => [
                [
                    'name' => 'Lamp',
                    'components' => [['_class' => self::TRANSFORM, 'position' => ['x' => 3, 'y' => 0, 'z' => 0]]],
                    'children' => [
                        ['name' => 'Bulb', 'components' => [['_class' => self::TRANSFORM]]],
                    ],
                ],
                ['name' => 'Ground', 'components' => []],
            ],
        ]);
    }

    private function doc(): SceneDocument
    {
        return $this->context->activeDocument;
    }

    /** @param array<string, mixed> $args */
    private function duplicate(array $args): array
    {
        return (new DuplicateEntityCommand($args))->execute($this->context);
    }

    public function test_a_copy_lands_beside_the_original(): void
    {
        $result = $this->duplicate(['entity' => 'Lamp']);

        $this->assertSame(['Lamp_2'], $result['duplicated']);
        $this->assertNotNull($this->doc()->getEntity('Lamp'));
        $this->assertNotNull($this->doc()->getEntity('Lamp_2'));
        $this->assertCount(3, $this->doc()->getEntities());
    }

    public function test_components_are_copied_with_their_values(): void
    {
        $this->duplicate(['entity' => 'Lamp']);

        $copy = $this->doc()->getEntity('Lamp_2');
        $this->assertSame(['x' => 3, 'y' => 0, 'z' => 0], $copy['components'][0]['position']);
    }

    public function test_descendants_are_copied_and_renamed(): void
    {
        // A copied child keeping its name would leave two entities answering to
        // "Bulb", and every command addresses entities by name.
        $this->duplicate(['entity' => 'Lamp']);

        $copy = $this->doc()->getEntity('Lamp_2');
        $this->assertCount(1, $copy['children']);
        $this->assertSame('Bulb_2', $copy['children'][0]['name']);
    }

    public function test_a_copy_stays_under_the_same_parent(): void
    {
        $this->duplicate(['entity' => 'Bulb']);

        $lamp = $this->doc()->getEntity('Lamp');
        $names = array_column($lamp['children'], 'name');
        $this->assertSame(['Bulb', 'Bulb_2'], $names);
        // And not at the root.
        $this->assertCount(2, $this->doc()->getEntities());
    }

    public function test_a_prefab_instance_duplicates_as_an_instance(): void
    {
        $this->context->activeDocument = new SceneDocument([
            'name' => 'test',
            'entities' => [[
                'name' => 'L1',
                'prefab' => 'Game\\Prefab\\Lamp',
                'components' => [['_class' => self::TRANSFORM]],
            ]],
        ]);

        $this->duplicate(['entity' => 'L1']);

        $this->assertSame('Game\\Prefab\\Lamp', $this->doc()->getEntity('L1_2')['prefab']);
    }

    public function test_several_entities_duplicate_in_one_undo_step(): void
    {
        // Duplicating a selection is one gesture; one ctrl+Z has to undo it.
        $result = $this->duplicate(['entities' => ['Lamp', 'Ground']]);

        $this->assertSame(['Lamp_2', 'Ground_2'], $result['duplicated']);

        $this->doc()->undo();

        $this->assertNull($this->doc()->getEntity('Lamp_2'));
        $this->assertNull($this->doc()->getEntity('Ground_2'));
        $this->assertFalse($this->doc()->canUndo(), 'one gesture, one undo entry');
    }

    public function test_a_selected_child_of_a_selected_parent_is_not_copied_twice(): void
    {
        // Duplicating the parent already brings the child along.
        $result = $this->duplicate(['entities' => ['Lamp', 'Bulb']]);

        $this->assertSame(['Lamp'], $result['from']);
        $this->assertSame(['Lamp_2'], $result['duplicated']);
        $this->assertNull($this->doc()->getEntity('Bulb_3'));
    }

    public function test_duplicating_a_missing_entity_is_an_error(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/');
        $this->duplicate(['entity' => 'Nope']);
    }

    public function test_no_target_is_an_error(): void
    {
        $this->expectException(RuntimeException::class);
        $this->duplicate([]);
    }

    // --- the name invariant duplicates depend on ---------------------------

    public function test_creating_an_entity_never_reuses_a_name(): void
    {
        (new CreateEntityCommand(['name' => 'Lamp']))->execute($this->context);

        $this->assertNotNull($this->doc()->getEntity('Lamp_2'));
        $this->assertCount(3, $this->doc()->getEntities());
    }

    public function test_renaming_onto_an_existing_name_is_made_unique(): void
    {
        // Otherwise one of the two becomes unaddressable: every command takes a
        // name, and the document answers with the first match.
        $result = (new RenameEntityCommand(['oldName' => 'Ground', 'newName' => 'Lamp']))
            ->execute($this->context);

        $this->assertSame('Lamp_2', $result['newName']);
        $this->assertNotNull($this->doc()->getEntity('Lamp'));
        $this->assertNotNull($this->doc()->getEntity('Lamp_2'));
    }

    public function test_renaming_an_entity_to_its_own_name_changes_nothing(): void
    {
        $result = (new RenameEntityCommand(['oldName' => 'Lamp', 'newName' => 'Lamp']))
            ->execute($this->context);

        $this->assertSame('Lamp', $result['newName']);
        $this->assertNull($this->doc()->getEntity('Lamp_2'));
    }

    public function test_renaming_a_missing_entity_is_an_error(): void
    {
        $this->expectException(RuntimeException::class);
        (new RenameEntityCommand(['oldName' => 'Nope', 'newName' => 'X']))->execute($this->context);
    }
}
