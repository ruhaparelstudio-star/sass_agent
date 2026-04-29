import React from 'react'
import { Head, useForm } from '@inertiajs/react'

export default function Activation({ token, email, status }) {
  const form = useForm({
    token: token ?? '',
    email: email ?? '',
    password: '',
    password_confirmation: '',
  })

  const submit = (event) => {
    event.preventDefault()
    form.post('/activation/set-password')
  }

  const statusText = {
    valid: 'Token valid. Silakan set password.',
    used: 'Token sudah digunakan.',
    expired: 'Token sudah kedaluwarsa.',
    invalid: 'Token tidak valid.',
  }[status] ?? 'Token tidak valid.'

  const canSubmit = status === 'valid'

  return (
    <>
      <Head title="Aktivasi Akun" />
      <main className="grid min-h-screen place-items-center bg-[radial-gradient(circle_at_top,_#ecfeff_0%,_#f8fafc_40%,_#f8fafc_100%)] px-4 py-8">
        <section className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-lg">
          <h1 className="text-2xl font-semibold text-slate-900">Aktivasi Akun Tenant Admin</h1>
          <p className="mt-2 text-sm text-slate-600">{statusText}</p>

          {form.errors.activation && (
            <div className="mt-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{form.errors.activation}</div>
          )}

          <form className="mt-4 space-y-3" onSubmit={submit}>
            <div>
              <label className="mb-1 block text-sm font-medium text-slate-700" htmlFor="email">Email</label>
              <input id="email" type="email" name="email" className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} required />
            </div>

            <input type="hidden" name="token" value={form.data.token} />

            <div>
              <label className="mb-1 block text-sm font-medium text-slate-700" htmlFor="password">Password Baru</label>
              <input id="password" type="password" name="password" className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} required minLength={8} disabled={!canSubmit} />
            </div>

            <div>
              <label className="mb-1 block text-sm font-medium text-slate-700" htmlFor="password_confirmation">Konfirmasi Password</label>
              <input id="password_confirmation" type="password" name="password_confirmation" className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)} required minLength={8} disabled={!canSubmit} />
            </div>

            <button type="submit" className="w-full rounded-lg bg-cyan-700 px-3 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-slate-400" disabled={!canSubmit || form.processing}>
              {form.processing ? 'Memproses...' : 'Aktifkan Akun'}
            </button>
          </form>
        </section>
      </main>
    </>
  )
}
