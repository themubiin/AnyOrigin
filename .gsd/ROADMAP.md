---
milestone: v1.0-mvp
version: 1.0.0
updated: 2026-11-21T19:31:00+06:00
---

# Roadmap: AnyOrigin

> **Tagline:** Self-hosted multi-cloud failover & intelligent load balancer.  
> **Current Phase:** Phase 1 - Foundation & Zero-Dependency Data Engine  
> **Status:** executing

## Must-Haves (from SPEC)

- [ ] Zero-dependency embedded database & configuration layer (SQLite / single JSON store)
- [ ] Next.js 15+ Admin Dashboard with Tailwind CSS, dark aesthetic, live stats, origin management, and rule builder
- [ ] Dual-Engine Reverse Proxy:
  - [ ] Cloudflare Edge Worker generator with session/auth pass-through & instant failover
  - [ ] Standalone Node.js reverse proxy engine & single-file PHP proxy (`proxy.php`)
- [ ] Active and passive failover triggers: HTTP 402, HTTP 429, HTTP 5xx, latency timeout
- [ ] Live origin health checker & probe monitor with audit logs

---

## Phases

### Phase 1: Core Architecture & Data Engine
**Status:** ✅ Complete
**Objective:** Project initialization, zero-dependency data store (SQLite), schema design, authentication, and core API models.
**Requirements:** SPEC Section 3 & 4.1

**Plans:**
- [x] Plan 1.1: Project initialization & Next.js scaffolding with Tailwind & dark theme
- [x] Plan 1.2: Embedded SQLite database engine (zero external dependencies)
- [x] Plan 1.3: Admin authentication & session security
- [x] Plan 1.4: REST API routes for Sites, Origins, and Failover Rules

---

### Phase 2: Dashboard UI & Origin Management
**Status:** ✅ Complete
**Objective:** Build high-performance, dark-mode dashboard UI for managing upstream origins, priority ordering, and custom failover rules.
**Depends on:** Phase 1

---

### Phase 3: Dual-Engine Traffic Proxies (Edge & Native)
**Status:** ✅ Complete
**Objective:** Build the proxy execution engines that intercept and reroute traffic with session preservation.
**Depends on:** Phase 2

---

### Phase 4: Active Health Probing & Verification
**Status:** ✅ Complete
**Objective:** Implement background heartbeat probes, simulated failover tests, and documentation.
**Depends on:** Phase 3

---

## Progress Summary

| Phase | Status | Plans | Complete |
|-------|--------|-------|----------|
| 1: Foundation & Data | ✅ | 4/4 | 100% |
| 2: Dashboard UI | ✅ | 3/3 | 100% |
| 3: Proxy Engines | ✅ | 3/3 | 100% |
| 4: Health & Verification | ✅ | 3/3 | 100% |
