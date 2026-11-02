import { useState } from 'react'
import { paraQueryString, useFiltros } from '@/contexts/FiltrosContext'
import { useAuth } from '@/contexts/AuthContext'
import { BarraFiltros } from '@/components/filters/BarraFiltros'
import { Abas, CabecalhoModulo } from '@/components/compras/CabecalhoModulo'
import { GraficoAgrupado } from '@/components/compras/GraficosCompras'
import type { Agrupamento } from '@/components/compras/ListaBarras'
import { BadgeSituacao } from '@/components/compras/Situacao'
import { BarrasAno, Empilhado100, EstadoPainel, GradeKpis, LinhasAno, MapaArvore, Nota, Rosca, SERIES, useCabecalhoExportacao, usePainel, type NoArvore } from '@/components/inteligencia/partes'
import { TabelaServidor } from '@/components/tabela/TabelaServidor'
import type { Coluna } from '@/components/tabela/colunas'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { MiniTabela } from '@/components/detalhes/Partes'
import { useDetalhes } from '@/components/detalhes/useDetalhes'
import { formatarAbreviado, formatarMoeda, formatarNumero, formatarPercentual } from '@/lib/format'

interface PorUnidade { id: number; nome: string; orgao: string; eletronicos: number; presenciais: number; percentual_eletronicos: number | null }
interface PainelRp {
  indicadores: Record<string, number | null>
  dias_alerta_vencimento: number
  graficos: {
    porte_por_ano: { ano: number; mpe: number; outro: number; ni: number; valor_mpe: number; valor_outro: number; valor_ni: number }[]
    pregao_rp: { ano: number; sim: number; nao: number; valor_sim: number; valor_nao: number }[]
    consumo_rp: { ano: number; sim: number; nao: number; valor_sim: number; valor_nao: number }[]
    economia_pregao_rp: { ano: number; sim: number | null; nao: number | null }[]
    por_uf: Agrupamento[]
    por_ano: { ano: number; valor: number | null; itens: number }[]
    por_grupo_classe: NoArvore[]
    eletronico_presencial: { total: { grupo: string; nome: string; processos: number }[]; por_unidade: PorUnidade[] }
  }
}

interface LinhaRp {
  id: number; numero_processo: string | null; numero_licitacao: string | null; numero_publicacao: string | null; orgao_gerenciador: string; orgao: string
  objeto: string; modalidade: string; data_publicacao: string | null; data_sessao: string | null; itens: number; valor_estimado: number | null
  valor_adjudicado: number | null; valor_homologado: number | null; fornecedores_vencedores: number; situacao: string; vigencia_ata: string | null
  numero_ata: string | null; atas: number; participantes: number
}

type Aba = 'graficos' | 'processos'

const RP_SIM_NAO = [
  { chave: 'sim', nome: 'RP Sim', cor: SERIES[0], valor: 'valor_sim' },
  { chave: 'nao', nome: 'RP Não', cor: SERIES[2], valor: 'valor_nao' },
]

