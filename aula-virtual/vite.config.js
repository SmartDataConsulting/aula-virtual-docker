import { defineConfig } from 'vite';
import { readdirSync } from 'node:fs';
import { join } from 'node:path';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

function topLevelEntries(directory, extension) {
    return readdirSync(directory)
        .filter((file) => file.endsWith(extension))
        .map((file) => join(directory, file).replace(/\\/g, '/'))
        .sort();
}

export default defineConfig({
    plugins: [
        laravel({
            input: [
                ...topLevelEntries('resources/css', '.css'),
                ...topLevelEntries('resources/js', '.js'),
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
