import { useMemo, useState, type ReactNode } from 'react'
import {
  flexRender, getCoreRowModel, getFilteredRowModel, getPaginationRowModel, getSortedRowModel, useReactTable,
  type ColumnDef, type ColumnFiltersState, type FilterFn, type SortingState,
} from '@tanstack/react-table'
import { ArrowDown, ArrowUp, ArrowUpDown, Filter, Info, Search } from 'lucide-react'
import { Input } from '@/components/ui/input'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { Dica } from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import { alinhadaDireita, BarraValor, SinalFaixa, textoColuna, type Coluna } from './colunas'
import { BotoesExportacao, Paginacao } from './partes'
import type { Cabecalho } from '@/lib/exportar'

interface FiltroColuna { texto?: string; min?: string; max?: string }

const filtroColuna: FilterFn<unknown> = (row, id, f: FiltroColuna) => {
  const v = row.getValue(id) as string | number | null
  if (f.texto) return String(v ?? '').toLowerCase().includes(f.texto.toLowerCase())
  if (f.min !== undefined && f.min !== '' && (v === null || Number(v) < Number(f.min))) return false
  if (f.max !== undefined && f.max !== '' && (v === null || Number(v) > Number(f.max))) return false
  return true
}

/**
 * Tabela com ordenação, pesquisa, filtros por coluna, paginação, escolha da quantidade de registros,
 * exportação (Excel, CSV, PDF), barras de desempenho, sinais verde/amarelo/vermelho, tooltips,
 * colunas fixas e rolagem horizontal (seção 8). Opera sobre os dados já carregados.
 */
