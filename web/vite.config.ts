import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// La app compilada se sirve desde Laravel (app/public/ui), en la misma dirección que la API.
export default defineConfig({
  plugins: [react()],
  base: '/ui/',
  build: { outDir: '../app/public/ui', emptyOutDir: true },
  server: { proxy: { '/api': 'http://localhost:8000' } },
});
