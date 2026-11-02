import { useMemo, useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Loader2, Printer } from 'lucide-react'
import { api } from '@/lib/api'
import type { Indicador, RespostaIndicadores } from '@/lib/tipos'
import { paraQueryString, useFiltros } from '@/contexts/FiltrosContext'
import { useAuth } from '@/contexts/AuthContext'
import { BarraFiltros } from '@/components/filters/BarraFiltros'
import { CartaoIndicador } from '@/components/dashboard/CartaoIndicador'
import { DialogoRegistros } from '@/components/dashboard/DialogoRegistros'
import { GraficoEconomia, GraficoEvolucao, GraficoPorte, GraficoSistematica, RankingUnidades, type Evolucao, type GrupoPorte, type Sistematica, type TopUnidade } from '@/components/dashboard/Graficos'
import { Button } from '@/components/ui/button'

const GRUPOS: { titulo: string; codigos: string[]; monetario?: boolean }[] = [
  { titulo: 'Valores da contratação', codigos: ['total_estimado', 'total_contratado', 'economia', 'percentual_economia', 'total_licitado', 'total_homologado'], monetario: true },
  { titulo: 'Execução financeira', codigos: ['total_empenhado', 'total_liquidado', 'total_pago'], monetario: true },
  { titulo: 'Volume', codigos: ['processos', 'itens_comprados', 'itens_catalogo', 'fornecedores', 'unidades_compradoras'] },
  { titulo: 'Procedimentos, atas e contratos', codigos: ['qtd_licitacoes', 'qtd_dispensas', 'qtd_inexigibilidades', 'qtd_chamadas_publicas', 'qtd_atas', 'contratos_vigentes', 'contratos_vencendo'] },
]

const BASES = [
  { valor: 'contratado', rotulo: 'Contratado' },
  { valor: 'homologado', rotulo: 'Homologado' },
  { valor: 'adjudicado', rotulo: 'Adjudicado' },
]

interface Graficos { evolucao_anual: Evolucao[]; por_sistematica: Sistematica[]; top_unidades: TopUnidade[]; por_grupo_porte: GrupoPorte[] }

export function Dashboard() {
  const { estado } = useFiltros()
  const { pode } = useAuth()
  const [base, setBase] = useState<string | undefined>()
  const [incluirSemDisputa, setIncluirSemDisputa] = useState<boolean | undefined>()
  const [aberto, setAberto] = useState<Indicador | null>(null)

  const consulta = useMemo(
    () => paraQueryString(estado, { economia_base: base, incluir_sem_disputa: incluirSemDisputa === undefined ? undefined : incluirSemDisputa ? '1' : '0' }),
    [estado, base, incluirSemDisputa],
  )

  const ind = useQuery({
    queryKey: ['indicadores', consulta],
    queryFn: () => api<RespostaIndicadores>(`/dashboard/indicadores?${consulta}`),
    placeholderData: keepPreviousData,
  })
  const graf = useQuery({
    queryKey: ['graficos', consulta],
    queryFn: () => api<Graficos>(`/dashboard/graficos?${consulta}`),
    placeholderData: keepPreviousData,
  })

  const porCodigo = useMemo(() => new Map(ind.data?.indicadores.map((i) => [i.codigo, i])), [ind.data])
  const baseAtual = base ?? ind.data?.opcoes.economia_base ?? 'contratado'
  const semDisputaAtual = incluirSemDisputa ?? ind.data?.opcoes.incluir_sem_disputa ?? false

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-lg font-bold tracking-tight sm:text-xl">PAINEL DAS COMPRAS PÚBLICAS — GOVERNO DE RORAIMA</h1>
          <p className="mt-0.5 text-sm text-muted-foreground">
            Período: <span className="font-medium text-foreground">{ind.data?.periodo ?? '…'}</span>
            {ind.data?.periodo_anterior && <> · comparado com {ind.data.periodo_anterior}</>}
          </p>
        </div>
        <Button variant="outline" size="sm" className="no-print" onClick={() => window.print()}><Printer /> Imprimir</Button>
      </div>

      <BarraFiltros />

      <div className="no-print mb-4 flex flex-wrap items-center gap-x-5 gap-y-2 rounded-lg border border-dashed px-4 py-2.5 text-xs text-muted-foreground">
        <span className="font-medium text-foreground">Critério da economia:</span>
        <label className="flex items-center gap-2">
          Comparar o estimado com o valor
          <select className="h-7 rounded border border-input bg-surface px-2 text-xs text-foreground" value={baseAtual} onChange={(e) => setBase(e.target.value)}>
            {BASES.map((b) => <option key={b.valor} value={b.valor}>{b.rotulo.toLowerCase()}</option>)}
          </select>
        </label>
        <label className="flex items-center gap-2">
          <input type="checkbox" className="size-3.5 accent-[hsl(var(--primary))]" checked={semDisputaAtual} onChange={(e) => setIncluirSemDisputa(e.target.checked)} />
          Incluir procedimentos sem disputa de preço (PNAE, credenciamento, inexigibilidade, adesão)
        </label>
      </div>

      {ind.isLoading && <div className="flex h-40 items-center justify-center"><Loader2 className="size-6 animate-spin text-primary" /></div>}
      {ind.isError && <p className="rounded-lg border border-danger/40 bg-danger/10 p-4 text-sm">Não foi possível carregar os indicadores: {(ind.error as Error).message}</p>}

      {ind.data && (
        <div className={ind.isFetching ? 'opacity-70 transition-opacity' : 'transition-opacity'}>
          {GRUPOS.map((g) => (
            <section key={g.titulo} className="mb-5" aria-label={g.titulo}>
              <h2 className="mb-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{g.titulo}</h2>
              <div className={g.monetario ? 'grid grid-cols-1 gap-3 min-[480px]:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6' : 'grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-7'}>
                {g.codigos.map((c) => {
                  const i = porCodigo.get(c)
                  return i ? (
                    <CartaoIndicador key={c} indicador={i} destaque={c === 'percentual_economia' || c === 'economia'} podeAbrir={pode('registros.ver')} aoAbrir={() => setAberto(i)} />
                  ) : null
                })}
              </div>
            </section>
          ))}
        </div>
      )}

      {graf.data && (
        <div className="mt-2 grid grid-cols-1 gap-4 xl:grid-cols-3">
          <GraficoEvolucao dados={graf.data.evolucao_anual} />
          <GraficoEconomia dados={graf.data.evolucao_anual} />
          <RankingUnidades dados={graf.data.top_unidades} />
          <GraficoPorte dados={graf.data.por_grupo_porte} />
          <GraficoSistematica dados={graf.data.por_sistematica} />
        </div>
      )}

      <DialogoRegistros indicador={aberto} consulta={consulta} aoFechar={() => setAberto(null)} />
    </div>
  )
}
