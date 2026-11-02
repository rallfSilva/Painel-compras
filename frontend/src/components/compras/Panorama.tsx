import { useMemo, useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Loader2 } from 'lucide-react'
import { api } from '@/lib/api'
import { descreverFiltros, paraQueryString, useFiltros } from '@/contexts/FiltrosContext'
import { useAuth } from '@/contexts/AuthContext'
import { useMeta } from '@/components/layout/Cabecalho'
import { formatarMoeda } from '@/lib/format'
import { Card, CardContent } from '@/components/ui/card'
import { TabelaAvancada } from '@/components/tabela/TabelaAvancada'
import type { Coluna, Faixa } from '@/components/tabela/colunas'
import { useDetalhes } from '@/components/detalhes/useDetalhes'

export interface LinhaPanorama {
  id: number; unidade: string; orgao: string | null; orgao_id: number; unidade_superior: string | null
  total_comprado: number | null; percentual_total: number | null; processos: number; itens: number; media_itens_processo: number | null
  percentual_economia: number | null; mediana_diferenca_estado: number | null; itens_comparados: number; fornecedores: number
  percentual_mpe: number | null; percentual_estado: number | null; percentual_regiao: number | null; percentual_disputa: number | null
  percentual_eletronicos: number | null
}
interface Resposta {
  dados: LinhaPanorama[]; total_geral: number; faixas: Record<string, Faixa>
  opcoes: { metrica_disputa: 'quantidade' | 'valor'; regiao_id: number | null; economia_base: string; incluir_sem_disputa: boolean; minimo_observacoes_preco: number }
  regioes: { id: number; nome: string }[]
}

