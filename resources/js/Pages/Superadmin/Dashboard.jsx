import React from 'react'
import { Head, Link } from '@inertiajs/react'
import SuperadminLayout from '../../layouts/SuperadminLayout'
import {
  Building2,
  Users,
  MessageSquareMore,
  ArrowUpRight,
  TrendingUp,
  AlertTriangle,
  CheckCircle2,
  Clock,
  CreditCard,
  Cpu,
} from 'lucide-react'

function MetricCard({ label, value, sub, icon: Icon, color = 'slate', href }) {
  const colorMap = {
    slate: 'bg-slate-50 text-slate-600 border-slate-200',
    blue: 'bg-blue-50 text-blue-600 border-blue-200',
    violet: 'bg-violet-50 text-violet-600 border-violet-200',
    emerald: 'bg-emerald-50 text-emerald-600 border-emerald-200',
    amber: 'bg-amber-50 text-amber-600 border-amber-200',
    red: 'bg-red-50 text-red-600 border-red-200',
    indigo: 'bg-indigo-50 text-indigo-600 border-indigo-200',
  }
  const cls = colorMap[color] ?? colorMap.slate
  const inner = (
    <div className={`rounded-xl border p-4 transition ${cls} ${href ? 'hover:shadow-md' : ''}`}>
      <div className="flex items-start justify-between">
        <div>
          <p className="text-xs font-semibold uppercase tracking-wider opacity-75">{label}</p>
          <p className="mt-1.5 text-3xl font-bold">{value}</p>
          {sub && <p className="mt-1 text-xs opacity-70">{sub}</p>}
        </div>
        <div className="rounded-lg bg-white/60 p-2 shadow-sm">
          {Icon && <Icon className="h-5 w-5 opacity-80" />}
        </div>
      </div>
    </div>
  )
  return href ? <Link href={href}>{inner}</Link> : inner
}

export default function Dashboard({ summary, recentTenants }) {
  const totalTenants = summary.tenants_total ?? 0
  const activeTenants = summary.tenants_active ?? 0
  const inactiveTenants = summary.tenants_inactive ?? 0

  return (
    <SuperadminLayout title="Beranda Superadmin">
      <Head title="Beranda Superadmin" />

      <section className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <MetricCard
          label="Total Tenant"
          value={totalTenants}
          sub={`${activeTenants} aktif · ${inactiveTenants} nonaktif`}
          icon={Building2}
          color="violet"
          href="/superadmin/tenants"
        />
        <MetricCard
          label="Total Lead"
          value={summary.lead_count ?? 0}
          sub="Seluruh tenant"
          icon={Users}
          color="emerald"
        />
        <MetricCard
          label="Total Percakapan"
          value={summary.total_conversations ?? 0}
          sub="Lintas tenant"
          icon={MessageSquareMore}
          color="blue"
          href="/superadmin/conversations"
        />
        <MetricCard
          label="Handoff Tertunda"
          value={summary.pending_handoffs ?? summary.handoff_count ?? 0}
          sub="Butuh perhatian admin"
          icon={AlertTriangle}
          color="amber"
        />
        <MetricCard
          label="Booking Tereksekusi"
          value={summary.booking_action_count ?? 0}
          sub="Booking link terkirim"
          icon={CheckCircle2}
          color="emerald"
        />
        <MetricCard
          label="Token LLM Digunakan"
          value={(summary.token_usage_total ?? 0).toLocaleString()}
          sub="Akumulasi semua tenant"
          icon={Cpu}
          color="indigo"
        />
      </section>

      <section className="mt-6 grid gap-4 lg:grid-cols-2">
        <div className="rounded-xl border border-slate-200 bg-white p-5">
          <div className="flex items-center justify-between">
            <h2 className="font-semibold text-slate-700">Aksi Cepat</h2>
            <TrendingUp className="h-4 w-4 text-slate-400" />
          </div>
          <p className="mt-1 text-xs text-slate-500">Seluruh alur dipantau sebagai sistem workflow terkontrol.</p>
          <div className="mt-4 grid gap-2 sm:grid-cols-2">
            {[
              { href: '/superadmin/tenants/create', label: 'Buat Tenant Baru', icon: Building2, color: 'violet' },
              { href: '/superadmin/tenants', label: 'Manajemen Tenant', icon: Building2, color: 'slate' },
              { href: '/superadmin/plans', label: 'Manajemen Paket', icon: CreditCard, color: 'slate' },
              { href: '/superadmin/conversations', label: 'Monitor Percakapan', icon: MessageSquareMore, color: 'slate' },
              { href: '/superadmin/data-monitoring', label: 'Pemantauan Data', icon: Clock, color: 'slate' },
            ].map((item) => {
              const Icon = item.icon
              return (
                <Link
                  key={item.href}
                  href={item.href}
                  className="flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm font-medium text-slate-700 transition hover:border-violet-300 hover:bg-violet-50 hover:text-violet-700"
                >
                  <Icon className="h-4 w-4 shrink-0 text-slate-400" />
                  {item.label}
                  <ArrowUpRight className="ml-auto h-3.5 w-3.5 opacity-50" />
                </Link>
              )
            })}
          </div>
        </div>

        <div className="rounded-xl border border-slate-200 bg-white p-5">
          <div className="flex items-center justify-between">
            <h2 className="font-semibold text-slate-700">Tenant Terbaru</h2>
            <Link href="/superadmin/tenants" className="flex items-center gap-1 text-xs font-medium text-violet-600 hover:underline">
              Lihat semua <ArrowUpRight className="h-3 w-3" />
            </Link>
          </div>
          <ul className="mt-4 space-y-2">
            {(recentTenants ?? []).length === 0 ? (
              <li className="text-sm text-slate-500">Belum ada tenant.</li>
            ) : (recentTenants ?? []).map((tenant) => (
              <li key={tenant.id}>
                <Link
                  href={`/superadmin/tenants/${tenant.id}`}
                  className="flex items-center justify-between rounded-lg border border-slate-100 bg-slate-50 px-3 py-2.5 text-sm transition hover:border-violet-200 hover:bg-violet-50"
                >
                  <div>
                    <p className="font-medium text-slate-800">{tenant.name}</p>
                    <p className="text-xs text-slate-500">{tenant.slug}</p>
                  </div>
                  <span
                    className={`rounded-full px-2 py-0.5 text-xs font-medium ${
                      tenant.is_active
                        ? 'bg-emerald-100 text-emerald-700'
                        : 'bg-slate-100 text-slate-500'
                    }`}
                  >
                    {tenant.is_active ? 'Aktif' : 'Nonaktif'}
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        </div>
      </section>
    </SuperadminLayout>
  )
}
