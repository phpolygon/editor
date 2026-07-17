<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Command;

use PHPUnit\Framework\TestCase;
use PHPolygon\Editor\Command\SaveAudioCommand;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Scene\Transpiler\SceneTranspiler;

class SaveAudioCommandTest extends TestCase
{
    private string $projectDir;

    private EditorContext $context;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/phpolygon-editor-audio-'.uniqid();
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

    public function testWritesWavFromBase64(): void
    {
        $bytes = 'RIFF----WAVEfmt ';
        $result = (new SaveAudioCommand([
            'name' => 'jump',
            'data' => base64_encode($bytes),
        ]))->execute($this->context);

        $this->assertTrue($result['saved']);
        $this->assertSame('jump', $result['name']);
        $this->assertSame('audio/jump.wav', $result['relativePath']);
        $this->assertFileExists($result['path']);
        $this->assertSame($bytes, file_get_contents($result['path']));
    }

    public function testAcceptsDataUrlPrefix(): void
    {
        $result = (new SaveAudioCommand([
            'name' => 'laser',
            'data' => 'data:audio/wav;base64,'.base64_encode('abc'),
        ]))->execute($this->context);

        $this->assertSame('abc', file_get_contents($result['path']));
    }

    public function testSanitizesName(): void
    {
        $result = (new SaveAudioCommand([
            'name' => 'My Cool/Laser!',
            'data' => base64_encode('x'),
        ]))->execute($this->context);

        $this->assertSame('My_Cool_Laser_', $result['name']);
        $this->assertSame('audio/My_Cool_Laser_.wav', $result['relativePath']);
    }

    public function testThrowsForMissingName(): void
    {
        $this->expectException(\RuntimeException::class);
        (new SaveAudioCommand(['data' => base64_encode('x')]))->execute($this->context);
    }

    public function testThrowsForEmptyData(): void
    {
        $this->expectException(\RuntimeException::class);
        (new SaveAudioCommand(['name' => 'x', 'data' => '']))->execute($this->context);
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
