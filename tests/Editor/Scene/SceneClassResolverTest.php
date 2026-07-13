<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Scene;

use PHPolygon\Editor\Scene\SceneClassResolver;
use PHPolygon\Scene\Scene;
use PHPolygon\Scene\SceneBuilder;
use PHPUnit\Framework\TestCase;

/** A concrete, loadable scene. */
class ResolverConcreteScene extends Scene
{
    public function getName(): string
    {
        return 'Concrete';
    }

    public function build(SceneBuilder $builder): void {}
}

/** An abstract base scene co-located in the scenes folder — not loadable. */
abstract class ResolverAbstractScene extends Scene {}

/** A static helper with a private constructor — the BootRenderer shape. */
final class ResolverStaticHelper
{
    private function __construct() {}

    public static function draw(): void {}
}

/** A scene whose constructor requires arguments — cannot be `new`'d blindly. */
class ResolverArgScene extends Scene
{
    public function __construct(private readonly string $required) {}

    public function getName(): string
    {
        return $this->required;
    }

    public function build(SceneBuilder $builder): void {}
}

class SceneClassResolverTest extends TestCase
{
    public function test_accepts_concrete_scene(): void
    {
        $this->assertTrue(SceneClassResolver::isLoadableScene(ResolverConcreteScene::class));
    }

    public function test_rejects_abstract_scene(): void
    {
        $this->assertFalse(SceneClassResolver::isLoadableScene(ResolverAbstractScene::class));
    }

    public function test_rejects_non_scene_helper_with_private_constructor(): void
    {
        // This is the BootRenderer case: a static helper in the scenes folder
        // that must never be instantiated as a scene.
        $this->assertFalse(SceneClassResolver::isLoadableScene(ResolverStaticHelper::class));
    }

    public function test_rejects_scene_requiring_constructor_arguments(): void
    {
        $this->assertFalse(SceneClassResolver::isLoadableScene(ResolverArgScene::class));
    }

    public function test_rejects_unknown_class(): void
    {
        $this->assertFalse(SceneClassResolver::isLoadableScene('No\\Such\\Class'));
    }
}
