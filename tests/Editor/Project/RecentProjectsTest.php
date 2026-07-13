<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Project;

use PHPolygon\Editor\Project\ProjectLoader;
use PHPolygon\Editor\Project\RecentProjects;
use PHPUnit\Framework\TestCase;

class RecentProjectsTest extends TestCase
{
    private string $tempDir;

    private string $storageFile;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/phpolygon_recent_'.uniqid();
        mkdir($this->tempDir);
        $this->storageFile = $this->tempDir.'/recent-projects.json';
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->tempDir);
    }

    public function test_empty_by_default(): void
    {
        $recent = new RecentProjects($this->storageFile);
        $this->assertSame([], $recent->all());
    }

    public function test_add_records_most_recent_first(): void
    {
        $recent = new RecentProjects($this->storageFile);
        $recent->add('/games/alpha', 'Alpha', 100);
        $recent->add('/games/beta', 'Beta', 200);

        $all = $recent->all();
        $this->assertCount(2, $all);
        $this->assertSame('Beta', $all[0]['name']);
        $this->assertSame('/games/beta', $all[0]['dir']);
        $this->assertSame(200, $all[0]['lastOpened']);
        $this->assertSame('Alpha', $all[1]['name']);
    }

    public function test_add_deduplicates_and_moves_to_front(): void
    {
        $recent = new RecentProjects($this->storageFile);
        $recent->add('/games/alpha', 'Alpha', 100);
        $recent->add('/games/beta', 'Beta', 200);
        $recent->add('/games/alpha', 'Alpha Renamed', 300);

        $all = $recent->all();
        $this->assertCount(2, $all);
        $this->assertSame('/games/alpha', $all[0]['dir']);
        $this->assertSame('Alpha Renamed', $all[0]['name']);
        $this->assertSame(300, $all[0]['lastOpened']);
    }

    public function test_deduplication_ignores_trailing_slashes_and_separators(): void
    {
        $recent = new RecentProjects($this->storageFile);
        $recent->add('/games/alpha', 'Alpha', 100);
        $recent->add('/games/alpha/', 'Alpha', 200);
        $recent->add('\\games\\alpha', 'Alpha', 300);

        $this->assertCount(1, $recent->all());
    }

    public function test_respects_max_cap(): void
    {
        $recent = new RecentProjects($this->storageFile, max: 3);
        for ($i = 1; $i <= 5; $i++) {
            $recent->add("/games/p{$i}", "P{$i}", $i);
        }

        $all = $recent->all();
        $this->assertCount(3, $all);
        $this->assertSame('/games/p5', $all[0]['dir']);
        $this->assertSame('/games/p3', $all[2]['dir']);
    }

    public function test_remove(): void
    {
        $recent = new RecentProjects($this->storageFile);
        $recent->add('/games/alpha', 'Alpha', 100);
        $recent->add('/games/beta', 'Beta', 200);
        $recent->remove('/games/alpha');

        $all = $recent->all();
        $this->assertCount(1, $all);
        $this->assertSame('/games/beta', $all[0]['dir']);
    }

    public function test_prune_drops_entries_without_manifest(): void
    {
        $liveProject = $this->tempDir.'/live';
        mkdir($liveProject);
        file_put_contents(
            $liveProject.'/'.ProjectLoader::MANIFEST_FILE,
            json_encode(['name' => 'Live']),
        );

        $recent = new RecentProjects($this->storageFile);
        $recent->add($liveProject, 'Live', 200);
        $recent->add('/games/gone', 'Gone', 100);

        $survivors = $recent->prune();
        $this->assertCount(1, $survivors);
        $this->assertSame($liveProject, $survivors[0]['dir']);
        // Persisted, not just returned.
        $this->assertCount(1, $recent->all());
    }

    public function test_ignores_corrupt_storage_file(): void
    {
        file_put_contents($this->storageFile, 'not json {');
        $recent = new RecentProjects($this->storageFile);
        $this->assertSame([], $recent->all());
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? $this->deleteTree($path) : unlink($path);
        }
        rmdir($dir);
    }
}
