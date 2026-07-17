<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Command;

use PHPUnit\Framework\TestCase;
use PHPolygon\Editor\Command\SaveShaderCommand;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Scene\Transpiler\SceneTranspiler;

class SaveShaderCommandTest extends TestCase
{
    private string $projectDir;

    private EditorContext $context;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/phpolygon-editor-shader-'.uniqid();
        mkdir($this->projectDir);
        mkdir($this->projectDir.'/assets');

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
            projectDir: $this->projectDir,
        );
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->projectDir);
    }

    public function testWritesGlslAndGraph(): void
    {
        $result = (new SaveShaderCommand([
            'name' => 'waves',
            'glsl' => "void main() {\n  gl_FragColor = vec4(1.0);\n}",
            'graph' => ['nodes' => [['id' => 'fragment', 'type' => 'fragment']], 'connections' => []],
        ]))->execute($this->context);

        $this->assertTrue($result['saved']);
        $this->assertSame('shaders/waves.frag.glsl', $result['relativePath']);
        $this->assertFileExists($result['path']);
        $this->assertStringContainsString('gl_FragColor', (string) file_get_contents($result['path']));

        $graphFile = $this->projectDir.'/assets/shaders/waves.shader.json';
        $this->assertFileExists($graphFile);
    }

    public function testThrowsForEmptyGlsl(): void
    {
        $this->expectException(\RuntimeException::class);
        (new SaveShaderCommand(['name' => 'x', 'glsl' => '  ']))->execute($this->context);
    }

    public function testThrowsForEmptyName(): void
    {
        $this->expectException(\RuntimeException::class);
        (new SaveShaderCommand(['name' => '', 'glsl' => 'x']))->execute($this->context);
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$f;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
