# Web Vue

Vue.js web application source and frontend tooling live here.

## Suggested Contents

- `src/` for Vue source
- `public/` for static assets
- frontend build and lint configuration

## Current App

- `hrms-external-ui/`
	- Vue 3 + TypeScript + Vite frontend
	- Connects to Frappe backend APIs in `bitwyse_hrms_backend`

## Local Development

```bash
cd apps/web-vue/hrms-external-ui
cp .env.example .env
npm install
npm run dev -- --host
```

Set `VITE_FRAPPE_BASE_URL` in `.env` to your Frappe backend URL.
