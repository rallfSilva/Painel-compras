import type { ReactNode } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Info, Loader2 } from 'lucide-react'
import {
  Bar, BarChart, CartesianGrid, Cell, Legend, Line, LineChart, Pie, PieChart, ResponsiveContainer, Tooltip, Treemap, XAxis, YAxis,
} from 'recharts'
import { api } from '@/lib/api'
import { formatarAbreviado, formatarMoeda, formatarNumero, formatarPercentual, NAO_INFORMADO } from '@/lib/format'
import { CartaoGrafico, COR, DicaGrafico, EIXO, Tabela } from '@/components/dashboard/Graficos'
import { Dica } from '@/components/ui/tooltip'
import { descreverFiltros, useFiltros } from '@/contexts/FiltrosContext'
import { useMeta } from '@/components/layout/Cabecalho'
import type { Cabecalho } from '@/lib/exportar'
import type { NomeFiltro } from '@/lib/tipos'
import { cn } from '@/lib/utils'

/** Paleta categórica em ordem fixa; "neutra" fica para Não informado / Demais. */
export const SERIES = [COR.s1, COR.s2, COR.s3, COR.neutra]

export function usePainel<T>(chave: string, caminho: string, consulta: string, ativo = true) {
  return useQuery({
    queryKey: [chave, caminho, consulta],
    queryFn: () => api<T>(`${caminho}${caminho.includes('?') ? '&' : '?'}${consulta}`),
    placeholderData: keepPreviousData,
    enabled: ativo,
  })
}

/** Cabeçalho das exportações com os filtros ativos e o aviso de dados fictícios. */
export function useCabecalhoExportacao() {
  const { estado } = useFiltros()
  const { data: meta } = useMeta()
  return (titulo: string, extra: string[] = []): Cabecalho => ({ titulo, filtros: [...descreverFiltros(estado), ...extra], ficticio: meta?.possui_dados_ficticios })
}

/** Carregamento, erro e conteúdo de uma consulta de painel. */
export function EstadoPainel<T>({ q, children }: { q: { isLoading: boolean; isError: boolean; error: unknown; data?: T; isFetching: boolean }; children: (d: T) => ReactNode }) {
  if (q.isLoading) return <div className="flex h-40 items-center justify-center"><Loader2 className="size-6 animate-spin text-primary" /></div>
  if (q.isError) return <p className="rounded-md border border-danger/40 bg-danger/10 p-3 text-xs">Não foi possível carregar o painel: {(q.error as Error)?.message}</p>
  if (!q.data) return null
  return <div className={cn('transition-opacity', q.isFetching && 'opacity-70')}>{children(q.data)}</div>
}

type TipoKpi = 'moeda' | 'numero' | 'percentual'
const formatarKpi = (t: TipoKpi, v: number | null | undefined) =>
  v === null || v === undefined ? NAO_INFORMADO : t === 'moeda' ? formatarMoeda(v) : t === 'percentual' ? formatarPercentual(v) : formatarNumero(v)

export interface DefKpi { rotulo: string; valor: number | null | undefined; tipo: TipoKpi; dica?: string; nota?: ReactNode; destaque?: boolean }

/** Cartão de indicador do módulo, com a fórmula em tooltip. */
export function Kpi({ rotulo, valor, tipo, dica, nota, destaque }: DefKpi) {
  return (
    <div className={cn('relative flex h-full flex-col rounded-lg border bg-card p-3.5 shadow-[0_1px_2px_rgba(15,30,60,0.06)]',
      destaque && 'border-destaque/50 before:absolute before:inset-y-3 before:left-0 before:w-[3px] before:rounded-r before:bg-destaque')}>
      <div className="flex items-start justify-between gap-2">
        <p className="text-[12px] font-medium leading-snug text-muted-foreground">{rotulo}</p>
        {dica && (
          <Dica conteudo={dica}>
            <span role="img" tabIndex={0} aria-label={`Como é calculado: ${rotulo}`} className="-m-1 rounded p-1 text-muted-foreground hover:text-foreground"><Info className="size-3.5" /></span>
          </Dica>
        )}
      </div>
      <p className={cn('mt-1.5 font-semibold tabular leading-tight', tipo === 'moeda' ? 'whitespace-nowrap text-[17px]' : 'text-[22px]',
        (valor === null || valor === undefined) && 'text-base font-medium text-muted-foreground')}>{formatarKpi(tipo, valor)}</p>
      {nota && <p className="mt-1.5 text-[11px] text-muted-foreground">{nota}</p>}
    </div>
  )
}

