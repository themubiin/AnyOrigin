import { getDb } from "./db";
import { Site, Origin, FailoverRule, AuditLog, OriginStatus } from "@/types";
import crypto from "crypto";

export function getSites(): Site[] {
  const db = getDb();
  const sites = db.prepare("SELECT * FROM sites ORDER BY created_at DESC").all() as Site[];

  for (const site of sites) {
    site.origins = getOriginsBySiteId(site.id);
    site.rules = getFailoverRuleBySiteId(site.id) || undefined;
  }
  return sites;
}

export function getSiteById(id: string): Site | null {
  const db = getDb();
  const site = db.prepare("SELECT * FROM sites WHERE id = ?").get(id) as Site | undefined;
  if (!site) return null;

  site.origins = getOriginsBySiteId(site.id);
  site.rules = getFailoverRuleBySiteId(site.id) || undefined;
  return site;
}

export function createSite(name: string, domain: string): Site {
  const db = getDb();
  const id = `site-${crypto.randomUUID().slice(0, 8)}`;
  const now = new Date().toISOString();

  db.prepare(`
    INSERT INTO sites (id, name, domain, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?)
  `).run(id, name, domain, now, now);

  // Create default rules for the site
  db.prepare(`
    INSERT INTO failover_rules (id, site_id, trigger_402, trigger_429, trigger_5xx, latency_threshold_ms, max_retries, retry_interval_ms, created_at, updated_at)
    VALUES (?, ?, 1, 1, 1, 3000, 2, 500, ?, ?)
  `).run(`rule-${id}`, id, now, now);

  return getSiteById(id)!;
}

export function getOriginsBySiteId(siteId: string): Origin[] {
  const db = getDb();
  const rows = db.prepare(`
    SELECT * FROM origins 
    WHERE site_id = ? 
    ORDER BY priority ASC
  `).all(siteId) as Array<Omit<Origin, "is_active"> & { is_active: number }>;

  return rows.map((r) => ({
    ...r,
    is_active: Boolean(r.is_active),
  }));
}

export function createOrigin(
  siteId: string,
  data: {
    name: string;
    url: string;
    priority?: number;
    weight?: number;
    host_header_override?: string;
  }
): Origin {
  const db = getDb();
  const id = `orig-${crypto.randomUUID().slice(0, 8)}`;
  const now = new Date().toISOString();

  // If no priority provided, make it highest current priority + 1
  let priority = data.priority;
  if (!priority) {
    const maxPriorityRow = db.prepare(`
      SELECT MAX(priority) as max_priority FROM origins WHERE site_id = ?
    `).get(siteId) as { max_priority: number | null };
    priority = (maxPriorityRow?.max_priority || 0) + 1;
  }

  const hostOverride = data.host_header_override || new URL(data.url).host;

  db.prepare(`
    INSERT INTO origins (id, site_id, name, url, priority, weight, host_header_override, is_active, status, latency_ms, last_checked_at, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, 1, 'healthy', 0, ?, ?, ?)
  `).run(
    id,
    siteId,
    data.name,
    data.url,
    priority,
    data.weight ?? 100,
    hostOverride,
    now,
    now,
    now
  );

  return db.prepare("SELECT * FROM origins WHERE id = ?").get(id) as Origin;
}

export function updateOrigin(
  id: string,
  data: Partial<Pick<Origin, "name" | "url" | "priority" | "weight" | "host_header_override" | "is_active" | "status" | "latency_ms">>
): Origin | null {
  const db = getDb();
  const existing = db.prepare("SELECT * FROM origins WHERE id = ?").get(id) as Origin | undefined;
  if (!existing) return null;

  const now = new Date().toISOString();
  const updated = {
    name: data.name ?? existing.name,
    url: data.url ?? existing.url,
    priority: data.priority ?? existing.priority,
    weight: data.weight ?? existing.weight,
    host_header_override: data.host_header_override !== undefined ? data.host_header_override : existing.host_header_override,
    is_active: data.is_active !== undefined ? (data.is_active ? 1 : 0) : (existing.is_active ? 1 : 0),
    status: data.status ?? existing.status,
    latency_ms: data.latency_ms ?? existing.latency_ms,
    updated_at: now,
  };

  db.prepare(`
    UPDATE origins
    SET name = ?, url = ?, priority = ?, weight = ?, host_header_override = ?, is_active = ?, status = ?, latency_ms = ?, updated_at = ?
    WHERE id = ?
  `).run(
    updated.name,
    updated.url,
    updated.priority,
    updated.weight,
    updated.host_header_override,
    updated.is_active,
    updated.status,
    updated.latency_ms,
    updated.updated_at,
    id
  );

  return db.prepare("SELECT * FROM origins WHERE id = ?").get(id) as Origin;
}

