import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath, URL } from 'node:url';

// package.json sets "type": "module", so __dirname does not exist here —
// resolve paths from import.meta.url instead.
const fromRoot = (relative: string) => fileURLToPath(new URL(relative, import.meta.url));

// The React app is served by Spring under /app so it can coexist with the
// Thymeleaf pages that still own "/" during the incremental migration.
// Build output lands directly in the backend's static resources, which is how
// the single-container deploy ends up serving both the API and the UI.
export default defineConfig({
  base: '/app/',
  plugins: [react()],
  resolve: {
    alias: { '@': fromRoot('./src') },
  },
  build: {
    outDir: fromRoot('../backend/src/main/resources/static/app'),
    emptyOutDir: true,
    sourcemap: true,
    // The backend's CSP is script-src 'self' 'nonce-...' with no
    // 'unsafe-inline', which would block Vite's inline modulepreload polyfill.
    // Native modulepreload is supported everywhere we target, so drop it
    // rather than punching a hole in the policy.
    modulePreload: { polyfill: false },
  },
  server: {
    port: 5173,
    // Same-origin in production, so dev proxies to Spring to keep session
    // cookies and CSRF working exactly as they will once built.
    proxy: {
      '/api': { target: 'http://localhost:8080', changeOrigin: false },
      '/login': { target: 'http://localhost:8080', changeOrigin: false },
      '/logout': { target: 'http://localhost:8080', changeOrigin: false },
      // Shared design system + assets still live in the backend's static/
      // folder, so dev has to reach them there too.
      '/css': { target: 'http://localhost:8080', changeOrigin: false },
      '/images': { target: 'http://localhost:8080', changeOrigin: false },
      '/icons': { target: 'http://localhost:8080', changeOrigin: false },
      '/manifest.json': { target: 'http://localhost:8080', changeOrigin: false },
    },
  },
});