/** PANORAMA DAS CONTRATAÇÕES POR UNIDADE CONTRATANTE (seção 8): tabela de 14 colunas. */
export function Panorama({ base, incluirSemDisputa }: { base?: string; incluirSemDisputa?: boolean }) {
  const { estado } = useFiltros()
  const { pode } = useAuth()
  const { data: meta } = useMeta()
  const abrir = useDetalhes()
  const [metrica, setMetrica] = useState<'quantidade' | 'valor'>('quantidade')
  const [regiao, setRegiao] = useState('')

  const consulta = paraQueryString(estado, {
    metrica_disputa: metrica, regiao_id: regiao || undefined, economia_base: base,
    incluir_sem_disputa: incluirSemDisputa === undefined ? undefined : incluirSemDisputa ? '1' : '0',
  })
  const q = useQuery({ queryKey: ['panorama', consulta], queryFn: () => api<Resposta>(`/compras/panorama?${consulta}`), placeholderData: keepPreviousData })
  const fx = q.data?.faixas
  const nomeRegiao = q.data?.regioes.find((r) => String(r.id) === regiao)?.nome
  const minimo = q.data?.opcoes.minimo_observacoes_preco ?? 3

  const colunas = useMemo<Coluna<LinhaPanorama>[]>(() => [
    { id: 'unidade', titulo: 'Unidade Contratante', tipo: 'texto', valor: (l) => l.unidade, fixa: true, largura: 220,
      render: (l) => <span className="block"><span className="font-medium text-primary">{l.unidade}</span><span className="block text-[11px] text-muted-foreground">{l.orgao}{l.unidade_superior ? ` · ${l.unidade_superior}` : ''}</span></span> },
    { id: 'total_comprado', titulo: 'Total Comprado (R$)', tipo: 'moeda', valor: (l) => l.total_comprado, barra: true, largura: 150,
      dica: 'Soma do valor contratado dos itens da unidade no recorte.' },
    { id: 'percentual_total', titulo: '% Total Comprado', tipo: 'percentual', valor: (l) => l.percentual_total, dica: 'Total comprado da unidade ÷ total comprado de todas as unidades × 100.' },
    { id: 'processos', titulo: 'Nº de Processos', tipo: 'numero', valor: (l) => l.processos },
    { id: 'itens', titulo: 'Nº de Itens', tipo: 'numero', valor: (l) => l.itens, dica: 'Itens com resultado (adjudicados, homologados ou contratados).' },
    { id: 'media_itens_processo', titulo: 'Média de Itens por Processo', tipo: 'decimal', valor: (l) => l.media_itens_processo, dica: 'Quantidade de itens ÷ quantidade de processos.' },
    { id: 'percentual_economia', titulo: '% Economia', tipo: 'percentual', valor: (l) => l.percentual_economia, faixa: fx?.percentual_economia,
      dica: `(Estimado − valor ${q.data?.opcoes.economia_base ?? 'contratado'}) ÷ estimado × 100, nos itens comparáveis. ${q.data?.opcoes.incluir_sem_disputa ? 'Inclui' : 'Exclui'} procedimentos sem disputa de preço.` },
    { id: 'mediana_diferenca_estado', titulo: 'Mediana da Dif. % em relação ao Estado', tipo: 'percentual', valor: (l) => l.mediana_diferenca_estado, faixa: fx?.mediana_diferenca_estado, largura: 130,
      texto: (l) => (l.mediana_diferenca_estado === null ? 'Sem base comparável' : `${l.mediana_diferenca_estado > 0 ? '+' : ''}${l.mediana_diferenca_estado.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}% (${l.itens_comparados} itens)`),
      dica: `Mediana das diferenças entre o preço unitário pago pela unidade e a mediana estadual do mesmo item de catálogo, mesma unidade de fornecimento e mesmo ano (mínimo de ${minimo} preços). Negativo: a unidade pagou menos que o Estado. Itens sem código de catálogo não são comparados.` },
    { id: 'fornecedores', titulo: 'Nº de Fornecedores', tipo: 'numero', valor: (l) => l.fornecedores },
    { id: 'percentual_mpe', titulo: '% Contratações de MPE', tipo: 'percentual', valor: (l) => l.percentual_mpe, faixa: fx?.percentual_mpe,
      dica: 'Valor contratado de fornecedores MEI, ME, EPP ÷ valor contratado elegível (com fornecedor informado) × 100.' },
    { id: 'percentual_estado', titulo: '% Contratações no Estado', tipo: 'percentual', valor: (l) => l.percentual_estado, faixa: fx?.percentual_estado,
      dica: 'Valor contratado de fornecedores com endereço cadastral em Roraima ÷ valor elegível × 100.' },
    { id: 'percentual_regiao', titulo: '% Contratações na Região', tipo: 'percentual', valor: (l) => l.percentual_regiao,
      dica: nomeRegiao ? `Fornecedores da região "${nomeRegiao}" ÷ valor elegível × 100.` : 'Fornecedores da mesma região de planejamento da unidade compradora ÷ valor elegível × 100.' },
    { id: 'percentual_disputa', titulo: '% Contratações com Disputa', tipo: 'percentual', valor: (l) => l.percentual_disputa, faixa: fx?.percentual_disputa,
      dica: metrica === 'valor' ? 'Calculado por VALOR: contratado em procedimentos com disputa ÷ total contratado × 100.' : 'Calculado por QUANTIDADE: processos com disputa ÷ total de processos × 100.' },
    { id: 'percentual_eletronicos', titulo: '% Nº de Processos Eletrônicos', tipo: 'percentual', valor: (l) => l.percentual_eletronicos, faixa: fx?.percentual_eletronicos,
      dica: 'Processos em forma eletrônica ÷ total de processos × 100.' },
  ], [fx, q.data?.opcoes, nomeRegiao, metrica, minimo])

  return (
    <Card>
      <CardContent className="pt-5">
        <div className="mb-4">
          <h2 className="text-base font-bold uppercase tracking-tight">Panorama das contratações por unidade contratante</h2>
          <p className="mt-0.5 text-xs text-muted-foreground">Clique em uma unidade para abrir o detalhamento. Passe o mouse nos títulos das colunas para ver como cada uma é calculada.</p>
        </div>
        <div className="no-print mb-3 flex flex-wrap items-center gap-x-5 gap-y-2 rounded-lg border border-dashed px-4 py-2.5 text-xs text-muted-foreground">
          <label className="flex items-center gap-2">
            <span className="font-medium text-foreground">% com disputa calculado por</span>
            <select className="h-7 rounded border border-input bg-surface px-2 text-foreground" value={metrica} onChange={(e) => setMetrica(e.target.value as 'quantidade' | 'valor')}>
              <option value="quantidade">quantidade de processos</option>
              <option value="valor">valor contratado</option>
            </select>
          </label>
          <label className="flex items-center gap-2">
            <span className="font-medium text-foreground">Região do % na região</span>
            <select className="h-7 max-w-[220px] rounded border border-input bg-surface px-2 text-foreground" value={regiao} onChange={(e) => setRegiao(e.target.value)}>
              <option value="">mesma região do comprador</option>
              {q.data?.regioes.map((r) => <option key={r.id} value={r.id}>{r.nome}</option>)}
            </select>
          </label>
          {q.isFetching && <Loader2 className="size-4 animate-spin text-primary" />}
        </div>
        {q.isLoading && <div className="flex h-40 items-center justify-center"><Loader2 className="size-6 animate-spin text-primary" /></div>}
        {q.isError && <p className="rounded-md border border-danger/40 bg-danger/10 p-3 text-xs">Não foi possível carregar: {(q.error as Error).message}</p>}
        {q.data && (
          <TabelaAvancada
            colunas={colunas}
            dados={q.data.dados}
            pesquisarEm={(l) => `${l.unidade} ${l.orgao ?? ''} ${l.unidade_superior ?? ''}`}
            placeholderPesquisa="Pesquisar unidade contratante…"
            aoClicarLinha={(l) => abrir('unidade', l.id, l.unidade)}
            rotuloLinha={(l) => `Abrir detalhamento de ${l.unidade}`}
            ordemInicial={[{ id: 'total_comprado', desc: true }]}
            podeExportar={pode('relatorios.exportar')}
            cabecalhoExportacao={{ titulo: 'Panorama das contratações por unidade contratante', filtros: descreverFiltros(estado), ficticio: meta?.possui_dados_ficticios }}
            rodape={
              <tr>
                <td className="sticky left-0 z-10 border-r border-t bg-muted px-2.5 py-2 text-xs font-semibold">Total geral</td>
                <td className="whitespace-nowrap border-t bg-muted px-2.5 py-2 text-right text-xs font-semibold tabular">{formatarMoeda(q.data.total_geral)}</td>
                <td colSpan={12} className="border-t bg-muted" />
              </tr>
            }
          />
        )}
      </CardContent>
    </Card>
  )
}
