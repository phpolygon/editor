<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\GameRunner;
use Tests\TestCase;

/**
 * The endpoint the play-mode viewport polls for the running game's world.
 *
 * It only ever READS what the game exported via `Engine::enableEditorSync()`,
 * so the cases that matter are the ones where there is nothing (yet) to read.
 */
class PlayWorldEndpointTest extends TestCase
{
    private GameRunner $runner;

    /** @var list<string> */
    private array $written = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner = new GameRunner;
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function writeWorld(string $id, string $contents): string
    {
        $path = $this->runner->worldPath($id);
        file_put_contents($path, $contents);
        $this->written[] = $path;

        return $path;
    }

    public function test_reports_unavailable_when_the_game_mirrors_nothing(): void
    {
        $this->getJson('/api/editor/project/play-world?id=00ff00ff00ff00ff')
            ->assertOk()
            ->assertJsonPath('data.available', false);
    }

    public function test_returns_the_live_entities_in_the_frontend_shape(): void
    {
        // The world snapshot stores components flat; the viewport consumes the
        // nested {_class, properties} shape.
        $this->writeWorld('aa00aa00aa00aa00', json_encode([
            'name' => 'game_world',
            'entities' => [
                ['name' => 'Player', 'components' => [
                    ['_class' => 'PHPolygon\\Component\\Transform3D', 'position' => ['x' => 1, 'y' => 2, 'z' => 3]],
                ]],
            ],
        ]));

        $this->getJson('/api/editor/project/play-world?id=aa00aa00aa00aa00')
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.changed', true)
            ->assertJsonPath('data.entities.0.name', 'Player')
            ->assertJsonPath('data.entities.0.components.0._class', 'PHPolygon\\Component\\Transform3D')
            ->assertJsonPath('data.entities.0.components.0.properties.position.x', 1);
    }

    public function test_skips_a_snapshot_the_caller_already_has(): void
    {
        // The engine only re-exports on structural change, so most polls have
        // nothing new — sending the whole world again would be pure waste.
        $path = $this->writeWorld('bb11bb11bb11bb11', json_encode([
            'name' => 'game_world',
            'entities' => [['name' => 'Ground', 'components' => []]],
        ]));

        $this->getJson('/api/editor/project/play-world?id=bb11bb11bb11bb11&since='.filemtime($path))
            ->assertOk()
            ->assertJsonPath('data.changed', false)
            ->assertJsonMissingPath('data.entities');
    }

    public function test_a_half_written_snapshot_reads_as_unavailable(): void
    {
        $this->writeWorld('cc22cc22cc22cc22', '{"entities": [{"name": "Play');

        $this->getJson('/api/editor/project/play-world?id=cc22cc22cc22cc22')
            ->assertOk()
            ->assertJsonPath('data.available', false);
    }

    public function test_an_id_cannot_escape_the_runs_directory(): void
    {
        $this->getJson('/api/editor/project/play-world?id='.urlencode('../../../../etc/passwd'))
            ->assertOk()
            ->assertJsonPath('data.available', false);
    }
}
