import { useCallback, useMemo, useState } from 'react'
import { descreverFiltros, paraQueryString, useFiltros } from '@/contexts/FiltrosContext'
import { useAuth } from '@/contexts/AuthContext'
import { useMeta } from '@/components/layout/Cabecalho'
import { BarraFiltros } from '@/components/filters/BarraFiltros'
import { FaixaIndicadores } from '@/components/compras/FaixaIndicadores'
import { Abas, CabecalhoModulo } from '@/components/compras/CabecalhoModulo'
import { BadgeSituacao, rotuloSituacao } from '@/components/compras/Situacao'
import { TabelaServidor, type RespostaPaginada } from '@/components/tabela/TabelaServidor'
import type { Coluna } from '@/components/tabela/colunas'
import { useDetalhes } from '@/components/detalhes/useDetalhes'
import { formatarDocumento, formatarNumero } from '@/lib/format'
import { Card, CardContent } from '@/components/ui/card'
import { cn } from '@/lib/utils'

interface LinhaProcesso {
  id: number; numero_publicacao: string | null; numero_processo_sei: string | null; numero_licitacao: string | null; ano: number; data_abertura: string
  unidade_id: number; unidade: string; orgao: string | null; sistematica: string; forma_aquisicao: string | null; amparo_legal: string | null; objeto: string
  registro_precos: number | boolean; eletronico: number | boolean; situacao: string; fase_atual: string | null; valor_estimado: string | null
  valor_homologado: string | null; valor_contratado: string | null; quantidade_participantes: number | null; quantidade_itens: number
}
interface Resumo { total: number; por_situacao: { situacao: string; quantidade: number }[]; tempo_medio_conclusao_dias: number | null }

interface LinhaItem {
  id: number; processo_id: number; numero_publicacao: string | null; data_abertura: string; unidade: string; sistematica: string; numero_item: number
  item_catalogo_id: number | null; codigo_item: string | null; descricao: string; unidade_medida: string | null; quantidade_adjudicada: string | null
  valor_unitario_estimado: string | null; valor_unitario_adjudicado: string | null; valor_total_estimado: string | null; valor_total_contratado: string | null
  fornecedor_id: number | null; fornecedor: string | null; fornecedor_documento: string | null; situacao: string
}

const n = (v: string | number | null) => (v === null ? null : Number(v))
const FASES: Record<string, string> = {
  PLANEJAMENTO: 'Planejamento', PREPARATORIA: 'Preparatória', DIVULGACAO: 'Divulgação', PROPOSTAS: 'Propostas', JULGAMENTO: 'Julgamento', HABILITACAO: 'Habilitação',
  RECURSOS: 'Recursos', ADJUDICACAO: 'Adjudicação', HOMOLOGACAO: 'Homologação', CONTRATACAO: 'Contratação', EXECUCAO: 'Execução',
}

type Aba = 'processos' | 'itens'

