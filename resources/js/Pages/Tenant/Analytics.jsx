import React from 'react'
import { Link } from '@inertiajs/react'
import TenantLayout from '../../layouts/TenantLayout'
import {
  Users,
  MessagesSquare,
  CheckCircle2,
  FileText,
  HandMetal,
  Flame,
  Thermometer,
  Snowflake,
  TrendingUp,
  ArrowUpRight,
  Package,
  HelpCircle,
} from 'lucide-react'

function MetricCard({ label, value, sub, icon: Icon, color = 'slate' }) {
  const colorMap = {
    slate:   'bg-white border-slate-200 text-slate-600',
    emerald: 'bg-emerald-50 border-emerald-200 text-emerald-700',
    amber:   'bg-amber-50 border-amber-200 text-amber-700',
    blue:    'bg-blue-50 border-blue-200 text-blue-700',
    red:     'bg-red-50 border-red-200 text-red-700',
    violet:  'bg-violet-50 border-violet-200 text-violet-700',
  }
  return (
    <div className={`rounded-xl border p-4 ${colorMap[color] ?? colorMap.slate}`}>
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
}

function SectionCard({ title, icon: Icon, children }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-5">
      <div className="mb-4 flex items-center gap-2 border-b border-slate-100 pb-3">
        {Icon && <Icon className="h-4 w-4 text-slate-500" />}
        <h2 className="text-sm font-semibold text-slate-700">{title}</h2>
      </div>
      {children}
    </div>
  )
}

const STAGE_LABEL = {
  new_lead:        'Lead Baru',
  greeting_sent:   'Salam Terkirim',
  exploring_needs: 'Eksplorasi Kebutuhan',
  presenting:      'Presentasi',
  handling_objections: 'Menangani Keberatan',
  closing:         'Closing',
  follow_up:       'Follow Up',
  closed:          'Selesai',
}

const HANDOFF_REASON_LABEL = {
  user_requested:   'Permintaan User',
  complex_query:    'Pertanyaan Kompleks',
  low_confidence:   'Kepercayaan Rendah',
  repeated_objection: 'Keberatan Berulang',
  payment_issue:    'Masalah Pembayaran',
  other:            'Lainnya',
}

function BarRow({ label, value, max, color = 'emerald' }) {
  const pct = max > 0 ? Math.round((value / max) * 100) : 0
  const colorClass = {
    emerald: 'bg-emerald-500',
    blue:    'bg-blue-500',
    amber:   'bg-amber-400',
    violet:  'bg-violet-500',
    red:     'bg-red-400',
  }[color] ?? 'bg-emerald-500'

  return (
    <div className="flex items-center gap-3 text-sm">
      <span className="w-36 shrink-0 truncate text-slate-600">{label}</span>
      <div className="flex-1 overflow-hidden rounded-full bg-slate-100 h-2">
        <div className={`h-full rounded-full transition-all ${colorClass}`} style={{ width: `${pct}%` }} />
      </div>
      <span className="w-8 text-right font-semibold text-slate-700">{value}</span>
    </div>
  )
}

