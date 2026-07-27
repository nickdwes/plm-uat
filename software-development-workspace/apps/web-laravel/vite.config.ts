import { defineConfig } from "vite";
import vue from "@vitejs/plugin-vue";
import tailwindcss from "@tailwindcss/vite";
import path from "path";

// Laravel backend URL — defaults to local Sail / artisan serve port.
// For Docker: change to http://localhost:9090
const backendTarget = process.env.VITE_LARAVEL_API_URL || "http://localhost:9090";

export default defineConfig({
  plugins: [vue(), tailwindcss()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  server: {
    host: true,
    port: 5174, // distinct from the Frappe UI on 5173
    proxy: {
      // Proxy Sanctum CSRF + auth routes to Laravel
      "/sanctum": { target: backendTarget, changeOrigin: true, secure: false },
      "/login":   { target: backendTarget, changeOrigin: true, secure: false },
      "/logout":  { target: backendTarget, changeOrigin: true, secure: false },
      "/register":{ target: backendTarget, changeOrigin: true, secure: false },
      // Proxy all API calls
      "/api":     { target: backendTarget, changeOrigin: true, secure: false },
    },
  },
  preview: {
    host: true,
    port: 4174,
  },
});