export function deleteOrigin(id: string): boolean {
  const db = getDb();
  const result = db.prepare("DELETE FROM origins WHERE id = ?").run(id);
  return result.changes > 0;
}

export function reorderOrigins(siteId: string, orderedOriginIds: string[]): Origin[] {
  const db = getDb();
  const updateStmt = db.prepare("UPDATE origins SET priority = ?, updated_at = ? WHERE id = ? AND site_id = ?");
  const now = new Date().toISOString();

  const transaction = db.transaction((ids: string[]) => {
    ids.forEach((id, index) => {
      updateStmt.run(index + 1, now, id, siteId);
    });
  });

  transaction(orderedOriginIds);
  return getOriginsBySiteId(siteId);
}

export function getFailoverRuleBySiteId(siteId: string): FailoverRule | null {
  const db = getDb();
  const row = db.prepare("SELECT * FROM failover_rules WHERE site_id = ?").get(siteId) as any;
  if (!row) return null;

  return {
    ...row,
    trigger_402: Boolean(row.trigger_402),
    trigger_429: Boolean(row.trigger_429),
    trigger_5xx: Boolean(row.trigger_5xx),
  };
}

export function updateFailoverRule(
  siteId: string,
  rules: Partial<Pick<FailoverRule, "trigger_402" | "trigger_429" | "trigger_5xx" | "latency_threshold_ms" | "max_retries" | "retry_interval_ms">>
): FailoverRule | null {
  const db = getDb();
  const existing = getFailoverRuleBySiteId(siteId);
  if (!existing) return null;

  const now = new Date().toISOString();
  db.prepare(`
    UPDATE failover_rules
    SET trigger_402 = ?, trigger_429 = ?, trigger_5xx = ?, latency_threshold_ms = ?, max_retries = ?, retry_interval_ms = ?, updated_at = ?
    WHERE site_id = ?
  `).run(
    rules.trigger_402 !== undefined ? (rules.trigger_402 ? 1 : 0) : (existing.trigger_402 ? 1 : 0),
    rules.trigger_429 !== undefined ? (rules.trigger_429 ? 1 : 0) : (existing.trigger_429 ? 1 : 0),
    rules.trigger_5xx !== undefined ? (rules.trigger_5xx ? 1 : 0) : (existing.trigger_5xx ? 1 : 0),
    rules.latency_threshold_ms ?? existing.latency_threshold_ms,
    rules.max_retries ?? existing.max_retries,
    rules.retry_interval_ms ?? existing.retry_interval_ms,
    now,
    siteId
  );

  return getFailoverRuleBySiteId(siteId);
}

export function logAuditEvent(
  siteId: string,
  event: {
    eventType: AuditLog["event_type"];
    fromOriginId?: string | null;
    toOriginId?: string | null;
    reason: string;
    statusCode?: number | null;
    latencyMs?: number | null;
  }
): AuditLog {
  const db = getDb();
  const id = `log-${crypto.randomUUID().slice(0, 8)}`;
  const now = new Date().toISOString();

  db.prepare(`
    INSERT INTO audit_logs (id, site_id, event_type, from_origin_id, to_origin_id, reason, status_code, latency_ms, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(
    id,
    siteId,
    event.eventType,
    event.fromOriginId || null,
    event.toOriginId || null,
    event.reason,
    event.statusCode || null,
    event.latencyMs || null,
    now
  );

  return {
    id,
    site_id: siteId,
    event_type: event.eventType,
    from_origin_id: event.fromOriginId,
    to_origin_id: event.toOriginId,
    reason: event.reason,
    status_code: event.statusCode,
    latency_ms: event.latencyMs,
    created_at: now,
  };
}

export function getAuditLogs(siteId: string, limit = 50): AuditLog[] {
  const db = getDb();
  return db.prepare(`
    SELECT 
      l.*,
      o1.name as from_origin_name,
      o2.name as to_origin_name
    FROM audit_logs l
    LEFT JOIN origins o1 ON l.from_origin_id = o1.id
    LEFT JOIN origins o2 ON l.to_origin_id = o2.id
    WHERE l.site_id = ?
    ORDER BY l.created_at DESC
    LIMIT ?
  `).all(siteId, limit) as AuditLog[];
}
