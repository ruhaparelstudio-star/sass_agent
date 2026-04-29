import React from 'react'
import { cn } from '../../lib/utils'

export function Card({ className, children }) {
  return <section className={cn('rounded-lg border border-slate-200 bg-white p-4', className)}>{children}</section>
}
