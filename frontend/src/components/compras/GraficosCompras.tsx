import { useState } from 'react'
import { Bar, BarChart, CartesianGrid, Cell, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { CartaoGrafico, COR, EIXO, Tabela } from '@/components/dashboard/Graficos'
import { useFiltros } from '@/contexts/FiltrosContext'
import { formatarAbreviado, formatarMoeda, formatarNumero, formatarPercentual } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { Agrupamento } from './ListaBarras'

const truncar = (s: string, n = 26) => (s.length > n ? `${s.slice(0, n - 1)}…` : s)

/**
 * Barras horizontais do valor contratado por dimensão. Clicar em uma barra aplica o filtro global
 * correspondente (filtro cruzado); o grupo "Demais" não é clicável.
 */
export function GraficoAgrupado({ titulo, subtitulo, dados, className, rotuloDimensao }: {
  titulo: string; subtitulo?: string; dados: Agrupamento[]; className?: string; rotuloDimensao: string
}) {
  const { aplicarCruzado } = useFiltros()
  const linhas = dados.map((d) => ({ ...d, rotulo: truncar(d.nome) }))
  return (
    <CartaoGrafico
      className={className}
      titulo={titulo}
      subtitulo={subtitulo ?? 'Valor contratado (R$) · clique em uma barra para filtrar o painel'}
      grafico={
        linhas.length ? (
          <div style={{ height: Math.max(160, linhas.length * 28 + 24) }}>
            <ResponsiveContainer>
              <BarChart data={linhas} layout="vertical" margin={{ top: 0, right: 16, left: 0, bottom: 0 }}>
                <CartesianGrid horizontal={false} stroke={COR.grade} />
                <XAxis type="number" tick={EIXO} axisLine={false} tickLine={false} tickFormatter={(v) => formatarAbreviado(v, false)} />
                <YAxis type="category" dataKey="rotulo" tick={EIXO} axisLine={false} tickLine={false} width={170} interval={0} />
                <Tooltip cursor={{ fill: 'hsl(var(--muted))', opacity: 0.6 }}
                  content={({ active, payload }) => {
                    const d = payload?.[0]?.payload as (Agrupamento & { rotulo: string }) | undefined
                    if (!active || !d) return null
                    return (
                      <div className="max-w-xs rounded-md border bg-popover px-3 py-2 text-xs shadow-lg">
                        <p className="mb-1 font-semibold">{d.nome}</p>
                        <p>Contratado: <span className="font-medium tabular">{formatarMoeda(d.contratado)}</span></p>
                        <p>Participação: <span className="font-medium tabular">{formatarPercentual(d.percentual)}</span></p>
                        <p>Processos: <span className="font-medium tabular">{formatarNumero(d.processos)}</span></p>
                        {d.chave && <p className="mt-1 text-muted-foreground">Clique para filtrar</p>}
                      </div>
                    )
                  }} />
                <Bar dataKey="contratado" name="Contratado" radius={[0, 4, 4, 0]} maxBarSize={18}
                  onClick={(d: { payload?: Agrupamento }) => { const x = d.payload; if (x?.filtro && x.chave) aplicarCruzado(x.filtro, x.chave, x.nome) }}>
                  {linhas.map((d) => <Cell key={d.chave ?? d.nome} fill={d.agrupado ? COR.neutra : COR.s1} cursor={d.chave ? 'pointer' : 'default'} />)}
                </Bar>
              </BarChart>
            </ResponsiveContainer>
          </div>
        ) : <p className="py-10 text-center text-xs text-muted-foreground">Sem dados para os filtros atuais.</p>
      }
      tabela={<Tabela cabecalho={[rotuloDimensao, 'Contratado', '%', 'Processos']} linhas={dados.map((d) => [d.nome, formatarMoeda(d.contratado), formatarPercentual(d.percentual), formatarNumero(d.processos)])} />}
    />
  )
}

export interface Disputa { grupo: 'COM' | 'SEM'; nome: string; contratado: number; processos: number; percentual_valor: number | null; percentual_processos: number | null }

/** Contratações com e sem disputa de preço, por valor ou por quantidade de processos. */
export function GraficoDisputa({ dados, className }: { dados: Disputa[]; className?: string }) {
  const [metrica, setMetrica] = useState<'valor' | 'processos'>('valor')
  const cores = { COM: COR.s1, SEM: COR.s2 }
  const pct = (d: Disputa) => (metrica === 'valor' ? d.percentual_valor : d.percentual_processos)
  return (
    <CartaoGrafico
      className={className}
      titulo="Compras com e sem disputa"
      subtitulo={metrica === 'valor' ? 'Percentual do valor contratado' : 'Percentual da quantidade de processos'}
      grafico={
        <div className="pt-2">
          <div className="mb-4 inline-flex rounded-md border p-0.5 text-[11px]" role="group" aria-label="Métrica">
            {(['valor', 'processos'] as const).map((m) => (
              <button key={m} onClick={() => setMetrica(m)} aria-pressed={metrica === m}
                className={cn('rounded px-2 py-1 text-muted-foreground', metrica === m && 'bg-muted font-medium text-foreground')}>
                {m === 'valor' ? 'Por valor' : 'Por quantidade'}
              </button>
            ))}
          </div>
          <div className="flex h-7 w-full gap-[2px] overflow-hidden rounded" role="img" aria-label={dados.map((d) => `${d.nome} ${formatarPercentual(pct(d))}`).join(', ')}>
            {dados.filter((d) => (pct(d) ?? 0) > 0).map((d) => <div key={d.grupo} style={{ width: `${pct(d)}%`, background: cores[d.grupo] }} />)}
          </div>
          <ul className="mt-5 space-y-3">
            {dados.map((d) => (
              <li key={d.grupo} className="flex items-center gap-3 text-sm">
                <span className="size-3 shrink-0 rounded-sm" style={{ background: cores[d.grupo] }} aria-hidden />
                <span className="flex-1">{d.nome}</span>
                <span className="font-semibold tabular">{formatarPercentual(pct(d))}</span>
                <span className="w-28 text-right text-xs text-muted-foreground tabular">
                  {metrica === 'valor' ? formatarAbreviado(d.contratado) : `${formatarNumero(d.processos)} proc.`}
                </span>
              </li>
            ))}
          </ul>
          <p className="mt-4 text-[11px] text-muted-foreground">Sem disputa: Chamada Pública PNAE, credenciamento, inexigibilidade, adesão a ata e demais procedimentos sem competição de preço.</p>
        </div>
      }
      tabela={<Tabela cabecalho={['Grupo', 'Contratado', '% valor', 'Processos', '% processos']} linhas={dados.map((d) => [d.nome, formatarMoeda(d.contratado), formatarPercentual(d.percentual_valor), formatarNumero(d.processos), formatarPercentual(d.percentual_processos)])} />}
    />
  )
}
