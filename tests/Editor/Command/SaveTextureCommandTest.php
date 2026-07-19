<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Command;

use PHPUnit\Framework\TestCase;
use PHPolygon\Editor\Command\SaveTextureCommand;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Scene\Transpiler\SceneTranspiler;

class SaveTextureCommandTest extends TestCase
{
    private string $projectDir;

    private EditorContext $context;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/phpolygon-editor-texture-'.uniqid();
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

    public function testWritesPngFromBase64(): void
    {
        $bytes = "\x89PNG\r\n\x1a\n";
        $result = (new SaveTextureCommand([
            'name' => 'robot_body_albedo',
            'data' => base64_encode($bytes),
        ]))->execute($this->context);

        $this->assertTrue($result['saved']);
        $this->assertSame('robot_body_albedo', $result['name']);
        $this->assertSame('textures/robot_body_albedo.png', $result['relativePath']);
        $this->assertFileExists($result['path']);
        $this->assertSame($bytes, file_get_contents($result['path']));
    }

    public function testAcceptsDataUrlPrefix(): void
    {
        $result = (new SaveTextureCommand([
            'name' => 'map',
            'data' => 'data:image/png;base64,'.base64_encode('abc'),
        ]))->execute($this->context);

        $this->assertSame('abc', file_get_contents($result['path']));
    }

    public function testSanitizesName(): void
    {
        $result = (new SaveTextureCommand([
            'name' => 'Base Color/Map!',
            'data' => base64_encode('x'),
        ]))->execute($this->context);

        $this->assertSame('Base_Color_Map_', $result['name']);
        $this->assertSame('textures/Base_Color_Map_.png', $result['relativePath']);
    }

    public function testThrowsForMissingName(): void
    {
        $this->expectException(\RuntimeException::class);
        (new SaveTextureCommand(['data' => base64_encode('x')]))->execute($this->context);
    }

    public function testThrowsForEmptyData(): void
    {
        $this->expectException(\RuntimeException::class);
        (new SaveTextureCommand(['name' => 'x', 'data' => '']))->execute($this->context);
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
