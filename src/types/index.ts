export type OriginStatus = "healthy" | "failing" | "down" | "standby";

export interface Origin {
  id: string;
  site_id: string;
  name: string;
  url: string;
  priority: number;
  weight: number;
  host_header_override?: string | null;
  is_active: boolean;
  status: OriginStatus;
  latency_ms: number;
  last_checked_at?: string | null;
  created_at: string;
  updated_at: string;
}

export interface FailoverRule {
  id: string;
  site_id: string;
  trigger_402: boolean;
  trigger_429: boolean;
  trigger_5xx: boolean;
  latency_threshold_ms: number;
  max_retries: number;
  retry_interval_ms: number;
  created_at: string;
  updated_at: string;
}

export interface Site {
  id: string;
  name: string;
  domain: string;
  created_at: string;
  updated_at: string;
  origins?: Origin[];
  rules?: FailoverRule;
}

export interface AuditLog {
  id: string;
  site_id: string;
  event_type: "failover" | "health_check" | "manual_switch" | "circuit_break";
  from_origin_id?: string | null;
  to_origin_id?: string | null;
  from_origin_name?: string | null;
  to_origin_name?: string | null;
  reason: string;
  status_code?: number | null;
  latency_ms?: number | null;
  created_at: string;
}

export interface OriginHealthCheckResult {
  originId: string;
  url: string;
  isHealthy: boolean;
  statusCode?: number;
  latencyMs: number;
  error?: string;
}
