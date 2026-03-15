# HRMS External UI

External Vue frontend that consumes custom Frappe APIs from the `bitwyse_hrms_backend` app.

## Prerequisites

- Node.js 20+
- npm 10+
- Reachable Frappe backend URL

## Configuration

1. Copy `.env.example` to `.env`.
2. Set the backend URL:

```env
VITE_FRAPPE_BASE_URL=https://hrms-be.bitwyse.co.ke
```

## Run Locally

```bash
npm install
npm run dev -- --host
```

## Build

```bash
npm run build
npm run preview -- --host
```

## Backend Methods Used

- `bitwyse_hrms_backend.api.hrms_dashboard_summary`
- `bitwyse_hrms_backend.api.list_employees`

## Authentication

The UI supports either:

- API key + secret (token auth), or
- existing Frappe session cookies

For production deployments, configure CORS and TLS appropriately on the Frappe edge proxy.
