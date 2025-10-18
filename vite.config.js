import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import fs from 'fs';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        https: detectServerConfig(),
        host: true,
        hmr: {
            host: 'localhost',
        },
    },
});

function detectServerConfig() {
    let keyPath = process.env.SSL_KEY_PATH;
    let certPath = process.env.SSL_CERT_PATH;

    if (!keyPath || !certPath) {
        return false;
    }

    if (!fs.existsSync(keyPath) || !fs.existsSync(certPath)) {
        return false;
    }

    return {
        key: fs.readFileSync(keyPath),
        cert: fs.readFileSync(certPath),
    };
}