export function GradeKpis({ itens, colunas = 'xl:grid-cols-5' }: { itens: DefKpi[]; colunas?: string }) {
  return (
    <section aria-label="Indicadores do módulo" className={cn('mb-4 grid grid-cols-1 gap-3 min-[480px]:grid-cols-2 md:grid-cols-3', colunas)}>
      {itens.map((k) => <Kpi key={k.rotulo} {...k} />)}
    </section>
  )
}

export function Nota({ children, className }: { children: ReactNode; className?: string }) {
  return <p className={cn('mb-4 flex gap-2 rounded-md border bg-muted/40 p-3 text-xs text-muted-foreground', className)}><Info className="mt-0.5 size-3.5 shrink-0" aria-hidden />{children}</p>
}

const Vazio = () => <p className="py-10 text-center text-xs text-muted-foreground">Sem dados para os filtros atuais.</p>

export interface Serie { chave: string; nome: string; cor: string; valor?: string }

/** Barras 100% empilhadas por ano (participação de cada grupo no total do ano). */
export function Empilhado100<T extends { ano: number }>({ titulo, subtitulo, dados, series, className }: {
  titulo: string; subtitulo?: string; dados: T[]; series: Serie[]; className?: string
}) {
  const reg = dados as unknown as Record<string, number | null>[]
  return (
    <CartaoGrafico className={className} titulo={titulo} subtitulo={subtitulo}
      grafico={dados.length ? (
        <div className="h-[280px]">
          <ResponsiveContainer>
            <BarChart data={dados} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
              <CartesianGrid vertical={false} stroke={COR.grade} />
              <XAxis dataKey="ano" tick={EIXO} axisLine={false} tickLine={false} />
              <YAxis tick={EIXO} axisLine={false} tickLine={false} width={44} domain={[0, 100]} ticks={[0, 25, 50, 75, 100]} tickFormatter={(v) => `${v}%`} />
              <Tooltip cursor={{ fill: 'hsl(var(--muted))', opacity: 0.6 }} content={<DicaGrafico formatar={formatarPercentual} />} />
              <Legend iconType="square" iconSize={10} wrapperStyle={{ fontSize: 12 }} />
              {series.map((s, i) => (
                <Bar key={s.chave} dataKey={s.chave} name={s.nome} stackId="t" fill={s.cor} stroke="hsl(var(--card))" strokeWidth={1}
                  radius={i === series.length - 1 ? [4, 4, 0, 0] : undefined} maxBarSize={36} />
              ))}
            </BarChart>
          </ResponsiveContainer>
        </div>
      ) : <Vazio />}
      tabela={<Tabela cabecalho={['Ano', ...series.flatMap((s) => (s.valor ? [`% ${s.nome}`, s.nome] : [`% ${s.nome}`]))]}
        linhas={reg.map((d) => [d.ano as number, ...series.flatMap((s) => (s.valor ? [formatarPercentual(d[s.chave]), formatarMoeda(d[s.valor])] : [formatarPercentual(d[s.chave])]))])} />}
    />
  )
}

