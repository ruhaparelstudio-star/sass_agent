import React from 'react'
import { Head, Link, useForm } from '@inertiajs/react'
import SuperadminLayout from '../../layouts/SuperadminLayout'
import { Card } from '../../components/ui/card'
import { Input } from '../../components/ui/input'
import { Button } from '../../components/ui/button'

export default function TenantsCreate({ errors = {} }) {
  const form = useForm({ name: '', slug: '', is_active: true })

  return (
    <SuperadminLayout title="Buat Tenant">
      <Head title="Buat Tenant" />
      <Card className="max-w-2xl">
        <div className="mb-3"><Link href="/superadmin/tenants" className="text-sm text-blue-700 underline">Kembali ke daftar</Link></div>
        <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); form.post('/superadmin/tenants') }}>
          <div><label className="mb-1 block text-sm font-medium">Nama</label><Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />{errors.name ? <p className="mt-1 text-xs text-red-600">{errors.name}</p> : null}</div>
          <div><label className="mb-1 block text-sm font-medium">Slug</label><Input value={form.data.slug} onChange={(e) => form.setData('slug', e.target.value)} required />{errors.slug ? <p className="mt-1 text-xs text-red-600">{errors.slug}</p> : null}</div>
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} /> Aktif</label>
          <Button type="submit" disabled={form.processing}>Buat Tenant</Button>
        </form>
      </Card>
    </SuperadminLayout>
  )
}
