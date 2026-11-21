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

  // Buffer body for non-GET/HEAD requests so we can re-send on retry
  let bodyBuffer: ArrayBuffer | null = null;
  if (req.method !== "GET" && req.method !== "HEAD") {
    bodyBuffer = await req.arrayBuffer();
  }

  for (let i = 0; i < origins.length; i++) {
    const origin = origins[i];
    const targetUrl = new URL(pathname + search, origin.url);

    const headers = new Headers(req.headers);
    headers.set("Host", origin.host_header_override || targetUrl.host);
    headers.set("X-Forwarded-Host", req.headers.get("host") || "");
    headers.set("X-Forwarded-Proto", req.nextUrl.protocol.replace(":", ""));
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

      const is402 = rules?.trigger_402 && upstreamRes.status === 402;
      const is429 = rules?.trigger_429 && upstreamRes.status === 429;
      const is5xx = rules?.trigger_5xx && upstreamRes.status >= 500;

      if ((is402 || is429 || is5xx) && i < origins.length - 1) {
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

      // Construct client response preserving headers
      const resHeaders = new Headers();
      upstreamRes.headers.forEach((val, key) => {
        // Strip transfer-encoding to avoid stream chunk conflicts
        if (key.toLowerCase() !== "transfer-encoding") {
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
      if (i < origins.length - 1) {
        const nextOrigin = origins[i + 1];
        logAuditEvent(site.id, {
          eventType: "failover",
          fromOriginId: origin.id,
          toOriginId: nextOrigin.id,
          reason: `Origin ${origin.name} network failure (${err.message}). Switched to ${nextOrigin.name}`,
          latencyMs,
        });
        continue;
      }

      return new NextResponse(`All AnyOrigin upstreams failed: ${err.message}`, {
        status: 502,
      });
    }
  }

  return new NextResponse("Gateway error", { status: 502 });
}
