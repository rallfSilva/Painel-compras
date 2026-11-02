import { NavLink } from 'react-router-dom'
import { ChevronsLeft, ChevronsRight, X } from 'lucide-react'
import { MODULOS } from '@/modulos'
import { cn } from '@/lib/utils'
import { Dica } from '@/components/ui/tooltip'

export function MenuLateral({ recolhido, aoRecolher, aberto, aoFechar }: {
  recolhido: boolean
  aoRecolher: () => void
  aberto: boolean
  aoFechar: () => void
}) {
  return (
    <>
      {aberto && <div className="fixed inset-0 z-30 bg-black/50 lg:hidden" onClick={aoFechar} aria-hidden />}
      <aside
        className={cn(
          'fixed inset-y-0 left-0 z-40 flex flex-col bg-sidebar text-sidebar-foreground transition-[width,transform] duration-200',
          'w-72 -translate-x-full lg:translate-x-0',
          aberto && 'translate-x-0',
          recolhido ? 'lg:w-[68px]' : 'lg:w-64',
        )}
        aria-label="Menu principal"
      >
        <div className="flex h-16 items-center gap-3 border-b border-white/10 px-4">
          <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary font-bold text-white" aria-hidden>
            SIC
          </div>
          <div className={cn('min-w-0 flex-1', recolhido && 'lg:hidden')}>
            <p className="truncate text-sm font-semibold leading-tight">SIC/SELC</p>
            <p className="truncate text-[11px] text-sidebar-muted">Inteligência de Compras · RR</p>
          </div>
          <button className="rounded-md p-1 text-sidebar-muted hover:text-white lg:hidden" onClick={aoFechar} aria-label="Fechar menu">
            <X className="size-5" />
          </button>
        </div>

        <nav className="flex-1 overflow-y-auto px-2 py-3">
          <ul className="space-y-0.5">
            {MODULOS.map((m) => {
              const item = (
                <NavLink
                  to={`/${m.slug}`}
                  end={m.slug === ''}
                  onClick={aoFechar}
                  className={({ isActive }) =>
                    cn(
                      'group flex items-center gap-3 rounded-md px-2.5 py-2 text-[13px] transition-colors',
                      isActive ? 'bg-sidebar-active font-medium text-white' : 'text-sidebar-foreground/80 hover:bg-white/5 hover:text-white',
                    )
                  }
                >
                  <m.icone className="size-[18px] shrink-0" />
                  <span className={cn('flex-1 truncate', recolhido && 'lg:hidden')}>{m.nome}</span>
                  {m.etapa > 1 && (
                    <span className={cn('rounded bg-white/10 px-1.5 text-[10px] text-sidebar-muted', recolhido && 'lg:hidden')}>E{m.etapa}</span>
                  )}
                </NavLink>
              )
              return <li key={m.slug}>{recolhido ? <Dica conteudo={m.nome}>{item}</Dica> : item}</li>
            })}
          </ul>
        </nav>

        <button
          onClick={aoRecolher}
          className="hidden items-center gap-2 border-t border-white/10 px-4 py-3 text-xs text-sidebar-muted hover:text-white lg:flex"
          aria-label={recolhido ? 'Expandir menu' : 'Recolher menu'}
        >
          {recolhido ? <ChevronsRight className="size-4" /> : <><ChevronsLeft className="size-4" /> Recolher menu</>}
        </button>
      </aside>
    </>
  )
}
