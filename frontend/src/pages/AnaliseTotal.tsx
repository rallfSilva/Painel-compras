import { Fragment, useMemo, useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { ChevronRight, ListTree, RotateCcw, Table2 } from 'lucide-react'
import { paraQueryString, useFiltros } from '@/contexts/FiltrosContext'
import { BarraFiltros } from '@/components/filters/BarraFiltros'
import { Abas, CabecalhoModulo } from '@/components/compras/CabecalhoModulo'
import { ListaBarras, type Agrupamento } from '@/components/compras/ListaBarras'
import { EstadoPainel, GradeKpis, Nota, useCabecalhoExportacao, usePainel } from '@/components/inteligencia/partes'
import { TabelaItensAdjudicados } from '@/components/inteligencia/TabelaItensAdjudicados'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Dialogo } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { MiniTabela } from '@/components/detalhes/Partes'
import { useDetalhes } from '@/components/detalhes/useDetalhes'
import { api } from '@/lib/api'
import type { NomeFiltro } from '@/lib/tipos'
import { formatarMoeda, formatarNumero, formatarPercentual, formatarUnitario } from '@/lib/format'
import { cn } from '@/lib/utils'

interface Dimensao { codigo: string; nome: string }
interface No { valor: string | null; nome: string; contratado: number; percentual: number | null; processos: number; itens: number; filtro: NomeFiltro | null; chave: string | null; agrupado?: boolean }
interface Nivel { dimensao: string; nome_dimensao: string; total: number; nos: No[] }
interface Uc { id: number; nome: string; orgao: string | null; contratado: number; percentual_superior: number | null; percentual: number | null; processos: number; fornecedores: number; chave: string }
interface Us { id: number | null; sigla: string; nome: string | null; contratado: number; percentual: number | null; processos: number; chave: string | null; unidades: Uc[] }
interface ClasseAmparo { id: number | null; nome: string; itens: number; contratado: number; percentual_amparo: number | null; chave: string | null }
interface Amparo { id: number | null; nome: string; itens: number; contratado: number; percentual: number | null; chave: string | null; classes: ClasseAmparo[] }
interface Recorrente { id: number; nome: string; codigo: string | null; unidade: string | null; processos: number; orgaos: number; anos: number; percentual_rp: number | null; contratado: number; quantidade: number | null; chave: string }
interface Indice { hhi: number | null; maior: number | null; cinco: number | null }
interface ClasseConcentracao extends Indice { id: number | null; nome: string; contratado: number; fornecedores: number; maior_fornecedor: string | null; maior_fornecedor_id: number; sinalizada: boolean }
interface Variacao { item_id: number; unidade_id: number | null; unidade: string | null; codigo: string | null; descricao: string; n: number; minimo: number; maximo: number; mediana: number; cv: number | null; variacao: number | null }
interface PainelTotal {
  dimensoes: Dimensao[]; sequencia_padrao: string[]; unidades: Us[]; amparo_classe: Amparo[]
  recorrentes: { minimo_processos: number; itens: Recorrente[] }
  concentracao: { limite: number; geral: Indice & { fornecedores: number; maior_fornecedor: string | null }; classes: ClasseConcentracao[]; observacao: string }
  variacoes_preco: Variacao[]
}
interface Rankings {
  limites: Record<string, number>; orgaos: Agrupamento[]; fornecedores: Agrupamento[]; itens: Agrupamento[]; classes: Agrupamento[]
  municipios: Agrupamento[]; amparos: Agrupamento[]; processos: { id: number; nome: string; objeto: string | null; contratado: number; percentual: number | null }[]
}

type Aba = 'arvore' | 'unidades' | 'amparo' | 'rankings' | 'recorrencia' | 'precos'

