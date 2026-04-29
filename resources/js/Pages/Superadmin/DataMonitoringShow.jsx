import React from 'react'
import { Head, Link } from '@inertiajs/react'
import SuperadminLayout from '../../layouts/SuperadminLayout'
import { Card } from '../../components/ui/card'

export default function DataMonitoringShow({ detail }) {
  return (
    <SuperadminLayout title="Detail Pemantauan Data">
      <Head title="Detail Pemantauan Data" />

      <div className="mb-3 flex items-center justify-between gap-3">
        <div>
          <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Ruang Lingkup Tenant</p>
          <p className="mt-1 text-lg font-semibold">#{detail.tenant.id} {detail.tenant.name} ({detail.tenant.slug})</p>
        </div>
        <Link href="/superadmin/data-monitoring" className="text-sm text-blue-700 underline">Kembali ke Pemantauan Data</Link>
      </div>

      <section className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <Card><p className="text-xs uppercase tracking-wide text-slate-500">Layanan Aktif</p><p className="mt-2 text-3xl font-bold">{detail.counts.service_catalogs_active}</p></Card>
        <Card><p className="text-xs uppercase tracking-wide text-slate-500">Produk Aktif</p><p className="mt-2 text-3xl font-bold">{detail.counts.products_active}</p></Card>
        <Card><p className="text-xs uppercase tracking-wide text-slate-500">Paket Aktif</p><p className="mt-2 text-3xl font-bold">{detail.counts.packages_active}</p></Card>
        <Card><p className="text-xs uppercase tracking-wide text-slate-500">Harga Aktif</p><p className="mt-2 text-3xl font-bold">{detail.counts.prices_active}</p></Card>
        <Card><p className="text-xs uppercase tracking-wide text-slate-500">FAQ Aktif</p><p className="mt-2 text-3xl font-bold">{detail.counts.faqs_active}</p></Card>
      </section>

      <Card className="mt-6">
        <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-600">Data Terstruktur Terbaru</h2>
        <div className="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
          {Object.entries(detail.recent).map(([label, rows]) => (
            <article key={label} className="rounded border border-slate-200 bg-slate-50 p-3">
              <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label.replaceAll('_', ' ')}</p>
              <ul className="mt-2 list-disc space-y-1 pl-4 text-sm">
                {rows.length === 0 ? <li className="text-slate-500">Tidak ada data</li> : rows.map((row) => <li key={row.id}>#{row.id} {row.name ?? row.label ?? row.question ?? '-'}</li>)}
              </ul>
            </article>
          ))}
        </div>
      </Card>
    </SuperadminLayout>
  )
}
