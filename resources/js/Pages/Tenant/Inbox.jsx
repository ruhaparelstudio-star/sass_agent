import React, { useState } from 'react'
import { Link, router, useForm, usePage } from '@inertiajs/react'
import TenantLayout from '../../layouts/TenantLayout'
import {
  CheckCircle2,
  Bot,
  PauseCircle,
  XCircle,
  Send,
  RefreshCw,
  ChevronDown,
  ChevronUp,
  User,
  Phone,
  Calendar,
  MapPin,
  Package,
  DollarSign,
  Target,
  Flame,
  Thermometer,
  Snowflake,
  FileText,
  AlertTriangle,
  Search,
  Info,
  MessagesSquare,
} from 'lucide-react'

const TEMP_MAP = {
  hot: { icon: Flame, cls: 'text-red-500 bg-red-50', label: 'Hot' },
  warm: { icon: Thermometer, cls: 'text-amber-500 bg-amber-50', label: 'Warm' },
  cold: { icon: Snowflake, cls: 'text-blue-400 bg-blue-50', label: 'Cold' },
}

const AGENT_MODE_MAP = {
  active: { label: 'AI Aktif', cls: 'bg-emerald-100 text-emerald-700' },
  paused: { label: 'Dijeda', cls: 'bg-amber-100 text-amber-700' },
  handoff: { label: 'Handoff', cls: 'bg-red-100 text-red-700' },
  limited: { label: 'Terbatas', cls: 'bg-violet-100 text-violet-700' },
}

const STAGE_LABEL = {
  new_lead: 'Lead Baru',
  exploration: 'Eksplorasi',
  qualification: 'Kualifikasi',
  recommendation: 'Rekomendasi',
  consideration: 'Pertimbangan',
  booking: 'Booking',
  waiting_booking: 'Menunggu Booking',
  closed: 'Ditutup',
  invoice_phase: 'Fase Invoice',
  post_invoice_limited: 'Pasca Invoice',
  handoff: 'Handoff',
  paused_admin: 'Dijeda Admin',
}

function ConversationItem({ conv, isActive }) {
  const mode = AGENT_MODE_MAP[conv.agent_mode] ?? { label: conv.agent_mode, cls: 'bg-slate-100 text-slate-500' }
  return (
    <Link
      href={`/tenant/inbox?conversation_id=${conv.id}`}
      className={`block rounded-lg border px-3 py-2.5 text-sm transition ${
        isActive
          ? 'border-emerald-300 bg-emerald-50'
          : 'border-slate-100 bg-white hover:border-emerald-200 hover:bg-emerald-50/50'
      }`}
    >
      <div className="flex items-start justify-between gap-2">
        <p className="font-medium text-slate-800 truncate">{conv.customer_phone}</p>
        <span className={`shrink-0 rounded-full px-1.5 py-0.5 text-xs font-medium ${mode.cls}`}>
          {mode.label}
        </span>
      </div>
      {conv.last_message_preview && (
        <p className="mt-0.5 truncate text-xs text-slate-500">{conv.last_message_preview}</p>
      )}
      <div className="mt-1 flex items-center gap-2">
        <span className="text-xs text-slate-400">{STAGE_LABEL[conv.current_stage] ?? conv.current_stage ?? '—'}</span>
        {conv.last_activity_at && (
          <span className="text-xs text-slate-300">· {new Date(conv.last_activity_at).toLocaleDateString('id-ID')}</span>
        )}
      </div>
    </Link>
  )
}

