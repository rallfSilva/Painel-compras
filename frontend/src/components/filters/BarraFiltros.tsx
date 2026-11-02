import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { CalendarDays, ChevronDown, Filter, X } from 'lucide-react'
import { api } from '@/lib/api'
import type { NoFiltro, NomeFiltro, Periodo } from '@/lib/tipos'
import { useFiltros } from '@/contexts/FiltrosContext'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { ArvoreSelecao, GatilhoFiltro } from './ArvoreSelecao'
import { BuscaSelecao } from './BuscaSelecao'

type Opcoes = Partial<Record<NomeFiltro, NoFiltro[]>>

const PERIODOS: { valor: Periodo; rotulo: string }[] = [
  { valor: 'todo', rotulo: 'Todo o histórico' },
  { valor: 'ano_atual', rotulo: 'Ano atual' },
  { valor: 'ano_anterior', rotulo: 'Ano anterior' },
  { valor: 'ultimos_5', rotulo: 'Últimos 5 anos' },
  { valor: 'ultimos_10', rotulo: 'Últimos 10 anos' },
  { valor: 'personalizado', rotulo: 'Personalizado' },
]

/** Definição dos 13 filtros globais (seção 6.3), na ordem de exibição. */
const ARVORES: { nome: NomeFiltro; titulo: string }[] = [
  { nome: 'tempo', titulo: 'Ano, semestre, trimestre e mês' },
  { nome: 'porte', titulo: 'Porte empresarial' },
  { nome: 'sistematica', titulo: 'Sistemática › Forma de aquisição' },
  { nome: 'natureza', titulo: 'Natureza › Subelemento de despesa' },
  { nome: 'classe', titulo: 'Grupo › Classe' },
  { nome: 'comprador_local', titulo: 'Região › Município do comprador' },
  { nome: 'unidade', titulo: 'Unid. superior › Unidade compradora' },
  { nome: 'rp', titulo: 'Registro de preços?' },
  { nome: 'amparo', titulo: 'Amparo legal' },
  { nome: 'fornecedor_local', titulo: 'UF › Município do fornecedor' },
]

const BUSCAS: { nome: 'item' | 'fornecedor' | 'publicacao'; titulo: string; dica: string }[] = [
  { nome: 'item', titulo: 'Item (descrição › código)', dica: 'Código ou descrição do item' },
  { nome: 'fornecedor', titulo: 'Fornecedor (nome › CPF/CNPJ)', dica: 'Nome, CPF ou CNPJ' },
  { nome: 'publicacao', titulo: 'Nº de publicação', dica: 'Ex.: 2025/00123' },
]

export const TITULOS_FILTRO: Record<string, string> = Object.fromEntries([...ARVORES, ...BUSCAS].map((f) => [f.nome, f.titulo]))

function SeletorPeriodo() {
  const { estado, definirPeriodo } = useFiltros()
  const [ini, setIni] = useState(estado.dataInicio)
  const [fim, setFim] = useState(estado.dataFim)
  const atual = PERIODOS.find((p) => p.valor === estado.periodo)!
  const resumo = estado.periodo === 'personalizado' && (estado.dataInicio || estado.dataFim)
    ? `${estado.dataInicio ? estado.dataInicio.split('-').reverse().join('/') : '…'} a ${estado.dataFim ? estado.dataFim.split('-').reverse().join('/') : '…'}`
    : atual.rotulo

  return (
    <Popover>
      <PopoverTrigger className="w-full rounded-md">
        <GatilhoFiltro titulo="Período de análise" resumo={resumo} ativo={estado.periodo !== 'todo'} />
      </PopoverTrigger>
      <PopoverContent className="w-[min(300px,calc(100vw-24px))] p-2">
        <ul className="space-y-0.5">
          {PERIODOS.filter((p) => p.valor !== 'personalizado').map((p) => (
            <li key={p.valor}>
              <button
                className={cn('w-full rounded px-3 py-1.5 text-left text-sm hover:bg-muted', estado.periodo === p.valor && 'bg-accent font-medium text-accent-foreground')}
                onClick={() => definirPeriodo(p.valor)}
              >
                {p.rotulo}
              </button>
            </li>
          ))}
        </ul>
        <div className="mt-2 border-t pt-2">
          <p className="mb-1.5 flex items-center gap-1.5 px-1 text-xs font-medium text-muted-foreground"><CalendarDays className="size-3.5" /> Personalizado</p>
          <div className="grid grid-cols-2 gap-2">
            <label className="text-[11px] text-muted-foreground">De<Input type="date" value={ini} onChange={(e) => setIni(e.target.value)} /></label>
            <label className="text-[11px] text-muted-foreground">Até<Input type="date" value={fim} onChange={(e) => setFim(e.target.value)} /></label>
          </div>
          <Button size="sm" className="mt-2 w-full" disabled={!ini && !fim} onClick={() => definirPeriodo('personalizado', ini, fim)}>Aplicar período</Button>
        </div>
      </PopoverContent>
    </Popover>
  )
}

