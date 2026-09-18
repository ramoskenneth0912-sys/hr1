import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';

export default defineConfig({
  plugins: [react()],
  root: import.meta.dirname,
  base: '/HR1/',
  resolve: {
    alias: {
      '@': path.resolve(import.meta.dirname, 'src'),
      // Preserved island/ESS modules live in react/src — imported untouched.
      '@hr1': path.resolve(import.meta.dirname, '..', 'src'),
    },
  },
  build: {
    outDir: path.resolve(import.meta.dirname, '..', '..', 'dist', 'spa'),
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: path.resolve(import.meta.dirname, 'index.html'),
    },
  },
  server: {
    port: 5174,
    strictPort: true,
    cors: true,
    proxy: {
      '/HR1/api': {
        target: 'http://localhost:80',
        changeOrigin: true,
      },
      '/HR1/auth': {
        target: 'http://localhost:80',
        changeOrigin: true,
      },
      '/HR1/public': {
        target: 'http://localhost:80',
        changeOrigin: true,
      },
    },
  },
});