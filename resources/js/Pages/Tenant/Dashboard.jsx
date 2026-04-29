import React from 'react'
import { Link } from '@inertiajs/react'
import TenantLayout from '../../layouts/TenantLayout'
import { Button } from '../../components/ui/button'
import { MessagesSquare } from 'lucide-react'

export default function Dashboard({ summary, recentConversations, recentHandoffs, recentNotifications }) {
  const cards = [
    { label: 'Total Conversations', value: summary?.conversations_total ?? 0 },
    { label: 'Open Conversations', value: summary?.conversations_open ?? 0 },
    { label: 'Pending Handoffs', value: summary?.handoffs_pending ?? 0 },
    { label: 'Failed Notifications', value: summary?.notifications_failed ?? 0 },
    { label: 'Leads', value: summary?.lead_count ?? 0 },
    { label: 'Handoffs (All)', value: summary?.handoff_count ?? 0 },
    { label: 'Booking Executed', value: summary?.booking_action_count ?? 0 },
    { label: 'Token Usage', value: summary?.token_usage_total ?? 0 },
  ]

  return (
    <TenantLayout title="Tenant Dashboard">
      <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {cards.map((card) => (
          <div key={card.label} className="rounded-xl border border-slate-200 bg-white p-4">
            <p className="text-xs uppercase tracking-wide text-slate-500">{card.label}</p>
            <p className="mt-2 text-2xl font-semibold">{card.value}</p>
          </div>
        ))}
      </section>

      <section className="mt-6 grid gap-4 lg:grid-cols-3">
        <div className="rounded-xl border border-slate-200 bg-white p-4">
          <h2 className="font-semibold">Recent Conversations</h2>
          <ul className="mt-3 space-y-2 text-sm">
            {(recentConversations ?? []).map((row) => (
              <li key={row.id}>{row.customer_phone} ({row.status})</li>
            ))}
            {(recentConversations ?? []).length === 0 && <li className="text-slate-500">No data</li>}
          </ul>
        </div>
        <div className="rounded-xl border border-slate-200 bg-white p-4">
          <h2 className="font-semibold">Recent Handoffs</h2>
          <ul className="mt-3 space-y-2 text-sm">
            {(recentHandoffs ?? []).map((row) => (
              <li key={row.id}>#{row.conversation_id} {row.reason_code} ({row.status})</li>
            ))}
            {(recentHandoffs ?? []).length === 0 && <li className="text-slate-500">No data</li>}
          </ul>
        </div>
        <div className="rounded-xl border border-slate-200 bg-white p-4">
          <h2 className="font-semibold">Recent Notifications</h2>
          <ul className="mt-3 space-y-2 text-sm">
            {(recentNotifications ?? []).map((row) => (
              <li key={row.id}>{row.type} ({row.status})</li>
            ))}
            {(recentNotifications ?? []).length === 0 && <li className="text-slate-500">No data</li>}
          </ul>
        </div>
      </section>

      <div className="mt-6">
        <Link href="/tenant/inbox">
          <Button leftIcon={MessagesSquare}>Open Conversation Inbox</Button>
        </Link>
      </div>
    </TenantLayout>
  )
}