/**
 * Barra de filtros globais. Todos os filtros funcionam em conjunto e atualizam indicadores, gráficos e tabelas.
 * Em telas pequenas fica recolhida atrás do botão "Filtros".
 */
export function BarraFiltros() {
  const { estado, definirSelecao, remover, limpar, totalAtivos } = useFiltros()
  const [abertoMobile, setAbertoMobile] = useState(false)
  const { data, isLoading, isError } = useQuery({
    queryKey: ['filtros-opcoes'],
    queryFn: () => api<{ opcoes: Opcoes }>('/filtros/opcoes'),
    staleTime: 10 * 60_000,
  })

  const chips = Object.entries(estado.selecoes).flatMap(([nome, chaves]) =>
    (chaves ?? []).map((c) => ({ nome: nome as NomeFiltro, chave: c, rotulo: estado.rotulos[c] ?? c })),
  )

  return (
    <section className="no-print mb-5 rounded-lg border bg-card p-3" aria-label="Filtros globais">
      <div className="flex items-center justify-between gap-2">
        <button className="flex items-center gap-2 text-sm font-semibold lg:pointer-events-none" onClick={() => setAbertoMobile((a) => !a)} aria-expanded={abertoMobile}>
          <Filter className="size-4 text-primary" /> Filtros
          {totalAtivos > 0 && <span className="rounded-full bg-destaque px-1.5 text-[11px] font-semibold text-destaque-foreground">{totalAtivos}</span>}
          <ChevronDown className={cn('size-4 transition-transform lg:hidden', abertoMobile && 'rotate-180')} />
        </button>
        <Button variant="ghost" size="sm" onClick={limpar} disabled={totalAtivos === 0}>
          <X /> Limpar todos os filtros
        </Button>
      </div>

      <div className={cn('mt-3 grid-cols-1 gap-2 sm:grid-cols-2 lg:grid lg:grid-cols-4 2xl:grid-cols-7', abertoMobile ? 'grid' : 'hidden')}>
        <SeletorPeriodo />
        {isLoading && Array.from({ length: 6 }).map((_, i) => <div key={i} className="h-[52px] animate-pulse rounded-md bg-muted" />)}
        {isError && <p className="col-span-full text-xs text-danger">Não foi possível carregar as opções de filtro.</p>}
        {data && ARVORES.map((f) => (
          <ArvoreSelecao
            key={f.nome}
            titulo={f.titulo}
            nos={data.opcoes[f.nome] ?? []}
            selecionadas={estado.selecoes[f.nome] ?? []}
            rotulos={estado.rotulos}
            aoMudar={(chaves, rotulos) => definirSelecao(f.nome, chaves, rotulos)}
          />
        ))}
        {data && BUSCAS.map((f) => (
          <BuscaSelecao
            key={f.nome}
            titulo={f.titulo}
            tipo={f.nome}
            dica={f.dica}
            selecionadas={estado.selecoes[f.nome] ?? []}
            rotulos={estado.rotulos}
            aoMudar={(chaves, rotulos) => definirSelecao(f.nome, chaves, rotulos)}
          />
        ))}
      </div>

      {chips.length > 0 && (
        <div className="mt-3 flex flex-wrap gap-1.5 border-t pt-3">
          {chips.map((c) => (
            <span key={`${c.nome}-${c.chave}`} className="inline-flex max-w-full items-center gap-1 rounded-full border bg-accent/60 py-0.5 pl-2.5 pr-1 text-xs">
              <span className="text-muted-foreground">{TITULOS_FILTRO[c.nome]?.split(' ')[0]}:</span>
              <span className="truncate font-medium">{c.rotulo}</span>
              <button className="rounded-full p-0.5 hover:bg-background" onClick={() => remover(c.nome, c.chave)} aria-label={`Remover filtro ${c.rotulo}`}>
                <X className="size-3" />
              </button>
            </span>
          ))}
        </div>
      )}
    </section>
  )
}
