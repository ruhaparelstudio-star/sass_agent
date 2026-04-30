import React from 'react'
import { Head, Link, router, useForm, usePage } from '@inertiajs/react'
import SuperadminLayout from '../../layouts/SuperadminLayout'
import { Card } from '../../components/ui/card'
import { Button } from '../../components/ui/button'
import { Select } from '../../components/ui/select'
import { Input } from '../../components/ui/input'
import { Badge } from '../../components/ui/badge'
import { Plus, Link2, Unlink } from 'lucide-react'

export default function PlansIndex({ plans, tenants, subscriptionStatuses }) {
  const { flash, errors } = usePage().props
  const assignForm = useForm({ tenant_id: '', plan_id: '', status: 'active', starts_at: '', ends_at: '' })

  const statusTone = (value) => (value === 'active' ? 'success' : value === 'trial' ? 'neutral' : 'danger')
  const statusLabel = (value) => {
    if (value === 'active') return 'aktif'
    if (value === 'trial') return 'uji coba'
    if (value === 'cancelled') return 'dibatalkan'
    return value
  }

  return (
    <SuperadminLayout title="Manajemen Paket">
      <Head title="Manajemen Paket Super Admin" />

      {flash?.success ? <div className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{flash.success}</div> : null}
      {errors?.operation ? <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{errors.operation}</div> : null}

      <Card className="shadow-sm">
        <div className="mb-3 flex items-center justify-between">
          <div>
            <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-600">Katalog Paket</h2>
            <p className="mt-1 text-xs text-slate-500">Kelola daftar paket dan fitur sebagai data terstruktur untuk keputusan sistem yang tervalidasi.</p>
          </div>
          <Link href="/superadmin/plans/create"><Button leftIcon={Plus}>Buat Paket</Button></Link>
        </div>
        <div className="overflow-x-auto">
          <table className="min-w-full text-sm">
            <thead>
              <tr className="text-left text-slate-600"><th className="py-2">ID</th><th className="py-2">Nama</th><th className="py-2">Slug</th><th className="py-2">Status</th><th className="py-2">Fitur</th><th className="py-2">Aksi</th></tr>
            </thead>
            <tbody>
              {plans.length === 0 ? <tr><td colSpan="6" className="py-2 text-slate-500">Belum ada paket.</td></tr> : plans.map((plan) => (
                <tr key={plan.id} className="border-t border-slate-100">
                  <td className="py-2">{plan.id}</td>
                  <td className="py-2 font-medium">{plan.name}</td>
                  <td className="py-2 text-slate-600">{plan.slug}</td>
                  <td className="py-2">{plan.is_active ? <Badge tone="success">aktif</Badge> : <Badge tone="danger">nonaktif</Badge>}</td>
                  <td className="py-2">{plan.features?.length ?? 0}</td>
                  <td className="py-2"><Link className="text-blue-700 underline" href={`/superadmin/plans/${plan.id}/edit`}>Ubah</Link></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>

      <div className="mt-6 grid gap-6 xl:grid-cols-5">
        <Card className="xl:col-span-2 shadow-sm">
          <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-600">Penetapan Langganan</h2>
          <p className="mt-1 text-xs text-slate-500">Guardrail: pastikan periode valid (starts_at &lt;= ends_at) agar lolos lapisan validasi.</p>
          <form className="mt-3 grid gap-3" onSubmit={(e) => { e.preventDefault(); assignForm.post('/superadmin/subscriptions/assign') }}>
            <div>
              <label className="mb-1 block text-xs font-medium">Tenant</label>
              <Select value={assignForm.data.tenant_id} onChange={(e) => assignForm.setData('tenant_id', e.target.value)} required>
                <option value="">Pilih tenant</option>
                {tenants.map((tenant) => <option key={tenant.id} value={tenant.id}>#{tenant.id} {tenant.name}</option>)}
              </Select>
              {errors?.tenant_id ? <p className="mt-1 text-xs text-red-600">{errors.tenant_id}</p> : null}
            </div>
            <div>
              <label className="mb-1 block text-xs font-medium">Paket</label>
              <Select value={assignForm.data.plan_id} onChange={(e) => assignForm.setData('plan_id', e.target.value)} required>
                <option value="">Pilih paket</option>
                {plans.map((plan) => <option key={plan.id} value={plan.id}>#{plan.id} {plan.name}</option>)}
              </Select>
              {errors?.plan_id ? <p className="mt-1 text-xs text-red-600">{errors.plan_id}</p> : null}
            </div>
            <div>
              <label className="mb-1 block text-xs font-medium">Status</label>
              <Select value={assignForm.data.status} onChange={(e) => assignForm.setData('status', e.target.value)} required>
                {subscriptionStatuses.map((status) => <option key={status} value={status}>{statusLabel(status)}</option>)}
              </Select>
              {errors?.status ? <p className="mt-1 text-xs text-red-600">{errors.status}</p> : null}
            </div>
            <div><label className="mb-1 block text-xs font-medium">Mulai Pada</label><Input type="datetime-local" value={assignForm.data.starts_at} onChange={(e) => assignForm.setData('starts_at', e.target.value)} />{errors?.starts_at ? <p className="mt-1 text-xs text-red-600">{errors.starts_at}</p> : null}</div>
            <div><label className="mb-1 block text-xs font-medium">Berakhir Pada</label><Input type="datetime-local" value={assignForm.data.ends_at} onChange={(e) => assignForm.setData('ends_at', e.target.value)} />{errors?.ends_at ? <p className="mt-1 text-xs text-red-600">{errors.ends_at}</p> : null}</div>
            <Button type="submit" disabled={assignForm.processing} leftIcon={Link2}>Tetapkan Langganan</Button>
          </form>
        </Card>

        <Card className="xl:col-span-3 shadow-sm">
          <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-600">Matriks Langganan Saat Ini</h2>
          <p className="mt-1 text-xs text-slate-500">Satu tenant hanya boleh memiliki satu current_marker aktif pada satu waktu untuk menjaga determinisme.</p>
          <div className="mt-3 overflow-x-auto">
            <table className="min-w-full text-sm">
              <thead><tr className="text-left text-slate-600"><th className="py-2">Tenant</th><th className="py-2">Paket</th><th className="py-2">Status</th><th className="py-2">Periode</th><th className="py-2">Aksi</th></tr></thead>
              <tbody>
                {tenants.length === 0 ? <tr><td className="py-2 text-slate-500" colSpan="5">Tenant tidak ditemukan.</td></tr> : tenants.map((tenant) => (
                  <tr key={tenant.id} className="border-t border-slate-100">
                    <td className="py-2">#{tenant.id} {tenant.name}</td>
                    <td className="py-2">{tenant.current_subscription?.plan?.name ?? '-'}</td>
                    <td className="py-2">{tenant.current_subscription?.status ? <Badge tone={statusTone(tenant.current_subscription.status)}>{statusLabel(tenant.current_subscription.status)}</Badge> : '-'}</td>
                    <td className="py-2 text-slate-600 text-xs">{tenant.current_subscription ? `${tenant.current_subscription.starts_at ?? '-'} -> ${tenant.current_subscription.ends_at ?? '-'}` : '-'}</td>
                    <td className="py-2">
                      {tenant.current_subscription ? (
                        <Button variant="destructive" className="px-3 py-1 text-xs" type="button" leftIcon={Unlink} onClick={() => router.post('/superadmin/subscriptions/unassign', { tenant_id: tenant.id })}>
                          Lepas
                        </Button>
                      ) : <span className="text-slate-500">Belum berlangganan</span>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      </div>
    </SuperadminLayout>
  )
}
