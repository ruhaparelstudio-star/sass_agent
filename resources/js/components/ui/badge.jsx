import React from 'react'
import { cn } from '../../lib/utils'

export function Badge({ className, tone = 'neutral', children }) {
  const tones = {
    neutral: 'bg-slate-100 text-slate-700',
    success: 'bg-emerald-100 text-emerald-800',
    danger: 'bg-red-100 text-red-800',
  }

  return <span className={cn('rounded-full px-2 py-1 text-xs font-medium', tones[tone], className)}>{children}</span>
}
