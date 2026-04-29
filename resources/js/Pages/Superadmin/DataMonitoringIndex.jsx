import React from 'react'
import { Head, Link } from '@inertiajs/react'
import SuperadminLayout from '../../layouts/SuperadminLayout'
import { Card } from '../../components/ui/card'

export default function DataMonitoringIndex({ tenants }) {
  return (
    <SuperadminLayout title="Pemantauan Data">
      <Head title="Pemantauan Data" />

      <Card>
        <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-600">Kesiapan Data Tenant</h2>
        <p className="mt-2 text-sm text-slate-600">Pantau kesiapan data terstruktur tenant untuk menjaga keputusan sistem tetap terkontrol, tervalidasi, dan bebas halusinasi.</p>

        <div className="mt-3 overflow-x-auto">
          <table className="min-w-full text-sm">
            <thead><tr className="text-left text-slate-600"><th className="py-2">Tenant</th><th className="py-2">Status</th><th className="py-2">Layanan</th><th className="py-2">Produk</th><th className="py-2">Paket</th><th className="py-2">Harga</th><th className="py-2">FAQ</th><th className="py-2">Penanda Gap</th><th className="py-2">Aksi</th></tr></thead>
            <tbody>
              {tenants.length === 0 ? <tr><td className="py-2 text-slate-500" colSpan="9">Tenant tidak ditemukan.</td></tr> : tenants.map((tenant) => {
                const flags = []
                if (tenant.data_gap_flags?.package_without_price) flags.push('package_without_price')
                if (tenant.data_gap_flags?.product_without_package) flags.push('product_without_package')

                return (
                  <tr key={tenant.id} className="border-t border-slate-100">
                    <td className="py-2">#{tenant.id} {tenant.name} ({tenant.slug})</td>
                    <td className="py-2">{tenant.is_active ? 'aktif' : 'nonaktif'}</td>
                    <td className="py-2">{tenant.counts.service_catalogs_active}</td>
                    <td className="py-2">{tenant.counts.products_active}</td>
                    <td className="py-2">{tenant.counts.packages_active}</td>
                    <td className="py-2">{tenant.counts.prices_active}</td>
                    <td className="py-2">{tenant.counts.faqs_active}</td>
                    <td className="py-2">{flags.length === 0 ? <span className="text-emerald-700">tidak_ada_gap</span> : <span className="text-amber-700">{flags.join(', ')}</span>}</td>
                    <td className="py-2"><Link href={`/superadmin/data-monitoring/${tenant.id}`} className="text-blue-700 underline">Detail</Link></td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      </Card>
    </SuperadminLayout>
  )
}
