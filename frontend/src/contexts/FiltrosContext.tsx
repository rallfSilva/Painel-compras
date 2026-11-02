import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import type { NomeFiltro, Periodo } from '@/lib/tipos'

/**
 * Filtros globais persistentes durante a navegação (seção 31).
 * Ficam no estado da aplicação, são guardados na sessão do navegador e refletidos na URL,
 * para que um link copiado abra com os mesmos filtros.
 */
export interface EstadoFiltros {
  periodo: Periodo
  dataInicio: string
  dataFim: string
  selecoes: Partial<Record<NomeFiltro, string[]>>
  /** Rótulos legíveis das chaves selecionadas, para os chips de filtros ativos. */
  rotulos: Record<string, string>
}

const VAZIO: EstadoFiltros = { periodo: 'todo', dataInicio: '', dataFim: '', selecoes: {}, rotulos: {} }
const CHAVE = 'sic-filtros'

interface FiltrosCtx {
  estado: EstadoFiltros
  definirPeriodo: (p: Periodo, inicio?: string, fim?: string) => void
  definirSelecao: (filtro: NomeFiltro, chaves: string[], rotulos?: Record<string, string>) => void
  remover: (filtro: NomeFiltro, chave: string) => void
  limpar: () => void
  queryString: string
  totalAtivos: number
}

const Ctx = createContext<FiltrosCtx | null>(null)

export function paraQueryString(e: EstadoFiltros, extra: Record<string, string | boolean | undefined> = {}): string {
  const p = new URLSearchParams()
  if (e.periodo !== 'todo') p.set('periodo', e.periodo)
  if (e.periodo === 'personalizado') {
    if (e.dataInicio) p.set('data_inicio', e.dataInicio)
    if (e.dataFim) p.set('data_fim', e.dataFim)
  }
  for (const [nome, chaves] of Object.entries(e.selecoes)) {
    for (const c of chaves ?? []) p.append(`f[${nome}][]`, c)
  }
  for (const [k, v] of Object.entries(extra)) {
    if (v !== undefined && v !== '') p.set(k, String(v))
  }
  return p.toString()
}

function daUrl(): EstadoFiltros | null {
  const p = new URLSearchParams(window.location.search)
  if (![...p.keys()].some((k) => k === 'periodo' || k.startsWith('f['))) return null
  const selecoes: EstadoFiltros['selecoes'] = {}
  p.forEach((v, k) => {
    const m = k.match(/^f\[(\w+)\]\[\]$/)
    if (m) (selecoes[m[1] as NomeFiltro] ??= []).push(v)
  })
  return { periodo: (p.get('periodo') as Periodo) || 'todo', dataInicio: p.get('data_inicio') || '', dataFim: p.get('data_fim') || '', selecoes, rotulos: {} }
}

function inicial(): EstadoFiltros {
  const url = daUrl()
  if (url) return url
  try {
    const s = sessionStorage.getItem(CHAVE)
    if (s) return { ...VAZIO, ...JSON.parse(s) }
  } catch { /* sessão indisponível */ }
  return VAZIO
}

export function FiltrosProvider({ children }: { children: ReactNode }) {
  const [estado, setEstado] = useState<EstadoFiltros>(inicial)

  const queryString = useMemo(() => paraQueryString(estado), [estado])

  useEffect(() => {
    try { sessionStorage.setItem(CHAVE, JSON.stringify(estado)) } catch { /* ok */ }
    const url = `${window.location.pathname}${queryString ? `?${queryString}` : ''}`
    window.history.replaceState(window.history.state, '', url)
  }, [estado, queryString])

  const definirPeriodo = useCallback((periodo: Periodo, dataInicio = '', dataFim = '') => {
    setEstado((e) => ({ ...e, periodo, dataInicio, dataFim }))
  }, [])

  const definirSelecao = useCallback((filtro: NomeFiltro, chaves: string[], rotulos: Record<string, string> = {}) => {
    setEstado((e) => {
      const selecoes = { ...e.selecoes }
      if (chaves.length) selecoes[filtro] = chaves
      else delete selecoes[filtro]
      return { ...e, selecoes, rotulos: { ...e.rotulos, ...rotulos } }
    })
  }, [])

  const remover = useCallback((filtro: NomeFiltro, chave: string) => {
    setEstado((e) => {
      const restantes = (e.selecoes[filtro] ?? []).filter((c) => c !== chave)
      const selecoes = { ...e.selecoes }
      if (restantes.length) selecoes[filtro] = restantes
      else delete selecoes[filtro]
      return { ...e, selecoes }
    })
  }, [])

  const limpar = useCallback(() => setEstado(VAZIO), [])

  const totalAtivos = Object.values(estado.selecoes).reduce((t, l) => t + (l?.length ?? 0), 0) + (estado.periodo !== 'todo' ? 1 : 0)

  return (
    <Ctx.Provider value={{ estado, definirPeriodo, definirSelecao, remover, limpar, queryString, totalAtivos }}>
      {children}
    </Ctx.Provider>
  )
}

export function useFiltros() {
  const c = useContext(Ctx)
  if (!c) throw new Error('useFiltros fora do FiltrosProvider')
  return c
}
