import { defineConfig } from "vite";
import vue from "@vitejs/plugin-vue";

// Dev server proxies /api to the Laravel backend so the browser makes
// same-origin calls (no CORS). useApi.js reads VITE_API_URL=/api (see .env).
export default defineConfig({
  plugins: [vue()],
  server: {
    host: true, // bind 0.0.0.0 so the dev server is reachable over the network IP, not just localhost
    port: 5173,
    proxy: {
      "/api": {
        target: "http://0.0.0.0:8000",
        changeOrigin: true,
      },
    },
  },
});
