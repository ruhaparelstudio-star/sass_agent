import React from 'react'
import { Head, Link } from '@inertiajs/react'
import SuperadminLayout from '../../layouts/SuperadminLayout'
import { Card } from '../../components/ui/card'

export default function ConversationsIndex({ summary, rows }) {
  return (
    <SuperadminLayout title="Pemantauan Percakapan Global">
      <Head title="Pemantauan Percakapan Global" />

      <section className="grid gap-4 md:grid-cols-3">
        <Card><p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Total Percakapan</p><p className="mt-2 text-3xl font-bold">{summary.total_conversations}</p></Card>
        <Card><p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Percakapan Terbuka</p><p className="mt-2 text-3xl font-bold">{summary.open_conversations}</p></Card>
        <Card><p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Handoff Tertunda</p><p className="mt-2 text-3xl font-bold">{summary.pending_handoffs}</p></Card>
      </section>

      <Card className="mt-6">
        <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-600">Percakapan Terbaru (Lintas Tenant)</h2>
        <ul className="mt-3 space-y-2 text-sm">
          {rows.length === 0 ? <li className="text-slate-500">Tidak ada percakapan.</li> : rows.map((row) => (
            <li key={row.id} className="rounded border border-slate-200 bg-slate-50 p-3">
              <p className="font-semibold">#{row.id} · {row.tenant.name} ({row.tenant.slug})</p>
              <p className="mt-1 text-slate-700">{row.customer_phone} · {row.status_label} · Handoff tertunda: {row.pending_handoffs}</p>
              <p className="mt-1 text-xs text-slate-500">Pesan terakhir: {row.last_message_preview}</p>
              <Link href={row.deep_link} className="mt-2 inline-block text-xs font-semibold text-blue-700 underline">Buka di inbox tenant</Link>
            </li>
          ))}
        </ul>
      </Card>
    </SuperadminLayout>
  )
}
