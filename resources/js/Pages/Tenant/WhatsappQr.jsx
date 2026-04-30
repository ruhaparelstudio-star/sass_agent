import React from 'react'
import { router } from '@inertiajs/react'
import TenantLayout from '../../layouts/TenantLayout'
import { Card } from '../../components/ui/card'
import { Button } from '../../components/ui/button'
import { Link2Off, Plus, RefreshCw, QrCode, RotateCcw, WifiOff } from 'lucide-react'

export default function WhatsappQr({ tenantId, qr, agent }) {
  const isAvailable = qr?.status === 'available'
  const qrImageUrl = isAvailable ? `/tenant/whatsapp/qr/image?t=${encodeURIComponent(qr?.generatedAt ?? Date.now())}` : null
  const canAddAgent = Boolean(agent?.canAdd)
  const hasConnectingAgent = (agent?.accounts ?? []).some((account) => account.status === 'connecting')

  React.useEffect(() => {
    if (!isAvailable) {
      return undefined
    }

    const timer = window.setInterval(() => {
      router.get('/tenant/whatsapp/qr', {}, { preserveState: true, preserveScroll: true, replace: true })
    }, 5000)

    return () => window.clearInterval(timer)
  }, [isAvailable])

  React.useEffect(() => {
    if (isAvailable || !hasConnectingAgent) {
      return undefined
    }

    const timer = window.setInterval(() => {
      router.get('/tenant/whatsapp/qr', {}, { preserveState: true, preserveScroll: true, replace: true })
    }, 3000)

    return () => window.clearInterval(timer)
  }, [isAvailable, hasConnectingAgent])

  return (
    <TenantLayout title="Pindai QR WhatsApp">
      <Card>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-600">Koneksi WhatsApp Tenant</h2>
            <p className="mt-1 text-xs text-slate-500">ID Tenant: {tenantId}</p>
          </div>
          <Button type="button" variant="outline" leftIcon={RefreshCw} onClick={() => router.get('/tenant/whatsapp/qr')}>
            Muat Ulang QR
          </Button>
        </div>
        <div className="mt-3 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
          Slot agen: {agent?.used ?? 0} / {agent?.limit ?? 0} (sisa: {agent?.remaining ?? 0})
        </div>

        <div className="mt-3 rounded-md border border-slate-200 bg-white p-3">
          <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Daftar Agen</p>
          <div className="mt-2 space-y-2">
            {(agent?.accounts ?? []).length === 0 ? (
              <p className="text-xs text-slate-500">Belum ada agen terdaftar.</p>
            ) : (
              (agent?.accounts ?? []).map((account) => (
                <div key={account.id} className="rounded-md border border-slate-200 px-3 py-2">
                  <p className="text-xs text-slate-700">Referensi: {account.providerRef}</p>
                  <p className="text-xs text-slate-700">Status: {account.status}</p>
                  <p className="text-xs text-slate-500">Pembaruan: {account.updatedAt ?? '-'}</p>
                  <div className="mt-2 flex gap-2">
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      leftIcon={Link2Off}
                      disabled={!account.canDisconnect}
                      onClick={() => router.post(`/tenant/whatsapp/agents/${account.id}/disconnect`)}
                    >
                      Putuskan Koneksi
                    </Button>
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      leftIcon={RotateCcw}
                      disabled={!account.canReconnect}
                      onClick={() => router.post(`/tenant/whatsapp/agents/${account.id}/reconnect`)}
                    >
                      Sambungkan Ulang
                    </Button>
                  </div>
                </div>
              ))
            )}
          </div>
        </div>

        {isAvailable ? (
          <div className="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-4">
            <div className="mb-2 flex items-center gap-2 text-emerald-800">
              <QrCode className="h-4 w-4" aria-hidden="true" />
              <p className="text-sm font-semibold">QR tersedia untuk dipindai</p>
            </div>
            <div className="rounded-lg border border-emerald-200 bg-white p-3">
              <p className="text-xs text-slate-500">Payload Kode QR</p>
              {qrImageUrl ? (
                <img
                  src={qrImageUrl}
                  alt="Kode QR WhatsApp"
                  className="mt-3 h-56 w-56 rounded-md border border-slate-200 bg-white object-contain"
                />
              ) : null}
              <pre className="mt-2 overflow-x-auto whitespace-pre-wrap break-all text-xs text-slate-800">{qr.code}</pre>
            </div>
            <div className="mt-3 text-xs text-slate-600">
              <p>Penyedia: {qr.provider ?? '-'}</p>
              <p>Masa berlaku: {qr.expiresInSeconds ?? '-'} detik</p>
              <p>Dibuat pada: {qr.generatedAt ?? '-'}</p>
            </div>
          </div>
        ) : (
          <div className="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4">
            <div className="mb-2 flex items-center gap-2 text-amber-800">
              <WifiOff className="h-4 w-4" aria-hidden="true" />
              <p className="text-sm font-semibold">QR belum tersedia</p>
            </div>
            <p className="text-xs text-slate-700">
              {canAddAgent
                ? 'Klik Tambah Agen untuk membuat QR baru.'
                : 'Slot agen pada langganan sudah habis. Tingkatkan paket untuk menambah agen baru.'}
            </p>
            <div className="mt-3">
              <Button
                type="button"
                leftIcon={Plus}
                onClick={() => router.post('/tenant/whatsapp/qr/connect')}
                disabled={!canAddAgent}
              >
                Tambah Agen
              </Button>
            </div>
          </div>
        )}
      </Card>
    </TenantLayout>
  )
}
