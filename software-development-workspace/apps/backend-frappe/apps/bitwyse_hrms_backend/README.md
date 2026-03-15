# Bitwyse HRMS Backend (Frappe App)

API-first custom Frappe app for powering external UIs (web/mobile) while keeping ERPNext/HRMS as the system of record.

## What this app provides

- Whitelisted backend methods for an external frontend
- A simple health endpoint
- HRMS summary and employee-list endpoints with auth guardrails

## Installation in a bench

1. Place this app inside your bench `apps/` directory (or install via git URL).
2. Install Python dependencies and app package:

```bash
bench setup requirements --python
```

3. Install app on your site:

```bash
bench --site <site-name> install-app bitwyse_hrms_backend
```

4. Verify endpoint:

```bash
curl -X POST "https://<frappe-host>/api/method/bitwyse_hrms_backend.api.health"
```

## API methods

- `bitwyse_hrms_backend.api.health`
- `bitwyse_hrms_backend.api.hrms_dashboard_summary`
- `bitwyse_hrms_backend.api.list_employees`

## Security notes

- `health` is guest-accessible.
- HRMS methods require authenticated users.
- Data access still respects Frappe DocType permissions.
