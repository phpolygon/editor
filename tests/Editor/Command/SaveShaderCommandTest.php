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

    public function testWritesVertexFragmentAndGraph(): void
    {
        $result = (new SaveShaderCommand([
            'name' => 'waves',
            'vertex' => "#version 150 core\nvoid main() { gl_Position = vec4(0.0); }",
            'fragment' => "#version 150 core\nout vec4 frag_color;\nvoid main() { frag_color = vec4(1.0); }",
            'graph' => ['nodes' => [['id' => 'fragment', 'type' => 'fragment']], 'connections' => []],
        ]))->execute($this->context);

        $this->assertTrue($result['saved']);
        $this->assertSame('shaders/waves.vert.glsl', $result['vertexPath']);
        $this->assertSame('shaders/waves.frag.glsl', $result['fragmentPath']);
        $this->assertFileExists($this->projectDir.'/assets/shaders/waves.vert.glsl');
        $this->assertFileExists($this->projectDir.'/assets/shaders/waves.frag.glsl');
        $this->assertFileExists($this->projectDir.'/assets/shaders/waves.shader.json');
        $this->assertStringContainsString('frag_color', (string) file_get_contents($this->projectDir.'/assets/shaders/waves.frag.glsl'));
    }

    public function testThrowsForEmptyGlsl(): void
    {
        $this->expectException(\RuntimeException::class);
        (new SaveShaderCommand(['name' => 'x', 'vertex' => '  ', 'fragment' => '  ']))->execute($this->context);
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
