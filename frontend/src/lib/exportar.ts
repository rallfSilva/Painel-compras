/**
 * Exportação dos dados exibidos para CSV, Excel (.xlsx) e PDF (seção 8: recursos da tabela).
 * As bibliotecas de Excel e PDF são carregadas só no momento da exportação.
 */
import { NAO_INFORMADO } from './format'

export type TipoColuna = 'texto' | 'moeda' | 'numero' | 'decimal' | 'percentual' | 'unitario' | 'data'

export interface ColunaExportacao<T> {
  titulo: string
  tipo: TipoColuna
  valor: (linha: T) => string | number | null | undefined
  /** Texto exibido (usado no PDF e no CSV para colunas de texto). */
  texto: (linha: T) => string
}

export interface Cabecalho {
  titulo: string
  periodo?: string
  filtros?: string[]
  ficticio?: boolean
}

function nomeArquivo(titulo: string, ext: string) {
  const base = titulo.normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^\w]+/g, '-').replace(/^-|-$/g, '').toLowerCase()
  const d = new Date()
  const carimbo = `${d.getFullYear()}${String(d.getMonth() + 1).padStart(2, '0')}${String(d.getDate()).padStart(2, '0')}`
  return `${base || 'exportacao'}-${carimbo}.${ext}`
}

function baixar(blob: Blob, nome: string) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = nome
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}

function linhasCabecalho(c: Cabecalho): string[] {
  const l = [c.titulo, `Gerado em ${new Date().toLocaleString('pt-BR')}`]
  if (c.periodo) l.push(`Período: ${c.periodo}`)
  if (c.filtros?.length) l.push(`Filtros: ${c.filtros.join('; ')}`)
  if (c.ficticio) l.push('ATENÇÃO: contém dados demonstrativos fictícios.')
  return l
}

const numeroCsv = (v: number, casas: number) => v.toLocaleString('pt-BR', { minimumFractionDigits: casas, maximumFractionDigits: casas, useGrouping: false })
const CASAS: Partial<Record<TipoColuna, number>> = { moeda: 2, decimal: 2, percentual: 2, unitario: 4, numero: 0 }

export function exportarCsv<T>(cab: Cabecalho, colunas: ColunaExportacao<T>[], linhas: T[]) {
  const esc = (s: string) => (/[";\n\r]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s)
  const corpo = linhas.map((l) => colunas.map((c) => {
    const v = c.valor(l)
    if (typeof v === 'number' && CASAS[c.tipo] !== undefined) return numeroCsv(v, CASAS[c.tipo]!)
    if (v === null || v === undefined || v === '') return c.tipo === 'texto' ? '' : NAO_INFORMADO
    return esc(c.tipo === 'texto' || c.tipo === 'data' ? c.texto(l) : String(v))
  }).join(';'))
  const meta = linhasCabecalho(cab).map((x) => esc(x))
  const conteudo = [...meta, '', colunas.map((c) => esc(c.titulo)).join(';'), ...corpo].join('\r\n')
  baixar(new Blob(['﻿' + conteudo], { type: 'text/csv;charset=utf-8' }), nomeArquivo(cab.titulo, 'csv'))
}

const FORMATO_XLSX: Partial<Record<TipoColuna, string>> = {
  moeda: '#,##0.00', decimal: '#,##0.00', percentual: '0.00"%"', unitario: '#,##0.0000', numero: '#,##0',
}

export async function exportarXlsx<T>(cab: Cabecalho, colunas: ColunaExportacao<T>[], linhas: T[]) {
  const { default: escrever } = await import('write-excel-file/browser')
  const meta = linhasCabecalho(cab).map((t, i) => [{ value: t, fontWeight: i === 0 ? ('bold' as const) : undefined }])
  const titulos = colunas.map((c) => ({ value: c.titulo, fontWeight: 'bold' as const, backgroundColor: '#E8EEF7' }))
  const dados = linhas.map((l) => colunas.map((c) => {
    const v = c.valor(l)
    if (typeof v === 'number' && FORMATO_XLSX[c.tipo]) return { value: v, type: Number, format: FORMATO_XLSX[c.tipo] }
    if (v === null || v === undefined || v === '') return { value: c.tipo === 'texto' ? '' : NAO_INFORMADO, type: String }
    return { value: c.texto(l), type: String }
  }))
  await escrever([...meta, [], titulos, ...dados], {
    columns: colunas.map((c) => ({ width: c.tipo === 'texto' ? 36 : 18 })),
    sheet: 'Dados',
  }).toFile(nomeArquivo(cab.titulo, 'xlsx'))
}

export async function exportarPdf<T>(cab: Cabecalho, colunas: ColunaExportacao<T>[], linhas: T[]) {
  const [{ jsPDF }, { autoTable }] = await Promise.all([import('jspdf'), import('jspdf-autotable')])
  const doc = new jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' })
  const meta = linhasCabecalho(cab)
  doc.setFontSize(12)
  doc.text(meta[0], 32, 36)
  doc.setFontSize(8)
  meta.slice(1).forEach((t, i) => doc.text(doc.splitTextToSize(t, 770), 32, 52 + i * 11))
  autoTable(doc, {
    startY: 52 + (meta.length - 1) * 11 + 8,
    head: [colunas.map((c) => c.titulo)],
    body: linhas.map((l) => colunas.map((c) => c.texto(l))),
    styles: { fontSize: colunas.length > 10 ? 6 : 7, cellPadding: 3, overflow: 'linebreak' },
    headStyles: { fillColor: [29, 78, 158], textColor: 255 },
    columnStyles: Object.fromEntries(colunas.map((c, i) => [i, { halign: c.tipo === 'texto' || c.tipo === 'data' ? 'left' : 'right' }])),
    margin: { left: 32, right: 32 },
    didDrawPage: () => {
      const n = doc.getNumberOfPages()
      doc.setFontSize(7)
      doc.text(`Página ${n}`, doc.internal.pageSize.getWidth() - 70, doc.internal.pageSize.getHeight() - 16)
    },
  })
  doc.save(nomeArquivo(cab.titulo, 'pdf'))
}
