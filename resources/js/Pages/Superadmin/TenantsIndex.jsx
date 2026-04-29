import React from 'react'
import { Head, Link } from '@inertiajs/react'
import SuperadminLayout from '../../layouts/SuperadminLayout'
import { Card } from '../../components/ui/card'
import { Button } from '../../components/ui/button'
import { Badge } from '../../components/ui/badge'

export default function TenantsIndex({ tenants }) {
  return (
    <SuperadminLayout title="Manajemen Tenant">
      <Head title="Manajemen Tenant" />

      <div className="mb-3 flex justify-end">
        <Link href="/superadmin/tenants/create"><Button>Buat Tenant</Button></Link>
      </div>

      <Card>
        <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-600">Daftar Tenant</h2>
        <div className="mt-3 overflow-x-auto">
          <table className="min-w-full text-sm">
            <thead><tr className="text-left text-slate-600"><th className="py-2">ID</th><th className="py-2">Nama</th><th className="py-2">Slug</th><th className="py-2">Status</th><th className="py-2">Aksi</th></tr></thead>
            <tbody>
              {tenants.length === 0 ? <tr><td className="py-2 text-slate-500" colSpan="5">Belum ada tenant.</td></tr> : tenants.map((tenant) => (
                <tr key={tenant.id} className="border-t border-slate-100">
                  <td className="py-2">{tenant.id}</td>
                  <td className="py-2 font-medium">{tenant.name}</td>
                  <td className="py-2 text-slate-600">{tenant.slug}</td>
                  <td className="py-2">{tenant.is_active ? <Badge tone="success">aktif</Badge> : <Badge tone="danger">nonaktif</Badge>}</td>
                  <td className="py-2"><Link className="text-blue-700 underline" href={`/superadmin/tenants/${tenant.id}`}>Detail</Link></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
    </SuperadminLayout>
  )
}
