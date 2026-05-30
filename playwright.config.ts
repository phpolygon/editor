import { defineConfig, devices } from '@playwright/test';

const PORT = process.env.PLAYWRIGHT_PORT ? Number(process.env.PLAYWRIGHT_PORT) : 8765;
const BASE_URL = `http://127.0.0.1:${PORT}`;

export default defineConfig({
    testDir: './tests/e2e',
    timeout: 60_000,
    expect: { timeout: 10_000 },
    fullyParallel: false,
    workers: 1,
    reporter: process.env.CI ? 'github' : 'list',

    use: {
        baseURL: BASE_URL,
        trace: 'retain-on-failure',
        viewport: { width: 1400, height: 900 },
    },

    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],

    // E2E uses the built (production) assets — Laravel's @vite() falls back
    // to /build/ when no hot file is present, so the vite dev server is not
    // needed. Run `npm run build` before `npm run test:e2e` (the
    // pretest:e2e script does this automatically).
    // Use php -S directly instead of `php artisan serve`. Artisan's
    // wrapper writes per-request log lines to stdout (server.php:21);
    // when Playwright reads the process output buffer those writes can
    // EPIPE and PHP leaks a Notice into the response body, blanking the
    // page. Disabling display_errors via `-d` keeps the response clean.
    webServer: {
        command: `cd public && php -d display_errors=0 -d error_reporting=0 -S 127.0.0.1:${PORT} ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`,
        url: BASE_URL,
        reuseExistingServer: !process.env.CI,
        timeout: 60_000,
    },
});
