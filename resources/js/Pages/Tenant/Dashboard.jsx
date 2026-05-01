import React from 'react'
import { Link } from '@inertiajs/react'
import TenantLayout from '../../layouts/TenantLayout'
import {
  MessagesSquare,
  Users,
  AlertTriangle,
  CheckCircle2,
  TrendingUp,
  Flame,
  Thermometer,
  Snowflake,
  ArrowUpRight,
  Bell,
  HandMetal,
  FileText,
} from 'lucide-react'

function MetricCard({ label, value, sub, icon: Icon, color = 'slate', href }) {
  const colorMap = {
    slate: 'bg-white border-slate-200 text-slate-600',
    emerald: 'bg-emerald-50 border-emerald-200 text-emerald-700',
    amber: 'bg-amber-50 border-amber-200 text-amber-700',
    blue: 'bg-blue-50 border-blue-200 text-blue-700',
    red: 'bg-red-50 border-red-200 text-red-700',
    violet: 'bg-violet-50 border-violet-200 text-violet-700',
  }
  const cls = colorMap[color] ?? colorMap.slate
  const inner = (
    <div className={`rounded-xl border p-4 transition ${cls} ${href ? 'hover:shadow-md' : ''}`}>
      <div className="flex items-start justify-between">
        <div>
          <p className="text-xs font-semibold uppercase tracking-wider opacity-75">{label}</p>
          <p className="mt-1.5 text-2xl font-bold">{value}</p>
          {sub && <p className="mt-1 text-xs opacity-70">{sub}</p>}
        </div>
        {Icon && (
          <div className="rounded-lg bg-white/70 p-2 shadow-sm">
            <Icon className="h-5 w-5 opacity-80" />
          </div>
        )}
      </div>
    </div>
  )
  return href ? <Link href={href}>{inner}</Link> : inner
}

function TemperatureBar({ hot = 0, warm = 0, cold = 0 }) {
  const total = hot + warm + cold || 1
  const hotPct = Math.round((hot / total) * 100)
  const warmPct = Math.round((warm / total) * 100)
  const coldPct = 100 - hotPct - warmPct

  return (
    <div className="rounded-xl border border-slate-200 bg-white p-5">
      <div className="flex items-center justify-between">
        <h2 className="font-semibold text-slate-700">Suhu Lead</h2>
        <Link href="/tenant/analytics" className="flex items-center gap-1 text-xs text-emerald-600 hover:underline">
          Detail <ArrowUpRight className="h-3 w-3" />
        </Link>
      </div>
      <div className="mt-4 flex gap-3">
        <div className="flex-1 text-center">
          <div className="flex items-center justify-center gap-1">
            <Flame className="h-4 w-4 text-red-500" />
            <span className="text-xl font-bold text-red-600">{hot}</span>
          </div>
          <p className="text-xs text-slate-500">Hot</p>
        </div>
        <div className="flex-1 text-center">
          <div className="flex items-center justify-center gap-1">
            <Thermometer className="h-4 w-4 text-amber-500" />
            <span className="text-xl font-bold text-amber-600">{warm}</span>
          </div>
          <p className="text-xs text-slate-500">Warm</p>
        </div>
        <div className="flex-1 text-center">
          <div className="flex items-center justify-center gap-1">
            <Snowflake className="h-4 w-4 text-blue-400" />
            <span className="text-xl font-bold text-blue-500">{cold}</span>
          </div>
          <p className="text-xs text-slate-500">Cold</p>
        </div>
      </div>
      <div className="mt-3 h-2.5 overflow-hidden rounded-full bg-slate-100">
        <div className="flex h-full">
          <div className="bg-red-500 transition-all" style={{ width: `${hotPct}%` }} />
          <div className="bg-amber-400 transition-all" style={{ width: `${warmPct}%` }} />
          <div className="bg-blue-300 transition-all" style={{ width: `${coldPct}%` }} />
        </div>
      </div>
      <p className="mt-1 text-right text-xs text-slate-400">{hot + warm + cold} total lead terscored</p>
    </div>
  )
}

