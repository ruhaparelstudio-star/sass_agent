import React from 'react'
import { cn } from '../../lib/utils'

export function Button({ className, variant = 'default', leftIcon: LeftIcon, rightIcon: RightIcon, children, ...props }) {
  const variants = {
    default: 'bg-slate-900 text-white hover:bg-slate-800',
    outline: 'border border-slate-300 bg-white text-slate-900 hover:bg-slate-50',
    destructive: 'bg-red-700 text-white hover:bg-red-800',
    secondary: 'bg-slate-200 text-slate-900 hover:bg-slate-300',
  }

  return (
    <button
      className={cn(
        'inline-flex items-center justify-center gap-2 rounded-md px-4 py-2 text-sm font-medium transition disabled:cursor-not-allowed disabled:opacity-60',
        variants[variant],
        className,
      )}
      {...props}
    >
      {LeftIcon ? <LeftIcon className="h-4 w-4 shrink-0" aria-hidden="true" /> : null}
      {children}
      {RightIcon ? <RightIcon className="h-4 w-4 shrink-0" aria-hidden="true" /> : null}
    </button>
  )
}
