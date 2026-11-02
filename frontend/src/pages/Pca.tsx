import { useMemo, useState } from 'react'
import { paraQueryString, useFiltros } from '@/contexts/FiltrosContext'
import { useAuth } from '@/contexts/AuthContext'
import { BarraFiltros } from '@/components/filters/BarraFiltros'
import { Abas, CabecalhoModulo } from '@/components/compras/CabecalhoModulo'
import { EstadoPainel, GradeKpis, Nota, SERIES, useCabecalhoExportacao, usePainel } from '@/components/inteligencia/partes'
import { BarrasDuplas } from '@/components/inteligencia/BarrasDuplas'
import { TabelaAvancada } from '@/components/tabela/TabelaAvancada'
import { TabelaServidor } from '@/components/tabela/TabelaServidor'
import type { Coluna } from '@/components/tabela/colunas'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { useDetalhes } from '@/components/detalhes/useDetalhes'
import type { NomeFiltro } from '@/lib/tipos'
import { formatarMoeda, formatarPercentual } from '@/lib/format'
import { cn } from '@/lib/utils'

interface Comparacao { id: number | string | null; nome: string; planejado: number; contratado: number; percentual: number | null; diferenca: number; demandas: number; filtro: NomeFiltro | null; chave: string | null }
interface PainelPca {
  indicadores: Record<'planejado' | 'contratado' | 'percentual_executado' | 'diferenca' | 'demandas' | 'nao_iniciadas' | 'em_andamento' | 'concluidas' | 'canceladas' | 'atrasadas', number | null>
  por_orgao: Comparacao[]; por_categoria: Comparacao[]; por_classe: Comparacao[]
  por_exercicio: { ano: number; planejado: number; contratado: number; percentual: number | null }[]
  por_situacao: { situacao: string; nome: string; quantidade: number }[]
  fora_do_pca: { processos: number; contratado: number; percentual: number | null }
}
interface Demanda {
  id: number; exercicio: number; orgao: string; unidade_requisitante: string | null; numero_demanda: string; codigo_contratacao: string | null; objeto: string
  descricao_necessidade: string | null; categoria_nome: string; grupo: string | null; classe: string | null; quantidade_estimada: number | null; valor_estimado: number
  data_pretendida: string | null; prioridade: string; situacao_planejamento: string; responsavel: string | null; processo_sei: string | null
  situacao_execucao: string; contratado: number; percentual_executado: number | null; processo_relacionado: string | null; processo_id: number | null; atrasada: boolean
}

const EXECUCAO: Record<string, { rotulo: string; cor: 'neutro' | 'primario' | 'sucesso' | 'alerta' }> = {
  NAO_INICIADA: { rotulo: 'Não iniciada', cor: 'neutro' }, EM_ANDAMENTO: { rotulo: 'Em andamento', cor: 'primario' },
  CONCLUIDA: { rotulo: 'Concluída', cor: 'sucesso' }, CANCELADA: { rotulo: 'Cancelada', cor: 'alerta' },
}
const rotulo = (s: string) => s.charAt(0) + s.slice(1).toLowerCase().replace(/_/g, ' ')
type Aba = 'comparacao' | 'demandas'

