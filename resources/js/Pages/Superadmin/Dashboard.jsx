import React from 'react'
import { Head, Link } from '@inertiajs/react'
import SuperadminLayout from '../../layouts/SuperadminLayout'
import { Card } from '../../components/ui/card'

export default function Dashboard({ summary, recentTenants }) {
  const quickLinks = [
    { href: '/superadmin/tenants', title: 'Manajemen Tenant', tag: 'Kelola' },
    { href: '/superadmin/plans', title: 'Manajemen Paket', tag: 'Kelola' },
    { href: '/superadmin/conversations', title: 'Pemantauan Percakapan', tag: 'Pantau' },
    { href: '/superadmin/data-monitoring', title: 'Pemantauan Data', tag: 'Pantau' },
  ]

  return (
    <SuperadminLayout title="Dashboard Super Admin">
      <Head title="Dashboard Super Admin" />

      <section className="grid gap-4 md:grid-cols-3">
        <Card><p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Total Tenant</p><p className="mt-2 text-3xl font-bold">{summary.tenants_total}</p></Card>
        <Card><p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Tenant Aktif</p><p className="mt-2 text-3xl font-bold">{summary.tenants_active}</p></Card>
        <Card><p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Tenant Nonaktif</p><p className="mt-2 text-3xl font-bold">{summary.tenants_inactive}</p></Card>
      </section>

      <section className="mt-4 grid gap-4 md:grid-cols-4">
        <Card><p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Total Lead</p><p className="mt-2 text-2xl font-bold">{summary.lead_count ?? 0}</p></Card>
        <Card><p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Total Handoff</p><p className="mt-2 text-2xl font-bold">{summary.handoff_count ?? 0}</p></Card>
        <Card><p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Booking Executed</p><p className="mt-2 text-2xl font-bold">{summary.booking_action_count ?? 0}</p></Card>
        <Card><p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Token Usage</p><p className="mt-2 text-2xl font-bold">{summary.token_usage_total ?? 0}</p></Card>
      </section>

      <Card className="mt-6">
        <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-600">Pusat Kendali</h2>
        <p className="mt-1 text-xs text-slate-500">Seluruh alur dipantau sebagai sistem workflow terkontrol dan tervalidasi.</p>
        <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {quickLinks.map((item) => (
            <Link key={item.href} href={item.href} className="block rounded-lg border border-slate-200 bg-slate-50 p-3 hover:border-blue-300 hover:bg-blue-50">
              <p className="text-xs uppercase tracking-wide text-slate-500">{item.tag}</p>
              <p className="mt-1 text-sm font-semibold text-slate-900">{item.title}</p>
            </Link>
          ))}
        </div>
      </Card>

      <Card className="mt-6">
        <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-600">Tenant Terbaru</h2>
        <ul className="mt-3 space-y-2 text-sm">
          {recentTenants.length === 0 ? <li className="text-slate-500">Belum ada tenant.</li> : recentTenants.map((tenant) => (
            <li key={tenant.id} className="rounded border border-slate-200 bg-slate-50 px-3 py-2">
              #{tenant.id} {tenant.name} ({tenant.slug}) - {tenant.is_active ? 'aktif' : 'nonaktif'}
            </li>
          ))}
        </ul>
      </Card>
    </SuperadminLayout>
  )
}
