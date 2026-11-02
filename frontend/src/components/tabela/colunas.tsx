import type { ReactNode } from 'react'
import { CircleAlert, CircleCheck, CircleX } from 'lucide-react'
import type { ColunaExportacao, TipoColuna } from '@/lib/exportar'
import { formatarData, formatarDecimal, formatarMoeda, formatarNumero, formatarPercentual, formatarUnitario, NAO_INFORMADO } from '@/lib/format'
import { Dica } from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'

/** Faixa de avaliação (verde / amarelo / vermelho). "maior": quanto maior, melhor. */
export interface Faixa { sentido: 'maior' | 'menor'; bom: number; atencao: number }

export interface Coluna<T> {
  id: string
  titulo: string
  /** Explicação exibida em tooltip no cabeçalho. */
  dica?: string
  tipo: TipoColuna
  valor: (l: T) => string | number | null | undefined
  /** Texto exibido; o padrão formata o valor conforme o tipo. */
  texto?: (l: T) => string
  /** Conteúdo personalizado da célula. */
  render?: (l: T) => ReactNode
  /** Barra horizontal proporcional ao maior valor da coluna. */
  barra?: boolean
  faixa?: Faixa
  /** Coluna fixa à esquerda durante a rolagem horizontal (exige largura). */
  fixa?: boolean
  largura?: number
  ordenavel?: boolean
}

export function formatarTipo(tipo: TipoColuna, v: string | number | null | undefined): string {
  if (v === null || v === undefined || v === '') return tipo === 'texto' ? '—' : NAO_INFORMADO
  switch (tipo) {
    case 'moeda': return formatarMoeda(v)
    case 'numero': return formatarNumero(v)
    case 'decimal': return formatarDecimal(v)
    case 'percentual': return formatarPercentual(v)
    case 'unitario': return formatarUnitario(v)
    case 'data': return formatarData(String(v))
    default: return String(v)
  }
}

export const textoColuna = <T,>(c: Coluna<T>, l: T) => (c.texto ? c.texto(l) : formatarTipo(c.tipo, c.valor(l)))
export const alinhadaDireita = (t: TipoColuna) => t !== 'texto' && t !== 'data'

export function paraExportacao<T>(colunas: Coluna<T>[]): ColunaExportacao<T>[] {
  return colunas.map((c) => ({ titulo: c.titulo, tipo: c.tipo, valor: c.valor, texto: (l: T) => textoColuna(c, l) }))
}

export type Avaliacao = 'bom' | 'atencao' | 'critico'

export function avaliar(f: Faixa, v: number | null | undefined): Avaliacao | null {
  if (v === null || v === undefined) return null
  if (f.sentido === 'maior') return v >= f.bom ? 'bom' : v >= f.atencao ? 'atencao' : 'critico'
  return v <= f.bom ? 'bom' : v <= f.atencao ? 'atencao' : 'critico'
}

const AVAL = {
  bom: { Icone: CircleCheck, cor: 'text-success', rotulo: 'Bom' },
  atencao: { Icone: CircleAlert, cor: 'text-warning', rotulo: 'Atenção' },
  critico: { Icone: CircleX, cor: 'text-danger', rotulo: 'Requer análise' },
}

/** Indicador visual com ícone e rótulo acessível (a cor nunca é o único sinal). */
export function SinalFaixa({ faixa, valor }: { faixa: Faixa; valor: number | null | undefined }) {
  const a = avaliar(faixa, valor)
  if (!a) return null
  const { Icone, cor, rotulo } = AVAL[a]
  const regra = faixa.sentido === 'maior'
    ? `Bom: ≥ ${faixa.bom}% · Atenção: ≥ ${faixa.atencao}% · abaixo disso, requer análise`
    : `Bom: ≤ ${faixa.bom}% · Atenção: ≤ ${faixa.atencao}% · acima disso, requer análise`
  return (
    <Dica conteudo={<><strong>{rotulo}.</strong> {regra}</>}>
      <span className={cn('inline-flex', cor)} role="img" aria-label={rotulo}><Icone className="size-3.5" /></span>
    </Dica>
  )
}

export function BarraValor({ valor, maximo }: { valor: number | null | undefined; maximo: number }) {
  if (valor === null || valor === undefined || maximo <= 0) return null
  return (
    <span className="mt-1 block h-1.5 w-full rounded-full bg-muted" aria-hidden>
      <span className="block h-1.5 rounded-full" style={{ width: `${Math.max(0, Math.min(100, (valor / maximo) * 100))}%`, background: 'var(--serie-1)' }} />
    </span>
  )
}
