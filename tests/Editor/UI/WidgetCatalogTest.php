<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\UI;

use PHPolygon\Editor\UI\WidgetCatalog;
use PHPolygon\UI\Widget\Label;
use PHPolygon\UI\Widget\Panel;
use PHPUnit\Framework\TestCase;

class WidgetCatalogTest extends TestCase
{
    public function test_types_include_containers_and_leaves(): void
    {
        $types = (new WidgetCatalog)->types();
        $byName = [];
        foreach ($types as $t) {
            $byName[$t['type']] = $t;
        }

        $this->assertArrayHasKey('Panel', $byName);
        $this->assertArrayHasKey('Label', $byName);
        $this->assertTrue($byName['Panel']['container']);
        $this->assertTrue($byName['VBox']['container']);
        $this->assertFalse($byName['Label']['container']);
        $this->assertFalse($byName['Spacer']['container']);
    }

    public function test_default_node_is_compact(): void
    {
        // A default node only carries its type; properties still at their
        // constructor default are omitted (the schema supplies them).
        $node = (new WidgetCatalog)->defaultNode('Panel');

        $this->assertSame(Panel::class, $node['_widget']);
        $this->assertArrayNotHasKey('padding', $node);
    }

    public function test_schema_describes_editable_properties(): void
    {
        $schema = (new WidgetCatalog)->schema('Panel');
        $byName = [];
        foreach ($schema as $f) {
            $byName[$f['name']] = $f;
        }

        $this->assertArrayHasKey('title', $byName);
        $this->assertSame('string', $byName['title']['kind']);

        $this->assertArrayHasKey('padding', $byName);
        $this->assertSame('edgeinsets', $byName['padding']['kind']);
        // The schema still carries the concrete default the inspector shows.
        $this->assertSame(8.0, $byName['padding']['default']['left']);

        $this->assertArrayHasKey('sizing', $byName);
        $this->assertSame('sizing', $byName['sizing']['kind']);
    }

    public function test_default_node_for_label(): void
    {
        $node = (new WidgetCatalog)->defaultNode('Label');
        $this->assertSame(Label::class, $node['_widget']);
        $this->assertArrayNotHasKey('children', $node);
    }

    public function test_unknown_type_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        (new WidgetCatalog)->defaultNode('Nonexistent');
    }
}
