import * as D from '@radix-ui/react-dialog'
import { X } from 'lucide-react'
import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'

export function Dialogo({ aberto, aoFechar, titulo, descricao, children, className }: {
  aberto: boolean
  aoFechar: () => void
  titulo: ReactNode
  descricao?: ReactNode
  children: ReactNode
  className?: string
}) {
  return (
    <D.Root open={aberto} onOpenChange={(o) => !o && aoFechar()}>
      <D.Portal>
        <D.Overlay className="fixed inset-0 z-40 bg-black/50" />
        <D.Content
          className={cn('fixed left-1/2 top-1/2 z-50 flex max-h-[90vh] w-[calc(100vw-32px)] max-w-5xl -translate-x-1/2 -translate-y-1/2 flex-col rounded-lg border bg-card shadow-xl', className)}
        >
          <div className="flex items-start justify-between gap-4 border-b px-5 py-4">
            <div>
              <D.Title className="text-base font-semibold">{titulo}</D.Title>
              {descricao && <D.Description className="mt-1 text-xs text-muted-foreground">{descricao}</D.Description>}
            </div>
            <D.Close className="rounded-md p-1 text-muted-foreground hover:bg-muted" aria-label="Fechar">
              <X className="size-4" />
            </D.Close>
          </div>
          <div className="overflow-auto">{children}</div>
        </D.Content>
      </D.Portal>
    </D.Root>
  )
}
