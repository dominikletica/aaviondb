import { defineConfig } from 'vite'
import path from 'path'

export default defineConfig({
  root: path.resolve(__dirname, 'system/assets/src'),
  base: './',
  publicDir: false,

  build: {
    outDir: path.resolve(__dirname, 'system/assets/build'),
    emptyOutDir: true,
    assetsInlineLimit: 0,
    sourcemap: true,
    rollupOptions: {
      input: path.resolve(__dirname, 'system/assets/src/main.ts'),
      output: {
        entryFileNames: 'ui.js',
        assetFileNames: 'ui.[ext]',
        chunkFileNames: 'chunks/[name]-[hash].js'
      }
    }
  },

  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'system/assets/src')
    }
  },

  css: {
    postcss: path.resolve(__dirname, 'postcss.config.js')
  },

  server: {
    port: 5173,
    open: false,
    strictPort: true
  }
})
