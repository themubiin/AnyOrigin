# 🛡️ AnyOrigin

> **Tagline:** Self-hosted multi-cloud failover & intelligent load balancer.  
> **Zero External Dependencies • Sub-second Failover • Multi-Cloud Freedom**

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Next.js](https://img.shields.io/badge/Next.js-15-black)](https://nextjs.org)
[![Cloudflare](https://img.shields.io/badge/Edge-Cloudflare%20Workers-orange)](https://workers.cloudflare.com)

---

## 🌟 Overview

**AnyOrigin** is an edge-first traffic coordinator and self-hosted multi-cloud failover engine designed for modern web applications. It allows developers and agencies to chain multiple hosting providers (e.g., **Vercel, Netlify, Render, cPanel, VPS, Koyeb**) behind a single domain without expensive monthly cloud load-balancer subscriptions or complex external database clusters.

If your primary host exhausts monthly free-tier quotas (such as HTTP `402 Payment Required` or `429 Too Many Requests`), AnyOrigin automatically reroutes incoming visitors to healthy standby hosts in under 1 second without dropping authentication cookies or bouncing users off your primary domain.

---

## ⚡ Architectural Highlights & Built-in Protections

* **Edge-First Proxying (No SPOF):** Traffic routes at Cloudflare's global edge network (0ms cold start, free tier). The AnyOrigin dashboard acts as the configuration Control Plane, ensuring the proxy itself never gets paused by serverless limits.
* **Smart Host & Location Rewriting:** When proxying to multi-tenant backends (e.g. `my-app.netlify.app`), AnyOrigin rewrites the upstream `Host` header so Netlify accepts the request, and intercepts upstream `Location` redirects and `Set-Cookie` headers so visitors remain permanently on your primary `example.com` domain.
* **Safe Idempotency Protection:** Blindly retrying `POST` requests on server errors can cause duplicate database writes or double billing. AnyOrigin safely retries `GET`/`HEAD`/`OPTIONS` on all failures, but **only retries `POST`/`PUT` on explicit `402` (payment quota) or `429` (rate limit)** where the cloud platform guarantees the request was rejected before processing.
* **SSRF Shield:** Strict URL validation prevents private IP probing (`127.0.0.1`, `10.0.0.0/8`, `192.168.0.0/16`, AWS/GCP metadata `169.254.169.254`).
* **Zero External Database:** Runs on an embedded, WAL-journaled SQLite database (`data/anyorigin.db`). No PostgreSQL, MySQL, or Redis required.

---

## 🔐 Session & Auth Continuity Guide

AnyOrigin transparently proxies `Cookie`, `Set-Cookie`, and `Authorization` headers. For sessions to persist seamlessly across different hosting providers during failover:
* **Stateless Auth:** Use JWT / Bearer tokens stored in HTTP-only cookies or local storage.
* **External Managed Auth & DB:** Back your application with a shared database (e.g. Neon, Supabase, PlanetScale) or external auth provider (Clerk, Supabase Auth, Firebase Auth). In-memory server sessions (like local Express session storage) will not be accessible to standby hosts.

---

## 🚀 Quick Start (Dashboard & Control Plane)

### 1. Clone & Install
```bash
git clone https://github.com/themubiin/AnyOrigin.git
cd AnyOrigin
npm install
```

### 2. Run Locally
```bash
npm run dev
```
Open [http://localhost:3000](http://localhost:3000) in your browser.  
Default Admin Password: `admin123` (override via `ADMIN_PASSWORD` environment variable).

### 3. Production Build
```bash
npm run build
npm start
```

---

## 🌐 Deployment Modes

### Mode 1: Cloudflare Edge Worker (Recommended / Zero-Cost)
1. In the AnyOrigin dashboard, click **Deploy / Export Engines**.
2. Select **Cloudflare Edge Worker** and click **Copy Code**.
3. Create a Worker in your Cloudflare dashboard and paste the script (or deploy via Wrangler).
4. Add your domain route in Cloudflare DNS. Traffic is now automatically balanced across your hosts with 0ms edge routing!

### Mode 2: cPanel / Shared Hosting (`proxy.php`)
1. Download or copy the standalone [proxy.php](file:///Users/mubin/Desktop/untitled%20folder/proxy.php) file from this repository.
2. Upload it to your cPanel `public_html` directory.
3. It intercepts requests and proxies them with cURL streaming and automatic failover—no Node.js or database required on your cPanel.

### Mode 3: Self-Hosted Docker / VPS
Run AnyOrigin as a standalone container on any cheap $3/month VPS to manage traffic for dozens of websites.

---

## 📁 Project Structure

```
├── .gsd/                  # GSD spec-driven development specifications & ADRs
│   ├── decisions.md       # Architecture Decision Records (SPOF, SSRF, Rewrites)
│   ├── SPEC.md            # Finalized project specification
│   └── ROADMAP.md         # Milestone and phase roadmap
├── data/                  # Embedded SQLite storage (anyorigin.db)
├── proxy.php              # Standalone single-file PHP reverse proxy for cPanel
├── src/
│   ├── app/
│   │   ├── api/           # REST APIs (Sites, Origins, Rules, Logs, Health Test)
│   │   ├── login/         # Sleek dark-mode admin login
│   │   ├── globals.css    # Tailwind styles & theme variables
│   │   └── page.tsx       # Live interactive AnyOrigin dashboard
│   ├── lib/
│   │   ├── auth.ts        # HMAC session token signing & verification
│   │   ├── db.ts          # Zero-dependency SQLite manager & schema migrator
│   │   ├── security.ts    # SSRF guard & IP whitelist validator
│   │   └── services.ts    # Business logic & audit event logging
│   └── types/             # TypeScript interfaces
└── scripts/
    └── verify-all.ts      # Empirical verification test suite
```

---

## 📄 License
MIT License. Built for the global developer community.
