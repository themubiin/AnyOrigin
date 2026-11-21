import { NextRequest, NextResponse } from "next/server";
import { updateOrigin, deleteOrigin } from "@/lib/services";

export async function PATCH(
  req: NextRequest,
  { params }: { params: Promise<{ id: string }> }
) {
  try {
    const { id } = await params;
    const body = await req.json();

    const origin = updateOrigin(id, body);
    if (!origin) {
      return NextResponse.json({ error: "Origin not found" }, { status: 404 });
    }

    return NextResponse.json({ origin });
  } catch (error: any) {
    return NextResponse.json({ error: error.message }, { status: 500 });
  }
}

export async function DELETE(
  _req: NextRequest,
  { params }: { params: Promise<{ id: string }> }
) {
  try {
    const { id } = await params;
    const success = deleteOrigin(id);
    if (!success) {
      return NextResponse.json({ error: "Origin not found" }, { status: 404 });
    }
    return NextResponse.json({ success: true });
  } catch (error: any) {
    return NextResponse.json({ error: error.message }, { status: 500 });
  }
}
