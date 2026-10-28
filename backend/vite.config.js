import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel(['resources/css/app.css', 'resources/js/app.js', 'resources/js/admin-dashboard.js', 'resources/js/admin-reports.js']),
        tailwindcss(),
    ],
});
