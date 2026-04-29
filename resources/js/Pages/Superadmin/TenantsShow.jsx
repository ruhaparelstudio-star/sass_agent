import React from 'react'
import { Head, Link, router } from '@inertiajs/react'
import SuperadminLayout from '../../layouts/SuperadminLayout'
import { Card } from '../../components/ui/card'
import { Badge } from '../../components/ui/badge'
import { Button } from '../../components/ui/button'

export default function TenantsShow({ tenant }) {
  return (
    <SuperadminLayout title={`Detail Tenant #${tenant.id}`}>
      <Head title={`Detail Tenant #${tenant.id}`} />

      <Card>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Detail Tenant</p>
            <h2 className="mt-1 text-lg font-semibold">{tenant.name}</h2>
            <p className="text-sm text-slate-600">{tenant.slug}</p>
          </div>
          <div>{tenant.is_active ? <Badge tone="success">aktif</Badge> : <Badge tone="danger">nonaktif</Badge>}</div>
        </div>

        <div className="mt-4 flex flex-wrap gap-2">
          <Link href="/superadmin/tenants" className="text-sm text-blue-700 underline">Kembali ke daftar</Link>
          <Link href={`/superadmin/tenants/${tenant.id}/edit`} className="text-sm text-blue-700 underline">Ubah tenant</Link>
        </div>

        {tenant.is_active ? (
          <div className="mt-4">
            <Button variant="destructive" type="button" onClick={() => router.post(`/superadmin/tenants/${tenant.id}/deactivate`)}>Nonaktifkan Tenant</Button>
          </div>
        ) : null}
      </Card>
    </SuperadminLayout>
  )
}
