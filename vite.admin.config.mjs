import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const projectRoot = fileURLToPath(new URL('.', import.meta.url))

export default defineConfig({
  root: resolve(projectRoot, 'resources/admin'),
  base: '/assets/admin-rebuilt/',
  plugins: [react()],
  build: {
    outDir: resolve(projectRoot, 'public/assets/admin-rebuilt'),
    emptyOutDir: true,
    manifest: true,
    sourcemap: false,
    target: 'es2022',
  },
  server: {
    host: '127.0.0.1',
    port: 5174,
  },
})