export function TabelaAvancada<T>({
  colunas, dados, pesquisarEm, placeholderPesquisa = 'Pesquisar…', aoClicarLinha, cabecalhoExportacao, podeExportar,
  rodape, ordemInicial, acoes, rotuloLinha, selecionada,
}: {
  colunas: Coluna<T>[]
  dados: T[]
  pesquisarEm?: (l: T) => string
  placeholderPesquisa?: string
  aoClicarLinha?: (l: T) => void
  cabecalhoExportacao: Cabecalho
  podeExportar: boolean
  rodape?: ReactNode
  ordemInicial?: SortingState
  acoes?: ReactNode
  rotuloLinha?: (l: T) => string
  /** Destaca a linha selecionada (ex.: filtro entre tabelas). */
  selecionada?: (l: T) => boolean
}) {
  const [ordem, setOrdem] = useState<SortingState>(ordemInicial ?? [])
  const [busca, setBusca] = useState('')
  const [filtros, setFiltros] = useState<ColumnFiltersState>([])
  const [paginacao, setPaginacao] = useState({ pageIndex: 0, pageSize: 25 })

  const maximos = useMemo(() => {
    const m: Record<string, number> = {}
    for (const c of colunas) if (c.barra) m[c.id] = Math.max(0, ...dados.map((d) => Number(c.valor(d)) || 0))
    return m
  }, [colunas, dados])

  const defs = useMemo<ColumnDef<T>[]>(() => colunas.map((c) => ({
    id: c.id,
    accessorFn: (l: T) => c.valor(l) ?? null,
    header: c.titulo,
    enableSorting: c.ordenavel !== false,
    sortUndefined: 'last',
    sortingFn: c.tipo === 'texto' ? 'text' : 'basic',
    filterFn: filtroColuna as FilterFn<T>,
    cell: ({ row }) => {
      const l = row.original
      if (c.render) return c.render(l)
      const v = c.valor(l)
      return (
        <>
          <span className={cn('inline-flex items-center gap-1.5', alinhadaDireita(c.tipo) && 'justify-end')}>
            {c.faixa && <SinalFaixa faixa={c.faixa} valor={v as number | null} />}
            <span className="tabular">{textoColuna(c, l)}</span>
          </span>
          {c.barra && <BarraValor valor={v as number | null} maximo={maximos[c.id]} />}
        </>
      )
    },
  })), [colunas, maximos])

  const filtrados = useMemo(() => {
    if (!busca.trim() || !pesquisarEm) return dados
    const t = busca.trim().toLowerCase()
    return dados.filter((d) => pesquisarEm(d).toLowerCase().includes(t))
  }, [dados, busca, pesquisarEm])

  const tabela = useReactTable({
    data: filtrados,
    columns: defs,
    state: { sorting: ordem, columnFilters: filtros, pagination: paginacao },
    onSortingChange: setOrdem,
    onColumnFiltersChange: setFiltros,
    onPaginationChange: setPaginacao,
    getCoreRowModel: getCoreRowModel(),
    getSortedRowModel: getSortedRowModel(),
    getFilteredRowModel: getFilteredRowModel(),
    getPaginationRowModel: getPaginationRowModel(),
    autoResetPageIndex: true,
  })

  const linhasFiltradas = tabela.getPrePaginationRowModel().rows.map((r) => r.original)
  const offsets = useMemo(() => {
    let acc = 0
    return colunas.map((c) => { const o = acc; if (c.fixa) acc += c.largura ?? 160; return o })
  }, [colunas])
  const ultimaFixa = colunas.reduce((u, c, i) => (c.fixa ? i : u), -1)

  return (
    <div>
      <div className="mb-3 flex flex-wrap items-center gap-2">
        {pesquisarEm && (
          <div className="relative w-full sm:w-72">
            <Search className="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
            <Input value={busca} onChange={(e) => setBusca(e.target.value)} placeholder={placeholderPesquisa} className="h-8 pl-8 text-xs" aria-label={placeholderPesquisa} />
          </div>
        )}
        {filtros.length > 0 && (
          <button className="text-xs text-primary underline-offset-2 hover:underline" onClick={() => setFiltros([])}>
            Limpar filtros das colunas ({filtros.length})
          </button>
        )}
        <div className="ml-auto flex flex-wrap items-center gap-2">
          {acoes}
          {podeExportar && <BotoesExportacao cabecalho={cabecalhoExportacao} colunas={colunas} obterLinhas={async () => linhasFiltradas} />}
        </div>
      </div>

      <div className="relative overflow-x-auto rounded-md border">
        <table className="w-full border-separate border-spacing-0 text-xs">
          <thead>
            {tabela.getHeaderGroups().map((hg) => (
              <tr key={hg.id}>
                {hg.headers.map((h, i) => {
                  const c = colunas[i]
                  const ordenado = h.column.getIsSorted()
                  const filtro = h.column.getFilterValue() as FiltroColuna | undefined
                  return (
                    <th key={h.id} scope="col"
                      aria-sort={ordenado === 'asc' ? 'ascending' : ordenado === 'desc' ? 'descending' : undefined}
                      style={c.fixa ? { left: offsets[i], minWidth: c.largura, width: c.largura, maxWidth: c.largura } : c.largura ? { minWidth: c.largura } : undefined}
                      className={cn('border-b bg-muted px-2.5 py-2 align-bottom text-[11px] font-semibold text-muted-foreground',
                        alinhadaDireita(c.tipo) ? 'text-right' : 'text-left',
                        c.fixa && 'sticky z-20', i === ultimaFixa && 'border-r')}>
                      <div className={cn('flex items-end gap-1', alinhadaDireita(c.tipo) && 'justify-end')}>
                        <button type="button" disabled={!h.column.getCanSort()} onClick={h.column.getToggleSortingHandler()}
                          className="inline-flex items-end gap-1 text-left leading-tight hover:text-foreground disabled:cursor-default">
                          <span>{c.titulo}</span>
                          {h.column.getCanSort() && (ordenado === 'asc' ? <ArrowUp className="size-3 shrink-0" /> : ordenado === 'desc' ? <ArrowDown className="size-3 shrink-0" /> : <ArrowUpDown className="size-3 shrink-0 opacity-40" />)}
                        </button>
                        {c.dica && (
                          <Dica conteudo={c.dica}><button type="button" aria-label={`Sobre: ${c.titulo}`} className="shrink-0 hover:text-foreground"><Info className="size-3" /></button></Dica>
                        )}
                        <FiltroCabecalho tipo={c.tipo} titulo={c.titulo} valor={filtro} aoMudar={(v) => h.column.setFilterValue(v)} />
                      </div>
                    </th>
                  )
                })}
              </tr>
            ))}
          </thead>
          <tbody>
            {tabela.getRowModel().rows.map((r) => (
              <tr key={r.id}
                className={cn('group', aoClicarLinha && 'cursor-pointer')}
                aria-selected={selecionada ? selecionada(r.original) : undefined}
                onClick={aoClicarLinha ? () => aoClicarLinha(r.original) : undefined}
                onKeyDown={aoClicarLinha ? (e) => { if (e.key === 'Enter') aoClicarLinha(r.original) } : undefined}
                tabIndex={aoClicarLinha ? 0 : undefined}
                aria-label={aoClicarLinha && rotuloLinha ? rotuloLinha(r.original) : undefined}>
                {r.getVisibleCells().map((cell, i) => {
                  const c = colunas[i]
                  return (
                    <td key={cell.id}
                      style={c.fixa ? { left: offsets[i], minWidth: c.largura, width: c.largura, maxWidth: c.largura } : undefined}
                      className={cn('border-b bg-card px-2.5 py-2 align-top group-hover:bg-muted/60 group-focus-visible:bg-muted',
                        alinhadaDireita(c.tipo) ? 'whitespace-nowrap text-right' : 'text-left',
                        c.fixa && 'sticky z-10', i === ultimaFixa && 'border-r', selecionada?.(r.original) && '!bg-accent')}>
                      {flexRender(cell.column.columnDef.cell, cell.getContext())}
                    </td>
                  )
                })}
              </tr>
            ))}
            {!tabela.getRowModel().rows.length && (
              <tr><td colSpan={colunas.length} className="py-8 text-center text-muted-foreground">Nenhum registro para os filtros atuais.</td></tr>
            )}
          </tbody>
          {rodape && <tfoot>{rodape}</tfoot>}
        </table>
      </div>

      <Paginacao
        pagina={paginacao.pageIndex + 1}
        ultima={Math.max(1, tabela.getPageCount())}
        total={linhasFiltradas.length}
        porPagina={paginacao.pageSize}
        aoMudarPagina={(p) => setPaginacao((x) => ({ ...x, pageIndex: p - 1 }))}
        aoMudarPorPagina={(n) => setPaginacao({ pageIndex: 0, pageSize: n })}
      />
    </div>
  )
}