/** Módulo 10 — Análise do Total Comprado (seção 19). */
export function AnaliseTotal() {
  const { estado } = useFiltros()
  const consulta = paraQueryString(estado)
  const [aba, setAba] = useState<Aba>('arvore')
  const q = usePainel<PainelTotal>('analise-total', '/inteligencia/analise-total', consulta)

  return (
    <div>
      <CabecalhoModulo titulo="Análise do Total Comprado" descricao="Decomposição do valor contratado, unidades, amparo legal, rankings, recorrência, concentração e variação de preços." consulta={consulta} />
      <BarraFiltros />
      <Abas<Aba> abas={[
        { id: 'arvore', rotulo: 'Árvore de decomposição' }, { id: 'unidades', rotulo: 'Unidades' }, { id: 'amparo', rotulo: 'Amparo legal × classe' },
        { id: 'rankings', rotulo: 'Rankings' }, { id: 'recorrencia', rotulo: 'Recorrência e concentração' }, { id: 'precos', rotulo: 'Variação de preços' },
      ]} ativa={aba} aoMudar={setAba} />
      {aba === 'rankings' ? <PainelRankings consulta={consulta} /> : (
        <EstadoPainel q={q}>
          {(d) => (
            <>
              {aba === 'arvore' && <Decomposicao consulta={consulta} dimensoes={d.dimensoes} sequencia={d.sequencia_padrao} />}
              {aba === 'unidades' && <TabelaUnidades dados={d.unidades} />}
              {aba === 'amparo' && <TabelaAmparo dados={d.amparo_classe} />}
              {aba === 'recorrencia' && <Recorrencia d={d} />}
              {aba === 'precos' && <Variacoes dados={d.variacoes_preco} />}
            </>
          )}
        </EstadoPainel>
      )}
    </div>
  )
}

// ---------------------------------------------------------------- Árvore de decomposição (19.1)

interface Passo { dimensao: string; selecionado?: { valor: string; nome: string } }

const textoCaminho = (passos: Passo[]) => passos.filter((p) => p.selecionado).map((p) => `${p.dimensao}:${p.selecionado!.valor}`).join(',')

function Decomposicao({ consulta, dimensoes, sequencia }: { consulta: string; dimensoes: Dimensao[]; sequencia: string[] }) {
  const cabecalho = useCabecalhoExportacao()
  const [passos, setPassos] = useState<Passo[]>([{ dimensao: sequencia[0] }])
  const [registros, setRegistros] = useState<Passo[] | null>(null)
  const nomeDim = (c: string) => dimensoes.find((d) => d.codigo === c)?.nome ?? c

  const proximaDimensao = (usadas: string[]) => sequencia.find((s) => !usadas.includes(s)) ?? dimensoes.find((d) => !usadas.includes(d.codigo))?.codigo

  const selecionar = (i: number, no: No) => {
    const valor = no.valor ?? 'nulo'
    setPassos((ps) => {
      const novos = ps.slice(0, i + 1).map((p, k) => (k === i ? { ...p, selecionado: { valor, nome: no.nome } } : p))
      const prox = proximaDimensao(novos.map((p) => p.dimensao))
      return prox ? [...novos, { dimensao: prox }] : novos
    })
  }
  const trocarDimensao = (i: number, dimensao: string) => setPassos((ps) => [...ps.slice(0, i), { dimensao }])
  const caminhoSelecionado = passos.filter((p) => p.selecionado)

  return (
    <Card>
      <CardHeader className="flex-wrap gap-3">
        <div>
          <CardTitle className="flex items-center gap-2"><ListTree className="size-4" aria-hidden />Árvore de decomposição do valor contratado</CardTitle>
          <p className="mt-0.5 text-xs text-muted-foreground">Clique num nó para abrir o próximo nível. Troque a dimensão de qualquer nível no seletor. A agregação é feita no servidor, nível a nível.</p>
        </div>
        <div className="flex flex-wrap gap-2">
          {caminhoSelecionado.length > 0 && (
            <Button size="sm" onClick={() => setRegistros(caminhoSelecionado)}><Table2 className="size-3.5" />Ver registros do nó</Button>
          )}
          <Button size="sm" variant="outline" onClick={() => setPassos([{ dimensao: sequencia[0] }])}><RotateCcw className="size-3.5" />Reiniciar</Button>
        </div>
      </CardHeader>
      <CardContent>
        <nav aria-label="Caminho da decomposição" className="mb-3 flex flex-wrap items-center gap-1 text-xs">
          <span className="font-medium">Total</span>
          {caminhoSelecionado.map((p) => (
            <Fragment key={p.dimensao}><ChevronRight className="size-3.5 text-muted-foreground" aria-hidden /><span><span className="text-muted-foreground">{nomeDim(p.dimensao)}:</span> {p.selecionado!.nome}</span></Fragment>
          ))}
        </nav>
        <div className="flex flex-col gap-3 md:flex-row md:overflow-x-auto md:pb-2">
          {passos.map((p, i) => (
            <ColunaNivel key={`${i}-${p.dimensao}-${textoCaminho(passos.slice(0, i))}`} consulta={consulta} caminho={textoCaminho(passos.slice(0, i))}
              dimensao={p.dimensao} selecionado={p.selecionado?.valor} opcoes={dimensoes.filter((d) => d.codigo === p.dimensao || !passos.slice(0, i).some((x) => x.dimensao === d.codigo))}
              aoTrocar={(d) => trocarDimensao(i, d)} aoSelecionar={(no) => selecionar(i, no)} />
          ))}
        </div>
      </CardContent>
      <Dialogo aberto={!!registros} aoFechar={() => setRegistros(null)} titulo="Registros do nó"
        descricao={registros?.map((p) => `${nomeDim(p.dimensao)}: ${p.selecionado!.nome}`).join(' › ')}>
        {registros && (
          <div className="p-5">
            <TabelaItensAdjudicados consulta={`${consulta}${consulta ? '&' : ''}caminho=${encodeURIComponent(textoCaminho(registros))}`}
              cabecalho={cabecalho('Itens do nó da árvore de decomposição', registros.map((p) => `${nomeDim(p.dimensao)}: ${p.selecionado!.nome}`))} />
          </div>
        )}
      </Dialogo>
    </Card>
  )
}

