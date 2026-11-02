import type { HTMLAttributes } from 'react'
import { cn } from '@/lib/utils'

const cores = {
  neutro: 'bg-muted text-muted-foreground',
  primario: 'bg-accent text-accent-foreground',
  destaque: 'bg-destaque/15 text-destaque',
  sucesso: 'bg-success/15 text-success',
  alerta: 'bg-warning/15 text-warning',
  perigo: 'bg-danger/15 text-danger',
}

export function Badge({ className, cor = 'neutro', ...p }: HTMLAttributes<HTMLSpanElement> & { cor?: keyof typeof cores }) {
  return <span className={cn('inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium', cores[cor], className)} {...p} />
}
