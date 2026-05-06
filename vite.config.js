import {
    defineConfig
} from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from "@tailwindcss/vite";

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: [
                'app/Livewire/**',
                'app/View/Components/**',
                'resources/views/**',
                'resources/js/**',
                'resources/css/**',
                'routes/**',
            ],
        }),
        tailwindcss(),
    ],
    server: {
        cors: true,
        watch: {
            ignored: [
                '**/storage/**',
                '**/bootstrap/cache/**',
                '**/vendor/**',
                '**/node_modules/**',
            ],
        },
    },
});