/** Linhas por ano (um único eixo; todas as séries com a mesma grandeza). */
export function LinhasAno<T extends { ano: number }>({ titulo, subtitulo, dados, series, formatar = formatarPercentual, sufixoEixo = '%', className, referencia }: {
  titulo: string; subtitulo?: string; dados: T[]; series: Serie[]; formatar?: (v: number | null) => string; sufixoEixo?: string; className?: string
  referencia?: { valor: number; rotulo: string }
}) {
  const reg = dados as unknown as Record<string, number | null>[]
  const comRef = referencia ? reg.map((d) => ({ ...d, _ref: referencia.valor })) : reg
  return (
    <CartaoGrafico className={className} titulo={titulo} subtitulo={subtitulo}
      grafico={dados.length ? (
        <div className="h-[280px]">
          <ResponsiveContainer>
            <LineChart data={comRef} margin={{ top: 16, right: 16, left: 0, bottom: 0 }}>
              <CartesianGrid vertical={false} stroke={COR.grade} />
              <XAxis dataKey="ano" tick={EIXO} axisLine={false} tickLine={false} />
              <YAxis tick={EIXO} axisLine={false} tickLine={false} width={48} tickFormatter={(v) => (sufixoEixo ? `${v}${sufixoEixo}` : formatarAbreviado(v, false))} />
              <Tooltip content={<DicaGrafico formatar={formatar} />} />
              <Legend iconType="plainline" wrapperStyle={{ fontSize: 12 }} />
              {series.map((s) => (
                <Line key={s.chave} type="monotone" dataKey={s.chave} name={s.nome} stroke={s.cor} strokeWidth={2}
                  dot={{ r: 4, strokeWidth: 2, fill: 'hsl(var(--card))' }} activeDot={{ r: 5 }} connectNulls={false} />
              ))}
              {referencia && <Line dataKey="_ref" name={referencia.rotulo} stroke={COR.neutra} strokeDasharray="5 4" strokeWidth={1.5} dot={false} activeDot={false} />}
            </LineChart>
          </ResponsiveContainer>
        </div>
      ) : <Vazio />}
      tabela={<Tabela cabecalho={['Ano', ...series.map((s) => s.nome), ...(referencia ? [referencia.rotulo] : [])]}
        linhas={reg.map((d) => [d.ano as number, ...series.map((s) => formatar(d[s.chave])), ...(referencia ? [formatar(referencia.valor)] : [])])} />}
    />
  )
}

/** Barras verticais por ano de uma única grandeza. */
export function BarrasAno<T extends { ano: number }>({ titulo, subtitulo, dados, chave, nome, formatar, abreviar = true, cor = COR.s1, className }: {
  titulo: string; subtitulo?: string; dados: T[]; chave: keyof T & string; nome: string; formatar: (v: number | null) => string; abreviar?: boolean; cor?: string; className?: string
}) {
  const reg = dados as unknown as Record<string, number | null>[]
  return (
    <CartaoGrafico className={className} titulo={titulo} subtitulo={subtitulo}
      grafico={dados.length ? (
        <div className="h-[240px]">
          <ResponsiveContainer>
            <BarChart data={dados} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
              <CartesianGrid vertical={false} stroke={COR.grade} />
              <XAxis dataKey="ano" tick={EIXO} axisLine={false} tickLine={false} />
              <YAxis tick={EIXO} axisLine={false} tickLine={false} width={56} tickFormatter={(v) => (abreviar ? formatarAbreviado(v, false) : formatarNumero(v))} />
              <Tooltip cursor={{ fill: 'hsl(var(--muted))', opacity: 0.6 }} content={<DicaGrafico formatar={formatar} />} />
              <Bar dataKey={chave} name={nome} fill={cor} radius={[4, 4, 0, 0]} maxBarSize={36} />
            </BarChart>
          </ResponsiveContainer>
        </div>
      ) : <Vazio />}
      tabela={<Tabela cabecalho={['Ano', nome]} linhas={reg.map((d) => [d.ano as number, formatar(d[chave]) ])} />}
    />
  )
}

export interface Fatia { chave: string; nome: string; valor: number; percentual?: number | null; cor: string }

