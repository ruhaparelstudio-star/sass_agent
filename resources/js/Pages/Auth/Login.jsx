import React from 'react'
import { Head, useForm, usePage } from '@inertiajs/react'

export default function Login() {
  const { flash = {} } = usePage().props
  const form = useForm({
    email: '',
    password: '',
    remember: false,
  })

  const submit = (event) => {
    event.preventDefault()
    form.post('/login', {
      preserveScroll: true,
    })
  }

  return (
    <>
      <Head title="Masuk Admin" />
      <main className="grid min-h-screen place-items-center bg-[radial-gradient(circle_at_20%_10%,_#f9fbff_0%,_#f3f6fb_45%,_#ecf2fa_100%)] px-4 py-8">
        <section className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-xl shadow-slate-200/60">
          <p className="text-xs font-bold uppercase tracking-[0.12em] text-blue-700">SaaS Agent Console</p>
          <h1 className="mt-2 text-2xl font-semibold tracking-tight text-slate-900">Masuk Admin</h1>
          <p className="mt-1 text-sm text-slate-600">Masuk sebagai tenant admin atau superadmin untuk mengelola workflow terkontrol.</p>

          {flash.login_error && (
            <div className="mt-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{flash.login_error}</div>
          )}
          {flash.success && (
            <div className="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{flash.success}</div>
          )}

          <form className="mt-4 space-y-3" onSubmit={submit}>
            <div>
              <label className="mb-1 block text-sm font-medium text-slate-700" htmlFor="email">Email</label>
              <input
                id="email"
                name="email"
                type="email"
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                value={form.data.email}
                onChange={(event) => form.setData('email', event.target.value)}
                required
              />
            </div>

            <div>
              <label className="mb-1 block text-sm font-medium text-slate-700" htmlFor="password">Kata Sandi</label>
              <input
                id="password"
                name="password"
                type="password"
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                value={form.data.password}
                onChange={(event) => form.setData('password', event.target.value)}
                required
              />
            </div>

            <label className="flex items-center gap-2 text-sm text-slate-600" htmlFor="remember">
              <input
                id="remember"
                name="remember"
                type="checkbox"
                checked={form.data.remember}
                onChange={(event) => form.setData('remember', event.target.checked)}
              />
              <span>Ingat perangkat ini</span>
            </label>

            <button
              type="submit"
              className="w-full rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800"
              disabled={form.processing}
            >
              {form.processing ? 'Sedang masuk...' : 'Masuk'}
            </button>
          </form>
        </section>
      </main>
    </>
  )
}
