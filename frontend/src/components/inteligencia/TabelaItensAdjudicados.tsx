import { useMemo } from 'react'
import { useAuth } from '@/contexts/AuthContext'
import { BadgeSituacao, rotuloSituacao } from '@/components/compras/Situacao'
import { TabelaServidor } from '@/components/tabela/TabelaServidor'
import type { Coluna } from '@/components/tabela/colunas'
import { useDetalhes } from '@/components/detalhes/useDetalhes'
import type { Cabecalho } from '@/lib/exportar'
import { formatarDocumento } from '@/lib/format'

export interface LinhaAdjudicado {
  id: number; processo_id: number; numero_publicacao: string | null; numero_processo: string | null; numero_edital: string | null
  data_abertura: string | null; unidade: string; orgao: string | null; municipio_unidade: string | null; numero_item: number; lote: boolean
  codigo_sufixo: string | null; codigo_item: string | null; tipo_item: string | null; descricao: string; unidade_fornecimento: string | null
  sistematica: string | null; registro_precos: boolean; quantidade_estimada: number | null; quantidade_adjudicada: number | null
  valor_unitario_estimado: number | null; valor_unitario_adjudicado: number | null; valor_total_estimado: number | null; valor_total_adjudicado: number | null
  percentual_economia: number | null; fornecedor_id: number | null; fornecedor: string | null; fornecedor_documento: string | null; porte: string | null
  uf_fornecedor: string | null; municipio_fornecedor: string | null; data_adjudicacao: string | null; situacao: string
  numero_contrato: string | null; numero_ata: string | null
}

const simNao = (v: boolean) => (v ? 'Sim' : 'Não')