export default function Analytics({ analytics }) {
  const a = analytics ?? {}
  const temp = a.temperature ?? { hot: 0, warm: 0, cold: 0 }
  const tempTotal = temp.hot + temp.warm + temp.cold || 1
  const hotPct  = Math.round((temp.hot  / tempTotal) * 100)
  const warmPct = Math.round((temp.warm / tempTotal) * 100)
  const coldPct = 100 - hotPct - warmPct

  const stageBreakdown = a.stage_breakdown ?? {}
  const maxStage = Math.max(...Object.values(stageBreakdown), 1)

  const handoffByReason = a.handoff_by_reason ?? {}
  const maxHandoff = Math.max(...Object.values(handoffByReason), 1)

  const topPackages = a.top_packages ?? []
  const maxPkg = topPackages.length > 0 ? (topPackages[0]?.count ?? 1) : 1

  const topFaqs = a.top_faqs ?? []
  const maxFaq = topFaqs.length > 0 ? (topFaqs[0]?.count ?? 1) : 1

  const convRate = typeof a.conversion_rate === 'number'
    ? `${a.conversion_rate.toFixed(1)}%`
    : '—'

  return (
    <TenantLayout title="Analitik Lead">
      {/* Top metrics */}
      <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <MetricCard
          label="Total Lead"
          value={a.lead_count ?? 0}
          sub="Semua waktu"
          icon={Users}
          color="emerald"
        />
        <MetricCard
          label="Percakapan"
          value={a.conversations_total ?? 0}
          sub={`${a.conversations_open ?? 0} terbuka · ${a.conversations_closed ?? 0} selesai`}
          icon={MessagesSquare}
          color="blue"
        />
        <MetricCard
          label="Tingkat Konversi"
          value={convRate}
          sub="Lead → Booking"
          icon={TrendingUp}
          color="violet"
        />
        <MetricCard
          label="Booking Tereksekusi"
          value={a.booking_action_count ?? 0}
          sub={`${a.invoice_count ?? 0} invoice dibuat`}
          icon={CheckCircle2}
          color="emerald"
        />
      </section>

      {/* Secondary metrics */}
      <section className="mt-3 grid gap-3 sm:grid-cols-3">
        <MetricCard
          label="Handoff"
          value={a.handoff_count ?? 0}
          sub="Perlu eskalasi manusia"
          icon={HandMetal}
          color="amber"
        />
        <MetricCard
          label="Invoice Dibuat"
          value={a.invoice_count ?? 0}
          sub="Total invoice"
          icon={FileText}
          color="slate"
        />
        <MetricCard
          label="Lead Terskor"
          value={(temp.hot + temp.warm + temp.cold)}
          sub="Dengan lead temperature"
          icon={TrendingUp}
          color="slate"
        />
      </section>

      {/* Temperature breakdown */}
      <section className="mt-4">
        <div className="rounded-xl border border-slate-200 bg-white p-5">
          <h2 className="mb-4 font-semibold text-slate-700">Distribusi Suhu Lead</h2>
          <div className="flex gap-4">
            <div className="flex-1 text-center">
              <div className="flex items-center justify-center gap-1">
                <Flame className="h-5 w-5 text-red-500" />
                <span className="text-3xl font-bold text-red-600">{temp.hot}</span>
              </div>
              <p className="mt-1 text-xs text-slate-500">Hot</p>
              <p className="text-xs font-medium text-red-400">{hotPct}%</p>
            </div>
            <div className="flex-1 text-center">
              <div className="flex items-center justify-center gap-1">
                <Thermometer className="h-5 w-5 text-amber-500" />
                <span className="text-3xl font-bold text-amber-600">{temp.warm}</span>
              </div>
              <p className="mt-1 text-xs text-slate-500">Warm</p>
              <p className="text-xs font-medium text-amber-400">{warmPct}%</p>
            </div>
            <div className="flex-1 text-center">
              <div className="flex items-center justify-center gap-1">
                <Snowflake className="h-5 w-5 text-blue-400" />
                <span className="text-3xl font-bold text-blue-500">{temp.cold}</span>
              </div>
              <p className="mt-1 text-xs text-slate-500">Cold</p>
              <p className="text-xs font-medium text-blue-300">{coldPct}%</p>
            </div>
          </div>
          <div className="mt-4 h-3 overflow-hidden rounded-full bg-slate-100">
            <div className="flex h-full">
              <div className="bg-red-500 transition-all" style={{ width: `${hotPct}%` }} />
              <div className="bg-amber-400 transition-all" style={{ width: `${warmPct}%` }} />
              <div className="bg-blue-300 transition-all" style={{ width: `${coldPct}%` }} />
            </div>
          </div>
          <p className="mt-2 text-right text-xs text-slate-400">{temp.hot + temp.warm + temp.cold} lead terscored</p>
        </div>
      </section>

      {/* Stage breakdown + Handoff by reason */}
      <section className="mt-4 grid gap-4 lg:grid-cols-2">
        <SectionCard title="Distribusi Stage Percakapan" icon={MessagesSquare}>
          {Object.keys(stageBreakdown).length === 0 ? (
            <p className="text-sm text-slate-400">Belum ada data stage.</p>
          ) : (
            <div className="space-y-3">
              {Object.entries(stageBreakdown).map(([stage, count]) => (
                <BarRow
                  key={stage}
                  label={STAGE_LABEL[stage] ?? stage}
                  value={count}
                  max={maxStage}
                  color="emerald"
                />
              ))}
            </div>
          )}
        </SectionCard>

        <SectionCard title="Alasan Handoff" icon={HandMetal}>
          {Object.keys(handoffByReason).length === 0 ? (
            <p className="text-sm text-slate-400">Belum ada data handoff.</p>
          ) : (
            <div className="space-y-3">
              {Object.entries(handoffByReason).map(([reason, count]) => (
                <BarRow
                  key={reason}
                  label={HANDOFF_REASON_LABEL[reason] ?? reason}
                  value={count}
                  max={maxHandoff}
                  color="amber"
                />
              ))}
            </div>
          )}
        </SectionCard>
      </section>

      {/* Top packages + Top FAQs */}
      <section className="mt-4 grid gap-4 lg:grid-cols-2">
        <SectionCard title="Paket Paling Diminati" icon={Package}>
          {topPackages.length === 0 ? (
            <p className="text-sm text-slate-400">Belum ada data paket.</p>
          ) : (
            <div className="space-y-3">
              {topPackages.map((pkg, i) => (
                <BarRow
                  key={pkg.name ?? i}
                  label={pkg.name ?? `Paket #${i + 1}`}
                  value={pkg.count}
                  max={maxPkg}
                  color="violet"
                />
              ))}
            </div>
          )}
          <div className="mt-4 border-t border-slate-100 pt-3">
            <Link
              href="/tenant/business-data"
              className="flex items-center gap-1 text-xs text-emerald-600 hover:underline"
            >
              Kelola paket <ArrowUpRight className="h-3 w-3" />
            </Link>
          </div>
        </SectionCard>

        <SectionCard title="FAQ Paling Sering Ditanyakan" icon={HelpCircle}>
          {topFaqs.length === 0 ? (
            <p className="text-sm text-slate-400">Belum ada data FAQ.</p>
          ) : (
            <div className="space-y-3">
              {topFaqs.map((faq, i) => (
                <BarRow
                  key={faq.question ?? i}
                  label={faq.question ?? `FAQ #${i + 1}`}
                  value={faq.count}
                  max={maxFaq}
                  color="blue"
                />
              ))}
            </div>
          )}
          <div className="mt-4 border-t border-slate-100 pt-3">
            <Link
              href="/tenant/business-data"
              className="flex items-center gap-1 text-xs text-emerald-600 hover:underline"
            >
              Kelola FAQ <ArrowUpRight className="h-3 w-3" />
            </Link>
          </div>
        </SectionCard>
      </section>
    </TenantLayout>
  )
}