function ColunaNivel({ consulta, caminho, dimensao, selecionado, opcoes, aoTrocar, aoSelecionar }: {
  consulta: string; caminho: string; dimensao: string; selecionado?: string; opcoes: Dimensao[]; aoTrocar: (d: string) => void; aoSelecionar: (no: No) => void
}) {
  const q = useQuery({
    queryKey: ['decomposicao', consulta, caminho, dimensao],
    queryFn: () => api<Nivel>(`/inteligencia/analise-total/decomposicao?dimensao=${dimensao}&caminho=${encodeURIComponent(caminho)}${consulta ? `&${consulta}` : ''}`),
    placeholderData: keepPreviousData,
  })
  const max = Math.max(1, ...(q.data?.nos.map((n) => n.contratado) ?? [1]))
  return (
    <section className="w-full shrink-0 rounded-md border bg-muted/20 p-2 md:w-72" aria-label={`Nível: ${opcoes.find((o) => o.codigo === dimensao)?.nome}`}>
      <div className="mb-2 flex items-center justify-between gap-2">
        <select value={dimensao} onChange={(e) => aoTrocar(e.target.value)} aria-label="Dimensão do nível"
          className="h-8 min-w-0 flex-1 rounded-md border border-input bg-surface px-2 text-xs font-medium">
          {opcoes.map((o) => <option key={o.codigo} value={o.codigo}>{o.nome}</option>)}
        </select>
        {q.data && <span className="shrink-0 text-[11px] tabular text-muted-foreground">{formatarMoeda(q.data.total)}</span>}
      </div>
      {q.isLoading ? <p className="py-6 text-center text-xs text-muted-foreground">Carregando…</p>
        : q.isError ? <p className="p-2 text-xs text-danger">{(q.error as Error).message}</p>
          : !q.data?.nos.length ? <p className="py-6 text-center text-xs text-muted-foreground">Sem valores neste nível.</p> : (
            <ul className={cn('space-y-1', q.isFetching && 'opacity-70')}>
              {q.data.nos.map((no) => {
                const ativo = selecionado !== undefined && selecionado === (no.valor ?? 'nulo') && !no.agrupado
                return (
                  <li key={`${no.valor}-${no.nome}`}>
                    <button type="button" disabled={no.agrupado} aria-pressed={ativo} onClick={() => aoSelecionar(no)}
                      className={cn('block w-full rounded px-2 py-1.5 text-left text-xs', ativo ? 'bg-accent ring-1 ring-primary' : 'hover:bg-muted', no.agrupado && 'cursor-default opacity-80')}>
                      <span className="flex items-baseline justify-between gap-2">
                        <span className="min-w-0 truncate font-medium" title={no.nome}>{no.nome}</span>
                        <span className="shrink-0 tabular text-muted-foreground">{formatarPercentual(no.percentual)}</span>
                      </span>
                      <span className="mt-1 block h-1.5 rounded-full bg-muted" aria-hidden>
                        <span className="block h-1.5 rounded-full" style={{ width: `${(no.contratado / max) * 100}%`, background: no.agrupado ? 'var(--serie-neutra)' : 'var(--serie-1)' }} />
                      </span>
                      <span className="mt-0.5 block tabular text-[11px] text-muted-foreground">{formatarMoeda(no.contratado)} · {formatarNumero(no.processos)} proc.</span>
                    </button>
                  </li>
                )
              })}
            </ul>
          )}
    </section>
  )
}

