import { defineConfig } from "vite";
import vue from "@vitejs/plugin-vue";

// Laravel backend URL — defaults to local Sail / artisan serve port.
const backendTarget = process.env.VITE_LARAVEL_API_URL || "http://localhost:8000";

export default defineConfig({
  plugins: [vue()],
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
