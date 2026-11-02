import { useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { ChevronLeft, ChevronRight, Loader2 } from 'lucide-react'
import { api } from '@/lib/api'
import type { Indicador } from '@/lib/tipos'
import { formatarData, formatarDocumento, formatarMoeda, formatarNumero, formatarUnitario, NAO_INFORMADO } from '@/lib/format'
import { Dialogo } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'

type Linha = Record<string, string | number | boolean | null>
interface Resposta { dados: Linha[]; pagina: number; total: number; ultima_pagina: number }

/** Colunas exibidas por tipo de registro. Cada coluna: [campo, título, formatador, alinhado à direita]. */
type Coluna = [string, string, (v: Linha[string]) => string, boolean?]
const txt = (v: Linha[string]) => (v === null || v === '' ? NAO_INFORMADO : String(v))
const moeda = (v: Linha[string]) => formatarMoeda(v as number)
const data = (v: Linha[string]) => formatarData(v as string)
const doc = (v: Linha[string]) => formatarDocumento(v as string)
const simNao = (v: Linha[string]) => (v ? 'Sim' : 'Não')

const COLUNAS: Record<string, Coluna[]> = {
  itens: [
    ['numero_publicacao', 'Nº publicação', txt], ['data_abertura', 'Abertura', data], ['unidade', 'Unidade compradora', txt],
    ['sistematica', 'Sistemática', txt], ['codigo_item', 'Cód. item', txt], ['descricao', 'Descrição', txt],
    ['quantidade_adjudicada', 'Qtde.', (v) => formatarNumero(v as number), true], ['valor_unitario_adjudicado', 'Vlr. unitário', (v) => formatarUnitario(v as number), true],
    ['valor_total_estimado', 'Estimado', moeda, true], ['valor_total_contratado', 'Contratado', moeda, true],
    ['fornecedor', 'Fornecedor', txt], ['fornecedor_documento', 'CPF/CNPJ', doc], ['situacao', 'Situação', txt],
  ],
  processos: [
    ['numero_publicacao', 'Nº publicação', txt], ['numero_processo_sei', 'Processo SEI', txt], ['data_abertura', 'Abertura', data],
    ['unidade', 'Unidade compradora', txt], ['sistematica', 'Sistemática', txt], ['amparo_legal', 'Amparo legal', txt],
    ['objeto', 'Objeto', txt], ['registro_precos', 'RP?', simNao], ['situacao', 'Situação', txt],
    ['valor_estimado', 'Estimado', moeda, true], ['valor_contratado', 'Contratado', moeda, true],
  ],
  fornecedores: [
    ['fornecedor', 'Fornecedor', txt], ['fornecedor_documento', 'CPF/CNPJ', doc], ['porte', 'Porte', txt], ['municipio', 'Município', txt],
    ['uf', 'UF', txt], ['processos', 'Processos', (v) => formatarNumero(v as number), true], ['valor_total_contratado', 'Contratado', moeda, true],
  ],
  atas: [
    ['numero', 'Nº ata', txt], ['numero_publicacao', 'Nº publicação', txt], ['orgao_gerenciador', 'Órgão gerenciador', txt], ['objeto', 'Objeto', txt],
    ['vigencia_inicio', 'Início', data], ['vigencia_fim', 'Validade', data], ['situacao', 'Situação', txt],
    ['valor_estimado', 'Estimado', moeda, true], ['valor_registrado', 'Registrado', moeda, true],
  ],
  contratos: [
    ['numero', 'Nº contrato', txt], ['unidade', 'Unidade', txt], ['fornecedor', 'Fornecedor', txt], ['fornecedor_documento', 'CPF/CNPJ', doc],
    ['objeto', 'Objeto', txt], ['vigencia_inicio', 'Início', data], ['vigencia_fim', 'Fim da vigência', data], ['valor_inicial', 'Valor inicial', moeda, true],
  ],
  execucao: [
    ['data', 'Data', data], ['tipo', 'Tipo', txt], ['documento', 'Documento', txt], ['contrato', 'Contrato', txt], ['valor', 'Valor', moeda, true], ['origem', 'Origem', txt],
  ],
}

export function tipoRegistro(codigo: string): keyof typeof COLUNAS {
  if (['processos', 'unidades_compradoras', 'qtd_licitacoes', 'qtd_dispensas', 'qtd_inexigibilidades', 'qtd_chamadas_publicas'].includes(codigo)) return 'processos'
  if (codigo === 'fornecedores') return 'fornecedores'
  if (codigo === 'qtd_atas') return 'atas'
  if (codigo.startsWith('contratos_')) return 'contratos'
  if (['total_empenhado', 'total_liquidado', 'total_pago'].includes(codigo)) return 'execucao'
  return 'itens'
}

/** Abre os registros que compõem um indicador, com os mesmos filtros (seção 6.1 e 31). */
export function DialogoRegistros({ indicador, consulta, aoFechar }: { indicador: Indicador | null; consulta: string; aoFechar: () => void }) {
  const [pagina, setPagina] = useState(1)
  const codigo = indicador?.codigo
  const { data: r, isFetching, isError } = useQuery({
    queryKey: ['registros', codigo, consulta, pagina],
    queryFn: () => api<Resposta>(`/dashboard/indicadores/${codigo}/registros?${consulta}${consulta ? '&' : ''}pagina=${pagina}&por_pagina=15`),
    enabled: !!codigo,
    placeholderData: keepPreviousData,
  })
  const colunas = codigo ? COLUNAS[tipoRegistro(codigo)] : []

  return (
    <Dialogo
      aberto={!!indicador}
      aoFechar={() => { setPagina(1); aoFechar() }}
      titulo={`Registros que compõem: ${indicador?.nome ?? ''}`}
      descricao={indicador?.explicacao ? `${indicador.explicacao.descricao} Fórmula: ${indicador.explicacao.formula}` : undefined}
    >
      <div className="relative min-h-[200px]">
        {isError && <p className="p-6 text-center text-sm text-danger">Não foi possível carregar os registros.</p>}
        {r && r.total === 0 && <p className="p-6 text-center text-sm text-muted-foreground">Nenhum registro compõe este indicador com os filtros atuais.</p>}
        {r && r.total > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[900px] text-xs">
              <thead className="sticky top-0 bg-muted text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                <tr>{colunas.map(([c, t, , dir]) => <th key={c} className={`whitespace-nowrap px-3 py-2 font-semibold ${dir ? 'text-right' : ''}`}>{t}</th>)}</tr>
              </thead>
              <tbody>
                {r.dados.map((l, i) => (
                  <tr key={String(l.id ?? i)} className="border-t hover:bg-muted/50">
                    {colunas.map(([c, , f, dir]) => (
                      <td key={c} className={`px-3 py-2 align-top ${dir ? 'whitespace-nowrap text-right tabular' : ''} ${c === 'descricao' || c === 'objeto' ? 'min-w-[240px]' : ''} ${c === 'amparo_legal' || c === 'unidade' ? 'min-w-[170px]' : ''}`}>{f(l[c])}</td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {isFetching && <div className="absolute inset-0 flex items-center justify-center bg-card/50"><Loader2 className="size-6 animate-spin text-primary" /></div>}
      </div>
      {r && r.total > 0 && (
        <div className="flex items-center justify-between gap-2 border-t px-5 py-3 text-xs text-muted-foreground">
          <span className="flex items-center gap-2">
            {formatarNumero(r.total)} registro(s)
            {r.dados.some((l) => l.ficticio) && <Badge cor="alerta">fictícios</Badge>}
          </span>
          <span className="flex items-center gap-1">
            <Button variant="outline" size="icon" className="size-8" disabled={pagina <= 1} onClick={() => setPagina((p) => p - 1)} aria-label="Página anterior"><ChevronLeft /></Button>
            <span className="px-2">Página {r.pagina} de {r.ultima_pagina}</span>
            <Button variant="outline" size="icon" className="size-8" disabled={pagina >= r.ultima_pagina} onClick={() => setPagina((p) => p + 1)} aria-label="Próxima página"><ChevronRight /></Button>
          </span>
        </div>
      )}
    </Dialogo>
  )
}
