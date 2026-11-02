import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { FileBarChart, Loader2 } from 'lucide-react'
import { paraQueryString, useFiltros } from '@/contexts/FiltrosContext'
import { useAuth } from '@/contexts/AuthContext'
import { api } from '@/lib/api'
import type { TipoColuna } from '@/lib/exportar'
import { BarraFiltros } from '@/components/filters/BarraFiltros'
import { CabecalhoModulo } from '@/components/compras/CabecalhoModulo'
import { Nota, useCabecalhoExportacao } from '@/components/inteligencia/partes'
import { TabelaAvancada } from '@/components/tabela/TabelaAvancada'
import type { Coluna } from '@/components/tabela/colunas'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Erro } from '@/components/detalhes/Partes'
import { formatarNumero } from '@/lib/format'
import { cn } from '@/lib/utils'

interface ItemLista { codigo: string; titulo: string; descricao: string }
type Linha = Record<string, string | number | null>
interface Relatorio {
  codigo: string; titulo: string; colunas: { id: string; titulo: string; tipo: TipoColuna }[]; linhas: Linha[]
  limitado: boolean; total_linhas: number; periodo: string
}

/** Módulo 16 — Relatórios gerenciais (seção 25): geração com os filtros globais e exportação em Excel, CSV e PDF. */
export function Relatorios() {
  const { estado } = useFiltros()
  const { pode } = useAuth()
  const cabecalho = useCabecalhoExportacao()
  const consulta = paraQueryString(estado)
  const [codigo, setCodigo] = useState<string | null>(null)
  const lista = useQuery({ queryKey: ['relatorios'], queryFn: () => api<{ relatorios: ItemLista[] }>('/relatorios') })
  const r = useQuery({ queryKey: ['relatorio', codigo, consulta], queryFn: () => api<Relatorio>(`/relatorios/${codigo}?${consulta}`), enabled: codigo !== null && pode('relatorios.exportar') })

  const colunas = useMemo<Coluna<Linha>[]>(() => (r.data?.colunas ?? []).map((c, i) => ({
    id: c.id, titulo: c.titulo, tipo: c.tipo, valor: (l: Linha) => l[c.id], ...(i === 0 ? { fixa: true, largura: 200 } : {}),
  })), [r.data])

  if (!pode('relatorios.exportar')) {
    return (
      <div>
        <CabecalhoModulo titulo="Relatórios gerenciais" consulta={consulta} />
        <Nota>Seu perfil não tem a permissão de gerar e exportar relatórios. Solicite ao administrador do sistema.</Nota>
      </div>
    )
  }

  return (
    <div>
      <CabecalhoModulo titulo="Relatórios gerenciais" descricao="Escolha um relatório: ele é gerado com os filtros globais atuais e pode ser exportado em Excel, CSV ou PDF." consulta={consulta} />
      <BarraFiltros />
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-[280px_minmax(0,1fr)]">
        <Card className="self-start">
          <CardHeader><CardTitle>Relatórios disponíveis</CardTitle></CardHeader>
          <CardContent className="p-2 pt-0">
            {lista.isLoading ? <div className="flex h-24 items-center justify-center"><Loader2 className="size-5 animate-spin text-primary" /></div>
              : lista.isError ? <Erro erro={lista.error} />
              : (
                <ul className="max-h-[70vh] space-y-0.5 overflow-auto" aria-label="Relatórios">
                  {lista.data?.relatorios.map((x) => (
                    <li key={x.codigo}>
                      <button onClick={() => setCodigo(x.codigo)} aria-current={codigo === x.codigo}
                        className={cn('w-full rounded-md px-3 py-2 text-left text-sm transition-colors', codigo === x.codigo ? 'bg-accent text-accent-foreground' : 'hover:bg-muted')}>
                        <span className="block font-medium">{x.titulo}</span>
                        <span className="block text-xs text-muted-foreground">{x.descricao}</span>
                      </button>
                    </li>
                  ))}
                </ul>
              )}
          </CardContent>
        </Card>
        <Card className="min-w-0">
          {codigo === null ? (
            <CardContent className="flex flex-col items-center justify-center gap-2 py-16 text-center text-sm text-muted-foreground">
              <FileBarChart className="size-8" />
              Selecione um relatório na lista para gerar a prévia.
            </CardContent>
          ) : r.isLoading ? (
            <CardContent className="flex h-48 items-center justify-center"><Loader2 className="size-6 animate-spin text-primary" /></CardContent>
          ) : r.isError ? <Erro erro={r.error} /> : r.data && (
            <>
              <CardHeader>
                <div>
                  <CardTitle>{r.data.titulo}</CardTitle>
                  <p className="mt-0.5 text-xs text-muted-foreground">{r.data.periodo} · {formatarNumero(r.data.total_linhas)} linhas</p>
                </div>
              </CardHeader>
              <CardContent className={cn(r.isFetching && 'opacity-70')}>
                {r.data.limitado && <Nota className="mb-3">O relatório tem {formatarNumero(r.data.total_linhas)} linhas; a prévia e a exportação trazem as primeiras 5.000. Refine os filtros para um recorte menor.</Nota>}
                <TabelaAvancada<Linha> colunas={colunas} dados={r.data.linhas} podeExportar cabecalhoExportacao={cabecalho(r.data.titulo, [r.data.periodo])} />
              </CardContent>
            </>
          )}
        </Card>
      </div>
    </div>
  )
}
