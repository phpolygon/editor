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
        $this->projectDir = sys_get_temp_dir() . '/phpolygon-editor-codeprefabs-' . uniqid();
        mkdir($this->projectDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->projectDir . '/*') ?: []);
        @rmdir($this->projectDir);
    }

    private function context(string $prefabsCommand): EditorContext
    {
        return new EditorContext(
            manifest: new ProjectManifest(
                name: 'Test',
                version: '0.1.0',
                engineVersion: '*',
                scenesPath: 'src/Scene',
                assetsPath: 'assets',
                psr4Roots: [],
                entryScene: '',
                prefabsCommand: $prefabsCommand,
            ),
            components: new ComponentRegistry(),
            systems: new SystemRegistry(),
            transpiler: new SceneTranspiler(),
            projectDir: $this->projectDir,
        );
    }

    /** Write a fixture list script into the project dir and return its command. */
    private function fixtureCommand(string $php): string
    {
        file_put_contents($this->projectDir . '/list.php', "<?php\n" . $php);

        return 'php list.php';
    }

    public function testListsCodePrefabsFromTheGameCommand(): void
    {
        $command = $this->fixtureCommand(
            'echo json_encode(["prefabs" => [["name" => "Terminal", "class" => "CodeRescue\\\\Prefab\\\\TerminalPrefabDef", "variants" => ["php", "rust"]]]]);'
        );

        $result = (new ListCodePrefabsCommand())->execute($this->context($command));

        self::assertCount(1, $result['prefabs']);
        self::assertSame('Terminal', $result['prefabs'][0]['name']);
        self::assertSame('CodeRescue\\Prefab\\TerminalPrefabDef', $result['prefabs'][0]['class']);
        self::assertSame(['php', 'rust'], $result['prefabs'][0]['variants']);
    }

    public function testEmptyCommandYieldsEmptyList(): void
    {
        $result = (new ListCodePrefabsCommand())->execute($this->context(''));

        self::assertSame([], $result['prefabs']);
    }

    public function testFailingCommandDegradesGracefully(): void
    {
        $result = (new ListCodePrefabsCommand())->execute($this->context('php does-not-exist.php'));

        self::assertSame([], $result['prefabs']);
    }

    public function testMalformedOutputYieldsEmptyList(): void
    {
        $command = $this->fixtureCommand('echo "not json";');

        $result = (new ListCodePrefabsCommand())->execute($this->context($command));

        self::assertSame([], $result['prefabs']);
    }
}
