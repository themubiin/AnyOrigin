# Architecture Decision Record (ADR) — AnyOrigin

## ADR-001: Mitigation of Single-Point-of-Failure (SPOF) via Edge-First Architecture
* **Context**: If the reverse proxy itself is hosted on a free-tier serverless platform (e.g. Vercel), when that platform hits billing or quota limits, the proxy halts as well, rendering multi-cloud failover impossible.
* **Decision**: 
  - The production ingress engine is designed as **Edge-First** via Cloudflare Workers (0ms cold start, globally distributed, independent of Vercel/Netlify quotas).
  - The Next.js dashboard acts as the **Control Plane** (runnable on VPS, Docker, or local machine) to configure and export edge/proxy deployments.
  - Native Node/PHP proxy modes are provided for dedicated VPS or cPanel environments with zero external dependencies.

---

## ADR-002: Storage Layer Architecture
* **Context**: Serverless environments have ephemeral file systems, making embedded SQLite databases non-persistent across container recycles.
* **Decision**:
  - Embedded SQLite (`better-sqlite3` with WAL mode) is used for self-hosted instances (VPS, Docker, local).
  - Cloudflare Edge Worker mode embeds or fetches configurations directly without requiring an active database server at request time.

---

## ADR-003: Host Header, Redirect Location, and Cookie Domain Rewriting
* **Context**: When routing traffic to a backup provider (e.g. `my-app.netlify.app`), sending the original `Host: example.com` header causes modern multi-tenant cloud platforms to reject the request with 404 or domain mismatch. Furthermore, upstream redirects (`Location: https://my-app.netlify.app/...`) would bounce the user away from the primary domain.
* **Decision**:
  - The proxy rewrites the upstream `Host` header to match the destination project.
  - Passes `X-Forwarded-Host: example.com` and `X-Forwarded-Proto: https` to the backend.
  - Intercepts and rewrites HTTP `Location` headers so any upstream redirects stay anchored to `example.com`.
  - Rewrites `Set-Cookie` domain attributes to match the client host.

---

## ADR-004: SSRF & Open Proxy Prevention
* **Context**: Reverse proxies can be weaponized to probe internal private subnets (`127.0.0.1`, `10.0.0.0/8`, `192.168.0.0/16`) or cloud instance metadata services (`169.254.169.254`).
* **Decision**:
  - Implemented strict URL validation in `src/lib/security.ts`.
  - All origin additions and edits reject private IPv4/IPv6 CIDR ranges, link-local metadata endpoints, and non-HTTP(S) schemes.

---

## ADR-005: Idempotency Protection for Non-Safe HTTP Requests
* **Context**: Blindly retrying non-idempotent HTTP methods (`POST`, `PUT`, `DELETE`) on server `500` errors or timeouts can cause severe business side-effects, such as duplicate order creation or double billing.
* **Decision**:
  - Safe methods (`GET`, `HEAD`, `OPTIONS`) are safely retried across all configured failover triggers.
  - Non-safe methods (`POST`, `PUT`, `DELETE`) are **only** retried when the upstream platform explicitly returns `HTTP 402` (Payment Required) or `HTTP 429` (Rate Limited), where the platform guarantees the request was rejected before business processing.
  - Retries for non-safe methods are suppressed on `500` server crashes and connection drops.

---

## ADR-006: Session Continuity Requirements
* **Context**: Sessions maintained purely in-memory on Server 1 will not exist on Server 2 after failover.
* **Decision**:
  - AnyOrigin transparently forwards `Cookie`, `Set-Cookie`, and `Authorization` headers.
  - Project documentation explicitly guides developers to adopt stateless authentication (JWT, Bearer tokens, or external auth providers like Supabase, Clerk, Firebase, external DB) for zero-downtime multi-cloud session preservation.
