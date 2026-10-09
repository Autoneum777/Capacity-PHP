import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  base: '/capacity/',
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    rollupOptions: {
      output: {
        manualChunks(id) {
          if (!id.includes('node_modules')) return;
          if (id.includes('recharts') || id.includes('d3-')) return 'charts';
          if (id.includes('xlsx') || id.includes('exceljs')) return 'excel';
          if (id.includes('jspdf') || id.includes('html2canvas')) return 'pdf';
          if (id.includes('react-dom') || id.includes('react-router') || id.includes('/react/')) return 'react-vendor';
        },
      },
    },
  },
  server: {
    host: true,
    port: 5173,
    proxy: {
      '/capacity/api': {
        target: 'http://127.0.0.1:8765',
        changeOrigin: true,
        timeout: 600_000,
        proxyTimeout: 600_000,
      },
    },
  },
});
