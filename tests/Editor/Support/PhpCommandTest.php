<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Tests\Support;

use PHPolygon\Editor\Support\PhpCommand;
use PHPUnit\Framework\TestCase;

/**
 * {@see PhpCommand::resolve} rewrites a leading bare `php` token to the editor's
 * absolute PHP binary, so manifest helper commands run even when the editor
 * process has no `php` on PATH (e.g. `php artisan serve` from a GUI).
 */
final class PhpCommandTest extends TestCase
{
    public function testLeadingPhpTokenBecomesAbsoluteBinary(): void
    {
        $resolved = PhpCommand::resolve('php scripts/expand-scene.php');

        self::assertStringStartsWith(escapeshellarg(PHP_BINARY), $resolved);
        self::assertStringEndsWith(' scripts/expand-scene.php', $resolved);
        self::assertStringNotContainsString('php scripts', $resolved);
    }

    public function testCaseInsensitivePhpToken(): void
    {
        self::assertStringStartsWith(escapeshellarg(PHP_BINARY), PhpCommand::resolve('PHP foo.php'));
    }

    public function testAbsoluteBinaryCommandLeftUntouched(): void
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' foo.php';
        self::assertSame($cmd, PhpCommand::resolve($cmd));
    }

    public function testNonPhpTokenLeftUntouched(): void
    {
        // Only the exact `php` token is rewritten — not `phpstan`, another runner,
        // or an empty command.
        self::assertSame('phpstan analyse', PhpCommand::resolve('phpstan analyse'));
        self::assertSame('node build.js', PhpCommand::resolve('node build.js'));
        self::assertSame('', PhpCommand::resolve(''));
    }
}
