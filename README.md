# 🛡️ AnyOrigin

> **Tagline:** Self-hosted multi-cloud failover & intelligent load balancer.  
> **Zero External Dependencies • Sub-second Failover • Multi-Cloud Freedom**

---

## 🌟 Overview

**AnyOrigin** is a self-hosted traffic manager and multi-cloud failover engine designed for modern web applications. It allows developers and agencies to chain multiple hosting providers (e.g., **Vercel, Netlify, Render, cPanel, VPS, Koyeb**) behind a single domain without expensive monthly cloud load-balancer subscriptions or complex external database clusters.

If your primary host crashes or exhausts monthly free-tier quotas (such as HTTP `402 Payment Required` or `429 Too Many Requests`), AnyOrigin automatically reroutes incoming visitors to healthy standby hosts in under 1 second without dropping authentication cookies or breaking user sessions.

---

## ⚡ Key Highlights

* **Zero External Database:** Runs on an embedded, WAL-journaled SQLite database (`data/anyorigin.db`). No PostgreSQL, MySQL, or Redis required.
* **Dual Execution Engine:**
  1. **Cloudflare Edge Mode (0ms cold start, $0/month):** 1-click generated Edge Worker that balances traffic across the globe at Cloudflare's edge network.
  2. **Native Node.js / PHP Mode:** Runs directly on your VPS, Docker container, or shared hosting via a single-file `proxy.php`.
* **Smart Failover Rules:**
  - `HTTP 402` (Cloud Payment / Free-tier Quota Exceeded)
  - `HTTP 429` (Rate Limit / Too Many Requests)
  - `HTTP 500, 502, 503, 504` (Server Crashes & Gateway Timeouts)
  - Latency Threshold (configurable in ms)
* **Session & Cookie Protection:** Forwarding of `Cookie`, `Set-Cookie`, `Authorization`, and `Host` headers ensures user logins remain active during failover hops.
* **Live Analytics & Audit Logs:** Real-time latency tracking, health status pills, and incident audit trails.

---

## 🚀 Quick Start (Dashboard & API)

### 1. Clone & Install
```bash
git clone https://github.com/your-repo/anyorigin.git
cd anyorigin
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

### Mode 1: Cloudflare Edge Worker (Recommended)
1. In the AnyOrigin dashboard, click **Deploy / Export Engines**.
2. Select **Cloudflare Edge Worker** and click **Copy Code**.
3. Create a Worker in your Cloudflare dashboard and paste the script (or deploy via Wrangler).
4. Add your domain route in Cloudflare DNS. Traffic is now automatically balanced across your hosts with 0ms edge routing!

### Mode 2: cPanel / Shared Hosting (`proxy.php`)
1. Download or copy the standalone [proxy.php](file:///Users/mubin/Desktop/untitled%20folder/proxy.php) file from this repository.
2. Upload it to your cPanel `public_html` directory.
3. It intercepts requests and proxies them with cURL streaming and automatic failover—no Node.js or database required on your cPanel.

### Mode 3: Docker / VPS
Run AnyOrigin as a container on any cheap $3/month VPS to manage traffic for dozens of websites.

---

## 📁 Project Structure

```
├── .gsd/                  # GSD spec-driven development specifications & roadmaps
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
│   │   └── services.ts    # Business logic & audit event logging
│   └── types/             # TypeScript interfaces
└── scripts/
    └── verify-all.ts      # Empirical verification test suite
```

---

## 📄 License
MIT License. Built for the global developer community.
