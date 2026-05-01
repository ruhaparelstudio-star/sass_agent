import React from 'react'
import { Link, router } from '@inertiajs/react'
import TenantLayout from '../../layouts/TenantLayout'
import {
  Bell,
  CheckCheck,
  MessagesSquare,
  AlertTriangle,
  CheckCircle2,
  Clock,
  Filter,
} from 'lucide-react'

const STATUS_CONFIG = {
  queued: { label: 'Antrian',   cls: 'bg-blue-100 text-blue-700' },
  sent:   { label: 'Terkirim',  cls: 'bg-emerald-100 text-emerald-700' },
  failed: { label: 'Gagal',     cls: 'bg-red-100 text-red-600' },
  read:   { label: 'Dibaca',    cls: 'bg-slate-100 text-slate-500' },
}

const TYPE_LABEL = {
  handoff_alert:     'Notifikasi Handoff',
  booking_confirmed: 'Booking Dikonfirmasi',
  invoice_sent:      'Invoice Terkirim',
  follow_up_due:     'Follow-up Jatuh Tempo',
  lead_hot:          'Lead Menjadi Hot',
  system:            'Sistem',
}

const FILTER_TABS = [
  { key: 'all',    label: 'Semua' },
  { key: 'unread', label: 'Belum Dibaca' },
  { key: 'failed', label: 'Gagal' },
]

function StatusIcon({ status }) {
  if (status === 'failed')  return <AlertTriangle className="h-4 w-4 text-red-400 shrink-0" />
  if (status === 'sent')    return <CheckCircle2  className="h-4 w-4 text-emerald-400 shrink-0" />
  if (status === 'queued')  return <Clock         className="h-4 w-4 text-blue-400 shrink-0" />
  return <Bell className="h-4 w-4 text-slate-300 shrink-0" />
}

function NotifRow({ notif }) {
  const statusCfg = STATUS_CONFIG[notif.status] ?? { label: notif.status, cls: 'bg-slate-100 text-slate-500' }
  const typeLabel = TYPE_LABEL[notif.type] ?? notif.type

  return (
    <li className={`flex items-start gap-3 rounded-lg border px-4 py-3 text-sm transition ${
      notif.status === 'read' ? 'border-slate-100 bg-white' : 'border-emerald-100 bg-emerald-50/40'
    }`}>
      <StatusIcon status={notif.status} />
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-2">
          <span className="font-medium text-slate-700">{typeLabel}</span>
          <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${statusCfg.cls}`}>
            {statusCfg.label}
          </span>
          {notif.channel && (
            <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500">
              {notif.channel}
            </span>
          )}
        </div>
        {notif.message && (
          <p className="mt-1 text-xs text-slate-500 line-clamp-2">{notif.message}</p>
        )}
        <div className="mt-1.5 flex flex-wrap items-center gap-3 text-xs text-slate-400">
          {notif.conversation_id && (
            <Link
              href={`/tenant/inbox?conversation_id=${notif.conversation_id}`}
              className="flex items-center gap-1 text-emerald-600 hover:underline"
            >
              <MessagesSquare className="h-3 w-3" />
              Percakapan #{notif.conversation_id}
            </Link>
          )}
          <span>{notif.created_at ? new Date(notif.created_at).toLocaleString('id-ID') : '—'}</span>
        </div>
      </div>
    </li>
  )
}

export default function Notifications({ notifications, summary, filter }) {
  const list    = notifications ?? []
  const summ    = summary ?? { total: 0, unread: 0, failed: 0 }
  const current = filter ?? 'all'

  function markAllRead() {
    router.post('/tenant/notifications/read-all')
  }

  return (
    <TenantLayout title="Notifikasi">
      {/* Summary cards */}
      <section className="grid gap-3 sm:grid-cols-3">
        <div className="rounded-xl border border-slate-200 bg-white p-4 text-center">
          <p className="text-2xl font-bold text-slate-700">{summ.total}</p>
          <p className="mt-1 text-xs font-medium text-slate-500 uppercase tracking-wider">Total</p>
        </div>
        <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-center">
          <p className="text-2xl font-bold text-emerald-700">{summ.unread}</p>
          <p className="mt-1 text-xs font-medium text-emerald-600 uppercase tracking-wider">Belum Dibaca</p>
        </div>
        <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-center">
          <p className="text-2xl font-bold text-red-600">{summ.failed}</p>
          <p className="mt-1 text-xs font-medium text-red-500 uppercase tracking-wider">Gagal</p>
        </div>
      </section>

      {/* Filter tabs + mark all */}
      <section className="mt-4 flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-1 rounded-lg border border-slate-200 bg-white p-1">
          <Filter className="ml-1 h-3.5 w-3.5 text-slate-400" />
          {FILTER_TABS.map((tab) => (
            <Link
              key={tab.key}
              href={`/tenant/notifications?filter=${tab.key}`}
              className={`rounded-md px-3 py-1.5 text-xs font-medium transition ${
                current === tab.key
                  ? 'bg-emerald-600 text-white shadow-sm'
                  : 'text-slate-600 hover:bg-slate-100'
              }`}
            >
              {tab.label}
              {tab.key === 'unread' && summ.unread > 0 && (
                <span className={`ml-1.5 rounded-full px-1.5 py-0.5 text-xs font-bold ${
                  current === tab.key ? 'bg-white/20 text-white' : 'bg-emerald-100 text-emerald-700'
                }`}>
                  {summ.unread}
                </span>
              )}
              {tab.key === 'failed' && summ.failed > 0 && (
                <span className={`ml-1.5 rounded-full px-1.5 py-0.5 text-xs font-bold ${
                  current === tab.key ? 'bg-white/20 text-white' : 'bg-red-100 text-red-600'
                }`}>
                  {summ.failed}
                </span>
              )}
            </Link>
          ))}
        </div>

        {summ.unread > 0 && (
          <button
            type="button"
            onClick={markAllRead}
            className="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-medium text-emerald-700 hover:bg-emerald-100 transition"
          >
            <CheckCheck className="h-3.5 w-3.5" />
            Tandai Semua Dibaca
          </button>
        )}
      </section>

      {/* List */}
      <section className="mt-3">
        {list.length === 0 ? (
          <div className="rounded-xl border border-slate-200 bg-white py-12 text-center">
            <Bell className="mx-auto mb-3 h-8 w-8 text-slate-200" />
            <p className="text-sm text-slate-400">Tidak ada notifikasi{current !== 'all' ? ' untuk filter ini' : ''}.</p>
            {current !== 'all' && (
              <Link href="/tenant/notifications" className="mt-2 block text-xs text-emerald-600 hover:underline">
                Lihat semua notifikasi
              </Link>
            )}
          </div>
        ) : (
          <ul className="space-y-2">
            {list.map((notif) => (
              <NotifRow key={notif.id} notif={notif} />
            ))}
          </ul>
        )}
      </section>
    </TenantLayout>
  )
}