/** Composição em barra única 100% + legenda com valores (substitui pizza em comparações de partes). */
export function Composicao({ titulo, subtitulo, dados, formatar = formatarAbreviado, rotuloValor = 'Valor', className, rodape }: {
  titulo: string; subtitulo?: string; dados: Fatia[]; formatar?: (v: number | null) => string; rotuloValor?: string; className?: string; rodape?: ReactNode
}) {
  const total = dados.reduce((s, d) => s + d.valor, 0)
  const pct = (d: Fatia) => d.percentual ?? (total > 0 ? (d.valor / total) * 100 : null)
  return (
    <CartaoGrafico className={className} titulo={titulo} subtitulo={subtitulo}
      grafico={
        <div className="pt-3">
          <div className="flex h-7 w-full gap-[2px] overflow-hidden rounded" role="img" aria-label={dados.map((d) => `${d.nome} ${formatarPercentual(pct(d))}`).join(', ')}>
            {dados.filter((d) => (pct(d) ?? 0) > 0).map((d) => <div key={d.chave} style={{ width: `${pct(d)}%`, background: d.cor }} title={`${d.nome}: ${formatarPercentual(pct(d))}`} />)}
          </div>
          <ul className="mt-5 space-y-3">
            {dados.map((d) => (
              <li key={d.chave} className="flex items-center gap-3 text-sm">
                <span className="size-3 shrink-0 rounded-sm" style={{ background: d.cor }} aria-hidden />
                <span className="min-w-0 flex-1">{d.nome}</span>
                <span className="font-semibold tabular">{formatarPercentual(pct(d))}</span>
                <span className="w-24 text-right text-xs text-muted-foreground tabular">{formatar(d.valor)}</span>
              </li>
            ))}
          </ul>
          {rodape && <div className="mt-4 text-[11px] text-muted-foreground">{rodape}</div>}
        </div>
      }
      tabela={<Tabela cabecalho={['Grupo', '%', rotuloValor]} linhas={dados.map((d) => [d.nome, formatarPercentual(pct(d)), formatar === formatarAbreviado ? formatarMoeda(d.valor) : formatar(d.valor)])} />}
    />
  )
}

/** Gráfico de rosca com o total no centro. */
export function Rosca({ titulo, subtitulo, dados, rotuloTotal, className, extra }: {
  titulo: string; subtitulo?: string; dados: Fatia[]; rotuloTotal: string; className?: string; extra?: ReactNode
}) {
  const total = dados.reduce((s, d) => s + d.valor, 0)
  return (
    <CartaoGrafico className={className} titulo={titulo} subtitulo={subtitulo}
      grafico={
        <div>
          <div className="relative h-[220px]">
            <ResponsiveContainer>
              <PieChart>
                <Pie data={dados} dataKey="valor" nameKey="nome" innerRadius="62%" outerRadius="90%" paddingAngle={1} stroke="hsl(var(--card))" strokeWidth={2} isAnimationActive={false}>
                  {dados.map((d) => <Cell key={d.chave} fill={d.cor} />)}
                </Pie>
                <Tooltip content={({ active, payload }) => {
                  const d = payload?.[0]?.payload as Fatia | undefined
                  if (!active || !d) return null
                  return <div className="rounded-md border bg-popover px-3 py-2 text-xs shadow-lg"><p className="font-semibold">{d.nome}</p><p className="tabular">{formatarNumero(d.valor)} · {formatarPercentual(total ? (d.valor / total) * 100 : null)}</p></div>
                }} />
              </PieChart>
            </ResponsiveContainer>
            <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
              <span className="text-xl font-semibold tabular">{formatarNumero(total)}</span>
              <span className="text-[11px] text-muted-foreground">{rotuloTotal}</span>
            </div>
          </div>
          <ul className="mt-3 flex flex-wrap justify-center gap-x-5 gap-y-1 text-xs">
            {dados.map((d) => (
              <li key={d.chave} className="flex items-center gap-1.5">
                <span className="size-2.5 rounded-sm" style={{ background: d.cor }} aria-hidden />{d.nome}: <strong className="tabular">{formatarNumero(d.valor)}</strong>
                <span className="text-muted-foreground tabular">({formatarPercentual(total ? (d.valor / total) * 100 : null)})</span>
              </li>
            ))}
          </ul>
          {extra}
        </div>
      }
      tabela={<Tabela cabecalho={['Grupo', 'Quantidade', '%']} linhas={dados.map((d) => [d.nome, formatarNumero(d.valor), formatarPercentual(total ? (d.valor / total) * 100 : null)])} />}
    />
  )
}

export interface NoArvore { id: number | null; nome: string; valor: number; percentual: number | null; filtro: NomeFiltro | null; chave: string | null; classes?: NoArvore[] }

