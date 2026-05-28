<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>{{ config('app.name', 'Bitwyse HRMS') }} — API</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --brand:      #1a56db;
            --brand-dark: #1a3bbb;
            --surface:    #f8fafc;
            --card:       #ffffff;
            --border:     #e2e8f0;
            --text:       #1e293b;
            --muted:      #64748b;
            --green:      #16a34a;
            --green-bg:   #dcfce7;
            --radius:     10px;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--surface);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* ── Header ──────────────────────────────────────────────────────── */
        header {
            background: var(--card);
            border-bottom: 1px solid var(--border);
            padding: 0 2rem;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            font-size: 1.05rem;
            color: var(--text);
            text-decoration: none;
        }

        .logo-icon {
            width: 32px;
            height: 32px;
            background: var(--brand);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 16px;
            font-weight: 800;
            letter-spacing: -1px;
            flex-shrink: 0;
        }

        .header-badge {
            font-size: 11px;
            font-weight: 600;
            background: var(--brand);
            color: #fff;
            padding: 2px 8px;
            border-radius: 20px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        /* ── Main ────────────────────────────────────────────────────────── */
        main {
            flex: 1;
            max-width: 900px;
            width: 100%;
            margin: 0 auto;
            padding: 3rem 2rem;
        }

        /* ── Hero ────────────────────────────────────────────────────────── */
        .hero {
            text-align: center;
            margin-bottom: 3rem;
        }

        .hero h1 {
            font-size: 2rem;
            font-weight: 800;
            letter-spacing: -0.5px;
            margin-bottom: 0.6rem;
        }

        .hero h1 span { color: var(--brand); }

        .hero p {
            color: var(--muted);
            font-size: 1rem;
            max-width: 520px;
            margin: 0 auto 1.5rem;
            line-height: 1.6;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--green-bg);
            color: var(--green);
            font-size: 0.8rem;
            font-weight: 600;
            padding: 5px 14px;
            border-radius: 20px;
            border: 1px solid #bbf7d0;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            background: var(--green);
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.4; }
        }

        /* ── Cards ───────────────────────────────────────────────────────── */
        .section-title {
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: 0.75rem;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.4rem 1.6rem;
            margin-bottom: 1.5rem;
        }

        /* ── Info grid ───────────────────────────────────────────────────── */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
        }

        .info-item {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.1rem 1.3rem;
        }

        .info-item .label {
            font-size: 0.7rem;
            font-weight: 600;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: 4px;
        }

        .info-item .value {
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--text);
        }

        /* ── Endpoints ───────────────────────────────────────────────────── */
        .endpoint-list {
            display: flex;
            flex-direction: column;
            gap: 0;
        }

        .endpoint {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px solid var(--border);
        }

        .endpoint:last-child { border-bottom: none; }

        .method {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.5px;
            padding: 2px 7px;
            border-radius: 4px;
            min-width: 44px;
            text-align: center;
            flex-shrink: 0;
        }

        .method.get    { background: #dbeafe; color: #1d4ed8; }
        .method.post   { background: #dcfce7; color: #15803d; }
        .method.put    { background: #fef9c3; color: #854d0e; }
        .method.delete { background: #fee2e2; color: #b91c1c; }

        .endpoint-path {
            font-family: 'SFMono-Regular', Consolas, monospace;
            font-size: 0.82rem;
            color: var(--text);
            flex: 1;
        }

        .endpoint-desc {
            font-size: 0.78rem;
            color: var(--muted);
            text-align: right;
        }

        /* ── Auth routes ─────────────────────────────────────────────────── */
        .auth-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 0.75rem;
        }

        .auth-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0.75rem 1rem;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
        }

        .auth-item .method { margin: 0; }

        .auth-item .path {
            font-family: 'SFMono-Regular', Consolas, monospace;
            font-size: 0.78rem;
            color: var(--text);
        }

        /* ── Health ──────────────────────────────────────────────────────── */
        .health-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .health-row a {
            font-size: 0.82rem;
            color: var(--brand);
            text-decoration: none;
            font-weight: 500;
        }

        .health-row a:hover { text-decoration: underline; }

        /* ── Footer ──────────────────────────────────────────────────────── */
        footer {
            border-top: 1px solid var(--border);
            padding: 1.2rem 2rem;
            text-align: center;
            font-size: 0.78rem;
            color: var(--muted);
            background: var(--card);
        }

        footer a { color: var(--brand); text-decoration: none; }
        footer a:hover { text-decoration: underline; }
    </style>
</head>
<body>

<header>
    <span class="logo">
        <span class="logo-icon">BW</span>
        {{ config('app.name', 'Bitwyse HRMS') }}
    </span>
    <span class="header-badge">REST API</span>
</header>

<main>

    {{-- Hero --}}
    <div class="hero">
        <h1>Welcome to the <span>Bitwyse HRMS</span> API</h1>
        <p>A secure, role-based REST API for human-resource management — employees, leave, payroll, and more.</p>
        <span class="status-pill">
            <span class="status-dot"></span>
            Operational
        </span>
    </div>

    {{-- Info grid --}}
    <p class="section-title">Runtime</p>
    <div class="info-grid" style="margin-bottom: 1.5rem;">
        <div class="info-item">
            <div class="label">Environment</div>
            <div class="value">{{ ucfirst(config('app.env')) }}</div>
        </div>
        <div class="info-item">
            <div class="label">API Version</div>
            <div class="value">{{ config('app.hrms_api_version', env('HRMS_API_VERSION', 'v1')) }}</div>
        </div>
        <div class="info-item">
            <div class="label">Laravel</div>
            <div class="value">{{ app()->version() }}</div>
        </div>
        <div class="info-item">
            <div class="label">PHP</div>
            <div class="value">{{ PHP_MAJOR_VERSION }}.{{ PHP_MINOR_VERSION }}</div>
        </div>
    </div>

    {{-- API Endpoints --}}
    <p class="section-title">API Endpoints — /api/v1</p>
    <div class="card">
        <div class="endpoint-list">

            <div class="endpoint">
                <span class="method get">GET</span>
                <span class="endpoint-path">/api/v1/employees</span>
                <span class="endpoint-desc">List employees</span>
            </div>
            <div class="endpoint">
                <span class="method post">POST</span>
                <span class="endpoint-path">/api/v1/employees</span>
                <span class="endpoint-desc">Create employee</span>
            </div>
            <div class="endpoint">
                <span class="method get">GET</span>
                <span class="endpoint-path">/api/v1/employees/{id}</span>
                <span class="endpoint-desc">View employee</span>
            </div>
            <div class="endpoint">
                <span class="method put">PUT</span>
                <span class="endpoint-path">/api/v1/employees/{id}</span>
                <span class="endpoint-desc">Update employee</span>
            </div>
            <div class="endpoint">
                <span class="method delete">DELETE</span>
                <span class="endpoint-path">/api/v1/employees/{id}</span>
                <span class="endpoint-desc">Archive employee</span>
            </div>

            <div class="endpoint">
                <span class="method get">GET</span>
                <span class="endpoint-path">/api/v1/leave</span>
                <span class="endpoint-desc">List leave requests</span>
            </div>
            <div class="endpoint">
                <span class="method post">POST</span>
                <span class="endpoint-path">/api/v1/leave</span>
                <span class="endpoint-desc">Submit leave request</span>
            </div>
            <div class="endpoint">
                <span class="method post">POST</span>
                <span class="endpoint-path">/api/v1/leave/{id}/approve</span>
                <span class="endpoint-desc">Approve request</span>
            </div>
            <div class="endpoint">
                <span class="method post">POST</span>
                <span class="endpoint-path">/api/v1/leave/{id}/reject</span>
                <span class="endpoint-desc">Reject request</span>
            </div>

            <div class="endpoint">
                <span class="method get">GET</span>
                <span class="endpoint-path">/api/v1/payroll/runs</span>
                <span class="endpoint-desc">List payroll runs</span>
            </div>
            <div class="endpoint">
                <span class="method post">POST</span>
                <span class="endpoint-path">/api/v1/payroll/runs</span>
                <span class="endpoint-desc">Create payroll run</span>
            </div>
            <div class="endpoint">
                <span class="method get">GET</span>
                <span class="endpoint-path">/api/v1/payroll/payslips</span>
                <span class="endpoint-desc">List payslips</span>
            </div>
            <div class="endpoint">
                <span class="method get">GET</span>
                <span class="endpoint-path">/api/v1/payroll/payslips/{id}</span>
                <span class="endpoint-desc">View payslip</span>
            </div>

        </div>
    </div>

    {{-- Auth --}}
    <p class="section-title">Auth (Sanctum)</p>
    <div class="auth-grid" style="margin-bottom: 1.5rem;">
        <div class="auth-item">
            <span class="method post">POST</span>
            <span class="path">/login</span>
        </div>
        <div class="auth-item">
            <span class="method post">POST</span>
            <span class="path">/register</span>
        </div>
        <div class="auth-item">
            <span class="method post">POST</span>
            <span class="path">/logout</span>
        </div>
        <div class="auth-item">
            <span class="method post">POST</span>
            <span class="path">/forgot-password</span>
        </div>
    </div>

    {{-- Health --}}
    <p class="section-title">Health</p>
    <div class="card">
        <div class="health-row">
            <span style="font-size: 0.88rem; color: var(--muted);">
                A JSON health-check endpoint is available for load balancers and Docker HEALTHCHECK.
            </span>
            <a href="{{ url('/health') }}">/health →</a>
        </div>
    </div>

</main>

<footer>
    {{ config('app.name', 'Bitwyse HRMS') }} &mdash; Built with
    <a href="https://laravel.com" target="_blank" rel="noopener">Laravel {{ app()->version() }}</a>
    &amp; <a href="https://laravel.com/docs/sanctum" target="_blank" rel="noopener">Sanctum</a>
</footer>

</body>
</html>
