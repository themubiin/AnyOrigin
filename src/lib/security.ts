import { URL } from "url";

// Private IPv4 and IPv6 CIDR / pattern checks
const PRIVATE_IP_PATTERNS = [
  /^127\./,                         // Loopback (127.0.0.0/8)
  /^10\./,                          // Private Class A (10.0.0.0/8)
  /^172\.(1[6-9]|2[0-9]|3[0-1])\./, // Private Class B (172.16.0.0/12)
  /^192\.168\./,                    // Private Class C (192.168.0.0/16)
  /^169\.254\./,                    // Link-Local / Cloud Metadata (169.254.0.0/16)
  /^0\./,                           // Zero addresses (0.0.0.0/8)
  /^localhost$/i,                   // Localhost
  /^::1$/,                          // IPv6 loopback
  /^fe80:/i,                        // IPv6 link-local
  /^fc00:/i,                        // IPv6 unique local
  /^fd00:/i,                        // IPv6 unique local
  /^::ffff:127\./i,                 // IPv4-mapped IPv6 loopback
];

export interface ValidationResult {
  isValid: boolean;
  error?: string;
}

/**
 * Validates an upstream origin URL against SSRF vulnerabilities,
 * malicious internal IP addresses, and disallowed schemes.
 */
export function validateOriginUrl(rawUrl: string): ValidationResult {
  if (!rawUrl || typeof rawUrl !== "string") {
    return { isValid: false, error: "Origin URL is required." };
  }

  let parsed: URL;
  try {
    parsed = new URL(rawUrl.trim());
  } catch {
    return { isValid: false, error: "Invalid URL format." };
  }

  // Scheme must be http or https
  if (parsed.protocol !== "http:" && parsed.protocol !== "https:") {
    return { isValid: false, error: "Only HTTP and HTTPS protocols are permitted." };
  }

  const hostname = parsed.hostname.toLowerCase();

  // Check against private IP & localhost patterns
  for (const pattern of PRIVATE_IP_PATTERNS) {
    if (pattern.test(hostname)) {
      return {
        isValid: false,
        error: `SSRF Blocked: Private/internal target (${hostname}) is not allowed as an upstream host.`,
      };
    }
  }

  // Disallow userinfo (e.g. http://admin:pass@host)
  if (parsed.username || parsed.password) {
    return { isValid: false, error: "URLs containing user credentials are not permitted." };
  }

  return { isValid: true };
}