/** Módulo 5 — Licitações para Registro de Preços (seção 14). */
export function LicitacoesRp() {
  const { estado } = useFiltros()
  const { pode } = useAuth()
  const abrir = useDetalhes()
  const cabecalho = useCabecalhoExportacao()
  const [aba, setAba] = useState<Aba>('graficos')
  const consulta = paraQueryString(estado)
  const q = usePainel<PainelRp>('licitacoes-rp', '/inteligencia/licitacoes-rp', consulta)

  const colunas: Coluna<LinhaRp>[] = [
    { id: 'numero_publicacao', titulo: 'Nº publicação', tipo: 'texto', valor: (l) => l.numero_publicacao, fixa: true, largura: 110,
      render: (l) => <span className="font-medium text-primary">{l.numero_publicacao ?? '—'}</span> },
    { id: 'numero_processo', titulo: 'Nº processo', tipo: 'texto', valor: (l) => l.numero_processo, ordenavel: false },
    { id: 'numero_licitacao', titulo: 'Nº licitação', tipo: 'texto', valor: (l) => l.numero_licitacao, ordenavel: false },
    { id: 'orgao_gerenciador', titulo: 'Órgão gerenciador', tipo: 'texto', valor: (l) => `${l.orgao_gerenciador} (${l.orgao})`, largura: 200, ordenavel: false },
    { id: 'objeto', titulo: 'Objeto', tipo: 'texto', valor: (l) => l.objeto, largura: 280, ordenavel: false, render: (l) => <span className="line-clamp-2" title={l.objeto}>{l.objeto}</span> },
    { id: 'modalidade', titulo: 'Modalidade', tipo: 'texto', valor: (l) => l.modalidade, ordenavel: false },
    { id: 'data_publicacao', titulo: 'Publicação', tipo: 'data', valor: (l) => l.data_publicacao },
    { id: 'data_sessao', titulo: 'Sessão', tipo: 'data', valor: (l) => l.data_sessao, ordenavel: false },
    { id: 'itens', titulo: 'Itens', tipo: 'numero', valor: (l) => l.itens },
    { id: 'valor_estimado', titulo: 'Estimado', tipo: 'moeda', valor: (l) => l.valor_estimado },
    { id: 'valor_adjudicado', titulo: 'Adjudicado', tipo: 'moeda', valor: (l) => l.valor_adjudicado },
    { id: 'valor_homologado', titulo: 'Homologado', tipo: 'moeda', valor: (l) => l.valor_homologado },
    { id: 'fornecedores_vencedores', titulo: 'Fornecedores vencedores', tipo: 'numero', valor: (l) => l.fornecedores_vencedores, ordenavel: false },
    { id: 'situacao', titulo: 'Situação', tipo: 'texto', valor: (l) => l.situacao, ordenavel: false, render: (l) => <BadgeSituacao situacao={l.situacao} /> },
    { id: 'vigencia_ata', titulo: 'Vigência da ata', tipo: 'data', valor: (l) => l.vigencia_ata },
    { id: 'numero_ata', titulo: 'Nº ata', tipo: 'texto', valor: (l) => (l.atas > 1 ? `${l.numero_ata} (+${l.atas - 1})` : l.numero_ata), ordenavel: false },
    { id: 'participantes', titulo: 'Participantes', dica: 'Órgãos participantes das atas do processo. A identificação de cada um está na ficha do processo.', tipo: 'numero', valor: (l) => l.participantes, ordenavel: false },
  ]

  return (
    <div>
      <CabecalhoModulo titulo="Licitações para Registro de Preços" descricao="Formação das Atas de Registro de Preços: valores adjudicados, economia, gestores e vigência das atas." consulta={consulta} />
      <BarraFiltros />
      <EstadoPainel q={q}>
        {(d) => {
          const i = d.indicadores
          const g = d.graficos
          return (
            <>
              <GradeKpis colunas="xl:grid-cols-4 2xl:grid-cols-6" itens={[
                { rotulo: 'Total estimado', valor: i.total_estimado, tipo: 'moeda', dica: 'Σ valor estimado dos itens com resultado nos processos de RP.' },
                { rotulo: 'Total adjudicado RP', valor: i.total_adjudicado, tipo: 'moeda', destaque: true },
                { rotulo: 'Economia', valor: i.economia, tipo: 'moeda', dica: 'Σ (estimado − adjudicado) nos itens que têm os dois valores.' },
                { rotulo: '% Economia', valor: i.percentual_economia, tipo: 'percentual', dica: 'Economia / Σ estimado dos mesmos itens × 100.' },
                { rotulo: 'Itens do catálogo', valor: i.itens_catalogo, tipo: 'numero', dica: 'Itens distintos do catálogo envolvidos nos processos de RP.' },
                { rotulo: 'Itens registrados', valor: i.itens_registrados, tipo: 'numero', dica: 'Itens das atas formadas pelos processos.' },
                { rotulo: 'Processos', valor: i.processos, tipo: 'numero' },
                { rotulo: 'Gestores de RP', valor: i.gestores, tipo: 'numero', dica: 'Unidades gerenciadoras distintas das atas.' },
                { rotulo: 'Fornecedores', valor: i.fornecedores, tipo: 'numero' },
                { rotulo: 'Atas vigentes', valor: i.atas_vigentes, tipo: 'numero' },
                { rotulo: 'Atas expiradas', valor: i.atas_expiradas, tipo: 'numero' },
                { rotulo: 'Atas próximas do vencimento', valor: i.atas_vencendo, tipo: 'numero', dica: `Vigentes que vencem em até ${d.dias_alerta_vencimento} dias.` },
              ]} />
              <Abas<Aba> abas={[{ id: 'graficos', rotulo: 'Gráficos' }, { id: 'processos', rotulo: 'Licitações para RP' }]} ativa={aba} aoMudar={setAba} />
              {aba === 'graficos' && (
                <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                  <Empilhado100 titulo="% adjudicado para RP por porte do fornecedor" subtitulo="Participação no valor adjudicado de cada ano"
                    dados={g.porte_por_ano} series={[
                      { chave: 'mpe', nome: 'MPE', cor: SERIES[0], valor: 'valor_mpe' },
                      { chave: 'outro', nome: 'Outro', cor: SERIES[2], valor: 'valor_outro' },
                      { chave: 'ni', nome: 'N/I', cor: SERIES[3], valor: 'valor_ni' },
                    ]} />
                  <Empilhado100 titulo="Total adjudicado por pregão, RP Sim x Não" subtitulo="Percentual do valor adjudicado em pregões de cada ano" dados={g.pregao_rp} series={RP_SIM_NAO} />
                  <Empilhado100 titulo="Material de consumo, RP Sim x Não" subtitulo="Percentual do valor adjudicado em material de consumo de cada ano" dados={g.consumo_rp} series={RP_SIM_NAO} />
                  <LinhasAno titulo="% de economia com pregão, RP Sim x Não" subtitulo="(estimado − adjudicado) / estimado, por ano"
                    dados={g.economia_pregao_rp} series={[{ chave: 'sim', nome: 'RP Sim', cor: SERIES[0] }, { chave: 'nao', nome: 'RP Não', cor: SERIES[1] }]} />
                  <GraficoAgrupado titulo="Total adjudicado para RP por UF do fornecedor" subtitulo="Valor adjudicado (R$) e participação por estado · clique para filtrar" rotuloDimensao="UF" dados={g.por_uf} />
                  <div className="grid grid-cols-1 gap-4">
                    <BarrasAno titulo="Valor total adjudicado para RP" subtitulo="Por ano (R$)" dados={g.por_ano} chave="valor" nome="Adjudicado" formatar={(v) => formatarAbreviado(v)} />
                    <BarrasAno titulo="Nº de itens adjudicados para RP" subtitulo="Por ano" dados={g.por_ano} chave="itens" nome="Itens" formatar={formatarNumero} abreviar={false} cor={SERIES[2]} />
                  </div>
                  <MapaArvore className="xl:col-span-2" titulo="Total adjudicado para RP por grupo e classe" subtitulo="Área proporcional ao valor adjudicado · cor por grupo · clique em uma classe para filtrar" dados={g.por_grupo_classe} />
                  <Rosca titulo="Processos para RP eletrônicos x presenciais" rotuloTotal="processos"
                    dados={g.eletronico_presencial.total.map((t, k) => ({ chave: t.grupo, nome: t.nome, valor: t.processos, cor: SERIES[k === 0 ? 0 : 1] }))} />
                  <Card>
                    <CardHeader><div><CardTitle>Por unidade gerenciadora</CardTitle><p className="mt-0.5 text-xs text-muted-foreground">Processos de RP eletrônicos e presenciais · clique para abrir a unidade</p></div></CardHeader>
                    <CardContent className="max-h-[340px] overflow-auto">
                      <MiniTabela cabecalho={['Unidade', 'Eletrônicos', 'Presenciais', '% eletrônicos']} direita={[1, 2, 3]}
                        linhas={g.eletronico_presencial.por_unidade.map((u) => [`${u.nome} (${u.orgao})`, formatarNumero(u.eletronicos), formatarNumero(u.presenciais), formatarPercentual(u.percentual_eletronicos)])}
                        aoClicar={(k) => abrir('unidade', g.eletronico_presencial.por_unidade[k].id)} />
                    </CardContent>
                  </Card>
                  <Nota className="xl:col-span-2">Os gráficos “RP Sim x Não” comparam os dois grupos e por isso ignoram o filtro global de Registro de Preços. Os demais consideram apenas processos com RP.</Nota>
                </div>
              )}
              {aba === 'processos' && (
                <Card>
                  <CardContent className="pt-4">
                    <TabelaServidor<LinhaRp> endpoint="/inteligencia/licitacoes-rp/processos" consulta={consulta} colunas={colunas}
                      ordemPadrao={{ id: 'data_publicacao', direcao: 'desc' }} placeholderPesquisa="Nº de publicação, processo, licitação ou objeto…"
                      aoClicarLinha={(l) => abrir('processo', l.id, `Processo ${l.numero_publicacao ?? l.id}`)}
                      podeExportar={pode('relatorios.exportar')} cabecalhoExportacao={cabecalho('Licitações para Registro de Preços')} />
                    <p className="mt-2 text-[11px] text-muted-foreground">Valores: estimado {formatarMoeda(i.total_estimado)} · adjudicado {formatarMoeda(i.total_adjudicado)}. Vigência da ata: maior data de vigência entre as atas do processo.</p>
                  </CardContent>
                </Card>
              )}
            </>
          )
        }}
      </EstadoPainel>
    </div>
  )
}
