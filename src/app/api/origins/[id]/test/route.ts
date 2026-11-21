import { NextRequest, NextResponse } from "next/server";
import { getDb } from "@/lib/db";
import { updateOrigin } from "@/lib/services";

export async function POST(
  _req: NextRequest,
  { params }: { params: Promise<{ id: string }> }
) {
  try {
    const { id } = await params;
    const db = getDb();
    const origin = db.prepare("SELECT * FROM origins WHERE id = ?").get(id) as any;

    if (!origin) {
      return NextResponse.json({ error: "Origin not found" }, { status: 404 });
    }

    const startTime = Date.now();
    try {
      const controller = new AbortController();
      const timeoutId = setTimeout(() => controller.abort(), 5000);

      const res = await fetch(origin.url, {
        method: "HEAD",
        headers: {
          "User-Agent": "AnyOrigin-Probe/1.0",
          ...(origin.host_header_override ? { Host: origin.host_header_override } : {}),
        },
        signal: controller.signal,
      });

      clearTimeout(timeoutId);
      const latencyMs = Date.now() - startTime;
      const isHealthy = res.status < 500 && res.status !== 402 && res.status !== 429;
      const status = isHealthy ? "healthy" : "failing";

      updateOrigin(id, {
        status,
        latency_ms: latencyMs,
      });

      return NextResponse.json({
        id,
        url: origin.url,
        isHealthy,
        statusCode: res.status,
        latencyMs,
      });
    } catch (err: any) {
      const latencyMs = Date.now() - startTime;
      updateOrigin(id, {
        status: "down",
        latency_ms: latencyMs,
      });

      return NextResponse.json({
        id,
        url: origin.url,
        isHealthy: false,
        latencyMs,
        error: err.name === "AbortError" ? "Timeout (>5000ms)" : err.message,
      });
    }
  } catch (error: any) {
    return NextResponse.json({ error: error.message }, { status: 500 });
  }
}
