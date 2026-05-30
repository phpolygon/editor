<?php

declare(strict_types=1);

namespace SampleProject\Scene;

use PHPolygon\Component\Transform3D;
use PHPolygon\Math\Vec3;
use PHPolygon\Scene\Scene;
use PHPolygon\Scene\SceneBuilder;

class MainScene extends Scene
{
    public function getName(): string
    {
        return 'MainScene';
    }

    public function build(SceneBuilder $builder): void
    {
        $builder->entity('CameraRig')
            ->with(new Transform3D(position: new Vec3(0, 2, 5)));

        $builder->entity('Origin')
            ->with(new Transform3D());
    }
}
