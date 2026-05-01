import React, { useState } from 'react'
import { Link, router, usePage } from '@inertiajs/react'
import {
  LayoutDashboard,
  MessagesSquare,
  Database,
  QrCode,
  LogOut,
  BarChart3,
  Bot,
  Bell,
  Menu,
  X,
  Sparkles,
  ChevronRight,
} from 'lucide-react'

export default function TenantLayout({ title, children }) {
  const { url, props } = usePage()
  const [mobileOpen, setMobileOpen] = useState(false)
  const user = props?.auth?.user ?? {}

  const [pathname, search = ''] = String(url ?? '').split('?')
  const query = new URLSearchParams(search)
  const step = query.get('step')
  const section = query.get('section')

  const notifCount = props?.unread_notifications ?? 0

  const menu = [
    {
      href: '/tenant/dashboard',
      label: 'Beranda',
      icon: LayoutDashboard,
      description: 'Ringkasan aktivitas',
      isActive: () => pathname === '/tenant/dashboard',
    },
    {
      href: '/tenant/inbox',
      label: 'Kotak Masuk',
      icon: MessagesSquare,
      description: 'Percakapan & handoff',
      isActive: () => pathname.startsWith('/tenant/inbox'),
    },
    {
      href: '/tenant/analytics',
      label: 'Analitik Lead',
      icon: BarChart3,
      description: 'Pipeline & konversi',
      isActive: () => pathname === '/tenant/analytics',
    },
    {
      href: '/tenant/business-data',
      label: 'Data Bisnis',
      icon: Database,
      description: 'Paket, harga & FAQ',
      isActive: () => pathname === '/tenant/business-data' && step !== 'settings',
    },
    {
      href: '/tenant/ai-config',
      label: 'Konfigurasi AI',
      icon: Bot,
      description: 'Tone & kebijakan AI',
      isActive: () => pathname === '/tenant/ai-config',
    },
    {
      href: '/tenant/notifications',
      label: 'Notifikasi',
      icon: Bell,
      description: 'Handoff & sistem',
      badge: notifCount > 0 ? notifCount : null,
      isActive: () => pathname === '/tenant/notifications',
    },
    {
      href: '/tenant/whatsapp/qr',
      label: 'WhatsApp Agent',
      icon: QrCode,
      description: 'Koneksi & status WA',
      isActive: () => pathname.startsWith('/tenant/whatsapp/qr'),
    },
  ]

  const SidebarContent = () => (
    <div className="flex h-full flex-col">
      <div className="flex items-center gap-3 border-b border-emerald-800/60 px-4 py-4">
        <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-500/20">
          <Sparkles className="h-5 w-5 text-emerald-300" />
        </div>
        <div>
          <p className="text-sm font-semibold text-white">AI Sales Agent</p>
          <p className="text-xs text-emerald-300">Admin Tenant</p>
        </div>
      </div>

      {user.name && (
        <div className="border-b border-emerald-800/40 px-4 py-3">
          <p className="text-xs text-emerald-400">Login sebagai</p>
          <p className="mt-0.5 truncate text-sm font-medium text-emerald-100">{user.name}</p>
          {user.email && <p className="truncate text-xs text-emerald-400">{user.email}</p>}
        </div>
      )}

      <nav className="flex-1 overflow-y-auto px-3 py-3">
        <p className="mb-2 px-2 text-xs font-semibold uppercase tracking-widest text-emerald-500">Menu Utama</p>
        <ul className="space-y-0.5">
          {menu.map((item) => {
            const isActive = item.isActive()
            const Icon = item.icon
            return (
              <li key={item.href}>
                <Link
                  href={item.href}
                  onClick={() => setMobileOpen(false)}
                  className={`group flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm transition-all ${
                    isActive
                      ? 'bg-emerald-500 text-white shadow-md'
                      : 'text-emerald-200 hover:bg-emerald-800/50 hover:text-white'
                  }`}
                >
                  <Icon className="h-4 w-4 shrink-0" />
                  <span className="flex-1 font-medium">{item.label}</span>
                  {item.badge != null && (
                    <span className="flex h-5 min-w-5 items-center justify-center rounded-full bg-red-500 px-1.5 text-xs font-bold text-white">
                      {item.badge > 99 ? '99+' : item.badge}
                    </span>
                  )}
                  {isActive && !item.badge && <ChevronRight className="h-3.5 w-3.5 opacity-70" />}
                </Link>
              </li>
            )
          })}
        </ul>
      </nav>

      <div className="border-t border-emerald-800/60 px-3 py-3">
        <button
          type="button"
          onClick={() => router.post('/logout')}
          className="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-emerald-300 transition hover:bg-red-500/20 hover:text-red-300"
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
        className={`fixed inset-y-0 left-0 z-50 w-64 bg-gradient-to-b from-emerald-950 to-emerald-900 transition-transform lg:translate-x-0 ${
          mobileOpen ? 'translate-x-0' : '-translate-x-full'
        }`}
      >
        {mobileOpen && (
          <button
            type="button"
            className="absolute right-3 top-3 text-emerald-400 lg:hidden"
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
            <Sparkles className="h-4 w-4 text-emerald-600" />
            <h1 className="text-sm font-semibold text-slate-700 md:text-base">{title}</h1>
          </div>
          <div className="flex items-center gap-2">
            {notifCount > 0 && (
              <Link
                href="/tenant/notifications"
                className="relative rounded-md p-1.5 text-slate-500 hover:bg-slate-100"
              >
                <Bell className="h-5 w-5" />
                <span className="absolute right-0.5 top-0.5 flex h-4 w-4 items-center justify-center rounded-full bg-red-500 text-xs font-bold text-white">
                  {notifCount > 9 ? '9+' : notifCount}
                </span>
              </Link>
            )}
            <div className="hidden items-center gap-2 rounded-full bg-emerald-50 px-3 py-1 sm:flex">
              <span className="h-2 w-2 animate-pulse rounded-full bg-emerald-500" />
              <span className="text-xs font-medium text-emerald-700">Tenant Admin</span>
            </div>
          </div>
        </header>

        <main className="p-4 md:p-6">
          {children}
        </main>
      </div>
    </div>
  )
}
