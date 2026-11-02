import { useState } from 'react'
import { Outlet } from 'react-router-dom'
import { TriangleAlert } from 'lucide-react'
import { cn } from '@/lib/utils'
import { MenuLateral } from './MenuLateral'
import { Cabecalho, useMeta } from './Cabecalho'
import { RodapeCreditos } from './RodapeCreditos'
import { AvisoFiltroCruzado } from './AvisoFiltroCruzado'
import { DetalhesProvider } from '@/components/detalhes/DetalhesContext'

function lerRecolhido() {
  try { return localStorage.getItem('sic-menu-recolhido') === '1' } catch { return false }
}

export function LayoutPrincipal() {
  const [recolhido, setRecolhido] = useState(lerRecolhido)
  const [aberto, setAberto] = useState(false)
  const { data: meta } = useMeta()

  const alternarRecolhido = () => {
    setRecolhido((r) => {
      try { localStorage.setItem('sic-menu-recolhido', r ? '0' : '1') } catch { /* ok */ }
      return !r
    })
  }

  return (
    <div className="min-h-full">
      <MenuLateral recolhido={recolhido} aoRecolher={alternarRecolhido} aberto={aberto} aoFechar={() => setAberto(false)} />
      <div className={cn('flex min-h-screen flex-col transition-[padding] duration-200', recolhido ? 'lg:pl-[68px]' : 'lg:pl-64')}>
        <Cabecalho aoAbrirMenu={() => setAberto(true)} />
        {meta?.possui_dados_ficticios && (
          <div className="flex items-start gap-2 border-b border-warning/30 bg-warning/10 px-4 py-2 text-xs text-foreground sm:px-6" role="status">
            <TriangleAlert className="mt-0.5 size-4 shrink-0 text-warning" />
            <span>
              <strong>Dados demonstrativos fictícios.</strong> Os números exibidos servem apenas para testar gráficos e cálculos e não representam compras reais do Estado.
            </span>
          </div>
        )}
        <main className="mx-auto w-full min-w-0 max-w-[1600px] flex-1 px-4 py-5 sm:px-6">
          <DetalhesProvider>
            <Outlet />
          </DetalhesProvider>
        </main>
        <AvisoFiltroCruzado />
        <RodapeCreditos />
      </div>
    </div>
  )
}