// ---------------------------------------------------------------- Unidade superior × contratante (19.2) e Amparo × classe (19.3)

function TabelaHierarquica<P, F>({ titulo, subtitulo, cabecalho, pais, filhos, linhaPai, linhaFilho, aoClicarPai, aoClicarFilho }: {
  titulo: string; subtitulo: string; cabecalho: string[]; pais: P[]; filhos: (p: P) => F[]
  linhaPai: (p: P) => string[]; linhaFilho: (f: F) => string[]; aoClicarPai?: (p: P) => void; aoClicarFilho?: (f: F) => void
}) {
  const [abertos, setAbertos] = useState<Set<number>>(() => new Set([0]))
  return (
    <Card>
      <CardHeader><div><CardTitle>{titulo}</CardTitle><p className="mt-0.5 text-xs text-muted-foreground">{subtitulo}</p></div></CardHeader>
      <CardContent className="overflow-x-auto">
        <table className="w-full min-w-[640px] text-xs">
          <thead><tr className="text-left text-muted-foreground">{cabecalho.map((c, k) => <th key={c} className={cn('px-2 py-1.5 font-medium', k > 0 && 'text-right')}>{c}</th>)}</tr></thead>
          <tbody>
            {pais.map((p, i) => {
              const aberto = abertos.has(i)
              const lp = linhaPai(p)
              return (
                <Fragment key={i}>
                  <tr className="border-t bg-muted/30 font-semibold">
                    <td className="px-2 py-2">
                      <button className="flex items-center gap-1 text-left" aria-expanded={aberto}
                        onClick={() => setAbertos((s) => { const n = new Set(s); if (aberto) n.delete(i); else n.add(i); return n })}>
                        <ChevronRight className={cn('size-3.5 transition-transform', aberto && 'rotate-90')} aria-hidden />{lp[0]}
                      </button>
                      {aoClicarPai && <button className="ml-5 text-[11px] font-normal text-primary hover:underline" onClick={() => aoClicarPai(p)}>filtrar</button>}
                    </td>
                    {lp.slice(1).map((v, k) => <td key={k} className="px-2 py-2 text-right tabular">{v}</td>)}
                  </tr>
                  {aberto && filhos(p).map((f, j) => {
                    const lf = linhaFilho(f)
                    return (
                      <tr key={j} className={cn('border-t', aoClicarFilho && 'cursor-pointer hover:bg-muted/60')} onClick={aoClicarFilho ? () => aoClicarFilho(f) : undefined}>
                        <td className="py-1.5 pl-7 pr-2">{lf[0]}</td>
                        {lf.slice(1).map((v, k) => <td key={k} className="px-2 py-1.5 text-right tabular">{v}</td>)}
                      </tr>
                    )
                  })}
                </Fragment>
              )
            })}
          </tbody>
        </table>
      </CardContent>
    </Card>
  )
}

function TabelaUnidades({ dados }: { dados: Us[] }) {
  const { aplicarCruzado } = useFiltros()
  const abrir = useDetalhes()
  return (
    <TabelaHierarquica titulo="Unidade superior × unidade contratante" subtitulo="Clique na seta para abrir as unidades · clique numa unidade para abrir a ficha"
      cabecalho={['Unidade', 'Contratado', '% na superior', '% do total', 'Processos', 'Fornecedores']} pais={dados} filhos={(p) => p.unidades}
      linhaPai={(p) => [p.nome ? `${p.sigla} · ${p.nome}` : p.sigla, formatarMoeda(p.contratado), '100,00%', formatarPercentual(p.percentual), formatarNumero(p.processos), '']}
      linhaFilho={(u) => [`${u.nome}${u.orgao ? ` (${u.orgao})` : ''}`, formatarMoeda(u.contratado), formatarPercentual(u.percentual_superior), formatarPercentual(u.percentual), formatarNumero(u.processos), formatarNumero(u.fornecedores)]}
      aoClicarPai={(p) => p.chave && aplicarCruzado('unidade', p.chave, p.sigla)} aoClicarFilho={(u) => abrir('unidade', u.id)} />
  )
}

