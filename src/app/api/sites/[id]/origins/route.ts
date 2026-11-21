import { NextRequest, NextResponse } from "next/server";
import { getOriginsBySiteId, createOrigin, reorderOrigins } from "@/lib/services";
import { validateOriginUrl } from "@/lib/security";

export async function GET(
  _req: NextRequest,
  { params }: { params: Promise<{ id: string }> }
) {
  try {
    const { id } = await params;
    const origins = getOriginsBySiteId(id);
    return NextResponse.json({ origins });
  } catch (error: any) {
    return NextResponse.json({ error: error.message }, { status: 500 });
  }
}

export async function POST(
  req: NextRequest,
  { params }: { params: Promise<{ id: string }> }
) {
  try {
    const { id } = await params;
    const body = await req.json();

    if (!body.name || !body.url) {
      return NextResponse.json({ error: "Origin name and URL are required" }, { status: 400 });
    }

    const validation = validateOriginUrl(body.url);
    if (!validation.isValid) {
      return NextResponse.json({ error: validation.error }, { status: 400 });
    }

    const origin = createOrigin(id, {
      name: body.name,
      url: body.url,
      priority: body.priority,
      weight: body.weight,
      host_header_override: body.host_header_override,
    });

    return NextResponse.json({ origin }, { status: 201 });
  } catch (error: any) {
    return NextResponse.json({ error: error.message }, { status: 500 });
  }
}

export async function PUT(
  req: NextRequest,
  { params }: { params: Promise<{ id: string }> }
) {
  try {
    const { id } = await params;
    const { orderedOriginIds } = await req.json();

    if (!Array.isArray(orderedOriginIds)) {
      return NextResponse.json({ error: "orderedOriginIds must be an array" }, { status: 400 });
    }

    const updatedOrigins = reorderOrigins(id, orderedOriginIds);
    return NextResponse.json({ origins: updatedOrigins });
  } catch (error: any) {
    return NextResponse.json({ error: error.message }, { status: 500 });
  }
}
