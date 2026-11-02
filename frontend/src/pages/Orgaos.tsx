import { useMemo, useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { ChevronDown, ChevronRight, Filter, Loader2, Search } from 'lucide-react'
import { api } from '@/lib/api'
import { descreverFiltros, paraQueryString, useFiltros } from '@/contexts/FiltrosContext'
import { useAuth } from '@/contexts/AuthContext'
import { useMeta } from '@/components/layout/Cabecalho'
import { formatarMoeda, formatarNumero, formatarPercentual } from '@/lib/format'
import { BarraFiltros } from '@/components/filters/BarraFiltros'
import { FaixaIndicadores } from '@/components/compras/FaixaIndicadores'
import { CabecalhoModulo } from '@/components/compras/CabecalhoModulo'
import { useDetalhes } from '@/components/detalhes/useDetalhes'
import { BotoesExportacao } from '@/components/tabela/partes'
import type { Coluna } from '@/components/tabela/colunas'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

interface Totais { contratado: number; percentual: number | null; processos: number; fornecedores: number }
interface Unidade extends Totais { id: number; chave: string; nome: string; uasg: string | null; municipio: string | null }
interface Orgao extends Totais { id: number; chave: string; nome: string; sigla: string | null; tipo: string; unidades: Unidade[] }
interface UnidadeSuperior extends Totais { id: number | null; chave: string | null; nome: string; orgaos: Orgao[] }

interface LinhaPlana { id: number; nivel: string; unidade_superior: string; orgao: string; unidade: string; municipio: string; contratado: number; percentual: number | null; processos: number; fornecedores: number }

const COLUNAS_EXPORTACAO: Coluna<LinhaPlana>[] = [
  { id: 'unidade_superior', titulo: 'Unidade superior', tipo: 'texto', valor: (l) => l.unidade_superior },
  { id: 'orgao', titulo: 'Órgão', tipo: 'texto', valor: (l) => l.orgao },
  { id: 'unidade', titulo: 'Unidade compradora', tipo: 'texto', valor: (l) => l.unidade },
  { id: 'municipio', titulo: 'Município', tipo: 'texto', valor: (l) => l.municipio },
  { id: 'contratado', titulo: 'Contratado (R$)', tipo: 'moeda', valor: (l) => l.contratado },
  { id: 'percentual', titulo: '% do total', tipo: 'percentual', valor: (l) => l.percentual },
  { id: 'processos', titulo: 'Processos', tipo: 'numero', valor: (l) => l.processos },
  { id: 'fornecedores', titulo: 'Fornecedores', tipo: 'numero', valor: (l) => l.fornecedores },
]

/** Módulo 15 — Órgãos e Unidades Compradoras: hierarquia Unidade superior > Órgão > Unidade compradora. */
export function Orgaos() {
  const { estado, aplicarCruzado } = useFiltros()
  const { pode } = useAuth()
  const { data: meta } = useMeta()
  const abrir = useDetalhes()
  const consulta = paraQueryString(estado)
  const [abertos, setAbertos] = useState<Set<string>>(new Set())
  const [busca, setBusca] = useState('')
  const q = useQuery({ queryKey: ['orgaos-arvore', consulta], queryFn: () => api<{ dados: UnidadeSuperior[] }>(`/orgaos/arvore?${consulta}`), placeholderData: keepPreviousData })

  const termo = busca.trim().toLowerCase()
  const arvore = useMemo(() => {
    const dados = q.data?.dados ?? []
    if (!termo) return dados
    return dados.map((us) => ({
      ...us,
      orgaos: us.orgaos.map((o) => {
        const orgaoBate = `${o.nome} ${o.sigla ?? ''}`.toLowerCase().includes(termo)
        return { ...o, unidades: orgaoBate ? o.unidades : o.unidades.filter((u) => u.nome.toLowerCase().includes(termo)) }
      }).filter((o) => o.unidades.length || `${o.nome} ${o.sigla ?? ''}`.toLowerCase().includes(termo)),
    })).filter((us) => us.orgaos.length)
  }, [q.data, termo])

  const plana = useMemo<LinhaPlana[]>(() => arvore.flatMap((us) => us.orgaos.flatMap((o) => o.unidades.map((u) => ({
    id: u.id, nivel: 'unidade', unidade_superior: us.nome, orgao: o.sigla ? `${o.sigla} — ${o.nome}` : o.nome, unidade: u.nome, municipio: u.municipio ?? '',
    contratado: u.contratado, percentual: u.percentual, processos: u.processos, fornecedores: u.fornecedores,
  })))), [arvore])

  const max = Math.max(1, ...arvore.map((u) => u.contratado))
  const alternar = (k: string) => setAbertos((s) => { const n = new Set(s); if (n.has(k)) n.delete(k); else n.add(k); return n })
  const expandido = (k: string) => !!termo || abertos.has(k)

  const linha = (nivel: 0 | 1 | 2, chaveUi: string, nome: string, extra: string | null, t: Totais, opts: { expansivel?: boolean; filtro?: [string, string]; aoAbrir?: () => void }) => (
    <tr key={chaveUi} className={cn('group border-b', nivel === 0 && 'bg-muted/40 font-semibold')}>
      <td className="px-2.5 py-2">
        <div className="flex items-center gap-1.5" style={{ paddingLeft: nivel * 20 }}>
          {opts.expansivel ? (
            <button onClick={() => alternar(chaveUi)} aria-expanded={expandido(chaveUi)} aria-label={`${expandido(chaveUi) ? 'Recolher' : 'Expandir'} ${nome}`} className="rounded p-0.5 hover:bg-muted">
              {expandido(chaveUi) ? <ChevronDown className="size-3.5" /> : <ChevronRight className="size-3.5" />}
            </button>
          ) : <span className="w-[18px]" />}
          {opts.aoAbrir ? <button className="text-left font-medium text-primary hover:underline" onClick={opts.aoAbrir}>{nome}</button> : <span>{nome}</span>}
          {extra && <span className="text-[11px] font-normal text-muted-foreground">{extra}</span>}
        </div>
      </td>
      <td className="whitespace-nowrap px-2.5 py-2 text-right tabular">
        {formatarMoeda(t.contratado)}
        <span className="mt-1 block h-1.5 rounded-full bg-muted" aria-hidden><span className="block h-1.5 rounded-full" style={{ width: `${(t.contratado / max) * 100}%`, background: 'var(--serie-1)' }} /></span>
      </td>
      <td className="px-2.5 py-2 text-right tabular">{formatarPercentual(t.percentual)}</td>
      <td className="px-2.5 py-2 text-right tabular">{formatarNumero(t.processos)}</td>
      <td className="px-2.5 py-2 text-right tabular">{formatarNumero(t.fornecedores)}</td>
      <td className="no-print px-2.5 py-2 text-right">
        {opts.filtro && (
          <Button variant="ghost" size="sm" className="h-7 px-2 text-[11px]" onClick={() => aplicarCruzado('unidade', opts.filtro![0], opts.filtro![1])} aria-label={`Filtrar o painel por ${nome}`}>
            <Filter className="size-3.5" /> Filtrar
          </Button>
        )}
      </td>
    </tr>
  )

  return (
    <div>
      <CabecalhoModulo titulo="Órgãos e Unidades Compradoras" descricao="Unidades superiores, órgãos e unidades compradoras, com o volume contratado de cada nível." consulta={consulta} />
      <BarraFiltros />
      <FaixaIndicadores codigos={['total_contratado', 'economia', 'unidades_compradoras', 'processos', 'fornecedores', 'qtd_atas', 'contratos_vigentes']} monetarios={['total_contratado', 'economia']} consulta={consulta} />
      <Card>
        <CardContent className="pt-5">
          <div className="mb-3 flex flex-wrap items-center gap-2">
            <div className="relative w-full sm:w-80">
              <Search className="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
              <Input value={busca} onChange={(e) => setBusca(e.target.value)} placeholder="Pesquisar órgão ou unidade…" className="h-8 pl-8 text-xs" aria-label="Pesquisar órgão ou unidade" />
            </div>
            <Button variant="outline" size="sm" className="h-7 text-[11px]" onClick={() => setAbertos(new Set(arvore.flatMap((us) => [`us${us.id}`, ...us.orgaos.map((o) => `o${o.id}`)])))}>Expandir tudo</Button>
            <Button variant="outline" size="sm" className="h-7 text-[11px]" onClick={() => setAbertos(new Set())}>Recolher tudo</Button>
            {q.isFetching && <Loader2 className="size-4 animate-spin text-primary" />}
            <div className="ml-auto">
              {pode('relatorios.exportar') && <BotoesExportacao colunas={COLUNAS_EXPORTACAO} obterLinhas={async () => plana}
                cabecalho={{ titulo: 'Órgãos e unidades compradoras', filtros: descreverFiltros(estado), ficticio: meta?.possui_dados_ficticios }} />}
            </div>
          </div>
          {q.isLoading ? <div className="flex h-40 items-center justify-center"><Loader2 className="size-6 animate-spin text-primary" /></div> : (
            <div className="relative overflow-x-auto rounded-md border">
              <table className="w-full min-w-[720px] text-xs">
                <thead className="bg-muted text-[11px] text-muted-foreground">
                  <tr>
                    <th className="px-2.5 py-2 text-left font-semibold">Unidade superior › Órgão › Unidade compradora</th>
                    <th className="w-44 px-2.5 py-2 text-right font-semibold">Contratado (R$)</th>
                    <th className="px-2.5 py-2 text-right font-semibold">% do total</th>
                    <th className="px-2.5 py-2 text-right font-semibold">Processos</th>
                    <th className="px-2.5 py-2 text-right font-semibold">Fornecedores</th>
                    <th className="no-print px-2.5 py-2"><span className="sr-only">Ações</span></th>
                  </tr>
                </thead>
                <tbody>
                  {arvore.map((us) => [
                    linha(0, `us${us.id}`, us.nome, `${us.orgaos.length} órgão(s)`, us, { expansivel: true, filtro: us.chave ? [us.chave, us.nome] : undefined }),
                    ...(expandido(`us${us.id}`) ? us.orgaos.flatMap((o) => [
                      linha(1, `o${o.id}`, o.sigla ? `${o.sigla} — ${o.nome}` : o.nome, `${o.unidades.length} unidade(s)`, o, { expansivel: true, filtro: [o.chave, o.sigla ?? o.nome] }),
                      ...(expandido(`o${o.id}`) ? o.unidades.map((u) => linha(2, `u${u.id}`, u.nome, [u.municipio, u.uasg ? `UASG ${u.uasg}` : null].filter(Boolean).join(' · ') || null, u,
                        { filtro: [u.chave, u.nome], aoAbrir: () => abrir('unidade', u.id, u.nome) })) : []),
                    ]) : []),
                  ])}
                  {!arvore.length && <tr><td colSpan={6} className="py-8 text-center text-muted-foreground">Nenhum órgão para os filtros atuais.</td></tr>}
                </tbody>
              </table>
            </div>
          )}
          <p className="mt-2 text-[11px] text-muted-foreground">Fornecedores de cada nível são contados sem repetição, por isso não equivalem à soma dos níveis abaixo.</p>
        </CardContent>
      </Card>
    </div>
  )
}
