import path from 'node:path'
import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'

// O build vai para backend/public/app, servido pelo Apache/XAMPP junto com a API Laravel.
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  return {
    base: env.VITE_BASE || '/app/',
    plugins: [react()],
    resolve: { alias: { '@': path.resolve(__dirname, './src') } },
    server: {
      port: 5173,
      proxy: { '/api': { target: env.VITE_API_PROXY || 'http://127.0.0.1:8000', changeOrigin: true } },
    },
    build: {
      outDir: '../backend/public/app',
      emptyOutDir: true,
      chunkSizeWarningLimit: 900,
    },
  }
})
