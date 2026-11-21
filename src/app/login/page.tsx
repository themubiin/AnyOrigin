"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { Shield, Lock, ArrowRight, AlertCircle, Sparkles } from "lucide-react";

export default function LoginPage() {
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const router = useRouter();

  const handleLogin = async (e: React.FormEvent) => {
    e.preventDefault();
    setError("");
    setLoading(true);

    try {
      const res = await fetch("/api/auth/login", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ password }),
      });

      const data = await res.json();
      if (!res.ok) {
        throw new Error(data.error || "Authentication failed");
      }

      router.push("/");
      router.refresh();
    } catch (err: any) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="flex min-h-screen flex-col items-center justify-center p-4 bg-gradient-to-br from-[#090d16] via-[#0d1424] to-[#090d16]">
      <div className="w-full max-w-md rounded-2xl border border-slate-800 bg-[#0f172a]/90 backdrop-blur-xl p-8 shadow-2xl shadow-sky-950/20">
        <div className="flex flex-col items-center text-center mb-8">
          <div className="h-14 w-14 rounded-2xl bg-gradient-to-tr from-sky-500 to-indigo-600 flex items-center justify-center shadow-lg shadow-sky-500/25 mb-4">
            <Shield className="h-7 w-7 text-white" />
          </div>
          <h1 className="text-2xl font-bold tracking-tight text-white flex items-center gap-2">
            AnyOrigin
            <span className="text-xs px-2 py-0.5 rounded-full bg-sky-500/10 text-sky-400 border border-sky-500/20 font-mono font-medium">
              v1.0
            </span>
          </h1>
          <p className="text-sm text-slate-400 mt-1">
            Self-Hosted Multi-Cloud Failover & Load Balancer
          </p>
        </div>

        {error && (
          <div className="mb-6 flex items-center gap-2 rounded-lg bg-rose-500/10 border border-rose-500/20 p-3 text-sm text-rose-400">
            <AlertCircle className="h-4 w-4 shrink-0" />
            <span>{error}</span>
          </div>
        )}

        <form onSubmit={handleLogin} className="space-y-4">
          <div>
            <label className="block text-xs font-medium text-slate-300 uppercase tracking-wider mb-2 font-mono">
              Admin Password
            </label>
            <div className="relative">
              <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                <Lock className="h-4 w-4 text-slate-500" />
              </div>
              <input
                type="password"
                required
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                placeholder="Default: admin123"
                className="w-full rounded-xl border border-slate-700 bg-slate-900/80 py-2.5 pl-10 pr-4 text-sm text-white placeholder-slate-500 focus:border-sky-500 focus:outline-none focus:ring-1 focus:ring-sky-500 transition-all font-mono"
              />
            </div>
            <p className="text-[11px] text-slate-500 mt-1.5">
              Default password is <code className="text-sky-400 font-mono">admin123</code>. Configurable via <code className="text-slate-400">ADMIN_PASSWORD</code>.
            </p>
          </div>

          <button
            type="submit"
            disabled={loading}
            className="w-full rounded-xl bg-gradient-to-r from-sky-500 to-indigo-600 py-2.5 px-4 text-sm font-semibold text-white shadow-lg shadow-sky-500/20 hover:from-sky-400 hover:to-indigo-500 focus:outline-none focus:ring-2 focus:ring-sky-400 disabled:opacity-50 transition-all flex items-center justify-center gap-2"
          >
            {loading ? (
              <span className="inline-block h-4 w-4 border-2 border-white/20 border-t-white rounded-full animate-spin" />
            ) : (
              <>
                <span>Access Dashboard</span>
                <ArrowRight className="h-4 w-4" />
              </>
            )}
          </button>
        </form>

        <div className="mt-8 border-t border-slate-800/80 pt-4 text-center">
          <div className="inline-flex items-center gap-1.5 text-xs text-slate-500">
            <Sparkles className="h-3.5 w-3.5 text-sky-400" />
            Zero External Database • 100% Self-Hosted
          </div>
        </div>
      </div>
    </div>
  );
}
