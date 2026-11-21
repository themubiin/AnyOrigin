import { NextRequest, NextResponse } from "next/server";
import { getFailoverRuleBySiteId, updateFailoverRule } from "@/lib/services";

export async function GET(
  _req: NextRequest,
  { params }: { params: Promise<{ id: string }> }
) {
  try {
    const { id } = await params;
    const rules = getFailoverRuleBySiteId(id);
    if (!rules) {
      return NextResponse.json({ error: "Rules not found" }, { status: 404 });
    }
    return NextResponse.json({ rules });
  } catch (error: any) {
    return NextResponse.json({ error: error.message }, { status: 500 });
  }
}

export async function PATCH(
  req: NextRequest,
  { params }: { params: Promise<{ id: string }> }
) {
  try {
    const { id } = await params;
    const body = await req.json();

    const updated = updateFailoverRule(id, body);
    if (!updated) {
      return NextResponse.json({ error: "Rules not found" }, { status: 404 });
    }

    return NextResponse.json({ rules: updated });
  } catch (error: any) {
    return NextResponse.json({ error: error.message }, { status: 500 });
  }
}
