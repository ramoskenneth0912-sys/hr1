const { defineConfig } = require('vite');
const vue = require('@vitejs/plugin-vue');
const path = require('path');

module.exports = defineConfig({
    plugins: [vue()],
    root: path.resolve(__dirname),
    base: '/HR1/assets/vue/',
    build: {
        outDir: path.resolve(__dirname, '../assets/vue'),
        emptyOutDir: true,
        manifest: true,
        rollupOptions: {
            input: path.resolve(__dirname, 'index.html')
        }
    },
    server: {
        port: 5174,
        strictPort: true,
        cors: true
    }
});
