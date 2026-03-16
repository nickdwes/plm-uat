# Bitwyse HRMS Backend — Deployment Guide

## Prerequisites

| Requirement | Version |
|---|---|
| Frappe / ERPNext | v15 (Python 3.11+) |
| Frappe HRMS app | v15 |
| MariaDB | 10.6+ |
| Redis | 6+ |
| Node.js (frontend build) | 18+ |
| nginx | any modern |

---

## 1. Install the Backend App

```bash
# SSH into the bench server
cd /home/frappe/frappe-bench

# Fetch the app
bench get-app bitwyse_hrms_backend https://github.com/bitwyse/bitwyse_hrms_backend

# Install on your site
bench --site <your-site> install-app bitwyse_hrms_backend
```

`after_install` runs automatically and creates:
- 4 HRMS Roles (`HRMS Global HR`, `HRMS Company HR`, `HRMS Manager`, `HRMS Employee`)
- 7 default Leave Types (Annual, Sick, Maternity, Paternity, Compassionate, LWP, Study)
- 8 Salary Components (Basic Salary, House Allowance, Transport Allowance, PAYE, NSSF, SHIF, AHL, LWP Deduction)

To re-run fixtures manually (idempotent):
```bash
bench --site <your-site> execute bitwyse_hrms_backend.setup.fixtures.setup_pilot_tenant
```

---

## 2. bench migrate

After install (or after pulling updates):

```bash
bench --site <your-site> migrate
```

This creates the `HRMS Audit Log` DocType and re-syncs fixtures via `after_migrate`.

---

## 3. Configure `site_config.json`

Add the following keys to `/home/frappe/frappe-bench/sites/<your-site>/site_config.json`:

```json
{
  "hrms_allowed_origins": [
    "https://hrms.bitwyse.co.ke"
  ]
}
```

| Key | Purpose | Default |
|---|---|---|
| `hrms_allowed_origins` | CORS Origin allow-list for browser clients | `[]` (permissive — set in production) |

---

## 4. Assign HRMS Roles to Users

In Frappe Desk → **User** → **Roles** tab, assign one HRMS role per user:

| Role | Grants |
|---|---|
| `HRMS Global HR` | All companies, all data, all operations |
| `HRMS Company HR` | Full HR + payroll + leave admin for assigned companies |
| `HRMS Manager` | Team view, leave approvals for own department |
| `HRMS Employee` | Self-service: own payslips, leave balances, own requests |

**Important:** Each user should hold exactly **one** HRMS role. Holding multiple HRMS roles results in the highest privilege winning (Global HR > Company HR > Manager > Employee).

---

## 5. Set Up Salary Structures

Before running payroll, configure Salary Structures in Frappe HRMS:

1. **Frappe Desk → Payroll → Salary Structure** — create a structure using the installed components (Basic Salary, PAYE, NSSF, SHIF, AHL).
2. **Salary Structure Assignment** — link each employee to a structure with a base amount.
3. The HRMS API statutory engine reads `Salary Structure Assignment.base` for shadow payroll previews; the live payroll run uses Frappe's built-in formula engine.

---

## 6. Configure Leave Policies

1. **Frappe Desk → HR → Leave Policy** — create a policy using the installed Leave Types.
2. **Leave Policy Assignment** — link each employee to a policy.
3. Use the API (`create_leave_allocation`) to open allocations at the start of each leave year.

---

## 7. Deploy the Vue Frontend

```bash
cd apps/web-vue/hrms-external-ui

# Install dependencies (requires Node 18+)
npm install

# Build for production
npm run build

# The dist/ folder contains the static SPA — serve via nginx
```

**nginx snippet:**

```nginx
server {
    listen 443 ssl;
    server_name hrms.bitwyse.co.ke;

    # Vue SPA
    root /var/www/hrms-external-ui/dist;
    index index.html;
    location / {
        try_files $uri $uri/ /index.html;
    }

    # Proxy API calls to Frappe
    location /api/ {
        proxy_pass https://hrms-be.bitwyse.co.ke;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }
}
```

**Environment variables** (create `apps/web-vue/hrms-external-ui/.env.production`):

```env
VITE_FRAPPE_BASE_URL=https://hrms-be.bitwyse.co.ke
```

---

## 8. Production Checklist

- [ ] `hrms_allowed_origins` set in `site_config.json`
- [ ] All 4 HRMS roles created (verify: `bench --site <site> execute bitwyse_hrms_backend.setup.fixtures.setup_pilot_tenant`)
- [ ] nginx TLS certificate configured (Let's Encrypt / ACM)
- [ ] Frappe `bench setup nginx` and `bench setup supervisor` completed
- [ ] `bench --site <site> set-config maintenance_mode 0`
- [ ] Rate limit reviewed: default 120 req/min; adjust `_RATE_LIMIT_PER_MINUTE` in `auth.py` if needed
- [ ] `HRMS Audit Log` DocType present post-migrate
- [ ] At least one `HRMS Global HR` user configured and tested
- [ ] Shadow payroll dry-run executed against pilot company before first live run
- [ ] Payroll period created for first live month
- [ ] Leave allocations opened for all employees

---

## 9. Shadow Payroll — First-Run Verification

Before the first live payroll run, use shadow mode to validate your salary structures:

```bash
# Via API (curl example)
curl -X POST https://hrms-be.bitwyse.co.ke/api/method/bitwyse_hrms_backend.api.run_shadow_payroll \
  -H "Authorization: token <api_key>:<api_secret>" \
  -H "Content-Type: application/json" \
  -d '{"company": "Bitwyse Ltd", "start_date": "2026-03-01", "end_date": "2026-03-31"}'
```

Check the response for:
- `anomaly_count > 0` — employees with missing salary structures or zero net pay
- `total_paye` / `total_nssf_employee` / `total_shif` / `total_ahl_employee` — match expected obligations
- Individual `slips[].anomalies` — per-employee issues to fix before the live run

---

## 10. Updating the App

```bash
cd /home/frappe/frappe-bench
bench update --apps bitwyse_hrms_backend
bench --site <your-site> migrate
```

`after_migrate` re-runs fixture sync (idempotent — no duplicate documents created).

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `PermissionError: Authentication required` | User is Guest | Verify API key/secret or session cookie |
| `PermissionError: Access denied. Required role: ...` | User missing HRMS role | Assign correct role in Frappe User settings |
| `ValidationError: Company 'X' does not exist` | Company name typo or wrong site | Check Frappe Desk → Company list |
| Shadow payroll returns `anomaly_count > 0` | Missing Salary Structure Assignment | Create assignment for flagged employees |
| Audit log entries in Error Log (not Audit Log DocType) | `bench migrate` not run after install | Run `bench --site <site> migrate` |
| CORS error in browser | `hrms_allowed_origins` not set | Add frontend origin to `site_config.json` |
