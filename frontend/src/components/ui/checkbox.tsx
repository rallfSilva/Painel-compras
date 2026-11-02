import * as C from '@radix-ui/react-checkbox'
import { Check, Minus } from 'lucide-react'
import { cn } from '@/lib/utils'

export function Caixa({ marcado, aoMudar, className, rotulo }: {
  marcado: boolean | 'indeterminate'
  aoMudar: (v: boolean) => void
  className?: string
  rotulo?: string
}) {
  return (
    <C.Root
      checked={marcado}
      onCheckedChange={(v) => aoMudar(v === true)}
      aria-label={rotulo}
      className={cn('flex size-4 shrink-0 items-center justify-center rounded-[4px] border border-input bg-surface data-[state=checked]:border-primary data-[state=checked]:bg-primary data-[state=indeterminate]:border-primary data-[state=indeterminate]:bg-primary text-primary-foreground', className)}
    >
      <C.Indicator>{marcado === 'indeterminate' ? <Minus className="size-3" /> : <Check className="size-3" />}</C.Indicator>
    </C.Root>
  )
}
