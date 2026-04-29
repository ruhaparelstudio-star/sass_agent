import React from 'react'
import { router } from '@inertiajs/react'
import TenantLayout from '../../layouts/TenantLayout'
import { Card } from '../../components/ui/card'
import { Button } from '../../components/ui/button'
import { RefreshCw, QrCode, WifiOff } from 'lucide-react'

export default function WhatsappQr({ tenantId, qr }) {
  const isAvailable = qr?.status === 'available'

  return (
    <TenantLayout title="WhatsApp QR Scan">
      <Card>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-600">Koneksi WhatsApp Tenant</h2>
            <p className="mt-1 text-xs text-slate-500">Tenant ID: {tenantId}</p>
          </div>
          <Button type="button" variant="outline" leftIcon={RefreshCw} onClick={() => router.get('/tenant/whatsapp/qr')}>
            Refresh QR
          </Button>
        </div>

        {isAvailable ? (
          <div className="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-4">
            <div className="mb-2 flex items-center gap-2 text-emerald-800">
              <QrCode className="h-4 w-4" aria-hidden="true" />
              <p className="text-sm font-semibold">QR tersedia untuk dipindai</p>
            </div>
            <div className="rounded-lg border border-emerald-200 bg-white p-3">
              <p className="text-xs text-slate-500">Kode QR payload</p>
              <pre className="mt-2 overflow-x-auto whitespace-pre-wrap break-all text-xs text-slate-800">{qr.code}</pre>
            </div>
            <div className="mt-3 text-xs text-slate-600">
              <p>Provider: {qr.provider ?? '-'}</p>
              <p>Masa berlaku: {qr.expiresInSeconds ?? '-'} detik</p>
              <p>Generated at: {qr.generatedAt ?? '-'}</p>
            </div>
          </div>
        ) : (
          <div className="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4">
            <div className="mb-2 flex items-center gap-2 text-amber-800">
              <WifiOff className="h-4 w-4" aria-hidden="true" />
              <p className="text-sm font-semibold">QR belum tersedia</p>
            </div>
            <p className="text-xs text-slate-700">Pastikan WA Gateway aktif dan `WA_GATEWAY_QR_URL` sudah dikonfigurasi.</p>
          </div>
        )}
      </Card>
    </TenantLayout>
  )
}

