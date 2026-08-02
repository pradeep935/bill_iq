import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

function resolveViteBase(env) {
    if (env.VITE_ASSET_BASE) {
        return env.VITE_ASSET_BASE.endsWith('/') ? env.VITE_ASSET_BASE : `${env.VITE_ASSET_BASE}/`;
    }

    const sourceUrl = env.ASSET_URL || env.APP_URL || '';
    if (!sourceUrl) {
        return '/build/';
    }

    try {
        const parsed = new URL(sourceUrl);
        const pathname = parsed.pathname.replace(/\/$/, '');
        return `${pathname}/build/`;
    } catch {
        return sourceUrl.endsWith('/') ? `${sourceUrl}build/` : `${sourceUrl}/build/`;
    }
}

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');

    return {
        base: resolveViteBase(env),
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.js'],
                refresh: true,
            }),
            vue({
                template: {
                    transformAssetUrls: {
                        base: null,
                        includeAbsolute: false,
                    },
                },
            }),
        ],
    };
});
