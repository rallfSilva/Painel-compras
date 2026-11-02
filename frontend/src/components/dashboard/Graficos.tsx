import { useState, type ReactNode } from 'react'
import { Bar, BarChart, CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { BarChart3, Table2 } from 'lucide-react'
import { formatarAbreviado, formatarMoeda, formatarNumero, formatarPercentual } from '@/lib/format'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { cn } from '@/lib/utils'

export const COR = { s1: 'var(--serie-1)', s2: 'var(--serie-2)', s3: 'var(--serie-3)', neutra: 'var(--serie-neutra)', grade: 'var(--grade)' }
export const EIXO = { fontSize: 11, fill: 'hsl(var(--muted-foreground))' }

export interface Evolucao { ano: number; estimado: number | null; contratado: number | null; economia: number | null; percentual_economia: number | null; processos: number }
export interface Sistematica { id: number; nome: string; contratado: number | null; processos: number }
export interface TopUnidade { id: number; nome: string; orgao: string; contratado: number; processos: number; percentual: number | null }
export interface GrupoPorte { grupo: string; nome: string; contratado: number; percentual: number | null }

/** Cartão de gráfico com alternância para visualização em tabela (acessibilidade). */
export function CartaoGrafico({ titulo, subtitulo, grafico, tabela, className }: { titulo: string; subtitulo?: string; grafico: ReactNode; tabela: ReactNode; className?: string }) {
  const [modo, setModo] = useState<'grafico' | 'tabela'>('grafico')
  return (
    <Card className={className}>
      <CardHeader>
        <div>
          <CardTitle>{titulo}</CardTitle>
          {subtitulo && <p className="mt-0.5 text-xs text-muted-foreground">{subtitulo}</p>}
        </div>
        <div className="no-print flex rounded-md border p-0.5" role="group" aria-label="Modo de visualização">
          {(['grafico', 'tabela'] as const).map((m) => (
            <button key={m} onClick={() => setModo(m)} aria-pressed={modo === m} aria-label={m === 'grafico' ? 'Ver gráfico' : 'Ver tabela'}
              className={cn('rounded px-1.5 py-1 text-muted-foreground', modo === m && 'bg-muted text-foreground')}>
              {m === 'grafico' ? <BarChart3 className="size-3.5" /> : <Table2 className="size-3.5" />}
            </button>
          ))}
        </div>
      </CardHeader>
      <CardContent>{modo === 'grafico' ? grafico : <div className="max-h-[300px] overflow-auto">{tabela}</div>}</CardContent>
    </Card>
  )
}

export function Tabela({ cabecalho, linhas }: { cabecalho: string[]; linhas: (string | number)[][] }) {
  return (
    <table className="w-full text-xs">
      <thead className="sticky top-0 bg-muted text-left text-muted-foreground">
        <tr>{cabecalho.map((c, i) => <th key={c} className={cn('px-2 py-1.5 font-semibold', i > 0 && 'text-right')}>{c}</th>)}</tr>
      </thead>
      <tbody>{linhas.map((l, i) => <tr key={i} className="border-t">{l.map((v, j) => <td key={j} className={cn('px-2 py-1.5', j > 0 && 'text-right tabular')}>{v}</td>)}</tr>)}</tbody>
    </table>
  )
}

export function DicaGrafico({ active, payload, label, formatar }: { active?: boolean; payload?: { name: string; value: number | null; color: string }[]; label?: string | number; formatar: (v: number | null) => string }) {
  if (!active || !payload?.length) return null
  return (
    <div className="rounded-md border bg-popover px-3 py-2 text-xs shadow-lg">
      <p className="mb-1 font-semibold">{label}</p>
      {payload.map((p) => (
        <p key={p.name} className="flex items-center gap-2">
          <span className="inline-block size-2.5 rounded-sm" style={{ background: p.color }} aria-hidden />
          <span className="text-muted-foreground">{p.name}:</span>
          <span className="font-medium tabular">{formatar(p.value)}</span>
        </p>
      ))}
    </div>
  )
}

export function GraficoEvolucao({ dados, className = 'xl:col-span-2' }: { dados: Evolucao[]; className?: string }) {
  return (
    <CartaoGrafico
      titulo="Evolução anual das compras"
      subtitulo="Valor estimado e valor contratado dos itens com resultado (R$)"
      className={className}
      grafico={
        <div className="h-[280px]">
          <ResponsiveContainer>
            <BarChart data={dados} barGap={2} margin={{ top: 8, right: 8, left: 8, bottom: 0 }}>
              <CartesianGrid vertical={false} stroke={COR.grade} />
              <XAxis dataKey="ano" tick={EIXO} axisLine={false} tickLine={false} />
              <YAxis tick={EIXO} axisLine={false} tickLine={false} width={64} tickFormatter={(v) => formatarAbreviado(v, false)} />
              <Tooltip cursor={{ fill: 'hsl(var(--muted))', opacity: 0.6 }} content={<DicaGrafico formatar={formatarMoeda} />} />
              <Legend iconType="square" iconSize={10} wrapperStyle={{ fontSize: 12 }} />
              <Bar dataKey="estimado" name="Estimado" fill={COR.s3} radius={[4, 4, 0, 0]} maxBarSize={28} />
              <Bar dataKey="contratado" name="Contratado" fill={COR.s1} radius={[4, 4, 0, 0]} maxBarSize={28} />
            </BarChart>
          </ResponsiveContainer>
        </div>
      }
      tabela={<Tabela cabecalho={['Ano', 'Estimado', 'Contratado', 'Processos']} linhas={dados.map((d) => [d.ano, formatarMoeda(d.estimado), formatarMoeda(d.contratado), formatarNumero(d.processos)])} />}
    />
  )
}

export function GraficoEconomia({ dados, className }: { dados: Evolucao[]; className?: string }) {
  return (
    <CartaoGrafico
      className={className}
      titulo="% de economia por exercício"
      subtitulo="Procedimentos com disputa de preço, salvo se a opção de incluir os demais estiver ativa"
      grafico={
        <div className="h-[280px]">
          <ResponsiveContainer>
            <LineChart data={dados} margin={{ top: 16, right: 16, left: 0, bottom: 0 }}>
              <CartesianGrid vertical={false} stroke={COR.grade} />
              <XAxis dataKey="ano" tick={EIXO} axisLine={false} tickLine={false} />
              <YAxis tick={EIXO} axisLine={false} tickLine={false} width={48} tickFormatter={(v) => `${v}%`} />
              <Tooltip content={<DicaGrafico formatar={formatarPercentual} />} />
              <Line type="monotone" dataKey="percentual_economia" name="% Economia" stroke={COR.s2} strokeWidth={2} dot={{ r: 4, strokeWidth: 2, fill: 'hsl(var(--card))' }} activeDot={{ r: 5 }} connectNulls={false} />
            </LineChart>
          </ResponsiveContainer>
        </div>
      }
      tabela={<Tabela cabecalho={['Ano', '% Economia', 'Economia (R$)']} linhas={dados.map((d) => [d.ano, formatarPercentual(d.percentual_economia), formatarMoeda(d.economia)])} />}
    />
  )
}

export function GraficoSistematica({ dados }: { dados: Sistematica[] }) {
  const comValor = dados.filter((d) => d.contratado !== null).sort((a, b) => (b.contratado ?? 0) - (a.contratado ?? 0))
  return (
    <CartaoGrafico
      className="xl:col-span-3"
      titulo="Compras por sistemática"
      subtitulo="Valor contratado (R$)"
      grafico={
        <div style={{ height: Math.max(180, comValor.length * 34 + 20) }}>
          <ResponsiveContainer>
            <BarChart data={comValor} layout="vertical" margin={{ top: 0, right: 16, left: 0, bottom: 0 }}>
              <CartesianGrid horizontal={false} stroke={COR.grade} />
              <XAxis type="number" tick={EIXO} axisLine={false} tickLine={false} tickFormatter={(v) => formatarAbreviado(v, false)} />
              <YAxis type="category" dataKey="nome" tick={EIXO} axisLine={false} tickLine={false} width={150} />
              <Tooltip cursor={{ fill: 'hsl(var(--muted))', opacity: 0.6 }} content={<DicaGrafico formatar={formatarMoeda} />} />
              <Bar dataKey="contratado" name="Contratado" fill={COR.s1} radius={[0, 4, 4, 0]} maxBarSize={20} />
            </BarChart>
          </ResponsiveContainer>
        </div>
      }
      tabela={<Tabela cabecalho={['Sistemática', 'Contratado', 'Processos']} linhas={dados.map((d) => [d.nome, formatarMoeda(d.contratado), formatarNumero(d.processos)])} />}
    />
  )
}

export function GraficoPorte({ dados }: { dados: GrupoPorte[] }) {
  const cores: Record<string, string> = { MPE: COR.s1, OUTRO: COR.s3, NI: COR.neutra }
  return (
    <CartaoGrafico
      titulo="Participação por porte do fornecedor"
      subtitulo="Percentual do valor contratado (MPE / Outro / Não identificado)"
      grafico={
        <div className="pt-4">
          <div className="flex h-7 w-full gap-[2px] overflow-hidden rounded" role="img" aria-label={dados.map((d) => `${d.nome} ${formatarPercentual(d.percentual)}`).join(', ')}>
            {dados.filter((d) => (d.percentual ?? 0) > 0).map((d) => (
              <div key={d.grupo} style={{ width: `${d.percentual}%`, background: cores[d.grupo] }} title={`${d.nome}: ${formatarPercentual(d.percentual)}`} />
            ))}
          </div>
          <ul className="mt-5 space-y-3">
            {dados.map((d) => (
              <li key={d.grupo} className="flex items-center gap-3 text-sm">
                <span className="size-3 shrink-0 rounded-sm" style={{ background: cores[d.grupo] }} aria-hidden />
                <span className="flex-1">{d.nome}</span>
                <span className="font-semibold tabular">{formatarPercentual(d.percentual)}</span>
                <span className="w-28 text-right text-xs text-muted-foreground tabular">{formatarAbreviado(d.contratado)}</span>
              </li>
            ))}
          </ul>
        </div>
      }
      tabela={<Tabela cabecalho={['Grupo', '%', 'Contratado']} linhas={dados.map((d) => [d.nome, formatarPercentual(d.percentual), formatarMoeda(d.contratado)])} />}
    />
  )
}

export function RankingUnidades({ dados, className = 'xl:col-span-2', aoClicar }: { dados: TopUnidade[]; className?: string; aoClicar?: (id: number) => void }) {
  const max = Math.max(...dados.map((d) => d.contratado), 1)
  return (
    <Card className={className}>
      <CardHeader>
        <div>
          <CardTitle>Ranking das unidades compradoras</CardTitle>
          <p className="mt-0.5 text-xs text-muted-foreground">{dados.length >= 20 ? '20' : '10'} maiores por valor contratado{aoClicar ? ' · clique para ver o detalhamento' : ''}</p>
        </div>
      </CardHeader>
      <CardContent className="overflow-x-auto">
        <table className="w-full min-w-[560px] text-xs">
          <thead className="text-left text-[11px] uppercase tracking-wide text-muted-foreground">
            <tr className="border-b">
              <th className="py-2 pr-2 font-semibold">#</th>
              <th className="py-2 pr-2 font-semibold">Unidade compradora</th>
              <th className="py-2 pr-2 font-semibold">Órgão</th>
              <th className="py-2 pr-2 text-right font-semibold">Processos</th>
              <th className="py-2 pr-2 text-right font-semibold">Contratado</th>
              <th className="w-[22%] py-2 font-semibold">% do total</th>
            </tr>
          </thead>
          <tbody>
            {dados.map((d, i) => (
              <tr key={d.id} className={cn('border-b last:border-0', aoClicar && 'cursor-pointer hover:bg-muted/60')} onClick={aoClicar ? () => aoClicar(d.id) : undefined}
                tabIndex={aoClicar ? 0 : undefined} onKeyDown={aoClicar ? (e) => { if (e.key === 'Enter') aoClicar(d.id) } : undefined}>
                <td className="py-2 pr-2 text-muted-foreground tabular">{i + 1}</td>
                <td className="py-2 pr-2 font-medium">{d.nome}</td>
                <td className="py-2 pr-2 text-muted-foreground">{d.orgao}</td>
                <td className="py-2 pr-2 text-right tabular">{formatarNumero(d.processos)}</td>
                <td className="whitespace-nowrap py-2 pr-2 text-right tabular">{formatarMoeda(d.contratado)}</td>
                <td className="py-2">
                  <div className="flex items-center gap-2">
                    <div className="h-2 flex-1 rounded-full bg-muted">
                      <div className="h-2 rounded-full" style={{ width: `${(d.contratado / max) * 100}%`, background: COR.s1 }} />
                    </div>
                    <span className="w-14 text-right tabular">{formatarPercentual(d.percentual)}</span>
                  </div>
                </td>
              </tr>
            ))}
            {!dados.length && <tr><td colSpan={6} className="py-6 text-center text-muted-foreground">Sem dados para os filtros atuais.</td></tr>}
          </tbody>
        </table>
      </CardContent>
    </Card>
  )
}