/** Módulo 12 — Licitações e Processos (seção 21), com a aba de itens dos processos. */
export function Processos() {
  const { estado, aplicarCruzado } = useFiltros()
  const { pode } = useAuth()
  const { data: meta } = useMeta()
  const abrir = useDetalhes()
  const [aba, setAba] = useState<Aba>('processos')
  const [situacoes, setSituacoes] = useState<string[]>([])
  const [resumo, setResumo] = useState<Resumo | null>(null)

  const consultaGlobal = paraQueryString(estado)
  const consultaProcessos = useMemo(() => {
    const p = new URLSearchParams(consultaGlobal)
    situacoes.forEach((s) => p.append('situacao[]', s))
    p.set('com_resumo', '1')
    return p.toString()
  }, [consultaGlobal, situacoes])
  const aoCarregar = useCallback((r: RespostaPaginada<LinhaProcesso> & { resumo?: Resumo }) => { if (r.resumo) setResumo(r.resumo) }, [])

  const cabecalho = (titulo: string) => ({ titulo, filtros: [...descreverFiltros(estado), ...situacoes.map(rotuloSituacao)], ficticio: meta?.possui_dados_ficticios })

  const colunasProcessos = useMemo<Coluna<LinhaProcesso>[]>(() => [
    { id: 'numero_publicacao', titulo: 'Nº publicação', tipo: 'texto', valor: (l) => l.numero_publicacao, fixa: true, largura: 120,
      render: (l) => <span className="font-medium text-primary">{l.numero_publicacao ?? '—'}</span> },
    { id: 'data_abertura', titulo: 'Abertura', tipo: 'data', valor: (l) => l.data_abertura },
    { id: 'unidade', titulo: 'Unidade compradora', tipo: 'texto', valor: (l) => l.unidade, largura: 180 },
    { id: 'sistematica', titulo: 'Sistemática', tipo: 'texto', valor: (l) => l.sistematica },
    { id: 'objeto', titulo: 'Objeto', tipo: 'texto', valor: (l) => l.objeto, ordenavel: false, largura: 280,
      render: (l) => <span className="line-clamp-2" title={l.objeto}>{l.objeto}</span> },
    { id: 'amparo_legal', titulo: 'Amparo legal', tipo: 'texto', valor: (l) => l.amparo_legal, ordenavel: false, largura: 180 },
    { id: 'registro_precos', titulo: 'RP?', tipo: 'texto', valor: (l) => (l.registro_precos ? 'Sim' : 'Não'), ordenavel: false },
    { id: 'situacao', titulo: 'Situação', tipo: 'texto', valor: (l) => rotuloSituacao(l.situacao), render: (l) => <BadgeSituacao situacao={l.situacao} /> },
    { id: 'fase_atual', titulo: 'Fase atual', tipo: 'texto', valor: (l) => (l.fase_atual ? FASES[l.fase_atual] ?? l.fase_atual : null), ordenavel: false },
    { id: 'quantidade_itens', titulo: 'Itens', tipo: 'numero', valor: (l) => l.quantidade_itens },
    { id: 'quantidade_participantes', titulo: 'Participantes', tipo: 'numero', valor: (l) => l.quantidade_participantes },
    { id: 'valor_estimado', titulo: 'Estimado (R$)', tipo: 'moeda', valor: (l) => n(l.valor_estimado) },
    { id: 'valor_contratado', titulo: 'Contratado (R$)', tipo: 'moeda', valor: (l) => n(l.valor_contratado) },
  ], [])

  const colunasItens = useMemo<Coluna<LinhaItem>[]>(() => [
    { id: 'codigo_item', titulo: 'Cód. item', tipo: 'texto', valor: (l) => l.codigo_item, fixa: true, largura: 90,
      render: (l) => l.item_catalogo_id ? (
        <button className="font-medium text-primary hover:underline" title="Ver todos os processos em que este item foi adquirido"
          onClick={(e) => { e.stopPropagation(); aplicarCruzado('item', `i:${l.item_catalogo_id}`, `${l.codigo_item} — ${l.descricao}`); setAba('processos') }}>
          {l.codigo_item}
        </button>
      ) : '—' },
    { id: 'descricao', titulo: 'Descrição', tipo: 'texto', valor: (l) => l.descricao, largura: 240 },
    { id: 'unidade_medida', titulo: 'Un.', tipo: 'texto', valor: (l) => l.unidade_medida, ordenavel: false },
    { id: 'data_abertura', titulo: 'Abertura', tipo: 'data', valor: (l) => l.data_abertura },
    { id: 'numero_publicacao', titulo: 'Processo', tipo: 'texto', valor: (l) => l.numero_publicacao, ordenavel: false,
      render: (l) => <button className="text-primary hover:underline" onClick={(e) => { e.stopPropagation(); abrir('processo', l.processo_id) }}>{l.numero_publicacao}</button> },
    { id: 'unidade', titulo: 'Unidade compradora', tipo: 'texto', valor: (l) => l.unidade, largura: 170 },
    { id: 'quantidade_adjudicada', titulo: 'Qtde.', tipo: 'numero', valor: (l) => n(l.quantidade_adjudicada) },
    { id: 'valor_unitario_adjudicado', titulo: 'Unit. adjudicado', tipo: 'unitario', valor: (l) => n(l.valor_unitario_adjudicado) },
    { id: 'valor_total_estimado', titulo: 'Total estimado (R$)', tipo: 'moeda', valor: (l) => n(l.valor_total_estimado) },
    { id: 'valor_total_contratado', titulo: 'Total contratado (R$)', tipo: 'moeda', valor: (l) => n(l.valor_total_contratado) },
    { id: 'fornecedor', titulo: 'Fornecedor', tipo: 'texto', valor: (l) => l.fornecedor, largura: 200,
      render: (l) => l.fornecedor_id ? (
        <button className="text-left text-primary hover:underline" onClick={(e) => { e.stopPropagation(); abrir('fornecedor', l.fornecedor_id!, l.fornecedor ?? undefined) }}>
          {l.fornecedor}<span className="block text-[11px] text-muted-foreground">{formatarDocumento(l.fornecedor_documento)}</span>
        </button>
      ) : '—' },
    { id: 'fornecedor_documento', titulo: 'CPF/CNPJ', tipo: 'texto', valor: (l) => l.fornecedor_documento, texto: (l) => formatarDocumento(l.fornecedor_documento), ordenavel: false },
    { id: 'situacao', titulo: 'Situação', tipo: 'texto', valor: (l) => rotuloSituacao(l.situacao), render: (l) => <BadgeSituacao situacao={l.situacao} /> },
  ], [abrir, aplicarCruzado])
  // A coluna de CPF/CNPJ existe para a exportação; na tela o documento aparece sob o nome do fornecedor.
  const colunasItensTela = colunasItens.filter((c) => c.id !== 'fornecedor_documento')

  return (
    <div>
      <CabecalhoModulo titulo="Licitações e Processos" descricao="Cadastro centralizado dos processos de contratação, fases e itens." consulta={consultaGlobal} />
      <BarraFiltros />
      <FaixaIndicadores consulta={consultaGlobal} codigos={['total_estimado', 'total_contratado', 'processos', 'qtd_licitacoes', 'qtd_dispensas', 'qtd_inexigibilidades', 'qtd_chamadas_publicas', 'itens_comprados']}
        monetarios={['total_estimado', 'total_contratado']} />
      <Abas<Aba> abas={[{ id: 'processos', rotulo: 'Processos' }, { id: 'itens', rotulo: 'Itens dos processos' }]} ativa={aba} aoMudar={setAba} />

      {aba === 'processos' && (
        <Card>
          <CardContent className="pt-5">
            {resumo && (
              <div className="mb-4">
                <p className="mb-2 text-xs text-muted-foreground">
                  Situação dos processos (clique para filtrar a lista) · Tempo médio de conclusão:{' '}
                  <strong className="text-foreground">{resumo.tempo_medio_conclusao_dias === null ? 'Não informado' : `${resumo.tempo_medio_conclusao_dias.toLocaleString('pt-BR')} dias`}</strong>
                  <span className="text-muted-foreground"> (da abertura à homologação)</span>
                </p>
                <div className="flex flex-wrap gap-2" role="group" aria-label="Filtrar por situação">
                  {resumo.por_situacao.filter((s) => s.quantidade > 0 || situacoes.includes(s.situacao)).map((s) => {
                    const ativo = situacoes.includes(s.situacao)
                    return (
                      <button key={s.situacao} aria-pressed={ativo}
                        onClick={() => setSituacoes((x) => (ativo ? x.filter((y) => y !== s.situacao) : [...x, s.situacao]))}
                        className={cn('rounded-md border px-3 py-1.5 text-xs', ativo ? 'border-primary bg-accent text-accent-foreground' : 'hover:bg-muted')}>
                        {rotuloSituacao(s.situacao)} <strong className="ml-1 tabular">{formatarNumero(s.quantidade)}</strong>
                      </button>
                    )
                  })}
                  {situacoes.length > 0 && <button className="px-2 text-xs text-primary hover:underline" onClick={() => setSituacoes([])}>Limpar situação</button>}
                </div>
              </div>
            )}
            <TabelaServidor<LinhaProcesso, RespostaPaginada<LinhaProcesso> & { resumo?: Resumo }>
              endpoint="/processos" consulta={consultaProcessos} colunas={colunasProcessos} ordemPadrao={{ id: 'data_abertura', direcao: 'desc' }}
              placeholderPesquisa="Nº de publicação, processo SEI, licitação ou objeto…" aoClicarLinha={(l) => abrir('processo', l.id, `Processo ${l.numero_publicacao ?? l.id}`)}
              podeExportar={pode('relatorios.exportar')} cabecalhoExportacao={cabecalho('Licitações e processos')} aoCarregar={aoCarregar} />
          </CardContent>
        </Card>
      )}

      {aba === 'itens' && (
        <Card>
          <CardContent className="pt-5">
            <p className="mb-3 text-xs text-muted-foreground">Clique no código do item para ver todos os processos em que ele foi adquirido, ou no fornecedor para ver o histórico de fornecimento.</p>
            <TabelaServidor<LinhaItem>
              endpoint="/itens" consulta={consultaGlobal} colunas={colunasItensTela} colunasExportacao={colunasItens} ordemPadrao={{ id: 'data_abertura', direcao: 'desc' }}
              placeholderPesquisa="Descrição, código do item ou nº de publicação…" aoClicarLinha={(l) => abrir('processo', l.processo_id, `Processo ${l.numero_publicacao ?? l.processo_id}`)}
              podeExportar={pode('relatorios.exportar')} cabecalhoExportacao={cabecalho('Itens dos processos')} />
          </CardContent>
        </Card>
      )}
    </div>
  )
}
