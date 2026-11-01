import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

/*
 * The SPA talks to the Laravel API through a dev proxy, so the browser only ever
 * sees same-origin requests: no CORS preflights in development, and the same
 * relative `/api` URLs work behind nginx in production.
 */
export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    strictPort: true,
    proxy: {
      '/api': {
        target: process.env.VITE_API_TARGET ?? 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
    css: false,
    restoreMocks: true,
  },
});
