"use client";

import { useEffect, useState, useCallback } from "react";
import { useRouter } from "next/navigation";
import {
  Shield,
  Activity,
  Server,
  Sliders,
  Terminal,
  FileCode,
  RefreshCw,
  Plus,
  ArrowUp,
  ArrowDown,
  Trash2,
  CheckCircle2,
  AlertTriangle,
  XCircle,
  ExternalLink,
  Lock,
  LogOut,
  Zap,
  Clock,
  Sparkles,
  Copy,
  Check,
} from "lucide-react";
import { Site, Origin, FailoverRule, AuditLog } from "@/types";

export default function DashboardPage() {
  const router = useRouter();
  const [site, setSite] = useState<Site | null>(null);
  const [origins, setOrigins] = useState<Origin[]>([]);
  const [rules, setRules] = useState<FailoverRule | null>(null);
  const [logs, setLogs] = useState<AuditLog[]>([]);
  const [loading, setLoading] = useState(true);
  const [testingOriginId, setTestingOriginId] = useState<string | null>(null);
  const [showAddOriginModal, setShowAddOriginModal] = useState(false);
  const [showExportModal, setShowExportModal] = useState(false);
  const [exportTab, setExportTab] = useState<"cloudflare" | "php" | "node">("cloudflare");
  const [copiedCode, setCopiedCode] = useState(false);

  // New origin form state
  const [newOriginName, setNewOriginName] = useState("");
  const [newOriginUrl, setNewOriginUrl] = useState("");
  const [newOriginHost, setNewOriginHost] = useState("");
  const [savingOrigin, setSavingOrigin] = useState(false);

  // Failover rules form state
  const [rule402, setRule402] = useState(true);
  const [rule429, setRule429] = useState(true);
  const [rule5xx, setRule5xx] = useState(true);
  const [latencyThreshold, setLatencyThreshold] = useState(3000);
  const [savingRules, setSavingRules] = useState(false);
  const [rulesSavedNotice, setRulesSavedNotice] = useState(false);

  const fetchSiteData = useCallback(async () => {
    try {
      const res = await fetch("/api/sites");
      if (res.status === 401) {
        router.push("/login");
        return;
      }
      const data = await res.json();
      if (data.sites && data.sites.length > 0) {
        const currentSite = data.sites[0];
        setSite(currentSite);
        setOrigins(currentSite.origins || []);
        if (currentSite.rules) {
          setRules(currentSite.rules);
          setRule402(currentSite.rules.trigger_402);
          setRule429(currentSite.rules.trigger_429);
          setRule5xx(currentSite.rules.trigger_5xx);
          setLatencyThreshold(currentSite.rules.latency_threshold_ms);
        }

        // Fetch logs
        const logsRes = await fetch(`/api/sites/${currentSite.id}/logs`);
        const logsData = await logsRes.json();
        setLogs(logsData.logs || []);
      }
    } catch (err) {
      console.error("Failed to load dashboard data", err);
    } finally {
      setLoading(false);
    }
  }, [router]);

  useEffect(() => {
    fetchSiteData();
  }, [fetchSiteData]);

  const handleTestHealth = async (originId: string) => {
    setTestingOriginId(originId);
    try {
      const res = await fetch(`/api/origins/${originId}/test`, { method: "POST" });
      const data = await res.json();
      setOrigins((prev) =>
        prev.map((o) =>
          o.id === originId
            ? {
                ...o,
                status: data.isHealthy ? "healthy" : "down",
                latency_ms: data.latencyMs,
                last_checked_at: new Date().toISOString(),
              }
            : o
        )
      );
      // Refresh logs
      if (site) {
        const logsRes = await fetch(`/api/sites/${site.id}/logs`);
        const logsData = await logsRes.json();
        setLogs(logsData.logs || []);
      }
    } catch (err) {
      console.error(err);
    } finally {
      setTestingOriginId(null);
    }
  };

  const handleMovePriority = async (index: number, direction: "up" | "down") => {
    if (!site) return;
    const targetIndex = direction === "up" ? index - 1 : index + 1;
    if (targetIndex < 0 || targetIndex >= origins.length) return;

    const newOrder = [...origins];
    const [moved] = newOrder.splice(index, 1);
    newOrder.splice(targetIndex, 0, moved);

    setOrigins(newOrder);

    const orderedIds = newOrder.map((o) => o.id);
    await fetch(`/api/sites/${site.id}/origins`, {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ orderedOriginIds: orderedIds }),
    });
  };

  const handleDeleteOrigin = async (originId: string) => {
    if (!confirm("Are you sure you want to remove this upstream origin?")) return;
    await fetch(`/api/origins/${originId}`, { method: "DELETE" });
    setOrigins((prev) => prev.filter((o) => o.id !== originId));
  };

  const handleToggleOrigin = async (origin: Origin) => {
    const updatedStatus = !origin.is_active;
    await fetch(`/api/origins/${origin.id}`, {
      method: "PATCH",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ is_active: updatedStatus }),
    });
    setOrigins((prev) =>
      prev.map((o) => (o.id === origin.id ? { ...o, is_active: updatedStatus } : o))
    );
  };

  const handleAddOrigin = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!site) return;
    setSavingOrigin(true);

    try {
      const res = await fetch(`/api/sites/${site.id}/origins`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          name: newOriginName,
          url: newOriginUrl,
          host_header_override: newOriginHost || undefined,
        }),
      });

      const data = await res.json();
      if (res.ok && data.origin) {
        setOrigins((prev) => [...prev, data.origin]);
        setNewOriginName("");
        setNewOriginUrl("");
        setNewOriginHost("");
        setShowAddOriginModal(false);
      } else {
        alert(data.error || "Failed to add origin");
      }
    } finally {
      setSavingOrigin(false);
    }
  };

  const handleSaveRules = async () => {
    if (!site) return;
    setSavingRules(true);

    try {
      const res = await fetch(`/api/sites/${site.id}/rules`, {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          trigger_402: rule402,
          trigger_429: rule429,
          trigger_5xx: rule5xx,
          latency_threshold_ms: latencyThreshold,
        }),
      });

      if (res.ok) {
        setRulesSavedNotice(true);
        setTimeout(() => setRulesSavedNotice(false), 3000);
      }
    } finally {
      setSavingRules(false);
    }
  };

  const handleLogout = async () => {
    await fetch("/api/auth/logout", { method: "POST" });
    router.push("/login");
  };

  // Generate Cloudflare Edge Worker Code dynamically with Smart Header & Idempotency Safety
  const generateCloudflareWorkerCode = () => {
    const originsList = origins
      .filter((o) => o.is_active)
      .map((o) => ({
        name: o.name,
        url: o.url,
        host: o.host_header_override || new URL(o.url).host,
      }));

    return `/**
 * AnyOrigin Cloudflare Edge Worker (Edge-First Proxy)
 * Generated for: ${site?.name || "Web App"} (${site?.domain || "example.com"})
 * Zero SPOF • Sub-second Failover • Idempotency Safe • Auto Header/Cookie Rewriter
 */

const UPSTREAM_ORIGINS = ${JSON.stringify(originsList, null, 2)};

const FAILOVER_RULES = {
  trigger402: ${rule402}, // Payment / Quota Exceeded
  trigger429: ${rule429}, // Rate Limit Exceeded
  trigger5xx: ${rule5xx}, // Server Down / Gateway Crash
  timeoutMs: ${latencyThreshold},
};

export default {
  async fetch(request, env, ctx) {
    const url = new URL(request.url);
    const clientHost = request.headers.get("Host") || url.host;
    const clientProto = url.protocol.replace(":", "");
    const isSafeMethod = request.method === "GET" || request.method === "HEAD" || request.method === "OPTIONS";

    // Clone request body for potential retry only if method is safe or explicitly allowed
    let bodyBuffer = null;
    if (!isSafeMethod) {
      bodyBuffer = await request.arrayBuffer();
    }

    for (let i = 0; i < UPSTREAM_ORIGINS.length; i++) {
      const origin = UPSTREAM_ORIGINS[i];
      const targetUrl = new URL(origin.url);
      targetUrl.pathname = url.pathname;
      targetUrl.search = url.search;

      const proxyHeaders = new Headers(request.headers);
      proxyHeaders.set("Host", origin.host);
      proxyHeaders.set("X-Forwarded-Host", clientHost);
      proxyHeaders.set("X-Forwarded-Proto", clientProto);
      proxyHeaders.set("X-AnyOrigin-Engine", "Cloudflare-Edge");
      proxyHeaders.set("X-AnyOrigin-Tier", String(i + 1));

      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), FAILOVER_RULES.timeoutMs);

      try {
        const response = await fetch(targetUrl.toString(), {
          method: request.method,
          headers: proxyHeaders,
          body: !isSafeMethod ? bodyBuffer : null,
          redirect: "manual",
          signal: controller.signal,
        });

        clearTimeout(timeout);

        // Platform detection: Check status 402 or Vercel payment error headers
        const vercelError = response.headers.get("x-vercel-error") || "";
        const is402 = FAILOVER_RULES.trigger402 && (response.status === 402 || vercelError.includes("DEPLOYMENT_PAUSED"));
        const is429 = FAILOVER_RULES.trigger429 && response.status === 429;
        const is5xx = FAILOVER_RULES.trigger5xx && response.status >= 500;

        // IDEMPOTENCY SAFETY:
        // Only retry non-idempotent methods (POST/PUT/DELETE) on 402/429 where upstream platform rejected prior to execution.
        const canSafelyRetry = isSafeMethod || is402 || is429;

        if ((is402 || is429 || is5xx) && canSafelyRetry && i < UPSTREAM_ORIGINS.length - 1) {
          console.warn(\`[AnyOrigin] \${origin.name} returned \${response.status}. Failing over to tier \${i + 2}...\`);
          continue; // Switch to next backup origin!
        }

        // Construct client response with Location and Set-Cookie domain rewriting
        const clientHeaders = new Headers(response.headers);

        // 1. Rewrite Location header to keep visitor on main domain
        const locationHeader = clientHeaders.get("Location");
        if (locationHeader) {
          try {
            const locUrl = new URL(locationHeader, targetUrl.origin);
            if (locUrl.host === targetUrl.host || locUrl.host === origin.host) {
              clientHeaders.set("Location", \`\${clientProto}://\${clientHost}\${locUrl.pathname}\${locUrl.search}\${locUrl.hash}\`);
            }
          } catch {}
        }

        // 2. Rewrite Set-Cookie Domain
        const setCookie = clientHeaders.get("Set-Cookie");
        if (setCookie) {
          const clientDomain = clientHost.split(":")[0];
          clientHeaders.set("Set-Cookie", setCookie.replace(/Domain=[^;]+/gi, \`Domain=\${clientDomain}\`));
        }

        clientHeaders.set("X-Served-By-Origin", origin.name);
        return new Response(response.body, {
          status: response.status,
          statusText: response.statusText,
          headers: clientHeaders,
        });
      } catch (err) {
        clearTimeout(timeout);
        // Only retry on network drop if method is safe
        if (isSafeMethod && i < UPSTREAM_ORIGINS.length - 1) {
          continue;
        }
        return new Response("All AnyOrigin upstream origins are unreachable or request is unsafe to retry.", { status: 502 });
      }
    }

    return new Response("No upstream origins configured.", { status: 503 });
  },
};`;
  };

  // Generate Standalone Single-file PHP Proxy
  const generatePHPProxyCode = () => {
    const originsList = origins
      .filter((o) => o.is_active)
      .map((o) => ({
        name: o.name,
        url: o.url,
        host: o.host_header_override || new URL(o.url).host,
      }));

    return `<?php
/**
 * AnyOrigin Standalone PHP Proxy Engine
 * Deploy to any cPanel / Shared Hosting / Apache / Nginx environment
 * Zero Database • Zero External Dependencies • Sub-second Failover
 */

$origins = ${JSON.stringify(originsList, null, 2)};
$timeout_seconds = ${Math.max(1, Math.round(latencyThreshold / 1000))};
$trigger_402 = ${rule402 ? "true" : "false"};
$trigger_429 = ${rule429 ? "true" : "false"};
$trigger_5xx = ${rule5xx ? "true" : "false"};

$request_method = $_SERVER['REQUEST_METHOD'];
$request_uri = $_SERVER['REQUEST_URI'];
$headers = getallheaders();

$input_body = file_get_contents('php://input');

foreach ($origins as $index => $origin) {
    $target_url = rtrim($origin['url'], '/') . $request_uri;
    $ch = curl_init($target_url);

    $forward_headers = [];
    foreach ($headers as $k => $v) {
        if (strtolower($k) === 'host') {
            $forward_headers[] = "Host: " . $origin['host'];
        } else {
            $forward_headers[] = "$k: $v";
        }
    }
    $forward_headers[] = "X-AnyOrigin-Engine: PHP-Native";
    $forward_headers[] = "X-Forwarded-For: " . ($_SERVER['REMOTE_ADDR'] ?? '');

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $request_method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $forward_headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout_seconds);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

    if (!empty($input_body) && $request_method !== 'GET' && $request_method !== 'HEAD') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $input_body);
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    $should_failover = false;
    if ($curl_error) {
        $should_failover = true;
    } elseif ($trigger_402 && $http_code === 402) {
        $should_failover = true;
    } elseif ($trigger_429 && $http_code === 429) {
        $should_failover = true;
    } elseif ($trigger_5xx && $http_code >= 500) {
        $should_failover = true;
    }

    if ($should_failover && $index < count($origins) - 1) {
        continue; // Try next origin
    }

    // Output response
    $response_headers = substr($response, 0, $header_size);
    $response_body = substr($response, $header_size);

    http_response_code($http_code);
    foreach (explode("\\r\\n", $response_headers) as $hdr) {
        if (!empty($hdr) && !str_starts_with(strtolower($hdr), 'transfer-encoding:')) {
            header($hdr, false);
        }
    }
    header("X-Served-By-Origin: " . $origin['name']);
    echo $response_body;
    exit;
}

http_response_code(502);
echo "All AnyOrigin upstream hosts failed.";
?>`;
  };

  const copyToClipboard = (text: string) => {
    navigator.clipboard.writeText(text);
    setCopiedCode(true);
    setTimeout(() => setCopiedCode(false), 2000);
  };

  if (loading) {
    return (
      <div className="flex h-screen items-center justify-center bg-[#090d16]">
        <div className="flex items-center gap-3 text-slate-400">
          <RefreshCw className="h-5 w-5 animate-spin text-sky-400" />
          <span>Loading AnyOrigin Engine...</span>
        </div>
      </div>
    );
  }

  const primaryOrigin = origins[0];
  const backupCount = Math.max(0, origins.length - 1);

  return (
    <div className="min-h-screen bg-[#090d16] text-slate-100 flex flex-col">
      {/* Top Navigation */}
      <header className="border-b border-slate-800/80 bg-[#0c1220]/80 backdrop-blur-md sticky top-0 z-40 px-6 py-3.5 flex items-center justify-between">
        <div className="flex items-center gap-3">
          <div className="h-9 w-9 rounded-xl bg-gradient-to-tr from-sky-500 to-indigo-600 flex items-center justify-center shadow-md shadow-sky-500/20">
            <Shield className="h-5 w-5 text-white" />
          </div>
          <div>
            <div className="flex items-center gap-2">
              <span className="font-bold text-base text-white tracking-tight">AnyOrigin</span>
              <span className="text-[11px] font-mono px-2 py-0.5 rounded-full bg-sky-500/10 text-sky-400 border border-sky-500/20 font-medium">
                Live Failover
              </span>
            </div>
            <p className="text-xs text-slate-400">Self-Hosted Multi-Cloud Load Balancer</p>
          </div>
        </div>

        <div className="flex items-center gap-3">
          <button
            onClick={() => setShowExportModal(true)}
            className="flex items-center gap-2 rounded-lg bg-indigo-600/20 border border-indigo-500/30 px-3.5 py-1.5 text-xs font-medium text-indigo-300 hover:bg-indigo-600/30 transition-all shadow-sm"
          >
            <Zap className="h-3.5 w-3.5 text-indigo-400" />
            <span>Deploy / Export Engines</span>
          </button>

          <button
            onClick={fetchSiteData}
            title="Refresh Status"
            className="rounded-lg border border-slate-800 bg-slate-900/60 p-2 text-slate-400 hover:text-white hover:bg-slate-800 transition-all"
          >
            <RefreshCw className="h-4 w-4" />
          </button>

          <button
            onClick={handleLogout}
            title="Log Out"
            className="rounded-lg border border-slate-800 bg-slate-900/60 p-2 text-slate-400 hover:text-rose-400 hover:bg-rose-500/10 transition-all"
          >
            <LogOut className="h-4 w-4" />
          </button>
        </div>
      </header>

      {/* Main Content */}
      <main className="flex-1 max-w-7xl w-full mx-auto p-6 space-y-6">
        {/* Site Overview Banner & KPI Cards */}
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          <div className="rounded-xl border border-slate-800 bg-[#0f172a] p-4 flex flex-col justify-between">
            <div className="flex items-center justify-between text-slate-400 text-xs">
              <span className="font-medium">Active Domain</span>
              <ExternalLink className="h-3.5 w-3.5" />
            </div>
            <div className="mt-2">
              <div className="text-lg font-bold text-white font-mono">{site?.domain || "example.com"}</div>
              <div className="text-xs text-emerald-400 flex items-center gap-1.5 mt-1">
                <span className="h-2 w-2 rounded-full bg-emerald-400 animate-pulse" />
                Zero-Downtime Protected
              </div>
            </div>
          </div>

          <div className="rounded-xl border border-slate-800 bg-[#0f172a] p-4 flex flex-col justify-between">
            <div className="flex items-center justify-between text-slate-400 text-xs">
              <span className="font-medium">Primary Origin</span>
              <Server className="h-3.5 w-3.5 text-sky-400" />
            </div>
            <div className="mt-2">
              <div className="text-lg font-bold text-white truncate">{primaryOrigin?.name || "None"}</div>
              <div className="text-xs text-slate-400 flex items-center gap-1.5 mt-1 font-mono">
                <span>{primaryOrigin?.latency_ms || 0}ms</span>
                <span className="text-slate-600">•</span>
                <span className="text-sky-400 font-sans">Priority #1</span>
              </div>
            </div>
          </div>

          <div className="rounded-xl border border-slate-800 bg-[#0f172a] p-4 flex flex-col justify-between">
            <div className="flex items-center justify-between text-slate-400 text-xs">
              <span className="font-medium">Standby Resilience</span>
              <Shield className="h-3.5 w-3.5 text-indigo-400" />
            </div>
            <div className="mt-2">
              <div className="text-lg font-bold text-indigo-300">
                {backupCount} {backupCount === 1 ? "Backup Host" : "Backup Hosts"}
              </div>
              <div className="text-xs text-slate-400 mt-1">Sub-second automatic failover</div>
            </div>
          </div>

          <div className="rounded-xl border border-slate-800 bg-[#0f172a] p-4 flex flex-col justify-between">
            <div className="flex items-center justify-between text-slate-400 text-xs">
              <span className="font-medium">Engine Mode</span>
              <Sparkles className="h-3.5 w-3.5 text-amber-400" />
            </div>
            <div className="mt-2">
              <div className="text-base font-bold text-slate-200">Zero External DB</div>
              <div className="text-xs text-amber-400 font-mono mt-1">Local SQLite (anyorigin.db)</div>
            </div>
          </div>
        </div>

        {/* Origin Prioritization & Upstream Topology */}
        <div className="rounded-2xl border border-slate-800 bg-[#0f172a] overflow-hidden shadow-xl">
          <div className="p-5 border-b border-slate-800/80 flex items-center justify-between bg-slate-900/40">
            <div>
              <h2 className="text-base font-bold text-white flex items-center gap-2">
                <Server className="h-4 w-4 text-sky-400" />
                Upstream Origins & Priority Chain
              </h2>
              <p className="text-xs text-slate-400 mt-0.5">
                Requests route sequentially through this chain. When a higher origin fails or hits quotas, AnyOrigin shifts down instantly.
              </p>
            </div>
            <button
              onClick={() => setShowAddOriginModal(true)}
              className="flex items-center gap-1.5 rounded-lg bg-sky-500 hover:bg-sky-400 px-3 py-1.5 text-xs font-semibold text-slate-950 transition-all shadow-md shadow-sky-500/20"
            >
              <Plus className="h-3.5 w-3.5" />
              <span>Add Host</span>
            </button>
          </div>

          <div className="divide-y divide-slate-800/60">
            {origins.map((orig, idx) => {
              const isPrimary = idx === 0;
              const isTesting = testingOriginId === orig.id;

              return (
                <div
                  key={orig.id}
                  className={`p-4 flex flex-col md:flex-row items-start md:items-center justify-between gap-4 transition-colors ${
                    !orig.is_active ? "opacity-50 bg-slate-950/20" : "hover:bg-slate-800/20"
                  }`}
                >
                  <div className="flex items-center gap-3.5 min-w-0">
                    <div className="flex flex-col gap-1 items-center">
                      <button
                        onClick={() => handleMovePriority(idx, "up")}
                        disabled={idx === 0}
                        title="Move Up Priority"
                        className="p-1 text-slate-500 hover:text-sky-400 disabled:opacity-20 transition-colors"
                      >
                        <ArrowUp className="h-3.5 w-3.5" />
                      </button>
                      <button
                        onClick={() => handleMovePriority(idx, "down")}
                        disabled={idx === origins.length - 1}
                        title="Move Down Priority"
                        className="p-1 text-slate-500 hover:text-sky-400 disabled:opacity-20 transition-colors"
                      >
                        <ArrowDown className="h-3.5 w-3.5" />
                      </button>
                    </div>

                    <div
                      className={`h-9 w-9 rounded-xl flex items-center justify-center font-mono font-bold text-xs shrink-0 ${
                        isPrimary
                          ? "bg-emerald-500/10 text-emerald-400 border border-emerald-500/30"
                          : idx === 1
                          ? "bg-amber-500/10 text-amber-400 border border-amber-500/30"
                          : "bg-slate-800 text-slate-400 border border-slate-700"
                      }`}
                    >
                      #{idx + 1}
                    </div>

                    <div className="min-w-0">
                      <div className="flex items-center gap-2">
                        <span className="font-semibold text-sm text-white truncate">{orig.name}</span>
                        {isPrimary && (
                          <span className="px-2 py-0.5 rounded text-[10px] font-semibold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                            PRIMARY
                          </span>
                        )}
                        {!orig.is_active && (
                          <span className="px-2 py-0.5 rounded text-[10px] font-semibold bg-slate-800 text-slate-400">
                            PAUSED
                          </span>
                        )}
                      </div>
                      <div className="flex items-center gap-3 text-xs text-slate-400 font-mono mt-0.5 truncate">
                        <span className="text-slate-300">{orig.url}</span>
                        {orig.host_header_override && (
                          <span className="text-slate-500 text-[11px]">
                            [Host: {orig.host_header_override}]
                          </span>
                        )}
                      </div>
                    </div>
                  </div>

                  <div className="flex items-center gap-3 shrink-0 self-end md:self-center">
                    {/* Status Pill */}
                    <div className="flex items-center gap-1.5 text-xs font-mono bg-slate-900 px-3 py-1.5 rounded-lg border border-slate-800">
                      {orig.status === "healthy" ? (
                        <>
                          <CheckCircle2 className="h-3.5 w-3.5 text-emerald-400" />
                          <span className="text-emerald-400">{orig.latency_ms}ms</span>
                        </>
                      ) : orig.status === "failing" ? (
                        <>
                          <AlertTriangle className="h-3.5 w-3.5 text-amber-400" />
                          <span className="text-amber-400">Warning ({orig.latency_ms}ms)</span>
                        </>
                      ) : (
                        <>
                          <XCircle className="h-3.5 w-3.5 text-rose-400" />
                          <span className="text-rose-400">Down</span>
                        </>
                      )}
                    </div>

                    {/* Test Probe Button */}
                    <button
                      onClick={() => handleTestHealth(orig.id)}
                      disabled={isTesting}
                      className="flex items-center gap-1 rounded-lg border border-slate-700 bg-slate-800/80 px-2.5 py-1.5 text-xs text-slate-300 hover:text-white hover:bg-slate-700 transition-colors"
                    >
                      <RefreshCw className={`h-3 w-3 ${isTesting ? "animate-spin text-sky-400" : ""}`} />
                      <span>{isTesting ? "Testing..." : "Ping"}</span>
                    </button>

                    {/* Toggle Active */}
                    <button
                      onClick={() => handleToggleOrigin(orig)}
                      className={`text-xs px-2.5 py-1.5 rounded-lg border transition-colors ${
                        orig.is_active
                          ? "border-emerald-500/30 bg-emerald-500/10 text-emerald-300 hover:bg-emerald-500/20"
                          : "border-slate-700 bg-slate-800 text-slate-400 hover:bg-slate-700"
                      }`}
                    >
                      {orig.is_active ? "Active" : "Enable"}
                    </button>

                    {/* Delete */}
                    <button
                      onClick={() => handleDeleteOrigin(orig.id)}
                      disabled={origins.length <= 1}
                      title="Delete Origin"
                      className="p-1.5 text-slate-500 hover:text-rose-400 disabled:opacity-20 transition-colors"
                    >
                      <Trash2 className="h-4 w-4" />
                    </button>
                  </div>
                </div>
              );
            })}
          </div>
        </div>

        {/* Smart Failover Trigger Rules & Live Event Log */}
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          {/* Rules Builder */}
          <div className="rounded-2xl border border-slate-800 bg-[#0f172a] p-5 flex flex-col justify-between shadow-xl">
            <div>
              <div className="flex items-center justify-between mb-4">
                <div className="flex items-center gap-2">
                  <Sliders className="h-4 w-4 text-sky-400" />
                  <h3 className="font-bold text-sm text-white">Smart Failover Trigger Rules</h3>
                </div>
                <span className="text-[11px] font-mono text-slate-400 bg-slate-900 px-2 py-0.5 rounded border border-slate-800">
                  Sub-second Routing
                </span>
              </div>
              <p className="text-xs text-slate-400 mb-5">
                Configure exact HTTP status codes and timeout metrics that trigger an automatic origin hop.
              </p>

              <div className="space-y-3.5">
                <label className="flex items-start gap-3 p-3 rounded-xl border border-slate-800/80 bg-slate-900/60 cursor-pointer hover:border-slate-700 transition-colors">
                  <input
                    type="checkbox"
                    checked={rule402}
                    onChange={(e) => setRule402(e.target.checked)}
                    className="mt-0.5 h-4 w-4 rounded border-slate-700 bg-slate-800 text-sky-500 focus:ring-sky-500"
                  />
                  <div>
                    <span className="text-sm font-semibold text-slate-200">
                      HTTP 402 Payment Required (Cloud Quota Exceeded)
                    </span>
                    <p className="text-xs text-slate-400 mt-0.5">
                      Fails over instantly when Vercel, Netlify, or cloud free tier limits are exhausted.
                    </p>
                  </div>
                </label>

                <label className="flex items-start gap-3 p-3 rounded-xl border border-slate-800/80 bg-slate-900/60 cursor-pointer hover:border-slate-700 transition-colors">
                  <input
                    type="checkbox"
                    checked={rule429}
                    onChange={(e) => setRule429(e.target.checked)}
                    className="mt-0.5 h-4 w-4 rounded border-slate-700 bg-slate-800 text-sky-500 focus:ring-sky-500"
                  />
                  <div>
                    <span className="text-sm font-semibold text-slate-200">
                      HTTP 429 Too Many Requests (Rate Limit Protection)
                    </span>
                    <p className="text-xs text-slate-400 mt-0.5">
                      Evacuates visitors to backup servers when the primary origin enforces rate limiting.
                    </p>
                  </div>
                </label>

                <label className="flex items-start gap-3 p-3 rounded-xl border border-slate-800/80 bg-slate-900/60 cursor-pointer hover:border-slate-700 transition-colors">
                  <input
                    type="checkbox"
                    checked={rule5xx}
                    onChange={(e) => setRule5xx(e.target.checked)}
                    className="mt-0.5 h-4 w-4 rounded border-slate-700 bg-slate-800 text-sky-500 focus:ring-sky-500"
                  />
                  <div>
                    <span className="text-sm font-semibold text-slate-200">
                      HTTP 500, 502, 503, 504 (Server Crashes & Timeouts)
                    </span>
                    <p className="text-xs text-slate-400 mt-0.5">
                      Intercepts server errors and routes the user request to standby origins transparently.
                    </p>
                  </div>
                </label>

                <div className="p-3 rounded-xl border border-slate-800/80 bg-slate-900/60">
                  <div className="flex items-center justify-between mb-2">
                    <span className="text-sm font-semibold text-slate-200">Latency Timeout Threshold</span>
                    <span className="font-mono text-xs text-sky-400">{latencyThreshold} ms</span>
                  </div>
                  <input
                    type="range"
                    min="500"
                    max="10000"
                    step="250"
                    value={latencyThreshold}
                    onChange={(e) => setLatencyThreshold(Number(e.target.value))}
                    className="w-full accent-sky-400 cursor-pointer"
                  />
                  <div className="flex justify-between text-[10px] text-slate-500 font-mono mt-1">
                    <span>500ms (Aggressive)</span>
                    <span>3000ms (Recommended)</span>
                    <span>10000ms</span>
                  </div>
                </div>
              </div>
            </div>

            <div className="mt-6 flex items-center justify-between pt-4 border-t border-slate-800/80">
              <span className="text-xs text-emerald-400 font-mono">
                {rulesSavedNotice ? "✓ Policy updated in SQLite!" : ""}
              </span>
              <button
                onClick={handleSaveRules}
                disabled={savingRules}
                className="rounded-xl bg-gradient-to-r from-sky-500 to-indigo-600 px-4 py-2 text-xs font-semibold text-white shadow-md shadow-sky-500/20 hover:from-sky-400 hover:to-indigo-500 transition-all"
              >
                {savingRules ? "Saving..." : "Save Failover Policy"}
              </button>
            </div>
          </div>

          {/* Incident & Audit Logs */}
          <div className="rounded-2xl border border-slate-800 bg-[#0f172a] p-5 flex flex-col justify-between shadow-xl">
            <div>
              <div className="flex items-center justify-between mb-4">
                <div className="flex items-center gap-2">
                  <Activity className="h-4 w-4 text-emerald-400" />
                  <h3 className="font-bold text-sm text-white">Live Health & Failover Audit Log</h3>
                </div>
                <span className="text-[11px] font-mono text-slate-400 bg-slate-900 px-2 py-0.5 rounded border border-slate-800">
                  Audit History
                </span>
              </div>
              <p className="text-xs text-slate-400 mb-4">
                Historical records of health checks, origin failovers, and latency events.
              </p>

              <div className="space-y-2 max-h-[360px] overflow-y-auto pr-1">
                {logs.length === 0 ? (
                  <div className="p-8 text-center text-xs text-slate-500">No failover events recorded yet.</div>
                ) : (
                  logs.map((log) => (
                    <div
                      key={log.id}
                      className="p-3 rounded-xl border border-slate-800/80 bg-slate-900/40 flex items-start justify-between gap-3 text-xs"
                    >
                      <div className="space-y-1">
                        <div className="flex items-center gap-2">
                          <span
                            className={`px-1.5 py-0.5 rounded text-[10px] font-mono font-medium ${
                              log.event_type === "failover"
                                ? "bg-rose-500/20 text-rose-300 border border-rose-500/30"
                                : "bg-sky-500/20 text-sky-300 border border-sky-500/30"
                            }`}
                          >
                            {log.event_type.toUpperCase()}
                          </span>
                          <span className="text-slate-200 font-medium">{log.reason}</span>
                        </div>
                        <div className="text-[11px] text-slate-500 flex items-center gap-2">
                          <Clock className="h-3 w-3" />
                          <span>{new Date(log.created_at).toLocaleTimeString()}</span>
                          {log.latency_ms && (
                            <>
                              <span>•</span>
                              <span className="font-mono">{log.latency_ms}ms</span>
                            </>
                          )}
                          {log.status_code && (
                            <>
                              <span>•</span>
                              <span className="font-mono text-amber-400">HTTP {log.status_code}</span>
                            </>
                          )}
                        </div>
                      </div>
                    </div>
                  ))
                )}
              </div>
            </div>

            <div className="pt-4 mt-2 border-t border-slate-800/80 flex items-center justify-between text-xs text-slate-500">
              <span>Automatic WAL-journaled in SQLite</span>
              <span className="font-mono text-[11px] text-slate-400">{logs.length} entries</span>
            </div>
          </div>
        </div>
      </main>

      {/* Modal: Add Upstream Origin */}
      {showAddOriginModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
          <div className="w-full max-w-md rounded-2xl border border-slate-800 bg-[#0f172a] p-6 shadow-2xl">
            <h3 className="text-lg font-bold text-white mb-1">Add Upstream Origin</h3>
            <p className="text-xs text-slate-400 mb-5">
              Add a new hosting provider (Vercel, Netlify, Render, VPS, cPanel) to the failover chain.
            </p>

            <form onSubmit={handleAddOrigin} className="space-y-4">
              <div>
                <label className="block text-xs font-medium text-slate-300 mb-1.5">Origin Name</label>
                <input
                  type="text"
                  required
                  placeholder="e.g. Netlify Standby or Render VPS"
                  value={newOriginName}
                  onChange={(e) => setNewOriginName(e.target.value)}
                  className="w-full rounded-xl border border-slate-700 bg-slate-900 px-3.5 py-2 text-sm text-white placeholder-slate-500 focus:border-sky-500 focus:outline-none focus:ring-1 focus:ring-sky-500"
                />
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-300 mb-1.5">Target Origin URL</label>
                <input
                  type="url"
                  required
                  placeholder="https://my-app.netlify.app"
                  value={newOriginUrl}
                  onChange={(e) => {
                    setNewOriginUrl(e.target.value);
                    try {
                      const host = new URL(e.target.value).host;
                      if (!newOriginHost) setNewOriginHost(host);
                    } catch {}
                  }}
                  className="w-full rounded-xl border border-slate-700 bg-slate-900 px-3.5 py-2 text-sm text-white placeholder-slate-500 focus:border-sky-500 focus:outline-none focus:ring-1 focus:ring-sky-500 font-mono"
                />
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-300 mb-1.5">
                  Host Header Override (Optional)
                </label>
                <input
                  type="text"
                  placeholder="my-app.netlify.app"
                  value={newOriginHost}
                  onChange={(e) => setNewOriginHost(e.target.value)}
                  className="w-full rounded-xl border border-slate-700 bg-slate-900 px-3.5 py-2 text-sm text-white placeholder-slate-500 focus:border-sky-500 focus:outline-none focus:ring-1 focus:ring-sky-500 font-mono"
                />
                <p className="text-[11px] text-slate-500 mt-1">
                  Required for multi-tenant cloud platforms like Vercel/Netlify to identify the project.
                </p>
              </div>

              <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setShowAddOriginModal(false)}
                  className="px-4 py-2 text-xs font-semibold text-slate-400 hover:text-white transition-colors"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={savingOrigin}
                  className="rounded-xl bg-sky-500 px-4 py-2 text-xs font-semibold text-slate-950 hover:bg-sky-400 shadow-md shadow-sky-500/20 disabled:opacity-50 transition-all"
                >
                  {savingOrigin ? "Adding..." : "Add to Chain"}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Modal: Export / Deploy Engines */}
      {showExportModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75 backdrop-blur-sm">
          <div className="w-full max-w-3xl rounded-2xl border border-slate-800 bg-[#0f172a] p-6 shadow-2xl flex flex-col max-h-[90vh]">
            <div className="flex items-center justify-between border-b border-slate-800 pb-4 mb-4">
              <div>
                <h3 className="text-lg font-bold text-white flex items-center gap-2">
                  <Zap className="h-5 w-5 text-indigo-400" />
                  Deploy AnyOrigin Traffic Engines
                </h3>
                <p className="text-xs text-slate-400 mt-0.5">
                  Choose your deployment target. Zero external dependencies required.
                </p>
              </div>
              <button
                onClick={() => setShowExportModal(false)}
                className="text-slate-400 hover:text-white text-sm font-semibold p-1"
              >
                ✕
              </button>
            </div>

            {/* Tab switch */}
            <div className="flex gap-2 border-b border-slate-800 pb-3 mb-4">
              <button
                onClick={() => setExportTab("cloudflare")}
                className={`flex items-center gap-2 px-3.5 py-1.5 rounded-lg text-xs font-semibold transition-all ${
                  exportTab === "cloudflare"
                    ? "bg-indigo-600 text-white shadow-md shadow-indigo-600/30"
                    : "text-slate-400 hover:text-white hover:bg-slate-800"
                }`}
              >
                <Zap className="h-3.5 w-3.5" />
                <span>Cloudflare Edge Worker (Zero-Cost / 0ms)</span>
              </button>

              <button
                onClick={() => setExportTab("php")}
                className={`flex items-center gap-2 px-3.5 py-1.5 rounded-lg text-xs font-semibold transition-all ${
                  exportTab === "php"
                    ? "bg-indigo-600 text-white shadow-md shadow-indigo-600/30"
                    : "text-slate-400 hover:text-white hover:bg-slate-800"
                }`}
              >
                <FileCode className="h-3.5 w-3.5" />
                <span>Standalone PHP Proxy (cPanel / Apache)</span>
              </button>
            </div>

            {/* Code view */}
            <div className="relative flex-1 overflow-hidden rounded-xl border border-slate-800 bg-slate-950">
              <button
                onClick={() =>
                  copyToClipboard(
                    exportTab === "cloudflare"
                      ? generateCloudflareWorkerCode()
                      : generatePHPProxyCode()
                  )
                }
                className="absolute top-3 right-3 z-10 flex items-center gap-1.5 rounded-lg bg-slate-800/80 px-2.5 py-1.5 text-xs text-slate-300 hover:text-white hover:bg-slate-700 transition-colors border border-slate-700"
              >
                {copiedCode ? (
                  <>
                    <Check className="h-3.5 w-3.5 text-emerald-400" />
                    <span className="text-emerald-400">Copied!</span>
                  </>
                ) : (
                  <>
                    <Copy className="h-3.5 w-3.5" />
                    <span>Copy Code</span>
                  </>
                )}
              </button>

              <pre className="p-4 text-xs font-mono text-slate-300 overflow-auto h-full max-h-[420px]">
                <code>
                  {exportTab === "cloudflare"
                    ? generateCloudflareWorkerCode()
                    : generatePHPProxyCode()}
                </code>
              </pre>
            </div>

            <div className="pt-4 mt-3 border-t border-slate-800 flex items-center justify-between text-xs text-slate-400">
              <span>
                {exportTab === "cloudflare"
                  ? "Paste into Cloudflare Workers Dashboard or deploy with Wrangler."
                  : "Save as `index.php` or `proxy.php` and upload to your cPanel public_html."}
              </span>
              <button
                onClick={() => setShowExportModal(false)}
                className="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-white font-semibold transition-colors"
              >
                Close
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
