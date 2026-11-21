import { NextRequest, NextResponse } from "next/server";
import { getSites, logAuditEvent } from "@/lib/services";

export async function HEAD(
  req: NextRequest,
  { params }: { params: Promise<{ path: string[] }> }
) {
  return handleProxy(req, params);
}

export async function OPTIONS() {
  return new NextResponse(null, {
    status: 204,
    headers: {
      "Access-Control-Allow-Origin": "*",
      "Access-Control-Allow-Methods": "GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS",
      "Access-Control-Allow-Headers": "*",
    },
  });
}

export async function GET(
  req: NextRequest,
  { params }: { params: Promise<{ path: string[] }> }
) {
  return handleProxy(req, params);
}

export async function POST(
  req: NextRequest,
  { params }: { params: Promise<{ path: string[] }> }
) {
  return handleProxy(req, params);
}

export async function PUT(
  req: NextRequest,
  { params }: { params: Promise<{ path: string[] }> }
) {
  return handleProxy(req, params);
}

export async function PATCH(
  req: NextRequest,
  { params }: { params: Promise<{ path: string[] }> }
) {
  return handleProxy(req, params);
}

export async function DELETE(
  req: NextRequest,
  { params }: { params: Promise<{ path: string[] }> }
) {
  return handleProxy(req, params);
}

async function handleProxy(
  req: NextRequest,
  paramsPromise: Promise<{ path: string[] }>
) {
  const { path } = await paramsPromise;
  const sites = getSites();
  if (sites.length === 0) {
    return new NextResponse("No site configured in AnyOrigin.", { status: 503 });
  }

  const site = sites[0]; // Active site
  const origins = (site.origins || []).filter((o) => o.is_active);
  const rules = site.rules;

  if (origins.length === 0) {
    return new NextResponse("No active upstream origins available.", { status: 502 });
  }

  const pathname = `/${path.join("/")}`;
  const search = req.nextUrl.search;
  const clientHost = req.headers.get("host") || "localhost";
  const clientProtocol = req.nextUrl.protocol.replace(":", "");
  const isSafeMethod = req.method === "GET" || req.method === "HEAD" || req.method === "OPTIONS";

  // Buffer body for non-GET/HEAD requests so we can re-send on retry if safe
  let bodyBuffer: ArrayBuffer | null = null;
  if (!isSafeMethod) {
    bodyBuffer = await req.arrayBuffer();
  }

  for (let i = 0; i < origins.length; i++) {
    const origin = origins[i];
    const targetUrl = new URL(pathname + search, origin.url);
    const upstreamHost = origin.host_header_override || targetUrl.host;

    const headers = new Headers(req.headers);
    headers.set("Host", upstreamHost);
    headers.set("X-Forwarded-Host", clientHost);
    headers.set("X-Forwarded-Proto", clientProtocol);
    headers.set("X-AnyOrigin-Tier", String(i + 1));

    const startTime = Date.now();
    try {
      const controller = new AbortController();
      const timeoutMs = rules?.latency_threshold_ms || 3000;
      const timeoutId = setTimeout(() => controller.abort(), timeoutMs);

      const upstreamRes = await fetch(targetUrl.toString(), {
        method: req.method,
        headers,
        body: bodyBuffer,
        redirect: "manual",
        signal: controller.signal,
      });

      clearTimeout(timeoutId);
      const latencyMs = Date.now() - startTime;

      // Check failover trigger conditions
      const is402 = rules?.trigger_402 && upstreamRes.status === 402;
      const is429 = rules?.trigger_429 && upstreamRes.status === 429;
      const is5xx = rules?.trigger_5xx && upstreamRes.status >= 500;

      // IDEMPOTENCY SAFETY CHECK:
      // If non-idempotent method (POST/PUT/DELETE) and error is 5xx/crash, DO NOT retry
      // to prevent double execution / duplicate charging!
      // Only retry non-idempotent requests on explicit 402 (quota exceeded) or 429 (rate limited),
      // where the upstream platform guaranteed it did NOT process the transaction.
      const canSafelyRetry = isSafeMethod || is402 || is429;

      if ((is402 || is429 || is5xx) && canSafelyRetry && i < origins.length - 1) {
        const nextOrigin = origins[i + 1];
        logAuditEvent(site.id, {
          eventType: "failover",
          fromOriginId: origin.id,
          toOriginId: nextOrigin.id,
          reason: `Origin ${origin.name} returned HTTP ${upstreamRes.status}. Switched to ${nextOrigin.name}`,
          statusCode: upstreamRes.status,
          latencyMs,
        });
        continue; // Failover to next origin!
      }

      // If unsafe method hit 5xx, do NOT retry: return directly with warning header
      if (is5xx && !canSafelyRetry) {
        console.warn(`[AnyOrigin] Suppressed non-idempotent retry for ${req.method} on HTTP ${upstreamRes.status}`);
      }

      // Construct client response with header rewriting
      const resHeaders = new Headers();
      upstreamRes.headers.forEach((val, key) => {
        const lowerKey = key.toLowerCase();

        // 1. Rewrite Location header to keep visitor on the primary client domain
        if (lowerKey === "location") {
          let rewrittenLocation = val;
          try {
            const locUrl = new URL(val, targetUrl.origin);
            if (locUrl.host === targetUrl.host || locUrl.host === upstreamHost) {
              rewrittenLocation = `${clientProtocol}://${clientHost}${locUrl.pathname}${locUrl.search}${locUrl.hash}`;
            }
          } catch {}
          resHeaders.set(key, rewrittenLocation);
          return;
        }

        // 2. Rewrite Set-Cookie domain to bind to client domain
        if (lowerKey === "set-cookie") {
          const rewrittenCookie = val.replace(/Domain=[^;]+/gi, `Domain=${clientHost.split(":")[0]}`);
          resHeaders.append(key, rewrittenCookie);
          return;
        }

        // 3. Strip transfer-encoding to avoid stream chunk conflicts
        if (lowerKey !== "transfer-encoding") {
          resHeaders.set(key, val);
        }
      });

      resHeaders.set("X-Served-By-Origin", origin.name);
      resHeaders.set("X-Origin-Latency", `${latencyMs}ms`);

      return new NextResponse(upstreamRes.body, {
        status: upstreamRes.status,
        statusText: upstreamRes.statusText,
        headers: resHeaders,
      });
    } catch (err: any) {
      const latencyMs = Date.now() - startTime;
      // If safe method, failover on network/timeout error
      if (isSafeMethod && i < origins.length - 1) {
        const nextOrigin = origins[i + 1];
        logAuditEvent(site.id, {
          eventType: "failover",
          fromOriginId: origin.id,
          toOriginId: nextOrigin.id,
          reason: `Origin ${origin.name} connection dropped (${err.message}). Switched to ${nextOrigin.name}`,
          latencyMs,
        });
        continue;
      }

      return new NextResponse(`All AnyOrigin upstreams failed or unsafe to retry: ${err.message}`, {
        status: 502,
      });
    }
  }

  return new NextResponse("Gateway error", { status: 502 });
}
