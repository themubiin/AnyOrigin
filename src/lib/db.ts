import Database from "better-sqlite3";
import path from "path";
import fs from "fs";
import { Site, Origin, FailoverRule, AuditLog } from "@/types";

let dbInstance: Database.Database | null = null;

export function getDb(): Database.Database {
  if (dbInstance) {
    return dbInstance;
  }

  const dataDir = path.join(process.cwd(), "data");
  if (!fs.existsSync(dataDir)) {
    fs.mkdirSync(dataDir, { recursive: true });
  }

  const dbPath = path.join(dataDir, "anyorigin.db");
  const db = new Database(dbPath);

  // Performance and integrity pragma
  db.pragma("journal_mode = WAL");
  db.pragma("synchronous = NORMAL");
  db.pragma("foreign_keys = ON");

  // Create tables idempotently
  db.exec(`
    CREATE TABLE IF NOT EXISTS sites (
      id TEXT PRIMARY KEY,
      name TEXT NOT NULL,
      domain TEXT NOT NULL,
      created_at TEXT NOT NULL,
      updated_at TEXT NOT NULL
    );

    CREATE TABLE IF NOT EXISTS origins (
      id TEXT PRIMARY KEY,
      site_id TEXT NOT NULL,
      name TEXT NOT NULL,
      url TEXT NOT NULL,
      priority INTEGER NOT NULL,
      weight INTEGER NOT NULL DEFAULT 100,
      host_header_override TEXT,
      is_active INTEGER NOT NULL DEFAULT 1,
      status TEXT NOT NULL DEFAULT 'healthy',
      latency_ms INTEGER NOT NULL DEFAULT 0,
      last_checked_at TEXT,
      created_at TEXT NOT NULL,
      updated_at TEXT NOT NULL,
      FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
    );

    CREATE TABLE IF NOT EXISTS failover_rules (
      id TEXT PRIMARY KEY,
      site_id TEXT NOT NULL UNIQUE,
      trigger_402 INTEGER NOT NULL DEFAULT 1,
      trigger_429 INTEGER NOT NULL DEFAULT 1,
      trigger_5xx INTEGER NOT NULL DEFAULT 1,
      latency_threshold_ms INTEGER NOT NULL DEFAULT 3000,
      max_retries INTEGER NOT NULL DEFAULT 2,
      retry_interval_ms INTEGER NOT NULL DEFAULT 500,
      created_at TEXT NOT NULL,
      updated_at TEXT NOT NULL,
      FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
    );

    CREATE TABLE IF NOT EXISTS audit_logs (
      id TEXT PRIMARY KEY,
      site_id TEXT NOT NULL,
      event_type TEXT NOT NULL,
      from_origin_id TEXT,
      to_origin_id TEXT,
      reason TEXT NOT NULL,
      status_code INTEGER,
      latency_ms INTEGER,
      created_at TEXT NOT NULL,
      FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
    );

    CREATE TABLE IF NOT EXISTS admin_settings (
      key TEXT PRIMARY KEY,
      value TEXT NOT NULL,
      updated_at TEXT NOT NULL
    );
  `);

  // Seed default demo site if database is empty
  const countStmt = db.prepare("SELECT COUNT(*) as count FROM sites");
  const result = countStmt.get() as { count: number };

  if (result.count === 0) {
    const siteId = "default-site";
    const now = new Date().toISOString();

    const insertSite = db.prepare(`
      INSERT INTO sites (id, name, domain, created_at, updated_at)
      VALUES (?, ?, ?, ?, ?)
    `);
    insertSite.run(siteId, "Production Web App", "app.example.com", now, now);

    const insertRule = db.prepare(`
      INSERT INTO failover_rules (id, site_id, trigger_402, trigger_429, trigger_5xx, latency_threshold_ms, max_retries, retry_interval_ms, created_at, updated_at)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    `);
    insertRule.run("rule-default", siteId, 1, 1, 1, 3000, 2, 500, now, now);

    const insertOrigin = db.prepare(`
      INSERT INTO origins (id, site_id, name, url, priority, weight, host_header_override, is_active, status, latency_ms, last_checked_at, created_at, updated_at)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    `);

    insertOrigin.run(
      "orig-1",
      siteId,
      "Vercel Primary",
      "https://my-app.vercel.app",
      1,
      100,
      "my-app.vercel.app",
      1,
      "healthy",
      45,
      now,
      now,
      now
    );

    insertOrigin.run(
      "orig-2",
      siteId,
      "Netlify Standby",
      "https://my-app.netlify.app",
      2,
      100,
      "my-app.netlify.app",
      1,
      "standby",
      62,
      now,
      now,
      now
    );

    insertOrigin.run(
      "orig-3",
      siteId,
      "Render / VPS Backup",
      "https://my-backup.onrender.com",
      3,
      100,
      "my-backup.onrender.com",
      1,
      "standby",
      115,
      now,
      now,
      now
    );

    // Initial audit log
    const insertLog = db.prepare(`
      INSERT INTO audit_logs (id, site_id, event_type, from_origin_id, to_origin_id, reason, status_code, latency_ms, created_at)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    `);
    insertLog.run(
      "log-init",
      siteId,
      "health_check",
      null,
      "orig-1",
      "System initialized. Primary origin healthy.",
      200,
      45,
      now
    );
  }

  dbInstance = db;
  return db;
}
