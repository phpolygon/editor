<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Command;

use PHPolygon\Editor\Command\InstallProjectDependenciesCommand;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Scene\Transpiler\SceneTranspiler;
use PHPUnit\Framework\TestCase;

class InstallProjectDependenciesCommandTest extends TestCase
{
    private string $projectDir;

    private EditorContext $context;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/phpolygon-editor-install-'.uniqid();
        mkdir($this->projectDir);

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
        foreach (glob($this->projectDir.'/*') ?: [] as $f) {
            is_file($f) ? unlink($f) : null;
        }
        @rmdir($this->projectDir);
    }

    public function testReportsSuccessWhenComposerExitsZero(): void
    {
        file_put_contents($this->projectDir.'/composer.json', '{"name":"x/y"}');

        $command = $this->fakeCommand(['projectDir' => $this->projectDir], 0, 'Nothing to install');
        $result = $command->execute($this->context);

        $this->assertTrue($result['installed']);
        $this->assertSame(0, $result['exitCode']);
        $this->assertStringContainsString('Nothing to install', $result['output']);
    }

    public function testReportsFailureWhenComposerExitsNonZero(): void
    {
        file_put_contents($this->projectDir.'/composer.json', '{"name":"x/y"}');

        $command = $this->fakeCommand(['projectDir' => $this->projectDir], 1, 'Could not resolve dependencies');
        $result = $command->execute($this->context);

        $this->assertFalse($result['installed']);
        $this->assertSame(1, $result['exitCode']);
    }

    public function testPassesTheProjectDirToComposer(): void
    {
        file_put_contents($this->projectDir.'/composer.json', '{"name":"x/y"}');

        $command = new class(['projectDir' => $this->projectDir]) extends InstallProjectDependenciesCommand {
            public static ?string $seen = null;

            protected function runComposer(string $projectDir): array
            {
                self::$seen = $projectDir;

                return [0, 'ok'];
            }
        };
        $command->execute($this->context);

        $this->assertSame($this->projectDir, $command::$seen);
    }

    public function testThrowsForMissingProjectDir(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->fakeCommand(['projectDir' => $this->projectDir.'/nope'], 0, '')->execute($this->context);
    }

    public function testThrowsWhenNoComposerJson(): void
    {
        $this->expectException(\RuntimeException::class);
        // projectDir exists but has no composer.json
        $this->fakeCommand(['projectDir' => $this->projectDir], 0, '')->execute($this->context);
    }

    public function testResolvesBundledComposerPharFromEnv(): void
    {
        $phar = $this->projectDir.'/composer.phar';
        file_put_contents($phar, "#!/usr/bin/env php\n");
        putenv('PHPOLYGON_COMPOSER_PHAR='.$phar);

        try {
            $this->assertSame([PHP_BINARY, $phar], $this->composerResolver()->argv());
        } finally {
            putenv('PHPOLYGON_COMPOSER_PHAR');
        }
    }

    public function testFallsBackToSystemComposerWhenNoPhar(): void
    {
        putenv('PHPOLYGON_COMPOSER_PHAR');

        $this->assertSame(['composer'], $this->composerResolver()->argv());
    }

    /**
     * Exposes the protected composer resolution for assertions.
     */
    private function composerResolver(): InstallProjectDependenciesCommand
    {
        return new class([]) extends InstallProjectDependenciesCommand {
            /** @return list<string> */
            public function argv(): array
            {
                return $this->resolveComposer();
            }
        };
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function fakeCommand(array $args, int $exitCode, string $output): InstallProjectDependenciesCommand
    {
        // The constructor signature is fixed by CommandInterface, so the fake
        // result is injected via public properties rather than the constructor.
        $command = new class($args) extends InstallProjectDependenciesCommand {
            public int $fakeExit = 0;

            public string $fakeOutput = '';

            protected function runComposer(string $projectDir): array
            {
                return [$this->fakeExit, $this->fakeOutput];
            }
        };
        $command->fakeExit = $exitCode;
        $command->fakeOutput = $output;

        return $command;
    }
}
