import { useMemo, useState } from 'react'
import { paraQueryString, useFiltros } from '@/contexts/FiltrosContext'
import { BarraFiltros } from '@/components/filters/BarraFiltros'
import { CabecalhoModulo } from '@/components/compras/CabecalhoModulo'
import { FaixaIndicadores } from '@/components/compras/FaixaIndicadores'
import { rotuloSituacao } from '@/components/compras/Situacao'
import { TabelaItensAdjudicados } from '@/components/inteligencia/TabelaItensAdjudicados'
import { useCabecalhoExportacao } from '@/components/inteligencia/partes'
import { Card, CardContent } from '@/components/ui/card'
import { cn } from '@/lib/utils'

const SITUACOES = ['ADJUDICADO', 'HOMOLOGADO', 'CONTRATADO']

/** Módulo 7 — Itens Adjudicados (seção 16). */
export function ItensAdjudicados() {
  const { estado } = useFiltros()
  const cabecalho = useCabecalhoExportacao()
  const [situacoes, setSituacoes] = useState<string[]>([])
  const global = paraQueryString(estado)
  const consulta = useMemo(() => {
    const p = new URLSearchParams(global)
    situacoes.forEach((s) => p.append('situacao[]', s))
    return p.toString()
  }, [global, situacoes])

  return (
    <div>
      <CabecalhoModulo titulo="Itens Adjudicados" descricao="Tabela detalhada dos itens com resultado: identificação, valores, economia, fornecedor, contrato e ata." consulta={global} />
      <BarraFiltros />
      <FaixaIndicadores consulta={global} codigos={['total_estimado', 'total_contratado', 'economia', 'percentual_economia', 'itens_comprados', 'processos', 'fornecedores']}
        monetarios={['total_estimado', 'total_contratado', 'economia']} />
      <Card>
        <CardContent className="pt-5">
          <div className="mb-3 flex flex-wrap items-center gap-2" role="group" aria-label="Filtrar por situação do item">
            <span className="text-xs text-muted-foreground">Situação do item:</span>
            {SITUACOES.map((s) => {
              const ativo = situacoes.includes(s)
              return (
                <button key={s} aria-pressed={ativo} onClick={() => setSituacoes((x) => (ativo ? x.filter((y) => y !== s) : [...x, s]))}
                  className={cn('rounded-md border px-3 py-1.5 text-xs', ativo ? 'border-primary bg-accent text-accent-foreground' : 'hover:bg-muted')}>
                  {rotuloSituacao(s)}
                </button>
              )
            })}
            {situacoes.length > 0 && <button className="px-2 text-xs text-primary hover:underline" onClick={() => setSituacoes([])}>Todas</button>}
          </div>
          <TabelaItensAdjudicados consulta={consulta} cabecalho={cabecalho('Itens adjudicados', situacoes.map(rotuloSituacao))} />
          <p className="mt-2 text-[11px] text-muted-foreground">Clique na linha para abrir o processo, ou no fornecedor para ver o histórico de fornecimento. A busca aceita descrição, código do item, fornecedor, CNPJ, órgão, unidade, processo e nº de publicação.</p>
        </CardContent>
      </Card>
    </div>
  )
}
