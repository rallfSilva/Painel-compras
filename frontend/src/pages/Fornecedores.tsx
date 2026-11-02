import { useMemo } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Info, SearchCheck } from 'lucide-react'
import { api } from '@/lib/api'
import { descreverFiltros, paraQueryString, useFiltros } from '@/contexts/FiltrosContext'
import { useAuth } from '@/contexts/AuthContext'
import { useMeta } from '@/components/layout/Cabecalho'
import { BarraFiltros } from '@/components/filters/BarraFiltros'
import { FaixaIndicadores } from '@/components/compras/FaixaIndicadores'
import { CabecalhoModulo } from '@/components/compras/CabecalhoModulo'
import { TabelaServidor } from '@/components/tabela/TabelaServidor'
import type { Coluna } from '@/components/tabela/colunas'
import { useDetalhes } from '@/components/detalhes/useDetalhes'
import { formatarDocumento, formatarMoeda, formatarNumero, formatarPercentual } from '@/lib/format'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Dica } from '@/components/ui/tooltip'

interface LinhaFornecedor {
  id: number; razao_social: string; fornecedor_documento: string; porte: string | null; grupo_porte: string | null; municipio: string | null; uf: string | null
  contratado: number; processos: number; itens: number; orgaos: number; unidades: number; percentual: number | null; posicao: number | null
}
interface Concentracao {
  fornecedores: number; total_contratado: number; percentual_top5: number | null; percentual_top10: number | null; hhi: number | null
  classificacao_hhi: 'BAIXA' | 'MODERADA' | 'ALTA' | null; limite_sinalizacao: number
  sinalizadores: { unidade_id: number; unidade: string; fornecedor_id: number; fornecedor: string; percentual: number; valor: number; processos: number }[]
}

const HHI = { BAIXA: { rotulo: 'Concentração baixa', cor: 'sucesso' }, MODERADA: { rotulo: 'Concentração moderada', cor: 'alerta' }, ALTA: { rotulo: 'Concentração alta', cor: 'perigo' } } as const

