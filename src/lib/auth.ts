import crypto from "crypto";
import { cookies } from "next/headers";

const SESSION_COOKIE_NAME = "anyorigin_session";
const SESSION_SECRET = process.env.SESSION_SECRET || "anyorigin-super-secret-key-32chars-min!";
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || "admin123";

export function getAdminPassword(): string {
  return ADMIN_PASSWORD;
}

export function signSessionToken(payload: { role: string; timestamp: number }): string {
  const data = JSON.stringify(payload);
  const base64Data = Buffer.from(data).toString("base64url");
  const signature = crypto
    .createHmac("sha256", SESSION_SECRET)
    .update(base64Data)
    .digest("base64url");
  return `${base64Data}.${signature}`;
}

export function verifySessionToken(token: string): { role: string; timestamp: number } | null {
  try {
    const [base64Data, signature] = token.split(".");
    if (!base64Data || !signature) return null;

    const expectedSignature = crypto
      .createHmac("sha256", SESSION_SECRET)
      .update(base64Data)
      .digest("base64url");

    if (signature !== expectedSignature) return null;

    const json = Buffer.from(base64Data, "base64url").toString("utf-8");
    const payload = JSON.parse(json);

    // Expire after 7 days
    const maxAgeMs = 7 * 24 * 60 * 60 * 1000;
    if (Date.now() - payload.timestamp > maxAgeMs) {
      return null;
    }

    return payload;
  } catch {
    return null;
  }
}

export async function isAuthenticated(): Promise<boolean> {
  const cookieStore = await cookies();
  const sessionToken = cookieStore.get(SESSION_COOKIE_NAME)?.value;
  if (!sessionToken) return false;
  return verifySessionToken(sessionToken) !== null;
}

export { SESSION_COOKIE_NAME };
