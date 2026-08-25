const { defineConfig } = require('vite');
const react = require('@vitejs/plugin-react');
const path = require('path');

module.exports = defineConfig({
    plugins: [react()],
    root: path.resolve(__dirname, 'react'),
    base: '/HR1/assets/react/',
    build: {
        outDir: path.resolve(__dirname, 'assets/react'),
        emptyOutDir: true,
        manifest: true,
        rollupOptions: {
            input: path.resolve(__dirname, 'react/src/main.jsx')
        }
    },
    server: {
        port: 5173,
        strictPort: true,
        cors: true
    }
});
