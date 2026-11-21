import { NextRequest, NextResponse } from "next/server";
import { getAuditLogs } from "@/lib/services";

export async function GET(
  req: NextRequest,
  { params }: { params: Promise<{ id: string }> }
) {
  try {
    const { id } = await params;
    const url = new URL(req.url);
    const limit = parseInt(url.searchParams.get("limit") || "50", 10);

    const logs = getAuditLogs(id, limit);
    return NextResponse.json({ logs });
  } catch (error: any) {
    return NextResponse.json({ error: error.message }, { status: 500 });
  }
}
