<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Download composer.phar into bin/ so the packaged editor can run
 * `composer install` for newly scaffolded projects without a system-wide
 * Composer. Idempotent: skips when the phar is already present unless --force.
 *
 * Wired into composer's post-autoload-dump so a build always has it, and safe
 * to run by hand for a fresh checkout.
 */
class FetchComposerCommand extends Command
{
    protected $signature = 'editor:fetch-composer {--force : Re-download even if bin/composer.phar exists} {--soft : Exit successfully even if the download fails (for build hooks)}';

    protected $description = 'Download composer.phar into bin/ so the built editor can install project dependencies';

    private const URL = 'https://getcomposer.org/composer.phar';

    public function handle(): int
    {
        $dest = base_path('bin/composer.phar');

        if (is_file($dest) && ! $this->option('force')) {
            $this->info('composer.phar already present ('.$this->human((int) filesize($dest)).'). Use --force to re-download.');

            return self::SUCCESS;
        }

        if (! is_dir(dirname($dest))) {
            mkdir(dirname($dest), 0o755, true);
        }

        $this->info('Downloading composer.phar …');

        // The bundled static PHP has no system CA store, so point TLS
        // verification at a CA bundle that ships with the app when present.
        $options = [];
        foreach ([
            base_path('vendor/nativephp/php-bin/cacert.pem'),
            base_path('vendor/nativephp/desktop/resources/build/cacert.pem'),
        ] as $ca) {
            if (is_file($ca)) {
                $options['verify'] = $ca;
                break;
            }
        }

        try {
            $response = Http::withOptions($options)->timeout(60)->get(self::URL);
        } catch (\Throwable $e) {
            $this->error('Download failed: '.$e->getMessage());

            return $this->outcome();
        }

        $body = $response->body();

        // Sanity: a real composer.phar is a multi-MB PHP phar.
        if (! $response->successful() || strlen($body) < 500_000 || ! str_contains(substr($body, 0, 64), 'php')) {
            $this->error('Downloaded file does not look like composer.phar (status '.$response->status().', '.strlen($body).' bytes).');

            return $this->outcome();
        }

        file_put_contents($dest, $body);
        $this->info('Saved '.$this->human(strlen($body)).' to '.$dest);

        return self::SUCCESS;
    }

    /**
     * Failure exit code, downgraded to success under --soft so build hooks
     * (e.g. post-autoload-dump) don't break when offline.
     */
    private function outcome(): int
    {
        if ($this->option('soft')) {
            $this->warn('Continuing without a bundled composer.phar (--soft).');

            return self::SUCCESS;
        }

        return self::FAILURE;
    }

    private function human(int $bytes): string
    {
        return $bytes >= 1_048_576
            ? round($bytes / 1_048_576, 1).' MB'
            : round($bytes / 1024).' KB';
    }
}
