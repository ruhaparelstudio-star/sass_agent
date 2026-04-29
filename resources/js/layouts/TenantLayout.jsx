import React from 'react'
import { Link, router, usePage } from '@inertiajs/react'

export default function TenantLayout({ title, children }) {
  const { url, props } = usePage()
  const role = props?.auth?.user?.role ?? 'tenant_admin'

  const menu = [
    { href: '/tenant/dashboard', label: 'Dashboard', match: '/tenant/dashboard' },
    { href: '/tenant/inbox', label: 'Conversation Inbox', match: '/tenant/inbox' },
    { href: '/tenant/business-data', label: 'Business Data', match: '/tenant/business-data' },
  ]

  const activeClass = 'bg-emerald-600 text-white'
  const idleClass = 'text-emerald-700 hover:bg-emerald-50'

  return (
    <div className="min-h-screen bg-[radial-gradient(circle_at_top,_#d1fae5_0%,_#f8fafc_40%,_#f8fafc_100%)] text-slate-900">
      <header className="border-b border-slate-200/80 bg-white/90 backdrop-blur">
        <div className="mx-auto flex w-full max-w-7xl flex-col gap-3 px-4 py-4 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <h1 className="text-xl font-semibold tracking-tight">{title}</h1>
            <p className="mt-1 text-xs uppercase tracking-wide text-slate-500">Peran {role}</p>
          </div>

          <nav className="flex flex-wrap gap-2 text-sm">
            {menu.map((item) => {
              const isActive = url.startsWith(item.match)
              return (
                <Link
                  key={item.href}
                  className={`rounded-md px-2 py-1 transition ${isActive ? activeClass : idleClass}`}
                  href={item.href}
                >
                  {item.label}
                </Link>
              )
            })}
          </nav>

          <button
            type="button"
            className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
            onClick={() => router.post('/logout')}
          >
            Keluar
          </button>
        </div>
      </header>

      <main className="mx-auto w-full max-w-7xl px-4 py-6">{children}</main>
    </div>
  )
}
