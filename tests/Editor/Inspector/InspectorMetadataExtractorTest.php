<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Inspector;

use PHPolygon\Component\AudioSource;
use PHPolygon\Component\RigidBody2D;
use PHPolygon\Component\SpriteRenderer;
use PHPolygon\Component\Transform2D;
use PHPolygon\Editor\Inspector\InspectorMetadataExtractor;
use PHPolygon\Editor\Inspector\PropertySchema;
use PHPUnit\Framework\TestCase;

class InspectorMetadataExtractorTest extends TestCase
{
    private InspectorMetadataExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new InspectorMetadataExtractor;
    }

    public function test_extract_transform2_d(): void
    {
        $schema = $this->extractor->extract(Transform2D::class);

        $this->assertSame(Transform2D::class, $schema->className);
        $this->assertSame('Transform2D', $schema->shortName);
        $this->assertSame('Core', $schema->category);

        // Should have position, rotation, scale, parentEntityId, childEntityIds
        // (worldMatrix is #[Hidden], so excluded)
        $names = array_map(fn ($p) => $p->name, $schema->properties);
        $this->assertContains('position', $names);
        $this->assertContains('rotation', $names);
        $this->assertContains('scale', $names);
        $this->assertNotContains('worldMatrix', $names);
    }

    public function test_extract_property_editor_hint(): void
    {
        $schema = $this->extractor->extract(Transform2D::class);
        $position = $this->findProperty($schema->properties, 'position');

        $this->assertNotNull($position);
        $this->assertSame('vec2', $position->editorHint);
    }

    public function test_extract_property_range(): void
    {
        $schema = $this->extractor->extract(Transform2D::class);
        $rotation = $this->findProperty($schema->properties, 'rotation');

        $this->assertNotNull($rotation);
        $this->assertNotNull($rotation->range);
        $this->assertEqualsWithDelta(0.0, $rotation->range['min'], 0.001);
        $this->assertEqualsWithDelta(360.0, $rotation->range['max'], 0.001);
    }

    public function test_extract_rigid_body2_d_category(): void
    {
        $schema = $this->extractor->extract(RigidBody2D::class);
        $this->assertSame('Physics', $schema->category);
    }

    public function test_extract_sprite_renderer_category(): void
    {
        $schema = $this->extractor->extract(SpriteRenderer::class);
        $this->assertSame('Rendering', $schema->category);
    }

    public function test_extract_audio_source_category(): void
    {
        $schema = $this->extractor->extract(AudioSource::class);
        $this->assertSame('Audio', $schema->category);
    }

    public function test_extract_property_types(): void
    {
        $schema = $this->extractor->extract(RigidBody2D::class);

        $mass = $this->findProperty($schema->properties, 'mass');
        $this->assertSame('float', $mass->type);

        $isKinematic = $this->findProperty($schema->properties, 'isKinematic');
        $this->assertSame('bool', $isKinematic->type);
    }

    public function test_to_array(): void
    {
        $schema = $this->extractor->extract(Transform2D::class);
        $array = $schema->toArray();

        $this->assertSame(Transform2D::class, $array['className']);
        $this->assertSame('Transform2D', $array['shortName']);
        $this->assertSame('Core', $array['category']);
        $this->assertIsArray($array['properties']);
        $this->assertGreaterThan(0, count($array['properties']));
    }

    public function test_caching(): void
    {
        $schema1 = $this->extractor->extract(Transform2D::class);
        $schema2 = $this->extractor->extract(Transform2D::class);
        $this->assertSame($schema1, $schema2);
    }

    /**
     * @param  list<PropertySchema>  $properties
     */
    private function findProperty(array $properties, string $name): ?PropertySchema
    {
        foreach ($properties as $prop) {
            if ($prop->name === $name) {
                return $prop;
            }
        }

        return null;
    }
}
