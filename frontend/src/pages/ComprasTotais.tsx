import { useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Loader2 } from 'lucide-react'
import { api } from '@/lib/api'
import { paraQueryString, useFiltros } from '@/contexts/FiltrosContext'
import { BarraFiltros } from '@/components/filters/BarraFiltros'
import { GraficoEconomia, GraficoEvolucao, GraficoPorte, RankingUnidades, type Evolucao, type GrupoPorte, type TopUnidade } from '@/components/dashboard/Graficos'
import { GraficoAgrupado, GraficoDisputa, type Disputa } from '@/components/compras/GraficosCompras'
import type { Agrupamento } from '@/components/compras/ListaBarras'
import { FaixaIndicadores, useIndicadores } from '@/components/compras/FaixaIndicadores'
import { Abas, CabecalhoModulo } from '@/components/compras/CabecalhoModulo'
import { CriterioEconomia } from '@/components/compras/CriterioEconomia'
import { Panorama } from '@/components/compras/Panorama'
import { useDetalhes } from '@/components/detalhes/useDetalhes'

interface Graficos {
  evolucao_anual: Evolucao[]; por_orgao: Agrupamento[]; por_modalidade: Agrupamento[]; por_natureza: Agrupamento[]; por_municipio: Agrupamento[]
  por_fornecedor: Agrupamento[]; por_grupo_material: Agrupamento[]; por_grupo_porte: GrupoPorte[]; com_sem_disputa: Disputa[]; top_unidades: TopUnidade[]
}

const INDICADORES = ['total_estimado', 'total_adjudicado', 'total_homologado', 'total_contratado', 'economia', 'percentual_economia', 'processos', 'itens_comprados', 'fornecedores', 'unidades_compradoras']
const MONETARIOS = ['total_estimado', 'total_adjudicado', 'total_homologado', 'total_contratado', 'economia']

type Aba = 'consolidada' | 'panorama'

/** Módulo 2 — Compras Totais (seção 7) e Panorama por unidade contratante (seção 8). */
export function ComprasTotais() {
  const { estado } = useFiltros()
  const abrir = useDetalhes()
  const [aba, setAba] = useState<Aba>('consolidada')
  const [base, setBase] = useState<string | undefined>()
  const [semDisputa, setSemDisputa] = useState<boolean | undefined>()

  const consulta = paraQueryString(estado, { economia_base: base, incluir_sem_disputa: semDisputa === undefined ? undefined : semDisputa ? '1' : '0' })
  const ind = useIndicadores(consulta)
  const graf = useQuery({ queryKey: ['compras-graficos', consulta], queryFn: () => api<Graficos>(`/compras/graficos?${consulta}`), placeholderData: keepPreviousData, enabled: aba === 'consolidada' })

  return (
    <div>
      <CabecalhoModulo titulo="Compras Totais" descricao="Visão consolidada das compras públicas e panorama por unidade contratante." consulta={consulta} />
      <BarraFiltros />
      <CriterioEconomia base={base ?? ind.data?.opcoes.economia_base ?? 'contratado'} incluirSemDisputa={semDisputa ?? ind.data?.opcoes.incluir_sem_disputa ?? false}
        aoMudarBase={setBase} aoMudarSemDisputa={setSemDisputa} />
      <FaixaIndicadores codigos={INDICADORES} monetarios={MONETARIOS} consulta={consulta} />

      <Abas<Aba> abas={[{ id: 'consolidada', rotulo: 'Visão consolidada' }, { id: 'panorama', rotulo: 'Panorama por unidade contratante' }]} ativa={aba} aoMudar={setAba} />

      {aba === 'panorama' && <Panorama base={base} incluirSemDisputa={semDisputa} />}

      {aba === 'consolidada' && (
        <>
          {graf.isLoading && <div className="flex h-40 items-center justify-center"><Loader2 className="size-6 animate-spin text-primary" /></div>}
          {graf.isError && <p className="rounded-md border border-danger/40 bg-danger/10 p-3 text-xs">Não foi possível carregar os gráficos: {(graf.error as Error).message}</p>}
          {graf.data && (
            <div className={`grid grid-cols-1 gap-4 xl:grid-cols-2 ${graf.isFetching ? 'opacity-70' : ''}`}>
              <GraficoEvolucao dados={graf.data.evolucao_anual} className="xl:col-span-2" />
              <GraficoAgrupado titulo="Compras por órgão" rotuloDimensao="Órgão" dados={graf.data.por_orgao} />
              <GraficoAgrupado titulo="Compras por modalidade" subtitulo="Modalidade ou sistemática de aquisição · valor contratado (R$) · clique para filtrar" rotuloDimensao="Modalidade" dados={graf.data.por_modalidade} />
              <GraficoAgrupado titulo="Compras por natureza" rotuloDimensao="Natureza" dados={graf.data.por_natureza} />
              <GraficoAgrupado titulo="Compras por município" subtitulo="Município da unidade compradora · valor contratado (R$) · clique para filtrar" rotuloDimensao="Município" dados={graf.data.por_municipio} />
              <GraficoAgrupado titulo="Compras por fornecedor" rotuloDimensao="Fornecedor" dados={graf.data.por_fornecedor} />
              <GraficoAgrupado titulo="Compras por grupo de materiais" subtitulo="Grupo de material ou serviço do catálogo · valor contratado (R$) · clique para filtrar" rotuloDimensao="Grupo" dados={graf.data.por_grupo_material} />
              <GraficoPorte dados={graf.data.por_grupo_porte} />
              <GraficoDisputa dados={graf.data.com_sem_disputa} />
              <GraficoEconomia dados={graf.data.evolucao_anual} className="xl:col-span-2" />
              <RankingUnidades dados={graf.data.top_unidades} className="xl:col-span-2" aoClicar={(id) => abrir('unidade', id)} />
            </div>
          )}
        </>
      )}
    </div>
  )
}
