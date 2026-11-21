import { NextRequest, NextResponse } from "next/server";
import { getSiteById } from "@/lib/services";

export async function GET(
  _req: NextRequest,
  { params }: { params: Promise<{ id: string }> }
) {
  try {
    const { id } = await params;
    const site = getSiteById(id);
    if (!site) {
      return NextResponse.json({ error: "Site not found" }, { status: 404 });
    }
    return NextResponse.json({ site });
  } catch (error: any) {
    return NextResponse.json({ error: error.message }, { status: 500 });
  }
}
