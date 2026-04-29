import React from 'react'
import { Head, useForm } from '@inertiajs/react'
import SuperadminLayout from '../../layouts/SuperadminLayout'
import { Card } from '../../components/ui/card'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'

export default function PlansCreate({ errors = {} }) {
  const form = useForm({ name: '', slug: '', is_active: true })

  return (
    <SuperadminLayout title="Buat Paket">
      <Head title="Buat Paket" />
      <Card className="max-w-2xl">
        <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); form.post('/superadmin/plans') }}>
          <div>
            <label className="mb-1 block text-sm font-medium">Nama</label>
            <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
            {errors.name ? <p className="mt-1 text-xs text-red-600">{errors.name}</p> : null}
          </div>
          <div>
            <label className="mb-1 block text-sm font-medium">Slug</label>
            <Input value={form.data.slug} onChange={(e) => form.setData('slug', e.target.value)} required />
            {errors.slug ? <p className="mt-1 text-xs text-red-600">{errors.slug}</p> : null}
          </div>
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} /> Aktif
          </label>
          <Button type="submit" disabled={form.processing}>Buat</Button>
        </form>
      </Card>
    </SuperadminLayout>
  )
}
