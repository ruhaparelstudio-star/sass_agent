import React from 'react'
import { Head, Link, router } from '@inertiajs/react'
import SuperadminLayout from '../../layouts/SuperadminLayout'
import {
  Building2,
  UserX,
  UserCheck,
  Pencil,
  ArrowLeft,
  CreditCard,
  Key,
  Smartphone,
  Users,
  CheckCircle2,
  AlertTriangle,
  Clock,
  Wifi,
  WifiOff,
} from 'lucide-react'

function Section({ title, icon: Icon, children }) {
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

function InfoRow({ label, value }) {
  return (
    <div className="flex items-start justify-between gap-4 py-2 text-sm">
      <span className="min-w-0 shrink-0 text-slate-500">{label}</span>
      <span className="text-right font-medium text-slate-800 break-all">{value ?? <span className="text-slate-400 italic">—</span>}</span>
    </div>
  )
}

const WA_STATUS_MAP = {
  connected: { label: 'Terhubung', icon: Wifi, cls: 'text-emerald-600' },
  disconnected: { label: 'Terputus', icon: WifiOff, cls: 'text-slate-400' },
  qr_pending: { label: 'Menunggu QR', icon: Clock, cls: 'text-amber-500' },
  reconnecting: { label: 'Menyambung ulang', icon: Clock, cls: 'text-blue-500' },
  failed: { label: 'Gagal', icon: AlertTriangle, cls: 'text-red-500' },
  banned_or_restricted: { label: 'Diblokir', icon: AlertTriangle, cls: 'text-red-700' },
}

export default function TenantsShow({ tenant, subscription, activationToken, waAccounts, tenantUsers }) {
  const waList = waAccounts ?? []
  const userList = tenantUsers ?? []

  return (
    <SuperadminLayout title={`Tenant: ${tenant.name}`}>
      <Head title={`Detail Tenant #${tenant.id}`} />

      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <Link href="/superadmin/tenants" className="flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700">
          <ArrowLeft className="h-4 w-4" /> Kembali ke daftar
        </Link>
        <div className="flex flex-wrap gap-2">
          <Link
            href={`/superadmin/tenants/${tenant.id}/edit`}
            className="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:border-violet-300 hover:text-violet-700"
          >
            <Pencil className="h-3.5 w-3.5" /> Edit
          </Link>
          {tenant.is_active ? (
            <button
              type="button"
              onClick={() => router.post(`/superadmin/tenants/${tenant.id}/deactivate`)}
              className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-100"
            >
              <UserX className="h-3.5 w-3.5" /> Nonaktifkan
            </button>
          ) : (
            <button
              type="button"
              onClick={() => router.post(`/superadmin/tenants/${tenant.id}/activate`)}
              className="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-sm font-medium text-emerald-700 hover:bg-emerald-100"
            >
              <UserCheck className="h-3.5 w-3.5" /> Aktifkan
            </button>
          )}
        </div>
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Section title="Informasi Tenant" icon={Building2}>
          <div className="divide-y divide-slate-100">
            <InfoRow label="ID" value={`#${tenant.id}`} />
            <InfoRow label="Nama" value={tenant.name} />
            <InfoRow label="Slug" value={tenant.slug} />
            <InfoRow label="Status" value={
              <span className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${tenant.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}>
                {tenant.is_active ? 'Aktif' : 'Nonaktif'}
              </span>
            } />
            <InfoRow label="Dibuat" value={tenant.created_at ? new Date(tenant.created_at).toLocaleDateString('id-ID', { dateStyle: 'long' }) : '—'} />
            <InfoRow label="Diperbarui" value={tenant.updated_at ? new Date(tenant.updated_at).toLocaleDateString('id-ID', { dateStyle: 'long' }) : '—'} />
          </div>
        </Section>

        <Section title="Langganan & Paket" icon={CreditCard}>
          {subscription ? (
            <div className="divide-y divide-slate-100">
              <InfoRow label="Paket" value={subscription.plan?.name ?? 'Tidak diketahui'} />
              <InfoRow label="Status Langganan" value={
                <span className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${
                  String(subscription.status).toLowerCase() === 'active' ? 'bg-emerald-100 text-emerald-700' :
                  String(subscription.status).toLowerCase() === 'trial' ? 'bg-blue-100 text-blue-700' :
                  String(subscription.status).toLowerCase() === 'expired' ? 'bg-amber-100 text-amber-700' :
                  'bg-slate-100 text-slate-500'
                }`}>
                  {subscription.status}
                </span>
              } />
              <InfoRow
                label="Mulai"
                value={subscription.starts_at ? new Date(subscription.starts_at).toLocaleDateString('id-ID') : '—'}
              />
              <InfoRow
                label="Berakhir"
                value={subscription.ends_at ? new Date(subscription.ends_at).toLocaleDateString('id-ID') : '—'}
              />
            </div>
          ) : (
            <div className="rounded-lg bg-amber-50 px-3 py-4 text-center text-sm text-amber-700">
              <AlertTriangle className="mx-auto mb-2 h-5 w-5" />
              Belum ada langganan aktif.{' '}
              <Link href="/superadmin/plans" className="font-semibold underline">Assign paket</Link>
            </div>
          )}
        </Section>

        <Section title="Token Aktivasi" icon={Key}>
          {activationToken ? (
            <div className="space-y-3">
              <div className="rounded-lg bg-blue-50 px-3 py-3 text-sm text-blue-800">
                <div className="flex items-center gap-2">
                  <Clock className="h-4 w-4 shrink-0" />
                  <span className="font-medium">Token aktivasi tersedia</span>
                </div>
                <p className="mt-1 text-xs text-blue-600">Email: {activationToken.email}</p>
                <p className="text-xs text-blue-600">
                  Kedaluwarsa: {new Date(activationToken.expires_at).toLocaleString('id-ID')}
                </p>
              </div>
              <p className="text-xs text-slate-500">
                Bagikan link aktivasi ke tenant agar dapat mengatur password dan login pertama kali.
              </p>
            </div>
          ) : (
            <div className="rounded-lg bg-slate-50 px-3 py-4 text-center text-sm text-slate-500">
              <CheckCircle2 className="mx-auto mb-2 h-5 w-5 text-emerald-500" />
              Tidak ada token aktivasi aktif. Tenant sudah aktif atau token sudah dipakai.
            </div>
          )}
        </Section>

        <Section title="WhatsApp Agent" icon={Smartphone}>
          {waList.length === 0 ? (
            <p className="text-sm text-slate-500">Belum ada WA agent terdaftar untuk tenant ini.</p>
          ) : (
            <ul className="space-y-2">
              {waList.map((wa) => {
                const statusInfo = WA_STATUS_MAP[wa.status] ?? { label: wa.status, icon: AlertTriangle, cls: 'text-slate-500' }
                const StatusIcon = statusInfo.icon
                return (
                  <li key={wa.id} className="flex items-center justify-between rounded-lg border border-slate-100 bg-slate-50 px-3 py-2.5 text-sm">
                    <div>
                      <p className="font-medium text-slate-700">{wa.label ?? `Agent #${wa.id}`}</p>
                      <p className="text-xs text-slate-500">{wa.phone_number ?? 'Nomor belum diset'}</p>
                    </div>
                    <div className={`flex items-center gap-1 text-xs font-medium ${statusInfo.cls}`}>
                      <StatusIcon className="h-3.5 w-3.5" />
                      {statusInfo.label}
                    </div>
                  </li>
                )
              })}
            </ul>
          )}
        </Section>

        <Section title="Pengguna Tenant" icon={Users}>
          {userList.length === 0 ? (
            <p className="text-sm text-slate-500">Belum ada pengguna terdaftar untuk tenant ini.</p>
          ) : (
            <ul className="space-y-2">
              {userList.map((u) => (
                <li key={u.id} className="flex items-center justify-between rounded-lg border border-slate-100 bg-slate-50 px-3 py-2.5 text-sm">
                  <div>
                    <p className="font-medium text-slate-700">{u.name}</p>
                    <p className="text-xs text-slate-500">{u.email}</p>
                  </div>
                  <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">
                    {u.role}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </Section>
      </div>
    </SuperadminLayout>
  )
}
