<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Support;

use PHPolygon\Editor\Support\Path;
use PHPUnit\Framework\TestCase;

class PathTest extends TestCase
{
    private string $sep = DIRECTORY_SEPARATOR;

    public function test_normalize_rewrites_both_separators(): void
    {
        $this->assertSame("src{$this->sep}Scene", Path::normalize('src/Scene'));
        $this->assertSame("src{$this->sep}Scene", Path::normalize('src\\Scene'));
        $this->assertSame("a{$this->sep}b{$this->sep}c", Path::normalize('a/b\\c'));
    }

    public function test_normalize_empty(): void
    {
        $this->assertSame('', Path::normalize(''));
    }

    public function test_join_uses_os_separator(): void
    {
        $this->assertSame("a{$this->sep}b{$this->sep}c", Path::join('a', 'b', 'c'));
    }

    public function test_join_collapses_mixed_and_duplicate_separators(): void
    {
        $this->assertSame(
            "root{$this->sep}src{$this->sep}Scene",
            Path::join('root/', '/src\\', '\\Scene'),
        );
    }

    public function test_join_skips_empty_segments(): void
    {
        $this->assertSame("a{$this->sep}b", Path::join('', 'a', '', 'b'));
        $this->assertSame('', Path::join('', ''));
    }

    public function test_join_preserves_leading_separator(): void
    {
        // Unix-style absolute path keeps its leading slash on this platform.
        $joined = Path::join($this->sep.'root', 'src');
        $this->assertSame("{$this->sep}root{$this->sep}src", $joined);
    }

    public function test_join_preserves_windows_drive_root(): void
    {
        $joined = Path::join('D:\\proj\\game', 'src/Scene');
        $expected = 'D:'.$this->sep.'proj'.$this->sep.'game'.$this->sep.'src'.$this->sep.'Scene';
        $this->assertSame($expected, $joined);
    }

    public function test_to_portable_uses_forward_slashes(): void
    {
        $this->assertSame('a/b/c', Path::toPortable('a\\b\\c'));
        $this->assertSame('a/b/c', Path::toPortable('a/b/c'));
    }
}
