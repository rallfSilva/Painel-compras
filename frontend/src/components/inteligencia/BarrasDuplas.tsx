import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { CartaoGrafico, COR, DicaGrafico, EIXO, Tabela } from '@/components/dashboard/Graficos'
import { formatarAbreviado, formatarMoeda, formatarNumero } from '@/lib/format'

/** Barras agrupadas de duas grandezas na mesma escala (ex.: planejado × contratado), com tabela alternativa. */
export function BarrasDuplas<T extends Record<string, unknown>>({ titulo, subtitulo, dados, x, series, moeda = true, className }: {
  titulo: string; subtitulo?: string; dados: T[]; x: keyof T & string; series: { chave: keyof T & string; nome: string; cor: string }[]; moeda?: boolean; className?: string
}) {
  const fmt = (v: number | null) => (moeda ? formatarMoeda(v) : formatarNumero(v))
  return (
    <CartaoGrafico className={className} titulo={titulo} subtitulo={subtitulo}
      grafico={dados.length ? (
        <div className="h-[280px]">
          <ResponsiveContainer>
            <BarChart data={dados} barGap={2} margin={{ top: 8, right: 8, left: 8, bottom: 0 }}>
              <CartesianGrid vertical={false} stroke={COR.grade} />
              <XAxis dataKey={x} tick={EIXO} axisLine={false} tickLine={false} />
              <YAxis tick={EIXO} axisLine={false} tickLine={false} width={64} tickFormatter={(v) => (moeda ? formatarAbreviado(v, false) : formatarNumero(v))} allowDecimals={false} />
              <Tooltip cursor={{ fill: 'hsl(var(--muted))', opacity: 0.6 }} content={<DicaGrafico formatar={fmt} />} />
              {series.length > 1 && <Legend iconType="square" iconSize={10} wrapperStyle={{ fontSize: 12 }} />}
              {series.map((s) => <Bar key={s.chave} dataKey={s.chave} name={s.nome} fill={s.cor} radius={[4, 4, 0, 0]} maxBarSize={28} />)}
            </BarChart>
          </ResponsiveContainer>
        </div>
      ) : <p className="py-10 text-center text-xs text-muted-foreground">Sem dados para os filtros atuais.</p>}
      tabela={<Tabela cabecalho={[String(x), ...series.map((s) => s.nome)]} linhas={dados.map((d) => [String(d[x]), ...series.map((s) => fmt(d[s.chave] as number | null))])} />}
    />
  )
}
