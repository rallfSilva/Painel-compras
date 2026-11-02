import { forwardRef, type InputHTMLAttributes } from 'react'
import { cn } from '@/lib/utils'

export const Input = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement>>(({ className, ...p }, ref) => (
  <input
    ref={ref}
    className={cn('flex h-9 w-full rounded-md border border-input bg-surface px-3 text-sm placeholder:text-muted-foreground disabled:opacity-50', className)}
    {...p}
  />
))
Input.displayName = 'Input'
