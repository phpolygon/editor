<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests;

use PHPolygon\Editor\SceneDocument;
use PHPUnit\Framework\TestCase;

class SceneDocumentTest extends TestCase
{
    private function createTestDocument(): SceneDocument
    {
        return new SceneDocument([
            'name' => 'test_scene',
            'entities' => [
                [
                    'name' => 'Camera',
                    'components' => [
                        ['_class' => 'Transform2D', 'position' => ['x' => 0, 'y' => 0]],
                    ],
                ],
                [
                    'name' => 'Player',
                    'components' => [
                        ['_class' => 'Transform2D', 'position' => ['x' => 100, 'y' => 200]],
                        ['_class' => 'SpriteRenderer', 'textureId' => 'player'],
                    ],
                    'children' => [
                        [
                            'name' => 'Weapon',
                            'components' => [
                                ['_class' => 'Transform2D', 'position' => ['x' => 20, 'y' => 0]],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function test_get_entity(): void
    {
        $doc = $this->createTestDocument();

        $camera = $doc->getEntity('Camera');
        $this->assertNotNull($camera);
        $this->assertSame('Camera', $camera['name']);

        // Nested entity
        $weapon = $doc->getEntity('Weapon');
        $this->assertNotNull($weapon);
    }

    public function test_add_entity(): void
    {
        $doc = $this->createTestDocument();
        $doc->addEntity('Enemy');

        $enemy = $doc->getEntity('Enemy');
        $this->assertNotNull($enemy);
        $this->assertCount(3, $doc->getEntities());
    }

    public function test_add_entity_as_child(): void
    {
        $doc = $this->createTestDocument();
        $doc->addEntity('Shield', 'Player');

        $player = $doc->getEntity('Player');
        $this->assertCount(2, $player['children']); // Weapon + Shield
    }

    public function test_remove_entity(): void
    {
        $doc = $this->createTestDocument();
        $doc->removeEntity('Camera');

        $this->assertNull($doc->getEntity('Camera'));
        $this->assertCount(1, $doc->getEntities());
    }

    public function test_rename_entity(): void
    {
        $doc = $this->createTestDocument();
        $doc->renameEntity('Camera', 'MainCamera');

        $this->assertNull($doc->getEntity('Camera'));
        $this->assertNotNull($doc->getEntity('MainCamera'));
    }

    public function test_add_component(): void
    {
        $doc = $this->createTestDocument();
        $doc->addComponent('Camera', 'RigidBody2D', ['mass' => 1.0]);

        $camera = $doc->getEntity('Camera');
        $this->assertCount(2, $camera['components']);
        $this->assertSame('RigidBody2D', $camera['components'][1]['_class']);
    }

    public function test_remove_component(): void
    {
        $doc = $this->createTestDocument();
        $doc->removeComponent('Player', 'SpriteRenderer');

        $player = $doc->getEntity('Player');
        $this->assertCount(1, $player['components']);
    }

    public function test_update_property(): void
    {
        $doc = $this->createTestDocument();
        $doc->updateProperty('Player', 'SpriteRenderer', 'textureId', 'player_run');

        $player = $doc->getEntity('Player');
        $sprite = null;
        foreach ($player['components'] as $c) {
            if ($c['_class'] === 'SpriteRenderer') {
                $sprite = $c;
            }
        }
        $this->assertSame('player_run', $sprite['textureId']);
    }

    public function test_undo(): void
    {
        $doc = $this->createTestDocument();
        $doc->addEntity('Enemy');
        $this->assertCount(3, $doc->getEntities());

        $doc->undo();
        $this->assertCount(2, $doc->getEntities());
        $this->assertNull($doc->getEntity('Enemy'));
    }

    public function test_redo(): void
    {
        $doc = $this->createTestDocument();
        $doc->addEntity('Enemy');
        $doc->undo();
        $doc->redo();

        $this->assertCount(3, $doc->getEntities());
        $this->assertNotNull($doc->getEntity('Enemy'));
    }

    public function test_undo_redo_chain(): void
    {
        $doc = $this->createTestDocument();
        $doc->addEntity('A');
        $doc->addEntity('B');
        $doc->addEntity('C');

        $this->assertCount(5, $doc->getEntities());

        $doc->undo();
        $this->assertCount(4, $doc->getEntities());

        $doc->undo();
        $this->assertCount(3, $doc->getEntities());

        $doc->redo();
        $this->assertCount(4, $doc->getEntities());
    }

    public function test_dirty_flag(): void
    {
        $doc = $this->createTestDocument();
        $this->assertFalse($doc->isDirty());

        $doc->addEntity('X');
        $this->assertTrue($doc->isDirty());

        $doc->markClean();
        $this->assertFalse($doc->isDirty());
    }

    public function test_new_action_clears_redo_stack(): void
    {
        $doc = $this->createTestDocument();
        $doc->addEntity('A');
        $doc->undo();
        $this->assertTrue($doc->canRedo());

        $doc->addEntity('B');
        $this->assertFalse($doc->canRedo());
    }

    public function test_reparent_entity(): void
    {
        $doc = $this->createTestDocument();
        // Move Camera under Player
        $doc->reparentEntity('Camera', 'Player');

        $this->assertCount(1, $doc->getEntities()); // Only Player at root
        $player = $doc->getEntity('Player');
        $this->assertCount(2, $player['children']); // Weapon + Camera
    }

    // --- State round-trip (what the editor does between HTTP requests) ---

    /**
     * The regression this exists for: the editor rebuilds the document from
     * storage on every request. Persisting only the scene array left the
     * rebuilt document with empty stacks, so undo silently did nothing at all
     * — for every command, not just some.
     */
    public function test_undo_survives_a_state_round_trip(): void
    {
        $doc = $this->createTestDocument();
        $doc->addEntity('Ghost');

        $restored = SceneDocument::fromState($doc->toState());

        $this->assertTrue($restored->canUndo(), 'history has to travel with the document');
        $restored->undo();
        $this->assertNull($restored->getEntity('Ghost'));
        $this->assertNotNull($restored->getEntity('Player'));
    }

    public function test_redo_survives_a_state_round_trip(): void
    {
        $doc = $this->createTestDocument();
        $doc->addEntity('Ghost');
        $doc->undo();

        $restored = SceneDocument::fromState($doc->toState());

        $this->assertTrue($restored->canRedo());
        $restored->redo();
        $this->assertNotNull($restored->getEntity('Ghost'));
    }

    public function test_state_round_trip_keeps_several_undo_steps(): void
    {
        $doc = $this->createTestDocument();
        $doc->addEntity('A');
        $doc->addEntity('B');
        $doc->addEntity('C');

        $restored = SceneDocument::fromState($doc->toState());
        $restored->undo();
        $restored->undo();

        $this->assertNull($restored->getEntity('B'));
        $this->assertNull($restored->getEntity('C'));
        $this->assertNotNull($restored->getEntity('A'));
    }

    public function test_a_plain_scene_array_still_loads(): void
    {
        // What older sessions hold, and what any caller with only scene data
        // can pass. It loads without history rather than failing.
        $doc = SceneDocument::fromState([
            'name' => 'legacy',
            'entities' => [['name' => 'Camera', 'components' => []]],
        ]);

        $this->assertSame('legacy', $doc->getName());
        $this->assertNotNull($doc->getEntity('Camera'));
        $this->assertFalse($doc->canUndo());
    }

    public function test_to_array_stays_the_plain_scene_shape(): void
    {
        // Commands and the transpiler consume toArray(); it must not start
        // carrying history fields.
        $doc = $this->createTestDocument();
        $doc->addEntity('A');

        $array = $doc->toArray();

        $this->assertArrayNotHasKey('__doc', $array);
        $this->assertArrayNotHasKey('undo', $array);
        $this->assertArrayHasKey('entities', $array);
    }

    /**
     * A scene with a sculpted heightmap is megabytes; 100 snapshots of it would
     * blow up the session store. The newest steps survive, the oldest are cut.
     */
    public function test_persisted_history_is_budgeted(): void
    {
        $doc = new SceneDocument(['name' => 'big', 'entities' => []]);
        // ~600 KB per entity, so a handful of snapshots exceed the 2 MB budget.
        for ($i = 0; $i < 8; $i++) {
            $doc->addComponent('', 'Heavy', ['blob' => str_repeat('x', 600_000)]);
            $doc->addEntity("E{$i}");
        }

        $state = $doc->toState();
        $persisted = strlen((string) json_encode($state['undo']));

        $this->assertLessThan(3 * 1024 * 1024, $persisted, 'history must stay bounded');

        // Whatever fits is the RECENT history, so the last edit is undoable.
        $restored = SceneDocument::fromState($state);
        $this->assertTrue($restored->canUndo());
        $restored->undo();
        $this->assertNull($restored->getEntity('E7'));
    }
}
