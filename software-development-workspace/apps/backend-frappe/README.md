# Backend Frappe

Frappe backend services and site-level server logic live here.

## Suggested Contents

- custom Frappe apps under `apps/` if co-located
- bench or deployment scripts
- backend-specific configuration

## Current Custom App

- `apps/bitwyse_hrms_backend/`
	- API-first Frappe app for external UI clients
	- Exposes whitelisted methods in `bitwyse_hrms_backend/api.py`

## API Methods

- `bitwyse_hrms_backend.api.health`
- `bitwyse_hrms_backend.api.hrms_dashboard_summary`
- `bitwyse_hrms_backend.api.list_employees`

## Install App on a Site

```bash
bench --site <site-name> install-app bitwyse_hrms_backend
```

## API Test Command

```bash
curl -X POST "https://<frappe-host>/api/method/bitwyse_hrms_backend.api.health"
```
