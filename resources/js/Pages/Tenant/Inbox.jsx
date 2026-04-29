import React from 'react'
import { Link, router } from '@inertiajs/react'
import TenantLayout from '../../layouts/TenantLayout'
import { Button } from '../../components/ui/button'
import { CheckCircle2, Bot } from 'lucide-react'

export default function Inbox({ query, conversationList, selectedConversation, messages, handoffs, contextPanel }) {
  return (
    <TenantLayout title="Conversation Inbox">
      <div className="grid gap-4 lg:grid-cols-12">
        <aside className="rounded-xl border border-slate-200 bg-white p-4 lg:col-span-4">
          <h2 className="font-semibold">Conversations</h2>
          <p className="mt-1 text-xs text-slate-500">Query: {query || '-'}</p>
          <ul className="mt-3 space-y-2 text-sm">
            {(conversationList ?? []).map((row) => (
              <li key={row.id}>
                <Link href={`/tenant/inbox?conversation_id=${row.id}`}>{row.customer_phone} ({row.status_label})</Link>
              </li>
            ))}
            {(conversationList ?? []).length === 0 && <li className="text-slate-500">No conversations</li>}
          </ul>
        </aside>

        <section className="space-y-4 lg:col-span-5">
          <div className="rounded-xl border border-slate-200 bg-white p-4">
            <h2 className="font-semibold">Messages</h2>
            <p className="mt-1 text-xs text-slate-500">Conversation: {selectedConversation?.customer_phone ?? '-'}</p>
            <ul className="mt-3 space-y-2 text-sm">
              {(messages ?? []).map((row) => (
                <li key={row.id}><strong>{row.direction_label}:</strong> {row.content}</li>
              ))}
              {(messages ?? []).length === 0 && <li className="text-slate-500">No messages</li>}
            </ul>
          </div>

          <div className="rounded-xl border border-slate-200 bg-white p-4">
            <h2 className="font-semibold">Handoffs</h2>
            <ul className="mt-3 space-y-2 text-sm">
              {(handoffs ?? []).map((row) => (
                <li key={row.id} className="rounded border border-slate-200 p-2">
                  <div>Reason: {row.reason_code}</div>
                  <div>Status: {row.status}</div>
                  <div className="mt-2 flex gap-2">
                    {row.can_resolve_handoff && (
                      <Button
                        type="button"
                        className="px-3 py-1 text-xs"
                        variant="secondary"
                        leftIcon={CheckCircle2}
                        onClick={() => router.post(`/tenant/inbox/${row.conversation_id}/handoff/${row.id}/resolve`)}
                      >
                        Resolve
                      </Button>
                    )}
                    {row.can_resume_ai && (
                      <Button
                        type="button"
                        className="px-3 py-1 text-xs"
                        leftIcon={Bot}
                        onClick={() => router.post(`/tenant/inbox/${row.conversation_id}/handoff/${row.id}/resume`)}
                      >
                        Resume AI
                      </Button>
                    )}
                  </div>
                </li>
              ))}
              {(handoffs ?? []).length === 0 && <li className="text-slate-500">No handoffs</li>}
            </ul>
          </div>
        </section>

        <aside className="rounded-xl border border-slate-200 bg-white p-4 lg:col-span-3">
          <h2 className="font-semibold">Context Panel</h2>
          <div className="mt-3 space-y-2 text-sm">
            <div><strong>Lead:</strong> {contextPanel?.lead?.full_name ?? 'Data belum tersedia'}</div>
            <div><strong>Stage:</strong> {contextPanel?.state?.current_stage ?? 'Data belum tersedia'}</div>
            <div><strong>Summary:</strong> {contextPanel?.context?.summary ?? 'Data belum tersedia'}</div>
            <div><strong>Reason:</strong> {contextPanel?.context?.reason ?? 'Data belum tersedia'}</div>
            <div><strong>Next:</strong> {contextPanel?.context?.recommended_next_action ?? 'Data belum tersedia'}</div>
          </div>
        </aside>
      </div>
    </TenantLayout>
  )
}
