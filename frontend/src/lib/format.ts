/**
 * Formatação de valores (seção 4.1 do prompt):
 * cartões e tabelas com valor completo; gráficos abreviados; unitário com 4 casas; percentuais com 2 casas.
 * Valor ausente é sempre "Não informado", nunca zero (seção 28).
 */
export const NAO_INFORMADO = 'Não informado'

const brl = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', minimumFractionDigits: 2, maximumFractionDigits: 2 })
const num = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 0 })
const dec2 = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dec4 = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 4, maximumFractionDigits: 4 })
const dec1 = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 })

type Valor = number | string | null | undefined

function n(v: Valor): number | null {
  if (v === null || v === undefined || v === '') return null
  const x = typeof v === 'number' ? v : Number(v)
  return Number.isFinite(x) ? x : null
}

export const formatarMoeda = (v: Valor) => { const x = n(v); return x === null ? NAO_INFORMADO : brl.format(x) }
export const formatarNumero = (v: Valor) => { const x = n(v); return x === null ? NAO_INFORMADO : num.format(x) }
export const formatarDecimal = (v: Valor) => { const x = n(v); return x === null ? NAO_INFORMADO : dec2.format(x) }
export const formatarUnitario = (v: Valor) => { const x = n(v); return x === null ? NAO_INFORMADO : dec4.format(x) }
export const formatarPercentual = (v: Valor) => { const x = n(v); return x === null ? NAO_INFORMADO : `${dec2.format(x)}%` }

/** Valor abreviado para gráficos: 13.643,2 Mi, 1,4 Bi, 950,0 Mil. */
export function formatarAbreviado(v: Valor, moeda = true): string {
  const x = n(v)
  if (x === null) return NAO_INFORMADO
  const abs = Math.abs(x)
  const prefixo = moeda ? "R$\u00a0" : ""
  if (abs >= 1e9) return `${prefixo}${dec1.format(x / 1e9)}\u00a0Bi`
  if (abs >= 1e6) return `${prefixo}${dec1.format(x / 1e6)}\u00a0Mi`
  if (abs >= 1e3) return `${prefixo}${dec1.format(x / 1e3)}\u00a0Mil`
  return `${prefixo}${dec2.format(x)}`
}

export function formatarData(v: string | null | undefined): string {
  if (!v) return NAO_INFORMADO
  const [a, m, d] = v.slice(0, 10).split('-')
  return a && m && d ? `${d}/${m}/${a}` : v
}

export function formatarDocumento(doc: string | null | undefined): string {
  if (!doc) return NAO_INFORMADO
  if (/^\d{14}$/.test(doc)) return doc.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5')
  if (/^\d{11}$/.test(doc)) return doc.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4')
  return doc
}

export function formatarPorUnidade(v: Valor, unidade: string): string {
  if (unidade === 'BRL') return formatarMoeda(v)
  if (unidade === 'PERCENTUAL') return formatarPercentual(v)
  return formatarNumero(v)
}
