import React, { useState } from 'react'
import { Link, router, usePage } from '@inertiajs/react'
import {
  LayoutDashboard,
  Building2,
  CreditCard,
  MessageSquareMore,
  Database,
  LogOut,
  ChevronRight,
  Settings,
  Menu,
  X,
  Shield,
} from 'lucide-react'

export default function SuperadminLayout({ title, children }) {
  const { url, props } = usePage()
  const [mobileOpen, setMobileOpen] = useState(false)
  const user = props?.auth?.user ?? {}

  const menu = [
    {
      href: '/superadmin/dashboard',
      label: 'Beranda',
      icon: LayoutDashboard,
      match: '/superadmin/dashboard',
      description: 'Ringkasan platform',
    },
    {
      href: '/superadmin/tenants',
      label: 'Manajemen Tenant',
      icon: Building2,
      match: '/superadmin/tenants',
      description: 'Kelola semua tenant',
    },
    {
      href: '/superadmin/plans',
      label: 'Manajemen Paket',
      icon: CreditCard,
      match: '/superadmin/plans',
      description: 'Paket & fitur langganan',
    },
    {
      href: '/superadmin/conversations',
      label: 'Pemantauan Percakapan',
      icon: MessageSquareMore,
      match: '/superadmin/conversations',
      description: 'Monitor lintas tenant',
    },
    {
      href: '/superadmin/data-monitoring',
      label: 'Pemantauan Data',
      icon: Database,
      match: '/superadmin/data-monitoring',
      description: 'Kesiapan data AI tenant',
    },
  ]

  const SidebarContent = () => (
    <div className="flex h-full flex-col">
      <div className="flex items-center gap-3 border-b border-violet-800/60 px-4 py-4">
        <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-violet-500/20">
          <Shield className="h-5 w-5 text-violet-300" />
        </div>
        <div>
          <p className="text-sm font-semibold text-white">Platform Admin</p>
          <p className="text-xs text-violet-300">Superadmin</p>
        </div>
      </div>

      {user.name && (
        <div className="border-b border-violet-800/40 px-4 py-3">
          <p className="text-xs text-violet-400">Login sebagai</p>
          <p className="mt-0.5 truncate text-sm font-medium text-violet-100">{user.name}</p>
          {user.email && <p className="truncate text-xs text-violet-400">{user.email}</p>}
        </div>
      )}

      <nav className="flex-1 overflow-y-auto px-3 py-3">
        <p className="mb-2 px-2 text-xs font-semibold uppercase tracking-widest text-violet-500">Menu Utama</p>
        <ul className="space-y-0.5">
          {menu.map((item) => {
            const isActive = url.startsWith(item.match)
            const Icon = item.icon
            return (
              <li key={item.href}>
                <Link
                  href={item.href}
                  onClick={() => setMobileOpen(false)}
                  className={`group flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm transition-all ${
                    isActive
                      ? 'bg-violet-500 text-white shadow-md'
                      : 'text-violet-200 hover:bg-violet-800/50 hover:text-white'
                  }`}
                >
                  <Icon className="h-4 w-4 shrink-0" />
                  <span className="flex-1 font-medium">{item.label}</span>
                  {isActive && <ChevronRight className="h-3.5 w-3.5 opacity-70" />}
                </Link>
              </li>
            )
          })}
        </ul>
      </nav>

      <div className="border-t border-violet-800/60 px-3 py-3">
        <button
          type="button"
          onClick={() => router.post('/logout')}
          className="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-violet-300 transition hover:bg-red-500/20 hover:text-red-300"
        >
          <LogOut className="h-4 w-4 shrink-0" />
          <span className="font-medium">Keluar</span>
        </button>
      </div>
    </div>
  )

  return (
    <div className="min-h-screen bg-slate-100 text-slate-900">
      {mobileOpen && (
        <div className="fixed inset-0 z-40 lg:hidden" onClick={() => setMobileOpen(false)}>
          <div className="absolute inset-0 bg-black/50" />
        </div>
      )}

      <aside
        className={`fixed inset-y-0 left-0 z-50 w-64 bg-gradient-to-b from-violet-950 to-violet-900 transition-transform lg:translate-x-0 ${
          mobileOpen ? 'translate-x-0' : '-translate-x-full'
        }`}
      >
        {mobileOpen && (
          <button
            type="button"
            className="absolute right-3 top-3 text-violet-400 lg:hidden"
            onClick={() => setMobileOpen(false)}
          >
            <X className="h-5 w-5" />
          </button>
        )}
        <SidebarContent />
      </aside>

      <div className="lg:pl-64">
        <header className="sticky top-0 z-30 flex h-14 items-center gap-3 border-b border-slate-200 bg-white px-4 shadow-sm">
          <button
            type="button"
            className="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 lg:hidden"
            onClick={() => setMobileOpen(true)}
          >
            <Menu className="h-5 w-5" />
          </button>
          <div className="flex flex-1 items-center gap-2">
            <Shield className="h-4 w-4 text-violet-600" />
            <h1 className="text-sm font-semibold text-slate-700 md:text-base">{title}</h1>
          </div>
          <div className="hidden items-center gap-2 rounded-full bg-violet-50 px-3 py-1 sm:flex">
            <span className="h-2 w-2 animate-pulse rounded-full bg-violet-500" />
            <span className="text-xs font-medium text-violet-700">Superadmin</span>
          </div>
        </header>

        <main className="p-4 md:p-6">
          {children}
        </main>
      </div>
    </div>
  )
}
