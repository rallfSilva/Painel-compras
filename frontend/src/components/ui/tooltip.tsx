import * as T from '@radix-ui/react-tooltip'
import type { ReactNode } from 'react'

export const TooltipProvider = T.Provider

export function Dica({ conteudo, children }: { conteudo: ReactNode; children: ReactNode }) {
  return (
    <T.Root delayDuration={250}>
      <T.Trigger asChild>{children}</T.Trigger>
      <T.Portal>
        <T.Content sideOffset={6} collisionPadding={12} className="z-50 max-w-xs rounded-md bg-foreground px-3 py-2 text-xs leading-relaxed text-background shadow-lg">
          {conteudo}
        </T.Content>
      </T.Portal>
    </T.Root>
  )
}
