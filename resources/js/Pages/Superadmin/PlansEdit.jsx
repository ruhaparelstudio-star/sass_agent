import React from 'react'
import { Head, router, useForm, usePage } from '@inertiajs/react'
import SuperadminLayout from '../../layouts/SuperadminLayout'
import { Card } from '../../components/ui/card'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Select } from '../../components/ui/select'
import { Plus, Save, Pencil, Trash2 } from 'lucide-react'

export default function PlansEdit({ plan }) {
  const { errors } = usePage().props
  const form = useForm({ name: plan.name, slug: plan.slug, is_active: Boolean(plan.is_active) })
  const createFeature = useForm({ code: '', name: '', value_string: '', value_int: '', value_bool: '' })

  const submitFeatureUpdate = (feature, e) => {
    e.preventDefault()
    const data = new FormData(e.currentTarget)
    router.put(`/superadmin/plans/${plan.id}/features/${feature.id}`, {
      code: String(data.get('code') ?? ''),
      name: String(data.get('name') ?? ''),
      value_string: String(data.get('value_string') ?? ''),
      value_int: String(data.get('value_int') ?? ''),
      value_bool: String(data.get('value_bool') ?? ''),
    })
  }

  return (
    <SuperadminLayout title={`Ubah Paket #${plan.id}`}>
      <Head title={`Ubah Paket #${plan.id}`} />
      {errors?.operation ? <div className="mb-4 rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{errors.operation}</div> : null}

      <Card>
        <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); form.put(`/superadmin/plans/${plan.id}`) }}>
          <div><label className="mb-1 block text-sm font-medium">Nama</label><Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />{errors.name ? <p className="mt-1 text-xs text-red-600">{errors.name}</p> : null}</div>
          <div><label className="mb-1 block text-sm font-medium">Slug</label><Input value={form.data.slug} onChange={(e) => form.setData('slug', e.target.value)} required />{errors.slug ? <p className="mt-1 text-xs text-red-600">{errors.slug}</p> : null}</div>
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} /> Aktif</label>
          <Button type="submit" leftIcon={Save}>Simpan</Button>
        </form>
      </Card>

      <Card className="mt-6">
        <h2 className="text-sm font-semibold uppercase text-slate-600">Tambah Fitur</h2>
        <form className="mt-3 grid gap-3 md:grid-cols-5" onSubmit={(e) => { e.preventDefault(); createFeature.post(`/superadmin/plans/${plan.id}/features`) }}>
          <div><label className="mb-1 block text-xs font-medium">Kode</label><Input value={createFeature.data.code} onChange={(e) => createFeature.setData('code', e.target.value)} required /></div>
          <div><label className="mb-1 block text-xs font-medium">Nama</label><Input value={createFeature.data.name} onChange={(e) => createFeature.setData('name', e.target.value)} required /></div>
          <div><label className="mb-1 block text-xs font-medium">Nilai String</label><Input value={createFeature.data.value_string} onChange={(e) => createFeature.setData('value_string', e.target.value)} /></div>
          <div><label className="mb-1 block text-xs font-medium">Nilai Int</label><Input type="number" value={createFeature.data.value_int} onChange={(e) => createFeature.setData('value_int', e.target.value)} /></div>
          <div><label className="mb-1 block text-xs font-medium">Nilai Bool</label><Select value={createFeature.data.value_bool} onChange={(e) => createFeature.setData('value_bool', e.target.value)}><option value="">-</option><option value="1">true</option><option value="0">false</option></Select></div>
          <div className="md:col-span-5"><Button type="submit" leftIcon={Plus}>Tambah Fitur</Button></div>
        </form>
      </Card>

      <Card className="mt-6">
        <h2 className="text-sm font-semibold uppercase text-slate-600">Daftar Fitur</h2>
        <div className="mt-3 space-y-3">
          {plan.features.length === 0 ? <p className="text-sm text-slate-500">Belum ada fitur.</p> : plan.features.map((feature) => (
            <div key={feature.id} className="rounded border border-slate-200 p-3">
              <form className="grid gap-3 md:grid-cols-6" onSubmit={(e) => submitFeatureUpdate(feature, e)}>
                <div><label className="mb-1 block text-xs font-medium">Kode</label><Input name="code" defaultValue={feature.code} required /></div>
                <div><label className="mb-1 block text-xs font-medium">Nama</label><Input name="name" defaultValue={feature.name} required /></div>
                <div><label className="mb-1 block text-xs font-medium">Nilai String</label><Input name="value_string" defaultValue={feature.value_string ?? ''} /></div>
                <div><label className="mb-1 block text-xs font-medium">Nilai Int</label><Input type="number" name="value_int" defaultValue={feature.value_int ?? ''} /></div>
                <div><label className="mb-1 block text-xs font-medium">Nilai Bool</label><Select name="value_bool" defaultValue={feature.value_bool === true ? '1' : feature.value_bool === false ? '0' : ''}><option value="">-</option><option value="1">true</option><option value="0">false</option></Select></div>
                <div className="flex items-end"><Button type="submit" className="px-3 py-2 text-xs" leftIcon={Pencil}>Ubah</Button></div>
              </form>
              <form className="mt-2" onSubmit={(e) => { e.preventDefault(); router.delete(`/superadmin/plans/${plan.id}/features/${feature.id}`) }}>
                <Button variant="destructive" className="px-3 py-2 text-xs" type="submit" leftIcon={Trash2}>Hapus</Button>
              </form>
            </div>
          ))}
        </div>
      </Card>
    </SuperadminLayout>
  )
}