function FiltroCabecalho({ tipo, titulo, valor, aoMudar }: { tipo: Coluna<unknown>['tipo']; titulo: string; valor?: FiltroColuna; aoMudar: (v: FiltroColuna | undefined) => void }) {
  const ativo = !!(valor?.texto || valor?.min || valor?.max)
  const numerico = alinhadaDireita(tipo)
  return (
    <Popover>
      <PopoverTrigger asChild>
        <button type="button" aria-label={`Filtrar ${titulo}`} className={cn('shrink-0 hover:text-foreground', ativo ? 'text-primary' : 'opacity-50')}>
          <Filter className="size-3" />
        </button>
      </PopoverTrigger>
      <PopoverContent className="w-60 p-3 text-xs" align="end">
        <p className="mb-2 font-semibold">Filtrar: {titulo}</p>
        {numerico ? (
          <div className="flex items-center gap-2">
            <Input type="number" inputMode="decimal" placeholder="Mínimo" className="h-8 text-xs" value={valor?.min ?? ''} onChange={(e) => aoMudar({ ...valor, min: e.target.value })} aria-label="Valor mínimo" />
            <Input type="number" inputMode="decimal" placeholder="Máximo" className="h-8 text-xs" value={valor?.max ?? ''} onChange={(e) => aoMudar({ ...valor, max: e.target.value })} aria-label="Valor máximo" />
          </div>
        ) : (
          <Input placeholder="Contém…" className="h-8 text-xs" value={valor?.texto ?? ''} onChange={(e) => aoMudar({ texto: e.target.value })} aria-label="Contém" />
        )}
        {ativo && <button className="mt-2 text-primary hover:underline" onClick={() => aoMudar(undefined)}>Limpar</button>}
      </PopoverContent>
    </Popover>
  )
}
