import { useEffect, useState, type ReactNode } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { ArrowDown, ArrowUp, ArrowUpDown, Info, Loader2, Search } from 'lucide-react'
import { api } from '@/lib/api'
import type { Cabecalho } from '@/lib/exportar'
import { Input } from '@/components/ui/input'
import { Dica } from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import { alinhadaDireita, BarraValor, SinalFaixa, textoColuna, type Coluna } from './colunas'
import { BotoesExportacao, Paginacao } from './partes'

export interface RespostaPaginada<T> { dados: T[]; pagina: number; por_pagina: number; total: number; ultima_pagina: number }

function useAtrasado<T>(v: T, ms = 350): T {
  const [x, setX] = useState(v)
  useEffect(() => { const t = setTimeout(() => setX(v), ms); return () => clearTimeout(t) }, [v, ms])
  return x
}

/**
 * Tabela paginada no servidor, para listas grandes (processos, itens, fornecedores).
 * Ordenação e pesquisa são feitas pela API; a exportação busca todas as linhas do recorte.
 */
export function TabelaServidor<T extends { id: number }, R extends RespostaPaginada<T> = RespostaPaginada<T>>({
  endpoint, consulta, colunas, colunasExportacao, ordemPadrao, placeholderPesquisa, aoClicarLinha, cabecalhoExportacao, podeExportar, acoes, aoCarregar, colunaMaximos,
}: {
  endpoint: string
  /** Query string dos filtros globais e locais. */
  consulta: string
  colunas: Coluna<T>[]
  /** Colunas da exportação, quando diferentes das exibidas. */
  colunasExportacao?: Coluna<T>[]
  ordemPadrao: { id: string; direcao: 'asc' | 'desc' }
  placeholderPesquisa?: string
  aoClicarLinha?: (l: T) => void
  cabecalhoExportacao: Cabecalho
  podeExportar: boolean
  acoes?: ReactNode
  aoCarregar?: (r: R) => void
  /** Máximo usado nas barras (ex.: total da primeira posição), já que a página não tem todos os dados. */
  colunaMaximos?: Record<string, number>
}) {
  const [ordem, setOrdem] = useState(ordemPadrao)
  const [pagina, setPagina] = useState(1)
  const [porPagina, setPorPagina] = useState(25)
  const [busca, setBusca] = useState('')
  const buscaAtrasada = useAtrasado(busca)

  useEffect(() => setPagina(1), [consulta, buscaAtrasada, ordem])

  const params = (extra: Record<string, string>) => {
    const p = new URLSearchParams(consulta)
    p.set('ordem', ordem.id)
    p.set('direcao', ordem.direcao)
    if (buscaAtrasada.trim()) p.set('busca', buscaAtrasada.trim())
    for (const [k, v] of Object.entries(extra)) p.set(k, v)
    return p.toString()
  }

  const q = useQuery({
    queryKey: [endpoint, consulta, ordem, pagina, porPagina, buscaAtrasada],
    queryFn: () => api<R>(`${endpoint}?${params({ pagina: String(pagina), por_pagina: String(porPagina) })}`),
    placeholderData: keepPreviousData,
  })

  useEffect(() => { if (q.data && aoCarregar) aoCarregar(q.data) }, [q.data, aoCarregar])

  const linhas = q.data?.dados ?? []
  const maximos: Record<string, number> = { ...colunaMaximos }
  for (const c of colunas) if (c.barra && maximos[c.id] === undefined) maximos[c.id] = Math.max(0, ...linhas.map((l) => Number(c.valor(l)) || 0))

  const alternar = (id: string) => setOrdem((o) => (o.id === id ? { id, direcao: o.direcao === 'desc' ? 'asc' : 'desc' } : { id, direcao: 'desc' }))

  return (
    <div>
      <div className="mb-3 flex flex-wrap items-center gap-2">
        <div className="relative w-full sm:w-80">
          <Search className="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
          <Input value={busca} onChange={(e) => setBusca(e.target.value)} placeholder={placeholderPesquisa ?? 'Pesquisar…'} className="h-8 pl-8 text-xs" aria-label={placeholderPesquisa ?? 'Pesquisar'} />
        </div>
        {q.isFetching && <Loader2 className="size-4 animate-spin text-primary" aria-label="Carregando" />}
        <div className="ml-auto flex flex-wrap items-center gap-2">
          {acoes}
          {podeExportar && (
            <BotoesExportacao cabecalho={cabecalhoExportacao} colunas={colunasExportacao ?? colunas}
              obterLinhas={async () => (await api<R>(`${endpoint}?${params({ pagina: '1', por_pagina: '5000', exportar: '1' })}`)).dados} />
          )}
        </div>
      </div>

      {q.isError && <p className="mb-3 rounded-md border border-danger/40 bg-danger/10 p-3 text-xs">Não foi possível carregar: {(q.error as Error).message}</p>}

      <div className={cn('relative overflow-x-auto rounded-md border', q.isFetching && 'opacity-80')}>
        <table className="w-full border-separate border-spacing-0 text-xs">
          <thead>
            <tr>
              {colunas.map((c, i) => {
                const ativo = ordem.id === c.id
                return (
                  <th key={c.id} scope="col" aria-sort={ativo ? (ordem.direcao === 'asc' ? 'ascending' : 'descending') : undefined}
                    style={c.largura ? { minWidth: c.largura } : undefined}
                    className={cn('border-b bg-muted px-2.5 py-2 align-bottom text-[11px] font-semibold text-muted-foreground', alinhadaDireita(c.tipo) ? 'text-right' : 'text-left',
                      i === 0 && c.fixa && 'sticky left-0 z-20 border-r')}>
                    <div className={cn('flex items-end gap-1', alinhadaDireita(c.tipo) && 'justify-end')}>
                      {c.ordenavel === false ? <span>{c.titulo}</span> : (
                        <button type="button" onClick={() => alternar(c.id)} className="inline-flex items-end gap-1 text-left leading-tight hover:text-foreground">
                          <span>{c.titulo}</span>
                          {ativo ? (ordem.direcao === 'asc' ? <ArrowUp className="size-3 shrink-0" /> : <ArrowDown className="size-3 shrink-0" />) : <ArrowUpDown className="size-3 shrink-0 opacity-40" />}
                        </button>
                      )}
                      {c.dica && <Dica conteudo={c.dica}><button type="button" aria-label={`Sobre: ${c.titulo}`}><Info className="size-3" /></button></Dica>}
                    </div>
                  </th>
                )
              })}
            </tr>
          </thead>
          <tbody>
            {linhas.map((l) => (
              <tr key={l.id} className={cn('group', aoClicarLinha && 'cursor-pointer')} tabIndex={aoClicarLinha ? 0 : undefined}
                onClick={aoClicarLinha ? () => aoClicarLinha(l) : undefined}
                onKeyDown={aoClicarLinha ? (e) => { if (e.key === 'Enter') aoClicarLinha(l) } : undefined}>
                {colunas.map((c, i) => {
                  const v = c.valor(l)
                  return (
                    <td key={c.id} style={i === 0 && c.fixa ? { minWidth: c.largura, maxWidth: c.largura } : undefined}
                      className={cn('border-b bg-card px-2.5 py-2 align-top group-hover:bg-muted/60 group-focus-visible:bg-muted',
                        alinhadaDireita(c.tipo) ? 'whitespace-nowrap text-right' : 'text-left', i === 0 && c.fixa && 'sticky left-0 z-10 border-r')}>
                      {c.render ? c.render(l) : (
                        <>
                          <span className={cn('inline-flex items-center gap-1.5', alinhadaDireita(c.tipo) && 'justify-end')}>
                            {c.faixa && <SinalFaixa faixa={c.faixa} valor={v as number | null} />}
                            <span className="tabular">{textoColuna(c, l)}</span>
                          </span>
                          {c.barra && <BarraValor valor={v as number | null} maximo={maximos[c.id]} />}
                        </>
                      )}
                    </td>
                  )
                })}
              </tr>
            ))}
            {!q.isLoading && !linhas.length && <tr><td colSpan={colunas.length} className="py-8 text-center text-muted-foreground">Nenhum registro para os filtros atuais.</td></tr>}
            {q.isLoading && <tr><td colSpan={colunas.length} className="py-8 text-center"><Loader2 className="mx-auto size-5 animate-spin text-primary" /></td></tr>}
          </tbody>
        </table>
      </div>

      <Paginacao pagina={q.data?.pagina ?? pagina} ultima={q.data?.ultima_pagina ?? 1} total={q.data?.total ?? 0} porPagina={porPagina}
        aoMudarPagina={setPagina} aoMudarPorPagina={(n) => { setPorPagina(n); setPagina(1) }} />
    </div>
  )
}
