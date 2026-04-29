import React from 'react'
import { cn } from '../../lib/utils'

export function Select({ className, children, ...props }) {
  return (
    <select className={cn('w-full rounded-md border border-slate-300 px-3 py-2 text-sm', className)} {...props}>
      {children}
    </select>
  )
}
