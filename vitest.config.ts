import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import { resolve } from 'path';

export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': resolve(__dirname, 'resources/js'),
        },
    },
    test: {
        environment: 'happy-dom',
        globals: false,
        setupFiles: ['resources/js/test-setup.ts'],
        include: ['resources/js/**/*.test.ts'],
        coverage: {
            provider: 'v8',
            include: ['resources/js/**/*.{ts,vue}'],
            exclude: ['resources/js/**/*.test.ts', 'resources/js/app.ts'],
        },
    },
});
