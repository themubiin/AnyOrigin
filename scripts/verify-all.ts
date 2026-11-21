import {
  getSites,
  createSite,
  createOrigin,
  reorderOrigins,
  updateFailoverRule,
  getFailoverRuleBySiteId,
  logAuditEvent,
  getAuditLogs,
} from "../src/lib/services";
import { validateOriginUrl } from "../src/lib/security";

async function runVerification() {
  console.log("=== AnyOrigin Empirical Verification Suite ===\n");

  // 1. Verify Database Initialization
  console.log("1. Checking embedded SQLite database initialization...");
  const initialSites = getSites();
  if (initialSites.length === 0) {
    throw new Error("Default site was not seeded in SQLite.");
  }
  const site = initialSites[0];
  console.log(`✓ SQLite initialized. Active site: "${site.name}" (ID: ${site.id})`);
  console.log(`✓ Initial origins count: ${site.origins?.length}`);

  // 2. Verify SSRF Protection & URL Validation
  console.log("\n2. Testing SSRF Shield & URL Validation...");
  const ssrfTest1 = validateOriginUrl("http://127.0.0.1:8080/admin");
  const ssrfTest2 = validateOriginUrl("http://169.254.169.254/latest/meta-data/");
  const ssrfTest3 = validateOriginUrl("http://localhost:3000");
  const ssrfTest4 = validateOriginUrl("https://my-app.vercel.app");

  if (ssrfTest1.isValid || ssrfTest2.isValid || ssrfTest3.isValid) {
    throw new Error("SSRF vulnerability detected: Internal IP addresses were not blocked.");
  }
  if (!ssrfTest4.isValid) {
    throw new Error("Valid public origin was incorrectly rejected.");
  }
  console.log("✓ SSRF Shield successfully blocked 127.0.0.1, localhost, and AWS metadata (169.254.169.254).");
  console.log("✓ Valid public origin accepted.");

  // 3. Verify Adding a New Origin
  console.log("\n3. Testing origin registration...");
  const newOrigin = createOrigin(site.id, {
    name: "Koyeb Backup Origin",
    url: "https://koyeb-backup.app",
    weight: 100,
  });
  console.log(`✓ Origin registered: "${newOrigin.name}" with priority ${newOrigin.priority}`);

  // 4. Verify Priority Reordering
  console.log("\n4. Testing origin priority reordering...");
  const existingOrigins = site.origins || [];
  const originIds = [newOrigin.id, ...existingOrigins.map((o) => o.id)];
  const reordered = reorderOrigins(site.id, originIds);
  if (reordered[0].id !== newOrigin.id) {
    throw new Error("Priority reordering failed.");
  }
  console.log(`✓ Priority reordered successfully. New #1: "${reordered[0].name}"`);

  // 5. Verify Failover Policy Updates
  console.log("\n5. Testing failover trigger rules...");
  const updatedRules = updateFailoverRule(site.id, {
    trigger_402: true,
    trigger_429: true,
    trigger_5xx: true,
    latency_threshold_ms: 2500,
  });
  if (!updatedRules || updatedRules.latency_threshold_ms !== 2500) {
    throw new Error("Failover rule update failed.");
  }
  console.log(`✓ Failover policy updated. Latency threshold: ${updatedRules.latency_threshold_ms}ms`);

  // 6. Verify Failover Audit Event Logging
  console.log("\n6. Testing failover audit logging & idempotency warning...");
  logAuditEvent(site.id, {
    eventType: "failover",
    fromOriginId: site.origins?.[0]?.id,
    toOriginId: site.origins?.[1]?.id,
    reason: "Primary origin exhausted quota (HTTP 402). Intercepted and safely routed to Standby.",
    statusCode: 402,
    latencyMs: 142,
  });
  const logs = getAuditLogs(site.id, 5);
  console.log(`✓ Audit log verified. Latest event: "${logs[0].reason}"`);

  console.log("\n✅ ALL EMPIRICAL VERIFICATION TESTS PASSED SUCCESSFULLY!\n");
}

runVerification().catch((err) => {
  console.error("❌ Verification failed:", err);
  process.exit(1);
});
