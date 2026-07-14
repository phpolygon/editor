<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Command;

use PHPolygon\Editor\Command\EvaluateProceduralMeshCommand;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Scene\Transpiler\SceneTranspiler;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ProceduralMeshCommandTest extends TestCase
{
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
            components: new ComponentRegistry(),
            systems: new SystemRegistry(),
            transpiler: new SceneTranspiler(),
            projectDir: sys_get_temp_dir(),
        );
    }

    public function testEvaluatesAGraphIntoAMeshDto(): void
    {
        $result = (new EvaluateProceduralMeshCommand([
            'nodes' => [
                ['id' => 'b', 'type' => 'box', 'params' => ['width' => 2, 'height' => 1, 'depth' => 1]],
            ],
            'output' => 'b',
        ]))->execute($this->context);

        $this->assertSame(12, $result['triangleCount']);
        $this->assertGreaterThan(0, $result['vertexCount']);
        $this->assertCount($result['vertexCount'] * 3, $result['vertices']);
        $this->assertSame('', $result['id']);
        $this->assertSame(0, $result['version']);
    }

    public function testRegistersUnderMeshIdAndBumpsVersion(): void
    {
        $meshId = 'proc_preview_'.__FUNCTION__;
        $before = MeshRegistry::version($meshId);

        $result = (new EvaluateProceduralMeshCommand([
            'nodes' => [['id' => 'b', 'type' => 'box']],
            'output' => 'b',
            'meshId' => $meshId,
        ]))->execute($this->context);

        $this->assertSame($meshId, $result['id']);
        $this->assertTrue(MeshRegistry::has($meshId));
        $this->assertSame($before + 1, $result['version']);
    }

    public function testInvalidGraphThrows(): void
    {
        $this->expectException(RuntimeException::class);
        (new EvaluateProceduralMeshCommand(['nodes' => [], 'output' => 'missing']))->execute($this->context);
    }
}