/** Módulo 14 — Fornecedores (seção 23): ranking, concentração e ficha de cada fornecedor. */
export function Fornecedores() {
  const { estado } = useFiltros()
  const { pode } = useAuth()
  const { data: meta } = useMeta()
  const abrir = useDetalhes()
  const consulta = paraQueryString(estado)
  const conc = useQuery({ queryKey: ['concentracao', consulta], queryFn: () => api<Concentracao>(`/fornecedores/concentracao?${consulta}`), placeholderData: keepPreviousData })

  const colunas = useMemo<Coluna<LinhaFornecedor>[]>(() => [
    { id: 'posicao', titulo: '#', tipo: 'numero', valor: (l) => l.posicao, ordenavel: false },
    { id: 'razao_social', titulo: 'Fornecedor', tipo: 'texto', valor: (l) => l.razao_social, largura: 240,
      render: (l) => <span className="block"><span className="font-medium text-primary">{l.razao_social}</span><span className="block text-[11px] text-muted-foreground">{formatarDocumento(l.fornecedor_documento)}</span></span> },
    { id: 'fornecedor_documento', titulo: 'CPF/CNPJ', tipo: 'texto', valor: (l) => l.fornecedor_documento, texto: (l) => formatarDocumento(l.fornecedor_documento), ordenavel: false },
    { id: 'porte', titulo: 'Porte', tipo: 'texto', valor: (l) => l.porte,
      render: (l) => <span>{l.porte ?? 'Não informado'}{l.grupo_porte === 'MPE' && <Badge cor="primario" className="ml-1">MPE</Badge>}</span> },
    { id: 'uf', titulo: 'Município / UF', tipo: 'texto', valor: (l) => [l.municipio, l.uf].filter(Boolean).join(' / ') },
    { id: 'processos', titulo: 'Processos vencidos', tipo: 'numero', valor: (l) => l.processos },
    { id: 'itens', titulo: 'Itens', tipo: 'numero', valor: (l) => l.itens },
    { id: 'orgaos', titulo: 'Órgãos atendidos', tipo: 'numero', valor: (l) => l.orgaos },
    { id: 'contratado', titulo: 'Contratado (R$)', tipo: 'moeda', valor: (l) => l.contratado, barra: true, largura: 150 },
    { id: 'percentual', titulo: '% do total', tipo: 'percentual', valor: (l) => l.percentual, ordenavel: false },
  ], [])
  const colunasTela = colunas.filter((c) => c.id !== 'fornecedor_documento')

  const c = conc.data
  return (
    <div>
      <CabecalhoModulo titulo="Fornecedores" descricao="Ranking, concentração das contratações e histórico de fornecimento." consulta={consulta} />
      <BarraFiltros />
      <FaixaIndicadores consulta={consulta} codigos={['total_contratado', 'fornecedores', 'processos', 'itens_comprados']} monetarios={['total_contratado']} />

      <div className="mb-4 grid grid-cols-1 gap-4 xl:grid-cols-3">
        <Card>
          <CardHeader><div><CardTitle>Concentração das contratações</CardTitle><p className="mt-0.5 text-xs text-muted-foreground">Participação dos maiores fornecedores no valor contratado</p></div></CardHeader>
          <CardContent>
            {c ? (
              <dl className="space-y-3 text-sm">
                <div className="flex items-baseline justify-between gap-3"><dt className="text-muted-foreground">5 maiores fornecedores</dt><dd className="font-semibold tabular">{formatarPercentual(c.percentual_top5)}</dd></div>
                <div className="flex items-baseline justify-between gap-3"><dt className="text-muted-foreground">10 maiores fornecedores</dt><dd className="font-semibold tabular">{formatarPercentual(c.percentual_top10)}</dd></div>
                <div className="flex items-baseline justify-between gap-3">
                  <dt className="flex items-center gap-1 text-muted-foreground">Índice HHI
                    <Dica conteudo="Índice Herfindahl-Hirschman: soma dos quadrados das participações (%) de cada fornecedor. Abaixo de 1.500 indica baixa concentração; de 1.500 a 2.500, moderada; acima de 2.500, alta.">
                      <button aria-label="Sobre o índice HHI"><Info className="size-3.5" /></button>
                    </Dica>
                  </dt>
                  <dd className="flex items-center gap-2 font-semibold tabular">{c.hhi === null ? 'Não informado' : c.hhi.toLocaleString('pt-BR')}
                    {c.classificacao_hhi && <Badge cor={HHI[c.classificacao_hhi].cor}>{HHI[c.classificacao_hhi].rotulo}</Badge>}</dd>
                </div>
                <div className="flex items-baseline justify-between gap-3"><dt className="text-muted-foreground">Fornecedores com contratação</dt><dd className="font-semibold tabular">{formatarNumero(c.fornecedores)}</dd></div>
              </dl>
            ) : <p className="text-xs text-muted-foreground">Carregando…</p>}
          </CardContent>
        </Card>
        <Card className="xl:col-span-2">
          <CardHeader>
            <div>
              <CardTitle className="flex items-center gap-2"><SearchCheck className="size-4 text-warning" /> Sinalizadores de necessidade de análise</CardTitle>
              <p className="mt-0.5 text-xs text-muted-foreground">
                Unidades em que um único fornecedor concentra {c ? `${c.limite_sinalizacao}%` : 'o limite configurado'} ou mais do valor contratado (com ao menos 2 fornecedores e 5 processos).
                O sinal indica apenas que o caso merece análise; não é conclusão de irregularidade.
              </p>
            </div>
          </CardHeader>
          <CardContent>
            <div className="max-h-[260px] overflow-auto rounded-md border">
              <table className="w-full text-xs">
                <thead className="sticky top-0 bg-muted text-left text-[11px] text-muted-foreground">
                  <tr><th className="px-2.5 py-1.5 font-semibold">Unidade compradora</th><th className="px-2.5 py-1.5 font-semibold">Fornecedor</th>
                    <th className="px-2.5 py-1.5 text-right font-semibold">% da unidade</th><th className="px-2.5 py-1.5 text-right font-semibold">Valor (R$)</th></tr>
                </thead>
                <tbody>
                  {c?.sinalizadores.map((s) => (
                    <tr key={`${s.unidade_id}-${s.fornecedor_id}`} className="border-t">
                      <td className="px-2.5 py-1.5"><button className="text-left text-primary hover:underline" onClick={() => abrir('unidade', s.unidade_id, s.unidade)}>{s.unidade}</button></td>
                      <td className="px-2.5 py-1.5"><button className="text-left text-primary hover:underline" onClick={() => abrir('fornecedor', s.fornecedor_id, s.fornecedor)}>{s.fornecedor}</button></td>
                      <td className="px-2.5 py-1.5 text-right font-semibold tabular">{formatarPercentual(s.percentual)}</td>
                      <td className="whitespace-nowrap px-2.5 py-1.5 text-right tabular">{formatarMoeda(s.valor)}</td>
                    </tr>
                  ))}
                  {c && !c.sinalizadores.length && <tr><td colSpan={4} className="py-6 text-center text-muted-foreground">Nenhuma concentração acima do limite no recorte atual.</td></tr>}
                </tbody>
              </table>
            </div>
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader><div><CardTitle>Ranking de fornecedores</CardTitle><p className="mt-0.5 text-xs text-muted-foreground">Por valor contratado no recorte · clique em um fornecedor para ver o histórico de fornecimento</p></div></CardHeader>
        <CardContent>
          <TabelaServidor<LinhaFornecedor>
            endpoint="/fornecedores" consulta={consulta} colunas={colunasTela} colunasExportacao={colunas} ordemPadrao={{ id: 'contratado', direcao: 'desc' }}
            placeholderPesquisa="Razão social, nome fantasia ou CNPJ…" aoClicarLinha={(l) => abrir('fornecedor', l.id, l.razao_social)}
            podeExportar={pode('relatorios.exportar')}
            cabecalhoExportacao={{ titulo: 'Ranking de fornecedores', filtros: descreverFiltros(estado), ficticio: meta?.possui_dados_ficticios }} />
        </CardContent>
      </Card>
    </div>
  )
}
