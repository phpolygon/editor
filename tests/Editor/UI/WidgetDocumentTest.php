<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\UI;

use PHPolygon\Editor\UI\WidgetCatalog;
use PHPolygon\Editor\UI\WidgetDocument;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class WidgetDocumentTest extends TestCase
{
    private WidgetCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new WidgetCatalog;
    }

    private function newDoc(): WidgetDocument
    {
        return new WidgetDocument('main_menu', $this->catalog->defaultNode('VBox'));
    }

    public function test_constructor_assigns_ids(): void
    {
        $doc = $this->newDoc();
        $this->assertNotSame('', $doc->getRootId());
        $this->assertSame('main_menu', $doc->getName());
        $this->assertSame($doc->getRootId(), $doc->toArray()['root']['_id']);
    }

    public function test_constructor_rejects_non_widget_root(): void
    {
        $this->expectException(RuntimeException::class);
        new WidgetDocument('x', ['not' => 'a widget']);
    }

    public function test_add_widget_under_root(): void
    {
        $doc = $this->newDoc();
        $id = $doc->addWidget($doc->getRootId(), $this->catalog->defaultNode('Label'));

        $children = $doc->toArray()['root']['children'];
        $this->assertCount(1, $children);
        $this->assertSame($id, $children[0]['_id']);
    }

    public function test_add_widget_under_missing_parent_throws(): void
    {
        $doc = $this->newDoc();
        $this->expectException(RuntimeException::class);
        $doc->addWidget('nope', $this->catalog->defaultNode('Label'));
    }

    public function test_remove_widget(): void
    {
        $doc = $this->newDoc();
        $id = $doc->addWidget($doc->getRootId(), $this->catalog->defaultNode('Label'));
        $doc->removeWidget($id);

        $this->assertArrayNotHasKey('children', $doc->toArray()['root']);
    }

    public function test_remove_root_throws(): void
    {
        $doc = $this->newDoc();
        $this->expectException(RuntimeException::class);
        $doc->removeWidget($doc->getRootId());
    }

    public function test_reparent_widget(): void
    {
        $doc = $this->newDoc();
        $panelId = $doc->addWidget($doc->getRootId(), $this->catalog->defaultNode('Panel'));
        $labelId = $doc->addWidget($doc->getRootId(), $this->catalog->defaultNode('Label'));

        $doc->reparentWidget($labelId, $panelId);

        $root = $doc->toArray()['root'];
        // Root now has only the panel; the label lives inside the panel.
        $this->assertCount(1, $root['children']);
        $panel = $root['children'][0];
        $this->assertSame($panelId, $panel['_id']);
        $this->assertSame($labelId, $panel['children'][0]['_id']);
    }

    public function test_reparent_into_descendant_throws(): void
    {
        $doc = $this->newDoc();
        $outer = $doc->addWidget($doc->getRootId(), $this->catalog->defaultNode('Panel'));
        $inner = $doc->addWidget($outer, $this->catalog->defaultNode('VBox'));

        // Moving the outer panel into its own child must be rejected.
        $this->expectException(RuntimeException::class);
        $doc->reparentWidget($outer, $inner);
    }

    public function test_update_property(): void
    {
        $doc = $this->newDoc();
        $id = $doc->addWidget($doc->getRootId(), $this->catalog->defaultNode('Label'));
        $doc->updateProperty($id, 'text', 'Play');

        $label = $doc->toArray()['root']['children'][0];
        $this->assertSame('Play', $label['text']);
    }

    public function test_update_reserved_property_throws(): void
    {
        $doc = $this->newDoc();
        $this->expectException(RuntimeException::class);
        $doc->updateProperty($doc->getRootId(), '_widget', 'evil');
    }

    public function test_to_file_array_strips_ids_and_adds_format(): void
    {
        $doc = $this->newDoc();
        $doc->addWidget($doc->getRootId(), $this->catalog->defaultNode('Label'));

        $file = $doc->toFileArray();
        $this->assertSame(1, $file['_format']);
        $this->assertSame('main_menu', $file['name']);
        $this->assertArrayNotHasKey('_id', $file['root']);
        $this->assertArrayNotHasKey('_id', $file['root']['children'][0]);
    }

    public function test_reload_from_file_array_reassigns_ids(): void
    {
        $doc = $this->newDoc();
        $doc->addWidget($doc->getRootId(), $this->catalog->defaultNode('Button'));

        $reloaded = WidgetDocument::fromFileArray($doc->toFileArray());
        $root = $reloaded->toArray()['root'];

        $this->assertArrayHasKey('_id', $root);
        $this->assertArrayHasKey('_id', $root['children'][0]);
        $this->assertSame('main_menu', $reloaded->getName());
    }
}
