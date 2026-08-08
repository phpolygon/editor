<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Command;

use PHPUnit\Framework\TestCase;
use PHPolygon\Editor\Command\ListShaderAssetsCommand;
use PHPolygon\Editor\Command\LoadShaderAssetCommand;
use PHPolygon\Editor\Command\SaveShaderCommand;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Scene\Transpiler\SceneTranspiler;

/**
 * Reopening saved shaders: the authoring graph SaveShaderCommand writes next to
 * the GLSL is only useful if it can be listed and loaded back.
 */
class ShaderAssetCommandsTest extends TestCase
{
    private string $projectDir;

    private EditorContext $context;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/phpolygon-editor-shader-assets-'.uniqid();
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

    private function saveShader(string $name): void
    {
        (new SaveShaderCommand([
            'name' => $name,
            'vertex' => "#version 150 core\nvoid main() { gl_Position = vec4(0.0); }",
            'fragment' => "#version 150 core\nout vec4 frag_color;\nvoid main() { frag_color = vec4(1.0); }",
            'graph' => ['nodes' => [['id' => 'fragment', 'type' => 'fragment']], 'connections' => []],
        ]))->execute($this->context);
    }

    public function testListsSavedShadersByName(): void
    {
        $this->saveShader('waves');
        $this->saveShader('caustics');

        $result = (new ListShaderAssetsCommand())->execute($this->context);

        $this->assertSame(
            [
                ['name' => 'caustics', 'path' => 'shaders/caustics.shader.json'],
                ['name' => 'waves', 'path' => 'shaders/waves.shader.json'],
            ],
            $result['shaders'],
        );
    }

    public function testListIsEmptyWithoutAShadersDirectory(): void
    {
        $this->assertSame([], (new ListShaderAssetsCommand())->execute($this->context)['shaders']);
    }

    public function testHandWrittenGlslWithoutAGraphIsNotListed(): void
    {
        mkdir($this->projectDir.'/assets/shaders', 0o755, true);
        file_put_contents($this->projectDir.'/assets/shaders/manual.frag.glsl', 'void main() {}');

        $this->assertSame([], (new ListShaderAssetsCommand())->execute($this->context)['shaders']);
    }

    public function testLoadsTheAuthoringGraphBack(): void
    {
        $this->saveShader('waves');

        $result = (new LoadShaderAssetCommand(['name' => 'waves']))->execute($this->context);

        $this->assertSame('waves', $result['name']);
        $this->assertSame([['id' => 'fragment', 'type' => 'fragment']], $result['graph']['nodes']);
        $this->assertSame([], $result['graph']['connections']);
    }

    public function testLoadThrowsForAnUnknownShader(): void
    {
        $this->expectException(\RuntimeException::class);
        (new LoadShaderAssetCommand(['name' => 'nope']))->execute($this->context);
    }

    public function testLoadCannotEscapeTheShadersDirectory(): void
    {
        $this->expectException(\RuntimeException::class);
        (new LoadShaderAssetCommand(['name' => '../../secret']))->execute($this->context);
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