export default function Dashboard({ summary, recentConversations, recentHandoffs, recentNotifications }) {
  const tempHot = summary?.lead_hot ?? 0
  const tempWarm = summary?.lead_warm ?? 0
  const tempCold = summary?.lead_cold ?? 0

  return (
    <TenantLayout title="Beranda">
      <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <MetricCard
          label="Total Lead"
          value={summary?.lead_count ?? 0}
          sub="Semua waktu"
          icon={Users}
          color="emerald"
          href="/tenant/analytics"
        />
        <MetricCard
          label="Percakapan Terbuka"
          value={summary?.conversations_open ?? 0}
          sub={`dari ${summary?.conversations_total ?? 0} total`}
          icon={MessagesSquare}
          color="blue"
          href="/tenant/inbox"
        />
        <MetricCard
          label="Handoff Tertunda"
          value={summary?.handoffs_pending ?? 0}
          sub="Butuh perhatian"
          icon={AlertTriangle}
          color="amber"
          href="/tenant/inbox"
        />
        <MetricCard
          label="Booking Tereksekusi"
          value={summary?.booking_action_count ?? 0}
          sub="Booking link terkirim"
          icon={CheckCircle2}
          color="emerald"
        />
      </section>

      <section className="mt-4 grid gap-4 lg:grid-cols-3">
        <TemperatureBar hot={tempHot} warm={tempWarm} cold={tempCold} />

        <div className="rounded-xl border border-slate-200 bg-white p-5">
          <div className="flex items-center justify-between">
            <h2 className="font-semibold text-slate-700">Percakapan Terbaru</h2>
            <Link href="/tenant/inbox" className="flex items-center gap-1 text-xs text-emerald-600 hover:underline">
              Inbox <ArrowUpRight className="h-3 w-3" />
            </Link>
          </div>
          <ul className="mt-3 space-y-2 text-sm">
            {(recentConversations ?? []).map((row) => (
              <li key={row.id}>
                <Link
                  href={`/tenant/inbox?conversation_id=${row.id}`}
                  className="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2 hover:bg-emerald-50"
                >
                  <span className="font-medium text-slate-700">{row.customer_phone}</span>
                  <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${
                    row.status === 'open' ? 'bg-blue-100 text-blue-600' : 'bg-slate-100 text-slate-500'
                  }`}>
                    {row.status}
                  </span>
                </Link>
              </li>
            ))}
            {(recentConversations ?? []).length === 0 && (
              <li className="text-slate-400 text-xs">Belum ada percakapan.</li>
            )}
          </ul>
        </div>

        <div className="space-y-3">
          <div className="rounded-xl border border-slate-200 bg-white p-4">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2">
                <HandMetal className="h-4 w-4 text-amber-500" />
                <h2 className="text-sm font-semibold text-slate-700">Handoff Terbaru</h2>
              </div>
              <Link href="/tenant/inbox" className="text-xs text-emerald-600 hover:underline">Lihat</Link>
            </div>
            <ul className="mt-2 space-y-1 text-xs">
              {(recentHandoffs ?? []).map((row) => (
                <li key={row.id} className="flex items-center justify-between rounded bg-slate-50 px-2 py-1.5">
                  <span className="text-slate-600">#{row.conversation_id} — {row.reason_code}</span>
                  <span className={`rounded-full px-1.5 py-0.5 text-xs font-medium ${
                    row.status === 'pending' ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'
                  }`}>
                    {row.status}
                  </span>
                </li>
              ))}
              {(recentHandoffs ?? []).length === 0 && <li className="text-slate-400">Tidak ada.</li>}
            </ul>
          </div>

          <div className="rounded-xl border border-slate-200 bg-white p-4">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2">
                <Bell className="h-4 w-4 text-violet-500" />
                <h2 className="text-sm font-semibold text-slate-700">Notifikasi Terbaru</h2>
              </div>
              <Link href="/tenant/notifications" className="text-xs text-emerald-600 hover:underline">Lihat</Link>
            </div>
            <ul className="mt-2 space-y-1 text-xs">
              {(recentNotifications ?? []).map((row) => (
                <li key={row.id} className="flex items-center justify-between rounded bg-slate-50 px-2 py-1.5">
                  <span className="text-slate-600">{row.type}</span>
                  <span className={`rounded-full px-1.5 py-0.5 text-xs font-medium ${
                    row.status === 'failed' ? 'bg-red-100 text-red-600' :
                    row.status === 'sent' ? 'bg-emerald-100 text-emerald-700' :
                    'bg-slate-100 text-slate-500'
                  }`}>
                    {row.status}
                  </span>
                </li>
              ))}
              {(recentNotifications ?? []).length === 0 && <li className="text-slate-400">Tidak ada.</li>}
            </ul>
          </div>
        </div>
      </section>

      <section className="mt-4 grid gap-3 sm:grid-cols-3">
        <Link
          href="/tenant/inbox"
          className="flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 text-sm font-medium text-slate-700 transition hover:border-emerald-300 hover:bg-emerald-50"
        >
          <MessagesSquare className="h-5 w-5 text-emerald-500" />
          Buka Kotak Masuk
          <ArrowUpRight className="ml-auto h-4 w-4 opacity-50" />
        </Link>
        <Link
          href="/tenant/analytics"
          className="flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 text-sm font-medium text-slate-700 transition hover:border-blue-300 hover:bg-blue-50"
        >
          <TrendingUp className="h-5 w-5 text-blue-500" />
          Analitik Lead
          <ArrowUpRight className="ml-auto h-4 w-4 opacity-50" />
        </Link>
        <Link
          href="/tenant/business-data"
          className="flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 text-sm font-medium text-slate-700 transition hover:border-violet-300 hover:bg-violet-50"
        >
          <FileText className="h-5 w-5 text-violet-500" />
          Kelola Data Bisnis
          <ArrowUpRight className="ml-auto h-4 w-4 opacity-50" />
        </Link>
      </section>
    </TenantLayout>
  )
}
