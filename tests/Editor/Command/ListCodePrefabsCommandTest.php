<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Command;

use PHPolygon\Editor\Command\ListCodePrefabsCommand;
use PHPolygon\Editor\EditorContext;
use PHPolygon\Editor\Project\ProjectManifest;
use PHPolygon\Editor\Registry\ComponentRegistry;
use PHPolygon\Editor\Registry\SystemRegistry;
use PHPolygon\Scene\Transpiler\SceneTranspiler;
use PHPUnit\Framework\TestCase;

class ListCodePrefabsCommandTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/phpolygon-editor-codeprefabs-'.uniqid();
        mkdir($this->projectDir);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->projectDir);
    }

    /** Recursive: the fixtures now include a src/Prefab tree, not just files. */
    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /** @param array<string, string> $psr4Roots */
    private function context(string $prefabsCommand, array $psr4Roots = []): EditorContext
    {
        return new EditorContext(
            manifest: new ProjectManifest(
                name: 'Test',
                version: '0.1.0',
                engineVersion: '*',
                scenesPath: 'src/Scene',
                assetsPath: 'assets',
                psr4Roots: $psr4Roots,
                entryScene: '',
                prefabsCommand: $prefabsCommand,
            ),
            components: new ComponentRegistry,
            systems: new SystemRegistry,
            transpiler: new SceneTranspiler,
            projectDir: $this->projectDir,
        );
    }

    /** Write a fixture list script into the project dir and return its command. */
    private function fixtureCommand(string $php): string
    {
        file_put_contents($this->projectDir.'/list.php', "<?php\n".$php);

        return 'php list.php';
    }

    public function test_lists_code_prefabs_from_the_game_command(): void
    {
        $command = $this->fixtureCommand(
            'echo json_encode(["prefabs" => [["name" => "Terminal", "class" => "CodeRescue\\\\Prefab\\\\TerminalPrefabDef", "variants" => ["php", "rust"]]]]);'
        );

        $result = (new ListCodePrefabsCommand)->execute($this->context($command));

        self::assertCount(1, $result['prefabs']);
        self::assertSame('Terminal', $result['prefabs'][0]['name']);
        self::assertSame('CodeRescue\\Prefab\\TerminalPrefabDef', $result['prefabs'][0]['class']);
        self::assertSame(['php', 'rust'], $result['prefabs'][0]['variants']);
    }

    public function test_empty_command_yields_empty_list(): void
    {
        $result = (new ListCodePrefabsCommand)->execute($this->context(''));

        self::assertSame([], $result['prefabs']);
    }

    public function test_failing_command_degrades_gracefully(): void
    {
        $result = (new ListCodePrefabsCommand)->execute($this->context('php does-not-exist.php'));

        self::assertSame([], $result['prefabs']);
    }

    public function test_malformed_output_yields_empty_list(): void
    {
        $command = $this->fixtureCommand('echo "not json";');

        $result = (new ListCodePrefabsCommand)->execute($this->context($command));

        self::assertSame([], $result['prefabs']);
    }

    /**
     * Write a prefab class into the project and load it, standing in for the
     * project autoloader the real editor process has registered.
     */
    private function writePrefabClass(string $class): void
    {
        $dir = $this->projectDir.'/src/Prefab';
        if (! is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
        $file = $dir.'/'.$class.'.php';
        file_put_contents($file, <<<PHP
        <?php
        namespace ListFixture\\Prefab;

        use PHPolygon\\Scene\\EntityDeclaration;
        use PHPolygon\\Scene\\Prefab;
        use PHPolygon\\Scene\\SceneBuilder;

        class {$class} extends Prefab
        {
            public function build(SceneBuilder \$builder): EntityDeclaration
            {
                return \$builder->entity(\$this->getInstanceName());
            }
        }
        PHP);
        require $file;
    }

    public function test_finds_prefab_classes_the_game_does_not_report(): void
    {
        // The gap this closes: a class the editor just generated is placeable
        // immediately, without anyone wiring it into the game first.
        $this->writePrefabClass('Streetlight');

        $result = (new ListCodePrefabsCommand)->execute(
            $this->context('', ['ListFixture\\' => 'src']),
        );

        self::assertCount(1, $result['prefabs']);
        self::assertSame('ListFixture\\Prefab\\Streetlight', $result['prefabs'][0]['class']);
    }

    public function test_the_games_entry_wins_over_the_scanned_one(): void
    {
        // Only the game knows about variants, so its richer entry must survive.
        $this->writePrefabClass('Vehicle');
        $command = $this->fixtureCommand(
            'echo json_encode(["prefabs" => [["name" => "Car", "class" => "ListFixture\\\\Prefab\\\\Vehicle", "variants" => ["suv", "cabrio"]]]]);'
        );

        $result = (new ListCodePrefabsCommand)->execute(
            $this->context($command, ['ListFixture\\' => 'src']),
        );

        self::assertCount(1, $result['prefabs']);
        self::assertSame('Car', $result['prefabs'][0]['name']);
        self::assertSame(['suv', 'cabrio'], $result['prefabs'][0]['variants']);
    }

    public function test_a_failing_game_command_still_shows_scanned_prefabs(): void
    {
        $this->writePrefabClass('Barrel');

        $result = (new ListCodePrefabsCommand)->execute(
            $this->context('php does-not-exist.php', ['ListFixture\\' => 'src']),
        );

        self::assertCount(1, $result['prefabs']);
        self::assertSame('ListFixture\\Prefab\\Barrel', $result['prefabs'][0]['class']);
    }
}