function TabelaAmparo({ dados }: { dados: Amparo[] }) {
  const { aplicarCruzado } = useFiltros()
  return (
    <TabelaHierarquica titulo="Amparo legal › classe" subtitulo="Nº de itens e total contratado por amparo legal e classe · clique numa classe para filtrar o painel"
      cabecalho={['Amparo legal / classe', 'Itens', 'Contratado', '% no amparo', '% do total']} pais={dados} filhos={(p) => p.classes}
      linhaPai={(p) => [p.nome, formatarNumero(p.itens), formatarMoeda(p.contratado), '100,00%', formatarPercentual(p.percentual)]}
      linhaFilho={(c) => [c.nome, formatarNumero(c.itens), formatarMoeda(c.contratado), formatarPercentual(c.percentual_amparo), '']}
      aoClicarPai={(p) => p.chave && aplicarCruzado('amparo', p.chave, p.nome)} aoClicarFilho={(c) => c.chave && aplicarCruzado('classe', c.chave, c.nome)} />
  )
}

// ---------------------------------------------------------------- Rankings (19.4)

const RANKINGS: { chave: keyof Omit<Rankings, 'limites' | 'processos'> | 'processos'; titulo: string }[] = [
  { chave: 'orgaos', titulo: 'Órgãos' }, { chave: 'fornecedores', titulo: 'Fornecedores' }, { chave: 'classes', titulo: 'Classes' },
  { chave: 'municipios', titulo: 'Municípios compradores' }, { chave: 'amparos', titulo: 'Amparos legais' }, { chave: 'processos', titulo: 'Processos' },
  { chave: 'itens', titulo: 'Itens' },
]
const PADRAO: Record<string, number> = { orgaos: 10, fornecedores: 20, itens: 50, classes: 20, municipios: 10, processos: 10, amparos: 10 }

function PainelRankings({ consulta }: { consulta: string }) {
  const abrir = useDetalhes()
  const [limites, setLimites] = useState(PADRAO)
  const extra = useMemo(() => Object.entries(limites).map(([k, v]) => `limite_${k}=${v}`).join('&'), [limites])
  const q = usePainel<Rankings>('rankings', `/inteligencia/analise-total/rankings?${extra}`, consulta)
  return (
    <EstadoPainel q={q}>
      {(d) => (
        <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
          {RANKINGS.map((r) => (
            <Card key={r.chave} className={cn(r.chave === 'itens' && 'xl:col-span-2')}>
              <CardHeader className="flex-wrap gap-2">
                <div><CardTitle>Top {d.limites[r.chave]} · {r.titulo}</CardTitle><p className="mt-0.5 text-xs text-muted-foreground">Por valor contratado{r.chave !== 'processos' ? ' · clique para filtrar' : ' · clique para abrir'}</p></div>
                <label className="flex items-center gap-1.5 text-xs text-muted-foreground">Mostrar
                  <select className="h-8 rounded-md border border-input bg-surface px-2 text-xs text-foreground" value={limites[r.chave]}
                    onChange={(e) => setLimites((l) => ({ ...l, [r.chave]: Number(e.target.value) }))}>
                    {[5, 10, 20, 50, 100].map((n) => <option key={n} value={n}>{n}</option>)}
                  </select>
                </label>
              </CardHeader>
              <CardContent className={cn(r.chave === 'itens' ? 'max-h-[560px] overflow-auto' : 'max-h-[420px] overflow-auto')}>
                {r.chave === 'processos' ? (
                  <MiniTabela cabecalho={['Processo', 'Objeto', 'Contratado', '%']} direita={[2, 3]}
                    linhas={d.processos.map((p) => [p.nome, <span key="o" className="line-clamp-2" title={p.objeto ?? undefined}>{p.objeto}</span>, formatarMoeda(p.contratado), formatarPercentual(p.percentual)])}
                    aoClicar={(k) => abrir('processo', d.processos[k].id)} />
                ) : <ListaBarras dados={d[r.chave]} />}
              </CardContent>
            </Card>
          ))}
        </div>
      )}
    </EstadoPainel>
  )
}

// ---------------------------------------------------------------- Recorrência, concentração e preços

