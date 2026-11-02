import { useCallback, useState, type ReactNode } from 'react'
import { ArrowLeft } from 'lucide-react'
import { Dialogo } from '@/components/ui/dialog'
import { DetalheUnidade } from './DetalheUnidade'
import { DetalheProcesso } from './DetalheProcesso'
import { DetalheFornecedor } from './DetalheFornecedor'
import { DetalhesCtx, type TipoDetalhe } from './useDetalhes'

interface Alvo { tipo: TipoDetalhe; id: number; titulo?: string }

const TITULOS: Record<TipoDetalhe, string> = { unidade: 'Unidade compradora', processo: 'Processo de contratação', fornecedor: 'Fornecedor' }

/**
 * Fichas de detalhamento (seção 31): clicar em um órgão mostra suas compras, em um fornecedor o seu
 * histórico, em um processo a sua ficha. As fichas abrem umas sobre as outras, com botão Voltar.
 */
export function DetalhesProvider({ children }: { children: ReactNode }) {
  const [pilha, setPilha] = useState<Alvo[]>([])
  const abrir = useCallback((tipo: TipoDetalhe, id: number, titulo?: string) => setPilha((p) => [...p, { tipo, id, titulo }]), [])
  const atual = pilha[pilha.length - 1]

  return (
    <DetalhesCtx.Provider value={abrir}>
      {children}
      <Dialogo
        aberto={!!atual}
        aoFechar={() => setPilha([])}
        titulo={
          <span className="flex items-center gap-2">
            {pilha.length > 1 && (
              <button onClick={() => setPilha((p) => p.slice(0, -1))} className="rounded p-1 text-muted-foreground hover:bg-muted" aria-label="Voltar">
                <ArrowLeft className="size-4" />
              </button>
            )}
            <span>{atual ? (atual.titulo ?? TITULOS[atual.tipo]) : ''}</span>
          </span>
        }
        descricao={atual ? `${TITULOS[atual.tipo]} · os dados analíticos respeitam os filtros globais ativos` : undefined}
        className="max-w-6xl"
      >
        {atual?.tipo === 'unidade' && <DetalheUnidade key={`u${atual.id}`} id={atual.id} aoFechar={() => setPilha([])} />}
        {atual?.tipo === 'processo' && <DetalheProcesso key={`p${atual.id}`} id={atual.id} />}
        {atual?.tipo === 'fornecedor' && <DetalheFornecedor key={`f${atual.id}`} id={atual.id} />}
      </Dialogo>
    </DetalhesCtx.Provider>
  )
}