/** Treemap grupo > classe. Cores por grupo (ordem fixa); clicar aplica o filtro cruzado da classe. */
export function MapaArvore({ titulo, subtitulo, dados, className }: { titulo: string; subtitulo?: string; dados: NoArvore[]; className?: string }) {
  const { aplicarCruzado } = useFiltros()
  const principais = dados.slice(0, 3)
  const corGrupo = (i: number) => (i < 3 ? SERIES[i] : COR.neutra)
  const arvore = dados.map((g, i) => ({
    name: g.nome, cor: corGrupo(i),
    children: (g.classes ?? []).map((c) => ({ name: c.nome, size: c.valor, cor: corGrupo(i), grupo: g.nome, no: c })),
  }))
  return (
    <CartaoGrafico className={className} titulo={titulo} subtitulo={subtitulo}
      grafico={dados.length ? (
        <div>
          <div className="h-[320px]">
            <ResponsiveContainer>
              <Treemap data={arvore} dataKey="size" isAnimationActive={false} aspectRatio={4 / 3}
                content={<CelulaArvore aoClicar={(no: NoArvore) => { if (no.filtro && no.chave) aplicarCruzado(no.filtro, no.chave, no.nome) }} />}>
                <Tooltip content={({ active, payload }) => {
                  const d = payload?.[0]?.payload as { name: string; size: number; grupo?: string; no?: NoArvore } | undefined
                  if (!active || !d?.no) return null
                  return (
                    <div className="max-w-xs rounded-md border bg-popover px-3 py-2 text-xs shadow-lg">
                      <p className="font-semibold">{d.name}</p><p className="text-muted-foreground">{d.grupo}</p>
                      <p className="tabular">{formatarMoeda(d.size)} · {formatarPercentual(d.no.percentual)}</p>
                      <p className="mt-1 text-muted-foreground">Clique para filtrar</p>
                    </div>
                  )
                }} />
              </Treemap>
            </ResponsiveContainer>
          </div>
          <ul className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs">
            {principais.map((g, i) => <li key={g.nome} className="flex items-center gap-1.5"><span className="size-2.5 rounded-sm" style={{ background: corGrupo(i) }} aria-hidden />{g.nome}</li>)}
            {dados.length > 3 && <li className="flex items-center gap-1.5"><span className="size-2.5 rounded-sm" style={{ background: COR.neutra }} aria-hidden />Demais grupos ({dados.length - 3})</li>}
          </ul>
        </div>
      ) : <Vazio />}
      tabela={<Tabela cabecalho={['Grupo', 'Classe', 'Adjudicado', '%']}
        linhas={dados.flatMap((g) => (g.classes ?? []).map((c) => [g.nome, c.nome, formatarMoeda(c.valor), formatarPercentual(c.percentual)]))} />}
    />
  )
}

interface PropsCelula { x?: number; y?: number; width?: number; height?: number; depth?: number; name?: string; cor?: string; no?: NoArvore; aoClicar: (no: NoArvore) => void }

function CelulaArvore({ x = 0, y = 0, width = 0, height = 0, depth, name, cor, no, aoClicar }: PropsCelula) {
  if (depth !== 2 || !no) return null
  const mostrar = width > 70 && height > 28
  return (
    <g style={{ cursor: no.chave ? 'pointer' : 'default' }} onClick={() => aoClicar(no)}>
      <rect x={x} y={y} width={width} height={height} fill={cor} stroke="hsl(var(--card))" strokeWidth={2} rx={3} />
      {mostrar && (
        <>
          <text x={x + 6} y={y + 16} fill="#fff" fontSize={11} fontWeight={600}>{(name ?? '').length > width / 7 ? `${(name ?? '').slice(0, Math.max(3, Math.floor(width / 7) - 1))}…` : name}</text>
          {height > 44 && <text x={x + 6} y={y + 31} fill="#fff" fontSize={10} opacity={0.9}>{formatarAbreviado(no.valor)}</text>}
        </>
      )}
    </g>
  )
}

/** Barra de percentual dentro de células de tabela, com limites de baixa/alta utilização. */
export function BarraPercentual({ valor, alerta }: { valor: number | null; alerta?: boolean }) {
  if (valor === null) return <span className="text-muted-foreground">{NAO_INFORMADO}</span>
  return (
    <span className="flex items-center gap-2">
      <span className="relative block h-2 w-full min-w-[60px] rounded-full bg-muted" aria-hidden>
        <span className="block h-2 rounded-full" style={{ width: `${Math.min(100, Math.max(0, valor))}%`, background: alerta ? 'hsl(var(--danger))' : 'var(--serie-1)' }} />
      </span>
      <span className="w-14 shrink-0 text-right tabular">{formatarPercentual(valor)}</span>
    </span>
  )
}