/** Tabela dos itens adjudicados (seção 16), com todos os campos; usada no módulo e nos nós da árvore de decomposição. */
export function TabelaItensAdjudicados({ consulta, cabecalho }: { consulta: string; cabecalho: Cabecalho }) {
  const { pode } = useAuth()
  const abrir = useDetalhes()

  const colunas = useMemo<Coluna<LinhaAdjudicado>[]>(() => [
    { id: 'numero_publicacao', titulo: 'Nº publicação', tipo: 'texto', valor: (l) => l.numero_publicacao, fixa: true, largura: 110,
      render: (l) => <span className="font-medium text-primary">{l.numero_publicacao ?? '—'}</span> },
    { id: 'numero_processo', titulo: 'Nº processo', tipo: 'texto', valor: (l) => l.numero_processo, ordenavel: false, largura: 170 },
    { id: 'numero_edital', titulo: 'Nº edital', tipo: 'texto', valor: (l) => l.numero_edital, ordenavel: false },
    { id: 'data_abertura', titulo: 'Abertura', tipo: 'data', valor: (l) => l.data_abertura },
    { id: 'unidade', titulo: 'Unidade', tipo: 'texto', valor: (l) => l.unidade, largura: 170 },
    { id: 'orgao', titulo: 'Órgão', tipo: 'texto', valor: (l) => l.orgao, ordenavel: false },
    { id: 'municipio_unidade', titulo: 'Município da unidade', tipo: 'texto', valor: (l) => l.municipio_unidade, ordenavel: false },
    { id: 'numero_item', titulo: 'Nº item', tipo: 'numero', valor: (l) => l.numero_item, ordenavel: false },
    { id: 'lote', titulo: 'Lote?', tipo: 'texto', valor: (l) => simNao(l.lote), ordenavel: false },
    { id: 'codigo_sufixo', titulo: 'Cód. + sufixo', dica: 'Código do item com o sufixo da unidade de fornecimento.', tipo: 'texto', valor: (l) => l.codigo_sufixo, ordenavel: false },
    { id: 'codigo_item', titulo: 'Cód. item', tipo: 'texto', valor: (l) => l.codigo_item },
    { id: 'tipo_item', titulo: 'Tipo', tipo: 'texto', valor: (l) => l.tipo_item, ordenavel: false },
    { id: 'descricao', titulo: 'Descrição', tipo: 'texto', valor: (l) => l.descricao, largura: 240,
      render: (l) => <span className="line-clamp-2" title={l.descricao}>{l.descricao}</span> },
    { id: 'unidade_fornecimento', titulo: 'Un. fornecimento', tipo: 'texto', valor: (l) => l.unidade_fornecimento, ordenavel: false },
    { id: 'sistematica', titulo: 'Forma / sistemática', tipo: 'texto', valor: (l) => l.sistematica, ordenavel: false, largura: 150 },
    { id: 'registro_precos', titulo: 'RP?', tipo: 'texto', valor: (l) => simNao(l.registro_precos), ordenavel: false },
    { id: 'quantidade_estimada', titulo: 'Qtde. estimada', tipo: 'numero', valor: (l) => l.quantidade_estimada, ordenavel: false },
    { id: 'quantidade_adjudicada', titulo: 'Qtde. adjudicada', tipo: 'numero', valor: (l) => l.quantidade_adjudicada },
    { id: 'valor_unitario_estimado', titulo: 'Unit. estimado', tipo: 'unitario', valor: (l) => l.valor_unitario_estimado, ordenavel: false },
    { id: 'valor_unitario_adjudicado', titulo: 'Unit. adjudicado', tipo: 'unitario', valor: (l) => l.valor_unitario_adjudicado },
    { id: 'valor_total_estimado', titulo: 'Total estimado (R$)', tipo: 'moeda', valor: (l) => l.valor_total_estimado, ordenavel: false },
    { id: 'valor_total_adjudicado', titulo: 'Total adjudicado (R$)', tipo: 'moeda', valor: (l) => l.valor_total_adjudicado },
    { id: 'percentual_economia', titulo: '% economia', dica: '(total estimado − total adjudicado) / total estimado × 100, no item.', tipo: 'percentual', valor: (l) => l.percentual_economia },
    { id: 'fornecedor', titulo: 'Fornecedor', tipo: 'texto', valor: (l) => l.fornecedor, largura: 200,
      render: (l) => l.fornecedor_id ? (
        <button className="text-left text-primary hover:underline" onClick={(e) => { e.stopPropagation(); abrir('fornecedor', l.fornecedor_id!, l.fornecedor ?? undefined) }}>
          {l.fornecedor}<span className="block text-[11px] text-muted-foreground">{formatarDocumento(l.fornecedor_documento)}</span>
        </button>
      ) : '—' },
    { id: 'fornecedor_documento', titulo: 'CPF/CNPJ', tipo: 'texto', valor: (l) => l.fornecedor_documento, texto: (l) => formatarDocumento(l.fornecedor_documento), ordenavel: false },
    { id: 'porte', titulo: 'Porte', tipo: 'texto', valor: (l) => l.porte, ordenavel: false },
    { id: 'uf_fornecedor', titulo: 'UF fornecedor', tipo: 'texto', valor: (l) => l.uf_fornecedor, ordenavel: false },
    { id: 'municipio_fornecedor', titulo: 'Município fornecedor', tipo: 'texto', valor: (l) => l.municipio_fornecedor, ordenavel: false },
    { id: 'data_adjudicacao', titulo: 'Adjudicação', tipo: 'data', valor: (l) => l.data_adjudicacao },
    { id: 'situacao', titulo: 'Situação', tipo: 'texto', valor: (l) => rotuloSituacao(l.situacao), ordenavel: false, render: (l) => <BadgeSituacao situacao={l.situacao} /> },
    { id: 'numero_contrato', titulo: 'Nº contrato', tipo: 'texto', valor: (l) => l.numero_contrato, ordenavel: false },
    { id: 'numero_ata', titulo: 'Nº ata', tipo: 'texto', valor: (l) => l.numero_ata, ordenavel: false },
  ], [abrir])
  // O documento aparece sob o nome do fornecedor na tela; a coluna própria fica para a exportação.
  const colunasTela = colunas.filter((c) => c.id !== 'fornecedor_documento')

  return (
    <TabelaServidor<LinhaAdjudicado> endpoint="/inteligencia/itens-adjudicados" consulta={consulta} colunas={colunasTela} colunasExportacao={colunas}
      ordemPadrao={{ id: 'data_abertura', direcao: 'desc' }} placeholderPesquisa="Item, código, fornecedor, CNPJ, órgão, processo ou nº de publicação…"
      aoClicarLinha={(l) => abrir('processo', l.processo_id, `Processo ${l.numero_publicacao ?? l.processo_id}`)}
      podeExportar={pode('relatorios.exportar')} cabecalhoExportacao={cabecalho} />
  )
}
