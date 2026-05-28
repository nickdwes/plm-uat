import { defineConfig } from "vite";
import vue from "@vitejs/plugin-vue";

// The backend URL can be overridden at build/dev time via VITE_FRAPPE_BASE_URL.
// Falls back to localhost:8080 for local development.
const backendTarget = process.env.VITE_FRAPPE_BASE_URL || "http://localhost:8080";

export default defineConfig({
  plugins: [vue()],
  server: {
    host: true,
    port: 5173,
    proxy: {
      // Proxy all /api/* requests to the Frappe backend to avoid CORS issues
      // during local development.
      "/api": {
        target: backendTarget,
        changeOrigin: true,
        secure: false,
      },
    },
  },
  preview: {
    host: true,
    port: 4173,
  },
});