function ChatBubble({ message }) {
  const [traceOpen, setTraceOpen] = useState(false)
  const isInbound = message.direction === 'inbound'
  const hasTrace = message.trace?.id || (message.trace?.validators && Object.keys(message.trace.validators ?? {}).length > 0)

  return (
    <div className={`flex gap-2 ${isInbound ? 'justify-start' : 'justify-end'}`}>
      {isInbound && (
        <div className="mt-1 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-slate-200">
          <User className="h-3.5 w-3.5 text-slate-500" />
        </div>
      )}
      <div className={`max-w-[75%] space-y-1 ${isInbound ? '' : 'items-end'}`}>
        <div
          className={`rounded-2xl px-3.5 py-2.5 text-sm leading-relaxed shadow-sm ${
            isInbound
              ? 'rounded-tl-sm bg-white text-slate-800 border border-slate-200'
              : 'rounded-tr-sm bg-emerald-600 text-white'
          }`}
        >
          {message.content || message.body || <span className="italic opacity-60">[media]</span>}
        </div>
        <div className={`flex items-center gap-2 ${isInbound ? '' : 'flex-row-reverse'}`}>
          <span className="text-xs text-slate-400">
            {message.created_at ? new Date(message.created_at).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }) : ''}
          </span>
          {!isInbound && hasTrace && (
            <button
              type="button"
              onClick={() => setTraceOpen((v) => !v)}
              className="flex items-center gap-1 rounded text-xs text-slate-400 hover:text-slate-600"
            >
              <Info className="h-3 w-3" />
              AI Trace
              {traceOpen ? <ChevronUp className="h-3 w-3" /> : <ChevronDown className="h-3 w-3" />}
            </button>
          )}
        </div>
        {!isInbound && traceOpen && message.trace && (
          <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
            {message.trace.validators && (
              <div className="mb-1">
                <span className="font-medium">Validator: </span>
                {Object.entries(message.trace.validators).filter(([k]) => k !== 'order').map(([k, v]) => (
                  <span key={k} className={`mr-2 rounded px-1 py-0.5 ${String(v) === 'passed' ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'}`}>
                    {k}:{String(v)}
                  </span>
                ))}
              </div>
            )}
            {message.trace.blocked_actions?.length > 0 && (
              <div>
                <span className="font-medium text-red-600">Diblokir: </span>
                {message.trace.blocked_actions.map((b, i) => (
                  <span key={i} className="mr-1 text-red-600">{b.action}({b.reason})</span>
                ))}
              </div>
            )}
            {message.trace.fallback_reason && (
              <div><span className="font-medium">Fallback: </span>{message.trace.fallback_reason}</div>
            )}
          </div>
        )}
      </div>
      {!isInbound && (
        <div className="mt-1 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-emerald-100">
          <Bot className="h-3.5 w-3.5 text-emerald-600" />
        </div>
      )}
    </div>
  )
}

