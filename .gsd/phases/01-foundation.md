---
phase: 1
plan: 1
wave: 1
gap_closure: false
---

# Plan 01.1: AnyOrigin Foundation & Zero-Dependency Data Engine

## Objective
Initialize the AnyOrigin codebase with Next.js (App Router), Tailwind CSS, Lucide Icons, a zero-dependency embedded database layer (SQLite via lightweight driver with automatic migrations), admin authentication, and core REST APIs for managing upstream origins and failover policies.

## Context
Load these files for context:
- .gsd/SPEC.md
- .gsd/ROADMAP.md

## Tasks

<task type="auto">
  <name>Scaffold Next.js App Router project with Tailwind CSS</name>
  <files>
    package.json
    tsconfig.json
    tailwind.config.ts
    src/app/layout.tsx
    src/app/page.tsx
    src/app/globals.css
  </files>
  <action>
    Initialize Next.js 15+ App Router application with TypeScript, Tailwind CSS, Lucide Icons, clsx, and tailwind-merge.
    Ensure dark mode aesthetic is preconfigured as default with deep sleek slate/zinc background (#090d16 / #0d1117).
  </action>
  <verify>
    npm run build or npx next build completes without type errors
  </verify>
  <done>
    Next.js app compiles cleanly and dependencies are installed.
  </done>
</task>

<task type="auto">
  <name>Implement Zero-Dependency SQLite Database & Models</name>
  <files>
    src/lib/db.ts
    src/lib/schema.ts
    src/types/index.ts
  </files>
  <action>
    Implement an embedded SQLite database connection and auto-migrator using `better-sqlite3` storing data at `data/anyorigin.db`.
    Schema must include:
    1. `sites`: id, name, domain, created_at, updated_at
    2. `origins`: id, site_id, name, url, priority (1, 2, 3..), weight, host_header_override, is_active, status (healthy/failing/down), latency_ms, last_checked_at
    3. `failover_rules`: id, site_id, trigger_402, trigger_429, trigger_5xx, latency_threshold_ms, max_retries, retry_interval_ms
    4. `audit_logs`: id, site_id, event_type, from_origin_id, to_origin_id, reason, status_code, latency_ms, created_at
    Auto-seed default demo site ("My Web App" with Primary Vercel and Backup Netlify) if empty.
  </action>
  <verify>
    Run a verification test script to insert and query a site with origins from SQLite.
  </verify>
  <done>
    SQLite initializes automatically without external database setup and creates tables idempotently.
  </done>
</task>

<task type="auto">
  <name>Implement Admin Authentication & Security Guard</name>
  <files>
    src/lib/auth.ts
    src/middleware.ts
    src/app/api/auth/login/route.ts
    src/app/api/auth/logout/route.ts
    src/app/login/page.tsx
  </files>
  <action>
    Implement zero-dependency password protection:
    - Default master password from `ADMIN_PASSWORD` env or `admin123`.
    - Secure HTTP-only session cookie (HMAC signed token).
    - Next.js middleware protecting `/dashboard` and `/api/admin/*` endpoints.
    - Clean dark-themed Login page.
  </action>
  <verify>
    POST /api/auth/login with wrong password -> 401; with correct password -> 200 + Set-Cookie.
  </verify>
  <done>
    Protected routes redirect unauthorized requests to /login.
  </done>
</task>

<task type="auto">
  <name>Implement Sites & Origins CRUD API Endpoints</name>
  <files>
    src/app/api/sites/route.ts
    src/app/api/sites/[id]/route.ts
    src/app/api/sites/[id]/origins/route.ts
    src/app/api/sites/[id]/rules/route.ts
  </files>
  <action>
    Implement REST API routes to:
    - List and create sites
    - Reorder origin priorities (drag-and-drop support)
    - Add/update/delete origins (with URL validation, custom headers)
    - Update failover trigger rules (402, 429, 5xx, latency threshold)
  </action>
  <verify>
    Automated fetch/curl check creating a site, adding two origins, and updating failover rules.
  </verify>
  <done>
    API returns 200 OK with valid JSON payloads and updates SQLite records accurately.
  </done>
</task>

## Must-Haves
- [ ] No external database required (runs purely on local file SQLite)
- [ ] Next.js app builds with 0 errors
- [ ] Auth & CRUD APIs operational with verification tests
