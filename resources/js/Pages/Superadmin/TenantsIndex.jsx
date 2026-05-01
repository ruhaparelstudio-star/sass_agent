import React from 'react'
import { Head, Link } from '@inertiajs/react'
import SuperadminLayout from '../../layouts/SuperadminLayout'
import { Plus, ArrowUpRight, Building2, Search } from 'lucide-react'

const STATUS_MAP = {
  active: { label: 'Aktif', cls: 'bg-emerald-100 text-emerald-700' },
  trial: { label: 'Trial', cls: 'bg-blue-100 text-blue-700' },
  expired: { label: 'Expired', cls: 'bg-amber-100 text-amber-700' },
  suspended: { label: 'Dibekukan', cls: 'bg-red-100 text-red-700' },
  inactive: { label: 'Nonaktif', cls: 'bg-slate-100 text-slate-500' },
}

function tenantStatus(tenant) {
  if (!tenant.is_active) return 'inactive'
  if (tenant.subscription?.status) {
    const s = String(tenant.subscription.status).toLowerCase()
    if (s === 'trial') return 'trial'
    if (s === 'active') return 'active'
    if (s === 'expired') return 'expired'
    if (s === 'suspended') return 'suspended'
  }
  return tenant.is_active ? 'active' : 'inactive'
}

export default function TenantsIndex({ tenants }) {
  return (
    <SuperadminLayout title="Manajemen Tenant">
      <Head title="Manajemen Tenant" />

      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2 text-sm text-slate-500">
          <Building2 className="h-4 w-4" />
          <span>{tenants.length} tenant terdaftar</span>
        </div>
        <Link
          href="/superadmin/tenants/create"
          className="flex items-center gap-2 rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-violet-700"
        >
          <Plus className="h-4 w-4" />
          Buat Tenant
        </Link>
      </div>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div className="border-b border-slate-100 px-4 py-3">
          <h2 className="text-sm font-semibold text-slate-700">Daftar Tenant</h2>
        </div>
        <div className="overflow-x-auto">
          <table className="min-w-full text-sm">
            <thead>
              <tr className="border-b border-slate-100 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                <th className="px-4 py-3">Tenant</th>
                <th className="px-4 py-3">Slug</th>
                <th className="px-4 py-3">Status</th>
                <th className="px-4 py-3">Paket</th>
                <th className="px-4 py-3">Dibuat</th>
                <th className="px-4 py-3">Aksi</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {tenants.length === 0 ? (
                <tr>
                  <td className="px-4 py-8 text-center text-slate-400" colSpan="6">
                    Belum ada tenant terdaftar.
                  </td>
                </tr>
              ) : tenants.map((tenant) => {
                const status = tenantStatus(tenant)
                const statusInfo = STATUS_MAP[status] ?? STATUS_MAP.inactive
                return (
                  <tr key={tenant.id} className="hover:bg-slate-50">
                    <td className="px-4 py-3">
                      <p className="font-medium text-slate-800">#{tenant.id} {tenant.name}</p>
                    </td>
                    <td className="px-4 py-3 text-slate-500">{tenant.slug}</td>
                    <td className="px-4 py-3">
                      <span className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${statusInfo.cls}`}>
                        {statusInfo.label}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-slate-500">
                      {tenant.subscription?.plan?.name ?? <span className="text-xs italic text-slate-400">Belum ada</span>}
                    </td>
                    <td className="px-4 py-3 text-xs text-slate-400">
                      {tenant.created_at ? new Date(tenant.created_at).toLocaleDateString('id-ID') : '-'}
                    </td>
                    <td className="px-4 py-3">
                      <Link
                        href={`/superadmin/tenants/${tenant.id}`}
                        className="flex items-center gap-1 text-violet-600 hover:text-violet-800 hover:underline"
                      >
                        Detail <ArrowUpRight className="h-3.5 w-3.5" />
                      </Link>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      </div>
    </SuperadminLayout>
  )
}