function ContextPanel({ contextPanel, selectedConversation }) {
  const lead = contextPanel?.lead
  const state = contextPanel?.state ?? selectedConversation
  const ctx = contextPanel?.context

  const temp = lead?.lead_temperature
  const tempInfo = temp ? (TEMP_MAP[temp] ?? null) : null
  const TempIcon = tempInfo?.icon

  const mode = AGENT_MODE_MAP[state?.agent_mode] ?? { label: state?.agent_mode ?? '—', cls: 'bg-slate-100 text-slate-500' }

  function Row({ icon: Icon, label, value, cls = '' }) {
    if (!value) return null
    return (
      <div className="flex items-start gap-2 py-1.5 text-xs">
        {Icon && <Icon className={`mt-0.5 h-3.5 w-3.5 shrink-0 ${cls || 'text-slate-400'}`} />}
        <div>
          <p className="font-medium text-slate-500">{label}</p>
          <p className="text-slate-700">{value}</p>
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-3">
      <div className="rounded-xl border border-slate-200 bg-white p-4">
        <h3 className="mb-3 text-xs font-semibold uppercase tracking-wider text-slate-500">Profil Lead</h3>
        <div className="divide-y divide-slate-100">
          <Row icon={User} label="Nama" value={lead?.full_name} />
          <Row icon={Phone} label="Telepon" value={selectedConversation?.customer_phone} />
          <Row icon={Calendar} label="Tanggal Acara" value={lead?.event_date} />
          <Row icon={Target} label="Jenis Acara" value={lead?.event_type} />
          <Row icon={MapPin} label="Lokasi" value={lead?.location} />
          <Row icon={Package} label="Minat Paket" value={lead?.package_interest} />
          <Row
            icon={DollarSign}
            label="Estimasi Budget"
            value={lead?.budget_min || lead?.budget_max
              ? `${lead.budget_min ? 'Rp ' + Number(lead.budget_min).toLocaleString('id-ID') : '?'} – ${lead.budget_max ? 'Rp ' + Number(lead.budget_max).toLocaleString('id-ID') : '?'}`
              : null}
          />
          {lead?.objection_summary && (
            <div className="py-1.5 text-xs">
              <p className="font-medium text-slate-500">Keberatan</p>
              <p className="mt-0.5 text-amber-700">{lead.objection_summary}</p>
            </div>
          )}
        </div>
        {tempInfo && (
          <div className={`mt-2 flex items-center gap-2 rounded-lg px-2 py-1.5 text-xs font-medium ${tempInfo.cls}`}>
            <TempIcon className="h-3.5 w-3.5" />
            Lead Temperature: {tempInfo.label}
          </div>
        )}
      </div>

      <div className="rounded-xl border border-slate-200 bg-white p-4">
        <h3 className="mb-3 text-xs font-semibold uppercase tracking-wider text-slate-500">State Percakapan</h3>
        <div className="space-y-1.5 text-xs">
          <div className="flex items-center justify-between">
            <span className="text-slate-500">Tahap</span>
            <span className="font-medium text-slate-700">{STAGE_LABEL[state?.current_stage] ?? state?.current_stage ?? '—'}</span>
          </div>
          <div className="flex items-center justify-between">
            <span className="text-slate-500">Goal Aktif</span>
            <span className="font-medium text-slate-700">{state?.active_goal ?? '—'}</span>
          </div>
          <div className="flex items-center justify-between">
            <span className="text-slate-500">Mode AI</span>
            <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${mode.cls}`}>{mode.label}</span>
          </div>
          <div className="flex items-center justify-between">
            <span className="text-slate-500">Mode Memori</span>
            <span className="font-medium text-slate-700">{state?.memory_mode ?? '—'}</span>
          </div>
        </div>
      </div>

      {(ctx?.summary || ctx?.recommended_next_action) && (
        <div className="rounded-xl border border-slate-200 bg-white p-4">
          <h3 className="mb-3 text-xs font-semibold uppercase tracking-wider text-slate-500">Ringkasan AI</h3>
          {ctx.summary && <p className="text-xs text-slate-700 leading-relaxed">{ctx.summary}</p>}
          {ctx.recommended_next_action && (
            <div className="mt-2 rounded-lg bg-blue-50 px-2 py-1.5 text-xs text-blue-700">
              <span className="font-medium">Aksi disarankan: </span>{ctx.recommended_next_action}
            </div>
          )}
        </div>
      )}
    </div>
  )
}

export default function Inbox({ query, conversationList, selectedConversation, messages, handoffs, contextPanel, invoiceAssets, invoices }) {
  const page = usePage()
  const flash = page?.props?.flash ?? {}
  const [searchQ, setSearchQ] = useState(query ?? '')
  const invoiceForm = useForm({ tenant_asset_id: invoiceAssets?.[0]?.id ? String(invoiceAssets[0].id) : '' })

  const submitInvoice = (event) => {
    event.preventDefault()
    if (!selectedConversation?.id) return
    invoiceForm.post(`/tenant/inbox/${selectedConversation.id}/invoices`, { preserveScroll: true })
  }

  const handleSearch = (e) => {
    e.preventDefault()
    router.get('/tenant/inbox', { q: searchQ }, { preserveState: true })
  }

  const agentMode = selectedConversation?.agent_mode
  const canTakeover = agentMode === 'active'
  const canResumeFromConv = agentMode === 'paused'
  const canClose = selectedConversation && selectedConversation.status !== 'closed'

  return (
    <TenantLayout title="Kotak Masuk Percakapan">
      {flash.success && (
        <div className="mb-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
          {flash.success}
        </div>
      )}
      {flash.error && (
        <div className="mb-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
          {flash.error}
        </div>
      )}

      <div className="grid gap-4 lg:grid-cols-12">
        <aside className="rounded-xl border border-slate-200 bg-slate-50 p-3 lg:col-span-3">
          <form onSubmit={handleSearch} className="mb-3 flex gap-2">
            <div className="relative flex-1">
              <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-slate-400" />
              <input
                className="w-full rounded-lg border border-slate-200 bg-white py-2 pl-8 pr-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-300"
                placeholder="Cari nomor..."
                value={searchQ}
                onChange={(e) => setSearchQ(e.target.value)}
              />
            </div>
            <button type="submit" className="rounded-lg bg-emerald-600 px-2.5 py-2 text-white hover:bg-emerald-700">
              <Search className="h-3.5 w-3.5" />
            </button>
          </form>

          <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-400">
            Percakapan ({(conversationList ?? []).length})
          </p>
          <ul className="space-y-1.5">
            {(conversationList ?? []).map((conv) => (
              <li key={conv.id}>
                <ConversationItem conv={conv} isActive={conv.id === selectedConversation?.id} />
              </li>
            ))}
            {(conversationList ?? []).length === 0 && (
              <li className="py-4 text-center text-xs text-slate-400">
                <MessagesSquare className="mx-auto mb-2 h-6 w-6 opacity-40" />
                Belum ada percakapan.
              </li>
            )}
          </ul>
        </aside>

        <section className="flex flex-col gap-4 lg:col-span-6">
          {selectedConversation ? (
            <>
              <div className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-200 bg-white px-4 py-3">
                <div>
                  <p className="font-semibold text-slate-800">{selectedConversation.customer_phone}</p>
                  <p className="text-xs text-slate-400">
                    {STAGE_LABEL[selectedConversation.current_stage] ?? selectedConversation.current_stage ?? '—'}
                    {selectedConversation.agent_mode && (
                      <span className={`ml-2 rounded-full px-1.5 py-0.5 text-xs font-medium ${(AGENT_MODE_MAP[selectedConversation.agent_mode] ?? {}).cls ?? 'bg-slate-100 text-slate-500'}`}>
                        {(AGENT_MODE_MAP[selectedConversation.agent_mode] ?? {}).label ?? selectedConversation.agent_mode}
                      </span>
                    )}
                  </p>
                </div>
                <div className="flex flex-wrap gap-2">
                  {canTakeover && (
                    <button
                      type="button"
                      onClick={() => router.post(`/tenant/inbox/${selectedConversation.id}/takeover`)}
                      className="flex items-center gap-1.5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-medium text-amber-700 hover:bg-amber-100"
                    >
                      <PauseCircle className="h-3.5 w-3.5" /> Ambil Alih
                    </button>
                  )}
                  {canResumeFromConv && (
                    <button
                      type="button"
                      onClick={() => router.post(`/tenant/inbox/${selectedConversation.id}/handoff/${handoffs?.[0]?.id}/resume`)}
                      className="flex items-center gap-1.5 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-medium text-emerald-700 hover:bg-emerald-100"
                    >
                      <Bot className="h-3.5 w-3.5" /> Aktifkan AI
                    </button>
                  )}
                  {canClose && (
                    <button
                      type="button"
                      onClick={() => { if (confirm('Tutup percakapan ini?')) router.post(`/tenant/inbox/${selectedConversation.id}/close`) }}
                      className="flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 hover:border-red-200 hover:bg-red-50 hover:text-red-700"
                    >
                      <XCircle className="h-3.5 w-3.5" /> Tutup
                    </button>
                  )}
                </div>
              </div>

              <div className="min-h-64 flex-1 overflow-y-auto rounded-xl border border-slate-200 bg-slate-50 p-4">
                {(messages ?? []).length === 0 ? (
                  <div className="flex h-40 items-center justify-center text-sm text-slate-400">
                    <div className="text-center">
                      <MessagesSquare className="mx-auto mb-2 h-8 w-8 opacity-30" />
                      Belum ada pesan.
                    </div>
                  </div>
                ) : (
                  <div className="space-y-4">
                    {(messages ?? []).map((msg) => (
                      <ChatBubble key={msg.id} message={msg} />
                    ))}
                  </div>
                )}
              </div>

              {(handoffs ?? []).length > 0 && (
                <div className="rounded-xl border border-amber-200 bg-amber-50 p-4">
                  <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-amber-700">Handoff Aktif</h3>
                  <ul className="space-y-2">
                    {(handoffs ?? []).map((handoff) => (
                      <li key={handoff.id} className="rounded-lg border border-amber-200 bg-white p-3 text-sm">
                        <div className="flex items-start justify-between gap-2">
                          <div>
                            <p className="font-medium text-slate-700">Alasan: {handoff.reason_code}</p>
                            {handoff.note && <p className="mt-0.5 text-xs text-slate-500">{handoff.note}</p>}
                          </div>
                          <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${
                            handoff.status === 'pending' ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'
                          }`}>
                            {handoff.status}
                          </span>
                        </div>
                        <div className="mt-2 flex gap-2">
                          {handoff.can_resolve_handoff && (
                            <button
                              type="button"
                              onClick={() => router.post(`/tenant/inbox/${handoff.conversation_id}/handoff/${handoff.id}/resolve`)}
                              className="flex items-center gap-1.5 rounded-md border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-100"
                            >
                              <CheckCircle2 className="h-3.5 w-3.5" /> Selesaikan
                            </button>
                          )}
                          {handoff.can_resume_ai && (
                            <button
                              type="button"
                              onClick={() => router.post(`/tenant/inbox/${handoff.conversation_id}/handoff/${handoff.id}/resume`)}
                              className="flex items-center gap-1.5 rounded-md border border-blue-200 bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700 hover:bg-blue-100"
                            >
                              <Bot className="h-3.5 w-3.5" /> Lanjutkan AI
                            </button>
                          )}
                        </div>
                      </li>
                    ))}
                  </ul>
                </div>
              )}

              <div className="rounded-xl border border-slate-200 bg-white p-4">
                <div className="flex items-center gap-2 mb-3">
                  <FileText className="h-4 w-4 text-slate-500" />
                  <h3 className="text-sm font-semibold text-slate-700">Invoice Lead</h3>
                </div>
                <form className="space-y-2" onSubmit={submitInvoice}>
                  <div className="flex gap-2">
                    <select
                      className="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-300"
                      value={invoiceForm.data.tenant_asset_id}
                      onChange={(e) => invoiceForm.setData('tenant_asset_id', e.target.value)}
                      disabled={!selectedConversation?.id || (invoiceAssets ?? []).length === 0}
                    >
                      {(invoiceAssets ?? []).length === 0
                        ? <option value="">Belum ada template invoice</option>
                        : (invoiceAssets ?? []).map((asset) => (
                          <option key={asset.id} value={asset.id}>{asset.label}</option>
                        ))}
                    </select>
                    <button
                      type="submit"
                      disabled={!selectedConversation?.id || (invoiceAssets ?? []).length === 0 || invoiceForm.processing}
                      className="flex items-center gap-2 rounded-lg bg-emerald-600 px-3 py-2 text-xs font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
                    >
                      <Send className="h-3.5 w-3.5" /> Buat Invoice
                    </button>
                  </div>
                  {invoiceForm.errors.tenant_asset_id && (
                    <p className="text-xs text-red-600">{invoiceForm.errors.tenant_asset_id}</p>
                  )}
                </form>

                {(invoices ?? []).length > 0 && (
                  <ul className="mt-3 space-y-2">
                    {(invoices ?? []).map((inv) => (
                      <li key={inv.id} className="flex items-center justify-between rounded-lg border border-slate-100 bg-slate-50 px-3 py-2 text-xs">
                        <div>
                          <p className="font-medium text-slate-700">Invoice #{inv.id}</p>
                          <p className="text-slate-400">{inv.asset_label ?? 'Template —'}</p>
                        </div>
                        <div className="flex items-center gap-2">
                          <span className={`rounded-full px-2 py-0.5 font-medium ${
                            inv.status === 'sent' ? 'bg-emerald-100 text-emerald-700' :
                            inv.status === 'ready' ? 'bg-blue-100 text-blue-700' :
                            'bg-slate-100 text-slate-500'
                          }`}>
                            {inv.status}
                          </span>
                          <button
                            type="button"
                            onClick={() => router.post(`/tenant/inbox/${selectedConversation.id}/invoices/${inv.id}/resend`)}
                            className="flex items-center gap-1 rounded border border-slate-200 bg-white px-2 py-0.5 font-medium text-slate-600 hover:border-emerald-300 hover:text-emerald-700"
                          >
                            <RefreshCw className="h-3 w-3" /> Kirim Ulang
                          </button>
                        </div>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            </>
          ) : (
            <div className="flex h-64 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-400">
              <div className="text-center">
                <MessagesSquare className="mx-auto mb-2 h-10 w-10 opacity-30" />
                <p className="text-sm">Pilih percakapan untuk memulai</p>
              </div>
            </div>
          )}
        </section>

        <aside className="lg:col-span-3">
          {selectedConversation ? (
            <ContextPanel contextPanel={contextPanel} selectedConversation={selectedConversation} />
          ) : (
            <div className="rounded-xl border border-slate-200 bg-white p-4 text-center text-sm text-slate-400">
              <AlertTriangle className="mx-auto mb-2 h-6 w-6 opacity-40" />
              Pilih percakapan untuk melihat konteks lead.
            </div>
          )}
        </aside>
      </div>
    </TenantLayout>
  )
}