/** Módulo 11 — Plano de Contratações Anual (seção 20). */
export function Pca() {
  const { estado, aplicarCruzado } = useFiltros()
  const { pode } = useAuth()
  const abrir = useDetalhes()
  const cabecalho = useCabecalhoExportacao()
  const consulta = paraQueryString(estado)
  const [aba, setAba] = useState<Aba>('comparacao')
  const [situacoes, setSituacoes] = useState<string[]>([])
  const q = usePainel<PainelPca>('pca', '/pca', consulta)
  const consultaDemandas = useMemo(() => {
    const p = new URLSearchParams(consulta)
    situacoes.forEach((s) => p.append('situacao[]', s))
    return p.toString()
  }, [consulta, situacoes])

  const colComparacao = (rotuloDim: string): Coluna<Comparacao>[] => [
    { id: 'nome', titulo: rotuloDim, tipo: 'texto', valor: (l) => l.nome, fixa: true, largura: 200 },
    { id: 'demandas', titulo: 'Demandas', tipo: 'numero', valor: (l) => l.demandas },
    { id: 'planejado', titulo: 'Planejado (R$)', tipo: 'moeda', valor: (l) => l.planejado, barra: true },
    { id: 'contratado', titulo: 'Contratado (R$)', tipo: 'moeda', valor: (l) => l.contratado },
    { id: 'diferenca', titulo: 'Diferença (R$)', dica: 'Planejado − contratado.', tipo: 'moeda', valor: (l) => l.diferenca },
    { id: 'percentual', titulo: '% executado', tipo: 'percentual', valor: (l) => l.percentual, faixa: { sentido: 'maior', bom: 70, atencao: 40 } },
  ]

  const colDemandas = useMemo<Coluna<Demanda>[]>(() => [
    { id: 'numero_demanda', titulo: 'Nº demanda', tipo: 'texto', valor: (l) => l.numero_demanda, fixa: true, largura: 100,
      render: (l) => <span className="font-medium">{l.numero_demanda}{l.atrasada && <Badge cor="perigo" className="ml-1">Atrasada</Badge>}</span> },
    { id: 'exercicio', titulo: 'Exercício', tipo: 'numero', valor: (l) => l.exercicio },
    { id: 'orgao', titulo: 'Órgão', tipo: 'texto', valor: (l) => l.orgao },
    { id: 'unidade_requisitante', titulo: 'Unidade requisitante', tipo: 'texto', valor: (l) => l.unidade_requisitante, ordenavel: false, largura: 160 },
    { id: 'codigo_contratacao', titulo: 'Código da contratação', tipo: 'texto', valor: (l) => l.codigo_contratacao, ordenavel: false },
    { id: 'objeto', titulo: 'Objeto', tipo: 'texto', valor: (l) => l.objeto, ordenavel: false, largura: 260, render: (l) => <span className="line-clamp-2" title={l.objeto}>{l.objeto}</span> },
    { id: 'descricao_necessidade', titulo: 'Necessidade', tipo: 'texto', valor: (l) => l.descricao_necessidade, ordenavel: false, largura: 200 },
    { id: 'categoria_nome', titulo: 'Categoria', tipo: 'texto', valor: (l) => l.categoria_nome, ordenavel: false },
    { id: 'grupo', titulo: 'Grupo', tipo: 'texto', valor: (l) => l.grupo, ordenavel: false },
    { id: 'classe', titulo: 'Classe', tipo: 'texto', valor: (l) => l.classe, ordenavel: false },
    { id: 'quantidade_estimada', titulo: 'Qtde. estimada', tipo: 'numero', valor: (l) => l.quantidade_estimada, ordenavel: false },
    { id: 'valor_estimado', titulo: 'Valor estimado (R$)', tipo: 'moeda', valor: (l) => l.valor_estimado },
    { id: 'data_pretendida', titulo: 'Data pretendida', tipo: 'data', valor: (l) => l.data_pretendida },
    { id: 'prioridade', titulo: 'Prioridade', tipo: 'texto', valor: (l) => rotulo(l.prioridade), ordenavel: false },
    { id: 'situacao_planejamento', titulo: 'Situação do planejamento', tipo: 'texto', valor: (l) => rotulo(l.situacao_planejamento), ordenavel: false },
    { id: 'responsavel', titulo: 'Responsável', tipo: 'texto', valor: (l) => l.responsavel, ordenavel: false },
    { id: 'processo_sei', titulo: 'Processo SEI', tipo: 'texto', valor: (l) => l.processo_sei, ordenavel: false },
    { id: 'processo_relacionado', titulo: 'Processo de contratação', tipo: 'texto', valor: (l) => l.processo_relacionado, ordenavel: false,
      render: (l) => l.processo_id ? <button className="text-primary hover:underline" onClick={(e) => { e.stopPropagation(); abrir('processo', l.processo_id!) }}>{l.processo_relacionado}</button> : '—' },
    { id: 'situacao_execucao', titulo: 'Execução', tipo: 'texto', valor: (l) => EXECUCAO[l.situacao_execucao]?.rotulo ?? l.situacao_execucao, ordenavel: false,
      render: (l) => <Badge cor={EXECUCAO[l.situacao_execucao]?.cor ?? 'neutro'}>{EXECUCAO[l.situacao_execucao]?.rotulo ?? l.situacao_execucao}</Badge> },
    { id: 'contratado', titulo: 'Contratado (R$)', tipo: 'moeda', valor: (l) => l.contratado },
    { id: 'percentual_executado', titulo: '% executado', tipo: 'percentual', valor: (l) => l.percentual_executado, ordenavel: false },
  ], [abrir])

  return (
    <div>
      <CabecalhoModulo titulo="Plano de Contratações Anual" descricao="Planejamento anual das contratações e comparação com o que foi efetivamente contratado." consulta={consulta} />
      <BarraFiltros />
      <EstadoPainel q={q}>
        {(d) => {
          const i = d.indicadores
          return (
            <>
              <GradeKpis colunas="xl:grid-cols-5" itens={[
                { rotulo: 'Valor total planejado', valor: i.planejado, tipo: 'moeda', destaque: true, dica: 'Σ valor estimado das demandas do PCA, exceto as excluídas do plano.' },
                { rotulo: 'Valor contratado', valor: i.contratado, tipo: 'moeda', dica: 'Σ valor contratado dos itens dos processos vinculados às demandas.' },
                { rotulo: 'Percentual executado', valor: i.percentual_executado, tipo: 'percentual', dica: 'Contratado / planejado × 100.' },
                { rotulo: 'Diferença planejado × contratado', valor: i.diferenca, tipo: 'moeda' },
                { rotulo: 'Demandas', valor: i.demandas, tipo: 'numero' },
                { rotulo: 'Não iniciadas', valor: i.nao_iniciadas, tipo: 'numero' },
                { rotulo: 'Em andamento', valor: i.em_andamento, tipo: 'numero' },
                { rotulo: 'Concluídas', valor: i.concluidas, tipo: 'numero' },
                { rotulo: 'Atrasadas', valor: i.atrasadas, tipo: 'numero', dica: 'Data pretendida já passou e a demanda não foi concluída nem cancelada.' },
                { rotulo: 'Canceladas', valor: i.canceladas, tipo: 'numero' },
              ]} />
              <Abas<Aba> abas={[{ id: 'comparacao', rotulo: 'PCA × contratações' }, { id: 'demandas', rotulo: 'Demandas do PCA' }]} ativa={aba} aoMudar={setAba} />
              {aba === 'comparacao' && (
                <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                  <BarrasDuplas titulo="Planejado × contratado por exercício" subtitulo="Valor das demandas do PCA e valor contratado dos processos vinculados (R$)"
                    dados={d.por_exercicio} x="ano" series={[{ chave: 'planejado', nome: 'Planejado', cor: SERIES[2] }, { chave: 'contratado', nome: 'Contratado', cor: SERIES[0] }]} />
                  <BarrasDuplas titulo="Demandas por situação de execução" dados={d.por_situacao} x="nome" moeda={false} series={[{ chave: 'quantidade', nome: 'Demandas', cor: SERIES[0] }]} />
                  {([['Por órgão', 'Órgão', d.por_orgao], ['Por categoria', 'Categoria', d.por_categoria], ['Por classe (20 maiores)', 'Classe', d.por_classe]] as const).map(([t, r, dados]) => (
                    <Card key={t} className={cn(t !== 'Por categoria' && 'xl:col-span-2')}>
                      <CardHeader><div><CardTitle>{t}</CardTitle><p className="mt-0.5 text-xs text-muted-foreground">Planejado no PCA × contratado{t !== 'Por categoria' ? ' · clique para filtrar o painel' : ''}</p></div></CardHeader>
                      <CardContent>
                        <TabelaAvancada<Comparacao> colunas={colComparacao(r)} dados={dados as Comparacao[]} podeExportar={pode('relatorios.exportar')} cabecalhoExportacao={cabecalho(`PCA × contratações · ${t}`)}
                          aoClicarLinha={(l) => { if (l.filtro && l.chave) aplicarCruzado(l.filtro, l.chave, l.nome) }} />
                      </CardContent>
                    </Card>
                  ))}
                  <Card>
                    <CardHeader><div><CardTitle>Contratações fora do PCA</CardTitle><p className="mt-0.5 text-xs text-muted-foreground">Processos com os filtros atuais que não estão vinculados a nenhuma demanda</p></div></CardHeader>
                    <CardContent className="space-y-2 text-sm">
                      <p><span className="text-2xl font-semibold tabular">{d.fora_do_pca.processos.toLocaleString('pt-BR')}</span> processos</p>
                      <p className="tabular">{formatarMoeda(d.fora_do_pca.contratado)} contratados · {formatarPercentual(d.fora_do_pca.percentual)} do total</p>
                      <p className="text-xs text-muted-foreground">Inclui contratações anteriores ao primeiro PCA cadastrado e demandas emergenciais. Pede análise, não indica irregularidade.</p>
                    </CardContent>
                  </Card>
                </div>
              )}
            </>
          )
        }}
      </EstadoPainel>
      {aba === 'demandas' && (
        <Card>
          <CardContent className="pt-5">
            <div className="mb-3 flex flex-wrap items-center gap-2" role="group" aria-label="Filtrar por situação de execução">
              {Object.entries(EXECUCAO).map(([k, v]) => {
                const ativo = situacoes.includes(k)
                return <button key={k} aria-pressed={ativo} onClick={() => setSituacoes((x) => (ativo ? x.filter((y) => y !== k) : [...x, k]))}
                  className={cn('rounded-md border px-3 py-1.5 text-xs', ativo ? 'border-primary bg-accent text-accent-foreground' : 'hover:bg-muted')}>{v.rotulo}</button>
              })}
            </div>
            <TabelaServidor<Demanda> endpoint="/pca/demandas" consulta={consultaDemandas} colunas={colDemandas} ordemPadrao={{ id: 'data_pretendida', direcao: 'asc' }}
              placeholderPesquisa="Objeto, nº da demanda, código da contratação ou processo SEI…" podeExportar={pode('relatorios.exportar')}
              cabecalhoExportacao={cabecalho('Demandas do PCA', situacoes.map((s) => EXECUCAO[s].rotulo))}
              aoClicarLinha={(l) => l.processo_id && abrir('processo', l.processo_id)} />
          </CardContent>
        </Card>
      )}
      <Nota className="mt-4">O PCA segue os filtros de período (exercício), órgão/unidade e grupo/classe. O cadastro das demandas entra pela importação de dados (Etapa 5) ou pela integração com o PNCP.</Nota>
    </div>
  )
}
