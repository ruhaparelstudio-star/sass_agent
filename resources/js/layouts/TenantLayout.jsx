import React from 'react'
import { Link, router, usePage } from '@inertiajs/react'
import { Button } from '../components/ui/button'
import { LayoutDashboard, MessagesSquare, Database, QrCode, LogOut } from 'lucide-react'

export default function TenantLayout({ title, children }) {
  const { url, props } = usePage()
  const role = props?.auth?.user?.role ?? 'tenant_admin'

  const menu = [
    { href: '/tenant/dashboard', label: 'Dashboard', match: '/tenant/dashboard', icon: LayoutDashboard },
    { href: '/tenant/inbox', label: 'Conversation Inbox', match: '/tenant/inbox', icon: MessagesSquare },
    { href: '/tenant/business-data', label: 'Business Data', match: '/tenant/business-data', icon: Database },
    { href: '/tenant/whatsapp/qr', label: 'WhatsApp QR Scan', match: '/tenant/whatsapp/qr', icon: QrCode },
  ]

  const activeClass = 'bg-emerald-600 text-white shadow-sm'
  const idleClass = 'text-emerald-800 hover:bg-emerald-50'

  return (
    <div className="min-h-screen bg-[radial-gradient(circle_at_top,_#d1fae5_0%,_#f8fafc_40%,_#f8fafc_100%)] text-slate-900">
      <div className="mx-auto w-full max-w-7xl px-4 py-6 lg:grid lg:grid-cols-[240px_minmax(0,1fr)] lg:gap-6">
        <aside className="rounded-2xl border border-slate-200/80 bg-white/90 p-4 backdrop-blur">
          <p className="text-xs font-semibold uppercase tracking-wider text-emerald-700">Tenant Admin</p>
          <p className="mt-1 text-xs uppercase tracking-wide text-slate-500">Peran {role}</p>
          <nav className="mt-4 space-y-1 text-sm">
            {menu.map((item) => {
              const isActive = url.startsWith(item.match)
              const Icon = item.icon
              return (
                <Link
                  key={item.href}
                  className={`flex items-center gap-2 rounded-md px-3 py-2 transition ${isActive ? activeClass : idleClass}`}
                  href={item.href}
                >
                  <Icon className="h-4 w-4 shrink-0" aria-hidden="true" />
                  {item.label}
                </Link>
              )
            })}
          </nav>
          <div className="mt-5 border-t border-slate-200 pt-4">
            <Button type="button" variant="outline" className="w-full justify-start" leftIcon={LogOut} onClick={() => router.post('/logout')}>
              Keluar
            </Button>
          </div>
        </aside>
        <main className="mt-4 min-w-0 lg:mt-0">
          <header className="mb-4 rounded-2xl border border-slate-200/80 bg-white/90 px-4 py-4 backdrop-blur">
            <h1 className="text-xl font-semibold tracking-tight">{title}</h1>
          </header>
          {children}
        </main>
      </div>
    </div>
  )
}