function Recorrencia({ d }: { d: PainelTotal }) {
  const { aplicarCruzado } = useFiltros()
  const abrir = useDetalhes()
  const c = d.concentracao
  return (
    <div className="space-y-4">
      <GradeKpis colunas="xl:grid-cols-4" itens={[
        { rotulo: 'HHI dos fornecedores', valor: c.geral.hhi, tipo: 'numero', dica: 'Índice Herfindahl-Hirschman: soma dos quadrados das participações (%) dos fornecedores no valor contratado. Vai de 0 a 10.000.' },
        { rotulo: 'Participação do maior fornecedor', valor: c.geral.maior, tipo: 'percentual', nota: c.geral.maior_fornecedor ?? undefined },
        { rotulo: 'Participação dos 5 maiores', valor: c.geral.cinco, tipo: 'percentual' },
        { rotulo: 'Fornecedores com contratação', valor: c.geral.fornecedores, tipo: 'numero' },
      ]} />
      <Nota>{c.observacao}</Nota>
      <Card>
        <CardHeader><div><CardTitle>Concentração por classe</CardTitle><p className="mt-0.5 text-xs text-muted-foreground">Classes com maior valor · sinalizadas quando um fornecedor tem {formatarPercentual(c.limite)} ou mais do valor da classe</p></div></CardHeader>
        <CardContent className="max-h-[440px] overflow-auto">
          <MiniTabela cabecalho={['Classe', 'Contratado', 'Fornecedores', 'Maior fornecedor', '% do maior', '% dos 5 maiores', 'HHI', '']} direita={[1, 2, 4, 5, 6]}
            linhas={c.classes.map((x) => [x.nome, formatarMoeda(x.contratado), formatarNumero(x.fornecedores),
              <button key="f" className="text-left text-primary hover:underline" onClick={(e) => { e.stopPropagation(); abrir('fornecedor', x.maior_fornecedor_id, x.maior_fornecedor ?? undefined) }}>{x.maior_fornecedor}</button>,
              formatarPercentual(x.maior), formatarPercentual(x.cinco), formatarNumero(x.hhi), x.sinalizada ? <Badge key="s" cor="alerta">Analisar</Badge> : ''])}
            aoClicar={(k) => { const x = c.classes[k]; if (x.id) aplicarCruzado('classe', `c:${x.id}`, x.nome) }} />
        </CardContent>
      </Card>
      <Card>
        <CardHeader><div><CardTitle>Compras recorrentes</CardTitle>
          <p className="mt-0.5 text-xs text-muted-foreground">Mesmo item e unidade de fornecimento em {d.recorrentes.minimo_processos} processos ou mais. Pode indicar oportunidade de planejamento conjunto ou de registro de preços; não é conclusão sobre fracionamento.</p></div></CardHeader>
        <CardContent className="max-h-[440px] overflow-auto">
          <MiniTabela cabecalho={['Item', 'Un.', 'Processos', 'Órgãos', 'Anos', '% em RP', 'Quantidade', 'Contratado']} direita={[2, 3, 4, 5, 6, 7]}
            linhas={d.recorrentes.itens.map((i) => [`${i.codigo ?? ''} ${i.nome}`.trim(), i.unidade ?? '—', formatarNumero(i.processos), formatarNumero(i.orgaos),
              formatarNumero(i.anos), formatarPercentual(i.percentual_rp), formatarNumero(i.quantidade), formatarMoeda(i.contratado)])}
            aoClicar={(k) => aplicarCruzado('item', d.recorrentes.itens[k].chave, d.recorrentes.itens[k].nome)} vazio="Nenhuma compra recorrente com os filtros atuais." />
        </CardContent>
      </Card>
    </div>
  )
}

function Variacoes({ dados }: { dados: Variacao[] }) {
  const navegar = useNavigate()
  return (
    <Card>
      <CardHeader><div><CardTitle>Itens com maior variação de preço</CardTitle>
        <p className="mt-0.5 text-xs text-muted-foreground">Por item e unidade de fornecimento, na janela de análise de preços, ordenados pelo coeficiente de variação · clique para abrir a análise de preços do item</p></div></CardHeader>
      <CardContent className="max-h-[520px] overflow-auto">
        <MiniTabela cabecalho={['Item', 'Un.', 'Referências', 'Mínimo', 'Mediana', 'Máximo', 'Variação mín.→máx.', 'CV']} direita={[2, 3, 4, 5, 6, 7]}
          linhas={dados.map((v) => [`${v.codigo ?? ''} ${v.descricao}`.trim(), v.unidade ?? '—', formatarNumero(v.n), formatarUnitario(v.minimo), formatarUnitario(v.mediana),
            formatarUnitario(v.maximo), formatarPercentual(v.variacao), formatarPercentual(v.cv)])}
          aoClicar={(k) => navegar(`/analise-precos?item=${dados[k].item_id}${dados[k].unidade_id ? `&unidade=${dados[k].unidade_id}` : ''}`)} vazio="Sem preços comparáveis com os filtros atuais." />
        <p className="mt-3 text-[11px] text-muted-foreground">Variação e dispersão altas pedem análise humana (especificação, quantidade, local de entrega, data). Não constituem conclusão sobre sobrepreço.</p>
      </CardContent>
    </Card>
  )
}
