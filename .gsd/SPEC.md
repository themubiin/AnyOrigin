# SPEC.md — Project Specification: AnyOrigin

> **Status**: `FINALIZED`
>
> **Project Name**: AnyOrigin  
> **Tagline**: *Self-hosted multi-cloud failover & intelligent load balancer.*  
> ⚠️ **Planning Lock**: Spec finalized. Ready for Phase Planning and Execution.

---

## 1. Vision & Elevator Pitch

**AnyOrigin** is an ultra-lightweight, zero-dependency, self-hosted traffic manager and multi-cloud failover engine designed for modern web applications. It allows developers and agencies to chain multiple hosting providers (e.g., Vercel, Netlify, Render, cPanel, VPS, Koyeb) behind a single unified domain without expensive cloud load balancers or external database infrastructure (no external Postgres or Redis required).

When a primary origin fails or exhausts monthly quotas (e.g., HTTP `402 Payment Required` or `429 Too Many Requests`), AnyOrigin seamlessly routes inbound traffic to healthy backup origins in under 1 second without dropping user sessions or breaking auth tokens.

---

## 2. Branding & Positioning

* **Project Name**: AnyOrigin
* **Tagline**: Self-hosted multi-cloud failover & intelligent load balancer.
* **Core Value Proposition**: Zero-cost, zero external database, single-binary / single-container / single-PHP-file portability, multi-cloud freedom.

---

## 3. Core Architecture: The "Zero External Dependency" Principle

```
                   ┌────────────────────────────────────────┐
                   │             Incoming Visitor           │
                   │           (https://example.com)        │
                   └───────────────────┬────────────────────┘
                                       │
              ┌────────────────────────┴────────────────────────┐
              ▼                                                 ▼
     [ Engine A: Edge Mode ]                           [ Engine B: Native Mode ]
    Cloudflare Worker / Edge                         Node.js Reverse Proxy / proxy.php
   (Fastest, 0ms cold, Free Tier)                    (Self-hosted cPanel, VPS, Docker)
              │                                                 │
              └────────────────────────┬────────────────────────┘
                                       │
                      Smart Failover & Health Evaluator
            (Checks: 402 Quota, 429 Rate Limit, 5xx, >3000ms Latency)
                                       │
           ┌───────────────────────────┼───────────────────────────┐
           ▼                           ▼                           ▼
    Primary Origin              Backup Origin 1             Backup Origin 2
   (e.g., Vercel Free)       (e.g., Netlify Free)         (e.g., VPS / cPanel)
      [🟢 Healthy]             [🟡 Hot Standby]            [⚪ Disaster Recovery]
```

1. **Storage Tier**:
   - Single embedded SQLite database (`anyorigin.db`) via lightweight driver with zero external dependencies.
   - Zero external DB setup: no PostgreSQL, no MySQL, no Redis required.
2. **Management Dashboard**:
   - Built with Next.js (App Router), Tailwind CSS, Lucide icons, and Recharts.
   - Self-contained, portable, single-container or easily hostable on Node/VPS/Docker.
   - Built-in session-based or token authentication (single admin password or credentials stored locally).
3. **Dual Execution Engine**:
   - **Mode 1: Cloudflare Edge Worker (Zero Latency / Global Edge)**:
     - 1-click generator or Cloudflare API deployer creating an ultra-lean Cloudflare Worker.
     - Intercepts requests, evaluates origin response codes (402, 429, 5xx), and immediately switches origins.
   - **Mode 2: Native Node.js / PHP Proxy (Self-Hosted / cPanel / Shared Hosting)**:
     - Standalone high-performance Node.js HTTP/HTTPS reverse proxy.
     - Single-file standalone `proxy.php` for shared hosting/cPanel environments with zero dependencies (uses cURL streaming).

---

## 4. Key Functional Requirements

### 4.1 Upstream Origin Management
- Drag-and-drop priority ordering (Primary -> Secondary -> Backup -> Fallback).
- Configure origins with:
  - Origin URL (e.g., `https://my-project.vercel.app`)
  - Custom Host header override
  - Custom headers to inject or strip
  - Active/Passive status toggle
  - Weight / percentage distribution (for optional A/B testing or split routing)

### 4.2 Smart Failover Triggers & Rules
- **HTTP 402 (Payment Required)**: Trigger immediate failover when hosting monthly spending/bandwidth limits are reached.
- **HTTP 429 (Too Many Requests)**: Trigger failover when rate limits are exhausted.
- **HTTP 5xx (500, 502, 503, 504)**: Instant failover on origin server crash or gateway timeout.
- **Latency Threshold**: Automatic reroute if origin latency exceeds threshold (e.g., > 3000ms).
- **Health Check Ping (Heartbeat)**: Configurable background probe to monitor health proactively.

### 4.3 Session & Header Integrity
- Transparent reverse proxying: Visitor always sees `example.com` in their browser bar.
- Full forwarding of `Cookie` / `Set-Cookie`, `Authorization` headers, `X-Forwarded-For`, and request bodies.

### 4.4 Real-time Dashboard & Analytics
- **Live Latency & Uptime Monitoring**: Real-time response time graph per origin.
- **Traffic Split Chart**: Visual breakdown of requests served by Primary vs. Backups.
- **Failover Audit Log**: Timestamped log recording every failover incident and cause.

---

## 5. Non-Goals
- Full enterprise WAF with DDoS layer-7 mitigation rules.
- Distributed multi-region state synchronization requiring Raft/Paxos clusters.

---

## 6. Constraints & Success Criteria
- Must run out-of-the-box on a fresh node/docker or cPanel environment with no external database service required.
- Memory footprint of the proxy engine must be under 50MB.
- Sub-second failover on primary error.

---

*Last updated: 2026-11-21*
