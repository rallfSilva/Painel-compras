import { useState } from 'react'
import { ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight, FileDown, FileSpreadsheet, FileText, Loader2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { exportarCsv, exportarPdf, exportarXlsx, type Cabecalho } from '@/lib/exportar'
import { formatarNumero } from '@/lib/format'
import { paraExportacao, type Coluna } from './colunas'

export const TAMANHOS_PAGINA = [10, 25, 50, 100]

export function Paginacao({ pagina, ultima, total, porPagina, aoMudarPagina, aoMudarPorPagina }: {
  pagina: number; ultima: number; total: number; porPagina: number
  aoMudarPagina: (p: number) => void; aoMudarPorPagina: (n: number) => void
}) {
  const ini = total === 0 ? 0 : (pagina - 1) * porPagina + 1
  const fim = Math.min(total, pagina * porPagina)
  return (
    <div className="mt-3 flex flex-wrap items-center justify-between gap-3 text-xs text-muted-foreground">
      <div className="flex items-center gap-2">
        <label className="flex items-center gap-2">
          Registros por página
          <select className="h-7 rounded border border-input bg-surface px-1.5 text-foreground" value={porPagina} onChange={(e) => aoMudarPorPagina(Number(e.target.value))}>
            {TAMANHOS_PAGINA.map((n) => <option key={n} value={n}>{n}</option>)}
          </select>
        </label>
        <span className="tabular">{formatarNumero(ini)}–{formatarNumero(fim)} de {formatarNumero(total)}</span>
      </div>
      <div className="flex items-center gap-1">
        <Button variant="outline" size="icon" className="size-7" disabled={pagina <= 1} onClick={() => aoMudarPagina(1)} aria-label="Primeira página"><ChevronsLeft /></Button>
        <Button variant="outline" size="icon" className="size-7" disabled={pagina <= 1} onClick={() => aoMudarPagina(pagina - 1)} aria-label="Página anterior"><ChevronLeft /></Button>
        <span className="px-2 tabular">Página {formatarNumero(pagina)} de {formatarNumero(ultima)}</span>
        <Button variant="outline" size="icon" className="size-7" disabled={pagina >= ultima} onClick={() => aoMudarPagina(pagina + 1)} aria-label="Próxima página"><ChevronRight /></Button>
        <Button variant="outline" size="icon" className="size-7" disabled={pagina >= ultima} onClick={() => aoMudarPagina(ultima)} aria-label="Última página"><ChevronsRight /></Button>
      </div>
    </div>
  )
}

/** Exporta todas as linhas do recorte atual (não só a página visível). */
export function BotoesExportacao<T>({ cabecalho, colunas, obterLinhas }: { cabecalho: Cabecalho; colunas: Coluna<T>[]; obterLinhas: () => Promise<T[]> }) {
  const [ocupado, setOcupado] = useState<string | null>(null)
  const [erro, setErro] = useState<string | null>(null)
  const executar = async (formato: 'xlsx' | 'csv' | 'pdf') => {
    setOcupado(formato)
    setErro(null)
    try {
      const linhas = await obterLinhas()
      const cols = paraExportacao(colunas)
      if (formato === 'csv') exportarCsv(cabecalho, cols, linhas)
      else if (formato === 'xlsx') await exportarXlsx(cabecalho, cols, linhas)
      else await exportarPdf(cabecalho, cols, linhas)
    } catch (e) {
      setErro((e as Error).message || 'Falha na exportação.')
    } finally {
      setOcupado(null)
    }
  }
  const botao = (f: 'xlsx' | 'csv' | 'pdf', rotulo: string, Icone: typeof FileDown) => (
    <Button variant="outline" size="sm" className="h-7 px-2 text-[11px]" disabled={!!ocupado} onClick={() => executar(f)} aria-label={`Exportar para ${rotulo}`}>
      {ocupado === f ? <Loader2 className="animate-spin" /> : <Icone />} {rotulo}
    </Button>
  )
  return (
    <div className="no-print flex items-center gap-1" role="group" aria-label="Exportar">
      {botao('xlsx', 'Excel', FileSpreadsheet)}
      {botao('csv', 'CSV', FileDown)}
      {botao('pdf', 'PDF', FileText)}
      {erro && <span className="text-[11px] text-danger" role="alert">{erro}</span>}
    </div>
  )
}
