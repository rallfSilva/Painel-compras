import * as P from '@radix-ui/react-popover'
import type { ComponentProps } from 'react'
import { cn } from '@/lib/utils'

export const Popover = P.Root
export const PopoverTrigger = P.Trigger
export function PopoverContent({ className, align = 'start', sideOffset = 6, ...p }: ComponentProps<typeof P.Content>) {
  return (
    <P.Portal>
      <P.Content
        align={align}
        sideOffset={sideOffset}
        collisionPadding={12}
        className={cn('z-50 rounded-lg border bg-popover text-popover-foreground shadow-lg outline-none', className)}
        {...p}
      />
    </P.Portal>
  )
}
