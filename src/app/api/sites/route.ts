import { NextRequest, NextResponse } from "next/server";
import { getSites, createSite } from "@/lib/services";

export async function GET() {
  try {
    const sites = getSites();
    return NextResponse.json({ sites });
  } catch (error: any) {
    return NextResponse.json({ error: error.message }, { status: 500 });
  }
}

export async function POST(req: NextRequest) {
  try {
    const { name, domain } = await req.json();
    if (!name || !domain) {
      return NextResponse.json({ error: "Name and domain are required" }, { status: 400 });
    }

    const site = createSite(name, domain);
    return NextResponse.json({ site }, { status: 201 });
  } catch (error: any) {
    return NextResponse.json({ error: error.message }, { status: 500 });
  }
}
