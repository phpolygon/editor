<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Command;

use PHPolygon\Editor\Command\BuildGamePackageCommand;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Editor\Support\Path;
use PHPolygon\Scene\Transpiler\SceneTranspiler;
use PHPUnit\Framework\TestCase;

class BuildGamePackageCommandTest extends TestCase
{
    private string $projectDir;

    private EditorContext $context;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/phpolygon-editor-build-'.uniqid();
        mkdir($this->projectDir.'/vendor/bin', 0o755, true);
        // The engine CLI presence gates the command.
        file_put_contents($this->projectDir.'/vendor/bin/phpolygon', "#!/usr/bin/env php\n");

        $this->context = new EditorContext(
            manifest: new ProjectManifest(
                name: 'Host',
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

    public function testReportsSuccessAndOutputDir(): void
    {
        $result = $this->fakeCommand(['projectDir' => $this->projectDir], 0, 'Build complete')->execute($this->context);

        $this->assertTrue($result['built']);
        $this->assertSame(0, $result['exitCode']);
        $this->assertStringContainsString('Build complete', $result['output']);
        $this->assertSame(Path::join($this->projectDir, 'build'), $result['outputDir']);
        $this->assertSame('base', $result['variant']);
        $this->assertSame('8.5', $result['phpVersion']);
    }

    public function testReportsFailureWhenBuildExitsNonZero(): void
    {
        $result = $this->fakeCommand(['projectDir' => $this->projectDir], 1, 'micro.sfx not found')->execute($this->context);

        $this->assertFalse($result['built']);
        $this->assertSame(1, $result['exitCode']);
    }

    public function testNormalizesVariantAndPhpVersion(): void
    {
        $result = $this->fakeCommand([
            'projectDir' => $this->projectDir,
            'variant' => 'steam',
            'phpVersion' => '8.4',
        ], 0, 'ok')->execute($this->context);

        $this->assertSame('steam', $result['variant']);
        $this->assertSame('8.4', $result['phpVersion']);
    }

    public function testDefaultsUnknownVariantToBase(): void
    {
        $result = $this->fakeCommand([
            'projectDir' => $this->projectDir,
            'variant' => 'nonsense',
            'phpVersion' => '9.9',
        ], 0, 'ok')->execute($this->context);

        $this->assertSame('base', $result['variant']);
        $this->assertSame('8.5', $result['phpVersion']);
    }

    public function testThrowsForMissingProjectDir(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->fakeCommand(['projectDir' => $this->projectDir.'/nope'], 0, '')->execute($this->context);
    }

    public function testThrowsWhenEngineCliMissing(): void
    {
        unlink($this->projectDir.'/vendor/bin/phpolygon');

        $this->expectException(\RuntimeException::class);
        $this->fakeCommand(['projectDir' => $this->projectDir], 0, '')->execute($this->context);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function fakeCommand(array $args, int $exitCode, string $output): BuildGamePackageCommand
    {
        $command = new class($args) extends BuildGamePackageCommand {
            public int $fakeExit = 0;

            public string $fakeOutput = '';

            protected function runBuild(string $projectDir, string $engineBin, string $platform, string $variant, string $phpVersion): array
            {
                return [$this->fakeExit, $this->fakeOutput];
            }
        };
        $command->fakeExit = $exitCode;
        $command->fakeOutput = $output;

        return $command;
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
