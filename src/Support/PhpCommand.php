<?php

declare(strict_types=1);

namespace PHPolygon\Editor\Support;

/**
 * A project manifest declares headless helper commands (prefabsCommand,
 * expandCommand, liveWorldCommand) as strings like `php scripts/foo.php`, which
 * the editor runs as subprocesses. The bare `php` token relies on `php` being on
 * the PATH of the editor PROCESS — but when the editor runs as `php artisan
 * serve` launched from a GUI / `composer dev`, that process often has no `php`
 * on PATH, so the subprocess dies with "'php' is not recognized / konnte nicht
 * gefunden werden" and the command silently falls back (empty prefab list, no
 * geometry preview).
 *
 * Rewrite a leading bare `php` token to the ABSOLUTE PHP binary running the
 * editor ({@see PHP_BINARY}), which is always a valid CLI PHP. Commands that
 * already use an absolute path or a different runner are left untouched.
 */
final class PhpCommand
{
    public static function resolve(string $command): string
    {
        $trimmed = ltrim($command);
        if ($trimmed === '' || PHP_BINARY === '') {
            return $command;
        }

        if (preg_match('/^php(?=\s|$)/i', $trimmed) === 1) {
            return escapeshellarg(PHP_BINARY) . substr($trimmed, 3);
        }

        return $command;
    }
}
