import { useEffect, useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { AlertTriangle, ArrowLeft, Info, Search } from 'lucide-react'
import { paraQueryString, useFiltros } from '@/contexts/FiltrosContext'
import { useAuth } from '@/contexts/AuthContext'
import { BarraFiltros } from '@/components/filters/BarraFiltros'
import { CabecalhoModulo } from '@/components/compras/CabecalhoModulo'
import { EstadoPainel, GradeKpis, LinhasAno, Nota, SERIES, useCabecalhoExportacao, usePainel } from '@/components/inteligencia/partes'
import { TabelaAvancada } from '@/components/tabela/TabelaAvancada'
import type { Coluna } from '@/components/tabela/colunas'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { MiniTabela } from '@/components/detalhes/Partes'
import { useDetalhes } from '@/components/detalhes/useDetalhes'
import { api } from '@/lib/api'
import { formatarData, formatarNumero, formatarPercentual, formatarUnitario } from '@/lib/format'
import { cn } from '@/lib/utils'

interface Estatisticas { n: number; minimo: number | null; maximo: number | null; media: number | null; mediana: number | null; desvio: number | null; cv: number | null; variacao: number | null }
interface ItemPainel extends Estatisticas { item_id: number; unidade_id: number | null; unidade: string | null; codigo: string | null; descricao: string; alertas: string[] }
interface Painel {
  parametros: { janela: number; mediana: number; cv: number; aumento: number; minimo: number; janela_usada: number }
  resumo: { itens: number; referencias: number; itens_comparaveis: number; dispersao_alta: number; referencias_insuficientes: number }
  maior_dispersao: ItemPainel[]; aviso: string
}
interface Grupo { chave: number | string | null; nome: string | number; n: number; minimo: number | null; maximo: number | null; media: number | null; mediana: number | null; desvio_mediana: number | null }
interface Referencia {
  id: number; processo_id: number; numero_publicacao: string | null; data: string; orgao: string | null; municipio: string | null; fornecedor: string | null; porte: string | null
  quantidade: number | null; preco: number; estimado: number | null; desvio_mediana: number | null; sinalizado: boolean
}
interface Alerta { tipo: string; nivel: 'atencao' | 'informacao'; mensagem: string; aviso: string }
interface AnaliseItem {
  item: { id: number; codigo: string; descricao: string; nome_resumido: string | null; especificacao: string | null; classe: string | null }
  unidades: { id: number | null; sigla: string; nome: string | null; referencias: number }[]; unidade_id: number | null
  criterios: { janela_meses: number | null; quantidade_min: number | null; quantidade_max: number | null; data_referencia: string }
  estatisticas: Estatisticas; evolucao: Grupo[]; por_orgao: Grupo[]; por_municipio: Grupo[]; por_fornecedor: Grupo[]
  estadual_nacional: { disponivel: boolean; mensagem: string }
  alertas: Alerta[]; referencias: Referencia[]; aviso: string
}
interface ResultadoBusca { item_id: number; unidade_id: number | null; unidade: string | null; codigo: string | null; descricao: string; referencias: number }

const JANELAS = [[12, '12 meses'], [24, '24 meses'], [36, '36 meses'], [60, '5 anos'], [0, 'Todo o histórico']] as const

/** Módulo 16 — Análise de Preços (seção 24). */
export function AnalisePrecos() {
  const { estado } = useFiltros()
  const consulta = paraQueryString(estado)
  const [params, setParams] = useSearchParams()
  const item = params.get('item') ? Number(params.get('item')) : null
  const unidade = params.get('unidade') ? Number(params.get('unidade')) : null
  const selecionar = (id: number | null, un?: number | null) => {
    const p = new URLSearchParams(params)
    if (id) p.set('item', String(id)); else p.delete('item')
    if (un) p.set('unidade', String(un)); else p.delete('unidade')
    setParams(p)
  }

  return (
    <div>
      <CabecalhoModulo titulo="Análise de Preços" descricao="Preços adjudicados do mesmo item e unidade de fornecimento: estatísticas, comparações e alertas para análise humana." consulta={consulta} />
      <BarraFiltros />
      <BuscaItem consulta={consulta} aoEscolher={(r) => selecionar(r.item_id, r.unidade_id)} />
      {item ? <AnaliseDoItem id={item} unidade={unidade} consulta={consulta} aoVoltar={() => selecionar(null)} aoTrocarUnidade={(u) => selecionar(item, u)} />
        : <VisaoGeral consulta={consulta} aoEscolher={(i) => selecionar(i.item_id, i.unidade_id)} />}
    </div>
  )
}

function BuscaItem({ consulta, aoEscolher }: { consulta: string; aoEscolher: (r: ResultadoBusca) => void }) {
  const [termo, setTermo] = useState('')
  const [atrasado, setAtrasado] = useState('')
  const [aberto, setAberto] = useState(false)
  useEffect(() => { const t = setTimeout(() => setAtrasado(termo.trim()), 300); return () => clearTimeout(t) }, [termo])
  const q = useQuery({
    queryKey: ['precos-busca', atrasado, consulta], enabled: atrasado.length >= 2,
    queryFn: () => api<{ itens: ResultadoBusca[] }>(`/inteligencia/precos/itens?busca=${encodeURIComponent(atrasado)}&${consulta}`),
  })
  return (
    <div className="relative mb-4">
      <label htmlFor="busca-preco" className="sr-only">Pesquisar item para análise de preço</label>
      <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
      <Input id="busca-preco" className="pl-9" placeholder="Pesquise o item por descrição ou código para analisar o preço…" value={termo}
        onChange={(e) => { setTermo(e.target.value); setAberto(true) }} onFocus={() => setAberto(true)} onBlur={() => setTimeout(() => setAberto(false), 150)} autoComplete="off" />
      {aberto && atrasado.length >= 2 && (
        <ul className="absolute z-20 mt-1 max-h-80 w-full overflow-auto rounded-md border bg-popover p-1 text-sm shadow-lg" role="listbox">
          {q.isLoading && <li className="px-3 py-2 text-xs text-muted-foreground">Pesquisando…</li>}
          {q.data?.itens.length === 0 && <li className="px-3 py-2 text-xs text-muted-foreground">Nenhum item com preço registrado.</li>}
          {q.data?.itens.map((r) => (
            <li key={`${r.item_id}-${r.unidade_id}`}>
              <button type="button" role="option" aria-selected={false} className="flex w-full items-baseline justify-between gap-3 rounded px-3 py-2 text-left hover:bg-muted"
                onMouseDown={(e) => e.preventDefault()} onClick={() => { aoEscolher(r); setAberto(false); setTermo('') }}>
                <span className="min-w-0"><span className="text-xs text-muted-foreground">{r.codigo}</span> {r.descricao}</span>
                <span className="shrink-0 text-xs text-muted-foreground">{r.unidade ?? '—'} · {formatarNumero(r.referencias)} ref.</span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

function VisaoGeral({ consulta, aoEscolher }: { consulta: string; aoEscolher: (i: ItemPainel) => void }) {
  const { pode } = useAuth()
  const cabecalho = useCabecalhoExportacao()
  const [janela, setJanela] = useState<number | null>(null)
  const q = usePainel<Painel>('precos', `/inteligencia/precos${janela !== null ? `?janela_meses=${janela}` : ''}`, consulta)
  const colunas: Coluna<ItemPainel>[] = [
    { id: 'descricao', titulo: 'Item', tipo: 'texto', valor: (l) => `${l.codigo ?? ''} ${l.descricao}`.trim(), fixa: true, largura: 240 },
    { id: 'unidade', titulo: 'Un.', tipo: 'texto', valor: (l) => l.unidade },
    { id: 'n', titulo: 'Referências', tipo: 'numero', valor: (l) => l.n },
    { id: 'minimo', titulo: 'Mínimo', tipo: 'unitario', valor: (l) => l.minimo },
    { id: 'mediana', titulo: 'Mediana', tipo: 'unitario', valor: (l) => l.mediana },
    { id: 'maximo', titulo: 'Máximo', tipo: 'unitario', valor: (l) => l.maximo },
    { id: 'variacao', titulo: 'Variação mín.→máx.', tipo: 'percentual', valor: (l) => l.variacao },
    { id: 'cv', titulo: 'CV', dica: 'Coeficiente de variação: desvio padrão / média × 100.', tipo: 'percentual', valor: (l) => l.cv, barra: true },
  ]
  return (
    <EstadoPainel q={q}>
      {(d) => (
        <>
          <GradeKpis itens={[
            { rotulo: 'Itens com preço', valor: d.resumo.itens, tipo: 'numero', dica: 'Pares item + unidade de fornecimento com preço adjudicado na janela.' },
            { rotulo: 'Referências de preço', valor: d.resumo.referencias, tipo: 'numero', destaque: true },
            { rotulo: 'Itens comparáveis', valor: d.resumo.itens_comparaveis, tipo: 'numero', dica: `Com ${d.parametros.minimo} referências ou mais.` },
            { rotulo: 'Dispersão alta', valor: d.resumo.dispersao_alta, tipo: 'numero', dica: `Coeficiente de variação de ${d.parametros.cv}% ou mais.` },
            { rotulo: 'Referências insuficientes', valor: d.resumo.referencias_insuficientes, tipo: 'numero', dica: `Menos de ${d.parametros.minimo} referências.` },
          ]} />
          <Card>
            <CardHeader className="flex-wrap gap-2">
              <div><CardTitle>Itens com maior dispersão de preço</CardTitle><p className="mt-0.5 text-xs text-muted-foreground">Clique num item para abrir a análise completa</p></div>
              <SeletorJanela valor={janela ?? d.parametros.janela_usada} aoMudar={setJanela} />
            </CardHeader>
            <CardContent>
              <TabelaAvancada<ItemPainel> colunas={colunas} dados={d.maior_dispersao} aoClicarLinha={aoEscolher} rotuloLinha={(l) => `Analisar preço de ${l.descricao}`}
                pesquisarEm={(l) => `${l.codigo} ${l.descricao}`} podeExportar={pode('relatorios.exportar')} cabecalhoExportacao={cabecalho('Itens com maior dispersão de preço')} />
            </CardContent>
          </Card>
          <Criterios p={d.parametros} aviso={d.aviso} />
        </>
      )}
    </EstadoPainel>
  )
}

function SeletorJanela({ valor, aoMudar }: { valor: number | null; aoMudar: (v: number) => void }) {
  return (
    <label className="flex items-center gap-1.5 text-xs text-muted-foreground">Janela
      <select className="h-8 rounded-md border border-input bg-surface px-2 text-xs text-foreground" value={valor ?? 0} onChange={(e) => aoMudar(Number(e.target.value))}>
        {JANELAS.map(([v, r]) => <option key={v} value={v}>{r}</option>)}
      </select>
    </label>
  )
}

function Criterios({ p, aviso }: { p: Painel['parametros']; aviso: string }) {
  return (
    <Nota className="mt-4">
      Critérios de comparabilidade: mesmo item do catálogo, mesma unidade de fornecimento (unidades diferentes nunca são comparadas), janela de {p.janela} meses até a data de referência
      e, quando informada, a mesma faixa de quantidade. Alertas: preço {p.mediana}% acima ou abaixo da mediana, CV de {p.cv}% ou mais, menos de {p.minimo} referências e aumento de
      {' '}{p.aumento}% ou mais na mediana de um ano para o seguinte. {aviso}
    </Nota>
  )
}

function AnaliseDoItem({ id, unidade, consulta, aoVoltar, aoTrocarUnidade }: {
  id: number; unidade: number | null; consulta: string; aoVoltar: () => void; aoTrocarUnidade: (u: number | null) => void
}) {
  const { pode } = useAuth()
  const abrir = useDetalhes()
  const cabecalho = useCabecalhoExportacao()
  const [janela, setJanela] = useState<number | null>(null)
  const [qMin, setQMin] = useState('')
  const [qMax, setQMax] = useState('')
  const [faixa, setFaixa] = useState<{ min: string; max: string }>({ min: '', max: '' })
  const p = new URLSearchParams(consulta)
  if (unidade) p.set('unidade_id', String(unidade))
  if (janela !== null) p.set('janela_meses', String(janela))
  if (faixa.min) p.set('quantidade_min', faixa.min)
  if (faixa.max) p.set('quantidade_max', faixa.max)
  const q = useQuery({ queryKey: ['preco-item', id, p.toString()], queryFn: () => api<AnaliseItem>(`/inteligencia/precos/itens/${id}?${p}`), placeholderData: keepPreviousData })

  const colunas: Coluna<Referencia>[] = [
    { id: 'data', titulo: 'Abertura', tipo: 'data', valor: (l) => l.data },
    { id: 'numero_publicacao', titulo: 'Processo', tipo: 'texto', valor: (l) => l.numero_publicacao,
      render: (l) => <button className="text-primary hover:underline" onClick={(e) => { e.stopPropagation(); abrir('processo', l.processo_id) }}>{l.numero_publicacao}</button> },
    { id: 'orgao', titulo: 'Órgão', tipo: 'texto', valor: (l) => l.orgao },
    { id: 'municipio', titulo: 'Município', tipo: 'texto', valor: (l) => l.municipio },
    { id: 'fornecedor', titulo: 'Fornecedor', tipo: 'texto', valor: (l) => l.fornecedor, largura: 200 },
    { id: 'porte', titulo: 'Porte', tipo: 'texto', valor: (l) => l.porte },
    { id: 'quantidade', titulo: 'Quantidade', tipo: 'numero', valor: (l) => l.quantidade },
    { id: 'estimado', titulo: 'Unit. estimado', tipo: 'unitario', valor: (l) => l.estimado },
    { id: 'preco', titulo: 'Unit. adjudicado', tipo: 'unitario', valor: (l) => l.preco },
    { id: 'desvio_mediana', titulo: 'Desvio da mediana', tipo: 'percentual', valor: (l) => l.desvio_mediana,
      render: (l) => <span className={cn('tabular', l.sinalizado && 'font-semibold text-warning')}>{formatarPercentual(l.desvio_mediana)}{l.sinalizado && ' ⚑'}</span> },
  ]

  if (q.isLoading) return <EstadoPainel q={q}>{() => null}</EstadoPainel>
  if (q.isError || !q.data) return <EstadoPainel q={q}>{() => null}</EstadoPainel>
  const d = q.data
  const e = d.estatisticas
  const un = d.unidades.find((u) => u.id === d.unidade_id)
  const grupo = (titulo: string, dados: Grupo[]) => (
    <Card>
      <CardHeader><div><CardTitle>{titulo}</CardTitle><p className="mt-0.5 text-xs text-muted-foreground">Mediana do grupo e desvio em relação à mediana geral</p></div></CardHeader>
      <CardContent className="max-h-[320px] overflow-auto">
        <MiniTabela cabecalho={['', 'Ref.', 'Mín.', 'Mediana', 'Máx.', 'Desvio']} direita={[1, 2, 3, 4, 5]}
          linhas={dados.map((g) => [String(g.nome), formatarNumero(g.n), formatarUnitario(g.minimo), formatarUnitario(g.mediana), formatarUnitario(g.maximo), formatarPercentual(g.desvio_mediana)])} />
      </CardContent>
    </Card>
  )

  return (
    <div className={cn('space-y-4 transition-opacity', q.isFetching && 'opacity-70')}>
      <Card>
        <CardContent className="pt-5">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div className="min-w-0">
              <button className="mb-1 inline-flex items-center gap-1 text-xs text-primary hover:underline" onClick={aoVoltar}><ArrowLeft className="size-3.5" />Visão geral</button>
              <p className="text-xs text-muted-foreground">{d.item.codigo}{d.item.classe ? ` · ${d.item.classe}` : ''}</p>
              <h2 className="text-base font-semibold">{d.item.descricao}</h2>
              {d.item.especificacao && <p className="mt-1 text-xs text-muted-foreground">{d.item.especificacao}</p>}
            </div>
            <div className="flex flex-wrap items-end gap-3">
              <label className="flex items-center gap-1.5 text-xs text-muted-foreground">Unidade
                <select className="h-8 rounded-md border border-input bg-surface px-2 text-xs text-foreground" value={d.unidade_id ?? ''} onChange={(x) => aoTrocarUnidade(x.target.value ? Number(x.target.value) : null)}>
                  {d.unidades.map((u) => <option key={u.id ?? 'ni'} value={u.id ?? ''}>{u.sigla} ({u.referencias})</option>)}
                </select>
              </label>
              <SeletorJanela valor={janela ?? d.criterios.janela_meses ?? 0} aoMudar={setJanela} />
              <form className="flex items-center gap-1.5 text-xs text-muted-foreground" onSubmit={(x) => { x.preventDefault(); setFaixa({ min: qMin, max: qMax }) }}>
                Quantidade
                <Input aria-label="Quantidade mínima" className="h-8 w-20 text-xs" inputMode="decimal" placeholder="mín." value={qMin} onChange={(x) => setQMin(x.target.value.replace(/[^\d.]/g, ''))} />
                <Input aria-label="Quantidade máxima" className="h-8 w-20 text-xs" inputMode="decimal" placeholder="máx." value={qMax} onChange={(x) => setQMax(x.target.value.replace(/[^\d.]/g, ''))} />
                <Button type="submit" size="sm" variant="outline">Aplicar</Button>
              </form>
            </div>
          </div>
        </CardContent>
      </Card>

      <GradeKpis colunas="xl:grid-cols-4 2xl:grid-cols-8" itens={[
        { rotulo: 'Referências', valor: e.n, tipo: 'numero', destaque: true },
        { rotulo: `Mínimo (${un?.sigla ?? 'un.'})`, valor: e.minimo, tipo: 'moeda' },
        { rotulo: 'Mediana', valor: e.mediana, tipo: 'moeda', dica: 'Valor central dos preços: metade das compras ficou abaixo e metade acima.' },
        { rotulo: 'Média', valor: e.media, tipo: 'moeda' },
        { rotulo: 'Máximo', valor: e.maximo, tipo: 'moeda' },
        { rotulo: 'Desvio padrão', valor: e.desvio, tipo: 'moeda', dica: 'Desvio padrão amostral dos preços.' },
        { rotulo: 'Coeficiente de variação', valor: e.cv, tipo: 'percentual', dica: 'Desvio padrão / média × 100.' },
        { rotulo: 'Variação mín.→máx.', valor: e.variacao, tipo: 'percentual', dica: '(máximo − mínimo) / mínimo × 100.' },
      ]} />

      <section aria-label="Alertas de preço" className="space-y-2">
        {d.alertas.length === 0 ? <Nota className="mb-0">Nenhum alerta para os critérios atuais.</Nota> : d.alertas.map((a) => (
          <div key={a.tipo + a.mensagem} className={cn('flex gap-2 rounded-md border p-3 text-xs', a.nivel === 'atencao' ? 'border-warning/50 bg-warning/10' : 'bg-muted/40')}>
            {a.nivel === 'atencao' ? <AlertTriangle className="mt-0.5 size-4 shrink-0 text-warning" aria-hidden /> : <Info className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden />}
            <p><strong>{a.mensagem}</strong> <span className="text-muted-foreground">{a.aviso}</span></p>
          </div>
        ))}
      </section>

      <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
        <LinhasAno titulo="Evolução do preço unitário" subtitulo={`Mínimo, mediana e máximo por ano (R$ por ${un?.sigla ?? 'unidade'})`}
          dados={d.evolucao.map((g) => ({ ano: Number(g.chave), minimo: g.minimo, mediana: g.mediana, maximo: g.maximo }))} formatar={formatarUnitario} sufixoEixo=""
          series={[{ chave: 'minimo', nome: 'Mínimo', cor: SERIES[1] }, { chave: 'mediana', nome: 'Mediana', cor: SERIES[0] }, { chave: 'maximo', nome: 'Máximo', cor: SERIES[2] }]} />
        <Card>
          <CardHeader><div><CardTitle>Estadual × nacional</CardTitle><p className="mt-0.5 text-xs text-muted-foreground">Mediana estadual frente à referência nacional</p></div></CardHeader>
          <CardContent className="space-y-3 text-xs">
            <div className="grid grid-cols-2 gap-3">
              <div className="rounded-md border p-3"><p className="text-muted-foreground">Estadual (mediana)</p><p className="mt-1 text-lg font-semibold tabular">{formatarUnitario(e.mediana)}</p><p className="text-muted-foreground">{formatarNumero(e.n)} referências</p></div>
              <div className="rounded-md border border-dashed p-3"><p className="text-muted-foreground">Nacional</p><p className="mt-1 text-lg font-semibold text-muted-foreground">—</p><Badge cor="neutro">Fonte não integrada</Badge></div>
            </div>
            <p className="text-muted-foreground">{d.estadual_nacional.mensagem}</p>
          </CardContent>
        </Card>
        {grupo('Por órgão', d.por_orgao)}
        {grupo('Por município comprador', d.por_municipio)}
        {grupo('Por fornecedor', d.por_fornecedor)}
        {grupo('Por ano', d.evolucao)}
      </div>

      <Card>
        <CardHeader><div><CardTitle>Referências de preço</CardTitle><p className="mt-0.5 text-xs text-muted-foreground">Compras usadas no cálculo · ⚑ = desvio da mediana acima do limite, para análise</p></div></CardHeader>
        <CardContent>
          <TabelaAvancada<Referencia> colunas={colunas} dados={d.referencias} pesquisarEm={(l) => `${l.orgao} ${l.fornecedor} ${l.numero_publicacao} ${l.municipio}`}
            podeExportar={pode('relatorios.exportar')} cabecalhoExportacao={cabecalho(`Referências de preço: ${d.item.codigo} ${d.item.descricao} (${un?.sigla ?? ''})`)}
            selecionada={(l) => l.sinalizado} />
          <p className="mt-2 text-[11px] text-muted-foreground">Data de referência: {formatarData(d.criterios.data_referencia)} · janela: {d.criterios.janela_meses ? `${d.criterios.janela_meses} meses` : 'todo o histórico'}
            {d.criterios.quantidade_min !== null || d.criterios.quantidade_max !== null ? ` · quantidade de ${d.criterios.quantidade_min ?? '—'} a ${d.criterios.quantidade_max ?? '—'}` : ''}. {d.aviso}</p>
        </CardContent>
      </Card>
    </div>
  )
}
