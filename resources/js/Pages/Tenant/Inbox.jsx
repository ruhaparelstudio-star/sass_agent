import React from 'react'
import { Link, router, useForm, usePage } from '@inertiajs/react'
import TenantLayout from '../../layouts/TenantLayout'
import { Button } from '../../components/ui/button'
import { CheckCircle2, Bot } from 'lucide-react'

export default function Inbox({ query, conversationList, selectedConversation, messages, handoffs, contextPanel, invoiceAssets, invoices }) {
  const page = usePage()
  const flash = page?.props?.flash ?? {}
  const invoiceForm = useForm({ tenant_asset_id: invoiceAssets?.[0]?.id ? String(invoiceAssets[0].id) : '' })

  const submitInvoice = (event) => {
    event.preventDefault()
    if (!selectedConversation?.id) return
    invoiceForm.post(`/tenant/inbox/${selectedConversation.id}/invoices`, { preserveScroll: true })
  }

  return (
    <TenantLayout title="Kotak Masuk Percakapan">
      {flash.success ? <div className="mb-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{flash.success}</div> : null}
      <div className="grid gap-4 lg:grid-cols-12">
        <aside className="rounded-xl border border-slate-200 bg-white p-4 lg:col-span-4">
          <h2 className="font-semibold">Percakapan</h2>
          <p className="mt-1 text-xs text-slate-500">Pencarian: {query || '-'}</p>
          <ul className="mt-3 space-y-2 text-sm">
            {(conversationList ?? []).map((row) => (
              <li key={row.id}>
                <Link href={`/tenant/inbox?conversation_id=${row.id}`}>{row.customer_phone} ({row.status_label})</Link>
              </li>
            ))}
            {(conversationList ?? []).length === 0 && <li className="text-slate-500">Belum ada percakapan</li>}
          </ul>
        </aside>

        <section className="space-y-4 lg:col-span-5">
          <div className="rounded-xl border border-slate-200 bg-white p-4">
            <h2 className="font-semibold">Pesan</h2>
            <p className="mt-1 text-xs text-slate-500">Percakapan: {selectedConversation?.customer_phone ?? '-'}</p>
            <ul className="mt-3 space-y-2 text-sm">
              {(messages ?? []).map((row) => (
                <li key={row.id}><strong>{row.direction_label}:</strong> {row.content}</li>
              ))}
              {(messages ?? []).length === 0 && <li className="text-slate-500">Belum ada pesan</li>}
            </ul>
          </div>

          <div className="rounded-xl border border-slate-200 bg-white p-4">
            <h2 className="font-semibold">Handoff</h2>
            <ul className="mt-3 space-y-2 text-sm">
              {(handoffs ?? []).map((row) => (
                <li key={row.id} className="rounded border border-slate-200 p-2">
                  <div>Alasan: {row.reason_code}</div>
                  <div>Status: {row.status}</div>
                  <div className="mt-2 flex gap-2">
                    {row.can_resolve_handoff && (
                      <Button
                        type="button"
                        className="px-3 py-1 text-xs"
                        variant="secondary"
                        leftIcon={CheckCircle2}
                        onClick={() => router.post(`/tenant/inbox/${row.conversation_id}/handoff/${row.id}/resolve`)}
                      >
                        Selesaikan
                      </Button>
                    )}
                    {row.can_resume_ai && (
                      <Button
                        type="button"
                        className="px-3 py-1 text-xs"
                        leftIcon={Bot}
                        onClick={() => router.post(`/tenant/inbox/${row.conversation_id}/handoff/${row.id}/resume`)}
                      >
                        Lanjutkan AI
                      </Button>
                    )}
                  </div>
                </li>
              ))}
              {(handoffs ?? []).length === 0 && <li className="text-slate-500">Belum ada handoff</li>}
            </ul>
          </div>

          <div className="rounded-xl border border-slate-200 bg-white p-4">
            <h2 className="font-semibold">Invoice Lead</h2>
            <p className="mt-1 text-xs text-slate-500">Invoice dibuat per conversation/lead aktif.</p>
            <form className="mt-3 space-y-2" onSubmit={submitInvoice}>
              <label className="block space-y-1 text-sm">
                <span className="font-medium text-slate-700">Template Invoice (Aset)</span>
                <select
                  className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                  value={invoiceForm.data.tenant_asset_id}
                  onChange={(e) => invoiceForm.setData('tenant_asset_id', e.target.value)}
                  disabled={!selectedConversation?.id || (invoiceAssets ?? []).length === 0}
                >
                  {(invoiceAssets ?? []).length === 0 ? <option value="">Belum ada aset invoice</option> : null}
                  {(invoiceAssets ?? []).map((asset) => <option key={asset.id} value={asset.id}>{asset.label}</option>)}
                </select>
              </label>
              {invoiceForm.errors.tenant_asset_id ? <p className="text-xs text-red-600">{invoiceForm.errors.tenant_asset_id}</p> : null}
              <Button type="submit" className="px-3 py-1 text-xs" disabled={!selectedConversation?.id || (invoiceAssets ?? []).length === 0 || invoiceForm.processing}>Generate Invoice Lead</Button>
            </form>

            <ul className="mt-3 space-y-2 text-sm">
              {(invoices ?? []).map((row) => (
                <li key={row.id} className="rounded border border-slate-200 p-2">
                  <div><strong>Invoice ID:</strong> {row.id}</div>
                  <div><strong>Status:</strong> {row.status}</div>
                  <div><strong>Template:</strong> {row.asset_label || '-'}</div>
                </li>
              ))}
              {(invoices ?? []).length === 0 && <li className="text-slate-500">Belum ada invoice untuk lead ini.</li>}
            </ul>
          </div>
        </section>

        <aside className="rounded-xl border border-slate-200 bg-white p-4 lg:col-span-3">
          <h2 className="font-semibold">Panel Konteks</h2>
          <div className="mt-3 space-y-2 text-sm">
            <div><strong>Prospek:</strong> {contextPanel?.lead?.full_name ?? 'Data belum tersedia'}</div>
            <div><strong>Tahap:</strong> {contextPanel?.state?.current_stage ?? 'Data belum tersedia'}</div>
            <div><strong>Ringkasan:</strong> {contextPanel?.context?.summary ?? 'Data belum tersedia'}</div>
            <div><strong>Alasan:</strong> {contextPanel?.context?.reason ?? 'Data belum tersedia'}</div>
            <div><strong>Langkah Berikutnya:</strong> {contextPanel?.context?.recommended_next_action ?? 'Data belum tersedia'}</div>
          </div>
        </aside>
      </div>
    </TenantLayout>
  )
}
