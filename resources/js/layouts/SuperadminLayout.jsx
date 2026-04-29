import React from 'react'
import { Link, router, usePage } from '@inertiajs/react'

export default function SuperadminLayout({ title, children }) {
  const { url, props } = usePage()
  const role = props?.auth?.user?.role ?? 'superadmin'

  const menu = [
    { href: '/superadmin/dashboard', label: 'Dashboard', match: '/superadmin/dashboard' },
    { href: '/superadmin/tenants', label: 'Manajemen Tenant', match: '/superadmin/tenants' },
    { href: '/superadmin/plans', label: 'Manajemen Paket', match: '/superadmin/plans' },
    { href: '/superadmin/conversations', label: 'Pemantauan Percakapan', match: '/superadmin/conversations' },
    { href: '/superadmin/data-monitoring', label: 'Pemantauan Data', match: '/superadmin/data-monitoring' },
  ]

  const activeClass = 'bg-blue-600 text-white'
  const idleClass = 'text-blue-700 hover:bg-blue-50'

  return (
    <div className="min-h-screen bg-[radial-gradient(circle_at_top,_#dbeafe_0%,_#f8fafc_35%,_#f8fafc_100%)] text-slate-900">
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
