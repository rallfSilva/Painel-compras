import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ExternalLink } from 'lucide-react'
import { paraQueryString, useFiltros } from '@/contexts/FiltrosContext'
import { useAuth } from '@/contexts/AuthContext'
import { api } from '@/lib/api'
import { BarraFiltros } from '@/components/filters/BarraFiltros'
import { CabecalhoModulo } from '@/components/compras/CabecalhoModulo'
import { ListaBarras, type Agrupamento } from '@/components/compras/ListaBarras'
import { EstadoPainel, GradeKpis, Nota, SERIES, useCabecalhoExportacao, usePainel } from '@/components/inteligencia/partes'
import { BarrasDuplas } from '@/components/inteligencia/BarrasDuplas'
import { TabelaServidor } from '@/components/tabela/TabelaServidor'
import type { Coluna } from '@/components/tabela/colunas'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Caixa } from '@/components/ui/checkbox'
import { Dialogo } from '@/components/ui/dialog'
import { Campos, Carregando, Erro, MiniTabela, Numeros, Secao } from '@/components/detalhes/Partes'
import { useDetalhes } from '@/components/detalhes/useDetalhes'
import { formatarData, formatarDocumento, formatarMoeda, formatarNumero, formatarPercentual } from '@/lib/format'
import { cn } from '@/lib/utils'

interface Parametros { prazos: number[]; limite: number; limite_reforma: number; proximo: number }
interface PainelContratos {
  parametros: Parametros
  indicadores: { vigentes: number; valor_vigente: number; aditivos: number; valor_aditivos: number; percentual_acrescimo: number | null; sinalizados: number; total: number }
  vencendo: { dias: number; quantidade: number }[]
  por_situacao: { situacao: string; quantidade: number; valor: number }[]
  vencimentos_por_mes: { mes: string; quantidade: number }[]
  por_orgao: Agrupamento[]
  execucao_financeira: string | null
}
interface Contrato {
  id: number; numero: string; processo_id: number | null; processo: string | null; ata: string | null; orgao: string; unidade: string | null
  fornecedor_id: number; fornecedor: string; fornecedor_documento: string | null; objeto: string | null; valor_inicial: number; valor_atualizado: number
  acrescimos: number; percentual_acrescimo: number | null; data_assinatura: string | null; vigencia_inicio: string | null; vigencia_fim: string | null
  situacao: string; gestor: string | null; fiscal: string | null; link_pncp: string | null; aditivos: number; dias_para_vencer: number | null
  sinal_acrescimo: 'ACIMA' | 'PROXIMO' | null
}
interface DetalheContrato {
  contrato: Omit<Contrato, 'aditivos' | 'dias_para_vencer' | 'sinal_acrescimo'> & { ficticio: boolean }
  aditivos: { id: number; numero: string; tipo: string; valor: number | null; nova_vigencia_fim: string | null; data_assinatura: string | null; justificativa: string | null; percentual: number | null }[]
  itens: { id: number; descricao: string; unidade: string | null; quantidade: number; valor_unitario: number; valor_total: number }[]
  financeiro: { empenhado: number; liquidado: number; pago: number } | null
  parametros: Parametros
}

const SITUACOES: Record<string, { rotulo: string; cor: 'sucesso' | 'neutro' | 'perigo' | 'alerta' }> = {
  VIGENTE: { rotulo: 'Vigente', cor: 'sucesso' }, ENCERRADO: { rotulo: 'Encerrado', cor: 'neutro' },
  RESCINDIDO: { rotulo: 'Rescindido', cor: 'perigo' }, SUSPENSO: { rotulo: 'Suspenso', cor: 'alerta' },
}
const TIPO_ADITIVO: Record<string, string> = { PRAZO: 'Prazo', VALOR: 'Valor', MISTO: 'Prazo e valor' }
const MESES = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez']
const rotuloMes = (m: string) => `${MESES[Number(m.slice(5, 7)) - 1]}/${m.slice(2, 4)}`

function SinalAcrescimo({ sinal, limite }: { sinal: Contrato['sinal_acrescimo']; limite: number }) {
  if (!sinal) return null
  return <Badge cor={sinal === 'ACIMA' ? 'perigo' : 'alerta'} title={`Limite legal de referência: ${limite}% do valor inicial`}>{sinal === 'ACIMA' ? 'Acima do limite' : 'Próximo do limite'}</Badge>
}

/** Módulo 13 — Gestão de contratos (seção 22). */
export function Contratos() {
  const { estado } = useFiltros()
  const { pode } = useAuth()
  const abrir = useDetalhes()
  const cabecalho = useCabecalhoExportacao()
  const consulta = paraQueryString(estado)
  const [vencendo, setVencendo] = useState<number | null>(null)
  const [sinalizados, setSinalizados] = useState(false)
  const [situacoes, setSituacoes] = useState<string[]>([])
  const [aberto, setAberto] = useState<number | null>(null)
  const q = usePainel<PainelContratos>('contratos', '/contratos/painel', consulta)
  const limite = q.data?.parametros.limite ?? 25

  const consultaLista = useMemo(() => {
    const p = new URLSearchParams(consulta)
    situacoes.forEach((s) => p.append('situacao[]', s))
    if (vencendo) p.set('vencendo_em', String(vencendo))
    if (sinalizados) p.set('sinalizados', '1')
    return p.toString()
  }, [consulta, situacoes, vencendo, sinalizados])

  const colunas = useMemo<Coluna<Contrato>[]>(() => [
    { id: 'numero', titulo: 'Contrato', tipo: 'texto', valor: (l) => l.numero, fixa: true, largura: 110, render: (l) => <span className="font-medium">{l.numero}</span> },
    { id: 'orgao', titulo: 'Órgão', tipo: 'texto', valor: (l) => l.orgao, ordenavel: false },
    { id: 'fornecedor', titulo: 'Fornecedor', tipo: 'texto', valor: (l) => l.fornecedor, largura: 220,
      render: (l) => <button className="text-left text-primary hover:underline" onClick={(e) => { e.stopPropagation(); abrir('fornecedor', l.fornecedor_id) }}>{l.fornecedor}</button> },
    { id: 'fornecedor_documento', titulo: 'CPF/CNPJ', tipo: 'texto', valor: (l) => l.fornecedor_documento, texto: (l) => formatarDocumento(l.fornecedor_documento), ordenavel: false },
    { id: 'objeto', titulo: 'Objeto', tipo: 'texto', valor: (l) => l.objeto, ordenavel: false, largura: 260, render: (l) => <span className="line-clamp-2" title={l.objeto ?? ''}>{l.objeto}</span> },
    { id: 'processo', titulo: 'Processo', tipo: 'texto', valor: (l) => l.processo, ordenavel: false,
      render: (l) => l.processo_id ? <button className="text-primary hover:underline" onClick={(e) => { e.stopPropagation(); abrir('processo', l.processo_id!) }}>{l.processo}</button> : '—' },
    { id: 'valor_inicial', titulo: 'Valor inicial (R$)', tipo: 'moeda', valor: (l) => l.valor_inicial },
    { id: 'valor_atualizado', titulo: 'Valor atualizado (R$)', tipo: 'moeda', valor: (l) => l.valor_atualizado },
    { id: 'percentual_acrescimo', titulo: '% acréscimo', dica: `Σ aditivos de valor / valor inicial × 100. Referência legal: ${limite}% (50% em reforma).`, tipo: 'percentual',
      valor: (l) => l.percentual_acrescimo, render: (l) => <span className="inline-flex items-center gap-1.5 tabular">{formatarPercentual(l.percentual_acrescimo)}<SinalAcrescimo sinal={l.sinal_acrescimo} limite={limite} /></span> },
    { id: 'aditivos', titulo: 'Aditivos', tipo: 'numero', valor: (l) => l.aditivos, ordenavel: false },
    { id: 'data_assinatura', titulo: 'Assinatura', tipo: 'data', valor: (l) => l.data_assinatura },
    { id: 'vigencia_fim', titulo: 'Fim da vigência', tipo: 'data', valor: (l) => l.vigencia_fim,
      render: (l) => <span className="tabular">{formatarData(l.vigencia_fim)}{l.dias_para_vencer !== null && l.dias_para_vencer >= 0 && l.dias_para_vencer <= 90 && <Badge cor={l.dias_para_vencer <= 30 ? 'perigo' : 'alerta'} className="ml-1">{l.dias_para_vencer} dias</Badge>}</span> },
    { id: 'situacao', titulo: 'Situação', tipo: 'texto', valor: (l) => SITUACOES[l.situacao]?.rotulo ?? l.situacao, ordenavel: false,
      render: (l) => <Badge cor={SITUACOES[l.situacao]?.cor ?? 'neutro'}>{SITUACOES[l.situacao]?.rotulo ?? l.situacao}</Badge> },
    { id: 'gestor', titulo: 'Gestor', tipo: 'texto', valor: (l) => l.gestor, ordenavel: false },
    { id: 'fiscal', titulo: 'Fiscal', tipo: 'texto', valor: (l) => l.fiscal, ordenavel: false },
  ], [abrir, limite])

  const filtrosExtra = [
    ...situacoes.map((s) => SITUACOES[s].rotulo),
    ...(vencendo ? [`Vencendo em até ${vencendo} dias`] : []),
    ...(sinalizados ? ['Somente acréscimo sinalizado'] : []),
  ]

  return (
    <div>
      <CabecalhoModulo titulo="Gestão de contratos" descricao="Contratos, aditivos, vigência e alertas de vencimento e de acréscimo." consulta={consulta} />
      <BarraFiltros />
      <EstadoPainel q={q}>
        {(d) => {
          const i = d.indicadores
          return (
            <>
              <GradeKpis colunas="xl:grid-cols-4" itens={[
                { rotulo: 'Contratos vigentes', valor: i.vigentes, tipo: 'numero', destaque: true, dica: 'Situação vigente e fim da vigência a partir de hoje.' },
                { rotulo: 'Valor dos contratos vigentes', valor: i.valor_vigente, tipo: 'moeda', dica: 'Σ valor atualizado (inicial + aditivos).' },
                { rotulo: 'Aditivos', valor: i.aditivos, tipo: 'numero' },
                { rotulo: 'Valor dos aditivos', valor: i.valor_aditivos, tipo: 'moeda' },
                { rotulo: 'Acréscimo médio', valor: i.percentual_acrescimo, tipo: 'percentual', dica: 'Σ aditivos de valor / Σ valor inicial dos contratos × 100.' },
                { rotulo: 'Contratos sinalizados', valor: i.sinalizados, tipo: 'numero', dica: `Acréscimo a partir de ${d.parametros.proximo}% do limite de ${d.parametros.limite}%. Pede análise; não indica irregularidade.` },
                { rotulo: 'Contratos no recorte', valor: i.total, tipo: 'numero' },
              ]} />
              <div className="mb-4 grid grid-cols-1 gap-4 xl:grid-cols-3">
                <Card>
                  <CardHeader><div><CardTitle>Vencendo</CardTitle><p className="mt-0.5 text-xs text-muted-foreground">Contratos vigentes por prazo até o fim · clique para listar</p></div></CardHeader>
                  <CardContent className="grid grid-cols-3 gap-2">
                    {d.vencendo.map((v) => {
                      const ativo = vencendo === v.dias
                      return (
                        <button key={v.dias} aria-pressed={ativo} onClick={() => setVencendo(ativo ? null : v.dias)}
                          className={cn('rounded-md border px-3 py-3 text-left transition-colors', ativo ? 'border-primary bg-accent' : 'hover:bg-muted')}>
                          <span className="block text-[11px] text-muted-foreground">Até {v.dias} dias</span>
                          <span className="mt-0.5 block text-xl font-semibold tabular">{formatarNumero(v.quantidade)}</span>
                        </button>
                      )
                    })}
                  </CardContent>
                </Card>
                <BarrasDuplas className="xl:col-span-2" titulo="Vencimentos nos próximos 12 meses" subtitulo="Quantidade de contratos vigentes por mês do fim da vigência"
                  dados={d.vencimentos_por_mes.map((m) => ({ ...m, rotulo: rotuloMes(m.mes) }))} x="rotulo" moeda={false} series={[{ chave: 'quantidade', nome: 'Contratos', cor: SERIES[0] }]} />
                <Card className="xl:col-span-2">
                  <CardHeader><div><CardTitle>Contratos vigentes por órgão</CardTitle><p className="mt-0.5 text-xs text-muted-foreground">Valor atualizado · clique para filtrar o painel</p></div></CardHeader>
                  <CardContent><ListaBarras dados={d.por_orgao} /></CardContent>
                </Card>
                <Card>
                  <CardHeader><CardTitle>Por situação</CardTitle></CardHeader>
                  <CardContent>
                    <MiniTabela cabecalho={['Situação', 'Contratos', 'Valor']} direita={[1, 2]}
                      linhas={d.por_situacao.map((s) => [<Badge key={s.situacao} cor={SITUACOES[s.situacao]?.cor ?? 'neutro'}>{SITUACOES[s.situacao]?.rotulo ?? s.situacao}</Badge>, formatarNumero(s.quantidade), formatarMoeda(s.valor)])} />
                    {d.execucao_financeira && <Nota className="mt-3">{d.execucao_financeira}</Nota>}
                  </CardContent>
                </Card>
              </div>
            </>
          )
        }}
      </EstadoPainel>
      <Card>
        <CardContent className="pt-5">
          <div className="mb-3 flex flex-wrap items-center gap-2">
            <div className="flex flex-wrap gap-2" role="group" aria-label="Filtrar por situação">
              {Object.entries(SITUACOES).map(([k, v]) => {
                const ativo = situacoes.includes(k)
                return <button key={k} aria-pressed={ativo} onClick={() => setSituacoes((x) => (ativo ? x.filter((y) => y !== k) : [...x, k]))}
                  className={cn('rounded-md border px-3 py-1.5 text-xs', ativo ? 'border-primary bg-accent text-accent-foreground' : 'hover:bg-muted')}>{v.rotulo}</button>
              })}
            </div>
            <label className="ml-1 flex cursor-pointer items-center gap-2 text-xs"><Caixa marcado={sinalizados} aoMudar={setSinalizados} rotulo="Somente acréscimo sinalizado" />Somente acréscimo sinalizado</label>
            {vencendo && <button className="rounded-md border border-primary bg-accent px-3 py-1.5 text-xs" onClick={() => setVencendo(null)}>Vencendo em até {vencendo} dias ✕</button>}
          </div>
          <TabelaServidor<Contrato> endpoint="/contratos" consulta={consultaLista} colunas={colunas} ordemPadrao={{ id: 'vigencia_fim', direcao: 'asc' }}
            placeholderPesquisa="Número, objeto, fornecedor, CNPJ, processo, gestor ou fiscal…" podeExportar={pode('relatorios.exportar')}
            cabecalhoExportacao={cabecalho('Contratos', filtrosExtra)} aoClicarLinha={(l) => setAberto(l.id)} />
        </CardContent>
      </Card>
      <Nota className="mt-4">O limite de acréscimo segue o art. 125 da Lei 14.133/2021 ({limite}% do valor inicial; 50% em reforma de edifício ou equipamento) e pode ser ajustado em Administração › Configurações. O alerta é um apoio à fiscalização e não substitui a análise jurídica.</Nota>
      <DetalheContratoDialogo id={aberto} consulta={consulta} aoFechar={() => setAberto(null)} />
    </div>
  )
}

function DetalheContratoDialogo({ id, consulta, aoFechar }: { id: number | null; consulta: string; aoFechar: () => void }) {
  const abrir = useDetalhes()
  const q = useQuery({ queryKey: ['contrato', id, consulta], queryFn: () => api<DetalheContrato>(`/contratos/${id}?${consulta}`), enabled: id !== null })
  const c = q.data?.contrato
  return (
    <Dialogo aberto={id !== null} aoFechar={aoFechar} titulo={c ? `Contrato ${c.numero}` : 'Contrato'} descricao={c ? `${c.orgao}${c.unidade ? ` · ${c.unidade}` : ''}` : undefined}>
      {q.isLoading ? <Carregando /> : q.isError ? <Erro erro={q.error} /> : q.data && c && (
        <>
          <Secao titulo="Valores">
            <Numeros itens={[
              ['Valor inicial', formatarMoeda(c.valor_inicial)], ['Acréscimos', formatarMoeda(c.acrescimos)], ['Valor atualizado', formatarMoeda(c.valor_atualizado)],
              ['% acréscimo', formatarPercentual(c.percentual_acrescimo)], ['Limite de referência', `${q.data.parametros.limite}%`],
            ]} />
          </Secao>
          <Secao titulo="Dados do contrato" acoes={c.link_pncp ? <a className="inline-flex items-center gap-1 text-xs text-primary hover:underline" href={c.link_pncp} target="_blank" rel="noreferrer">PNCP <ExternalLink className="size-3" /></a> : undefined}>
            <Campos itens={[
              ['Fornecedor', <button key="f" className="text-left text-primary hover:underline" onClick={() => abrir('fornecedor', c.fornecedor_id)}>{c.fornecedor}</button>],
              ['CPF/CNPJ', formatarDocumento(c.fornecedor_documento)],
              ['Processo', c.processo_id ? <button key="p" className="text-primary hover:underline" onClick={() => abrir('processo', c.processo_id!)}>{c.processo}</button> : null],
              ['Ata de registro de preços', c.ata], ['Situação', SITUACOES[c.situacao]?.rotulo ?? c.situacao],
              ['Assinatura', formatarData(c.data_assinatura)], ['Vigência', `${formatarData(c.vigencia_inicio)} a ${formatarData(c.vigencia_fim)}`],
              ['Gestor', c.gestor], ['Fiscal', c.fiscal], ['Objeto', c.objeto],
            ]} />
            {c.ficticio && <p className="mt-3 text-xs text-muted-foreground">Registro demonstrativo (fictício).</p>}
          </Secao>
          <Secao titulo={`Aditivos (${q.data.aditivos.length})`}>
            <MiniTabela cabecalho={['Número', 'Tipo', 'Assinatura', 'Valor', '% do inicial', 'Nova vigência', 'Justificativa']} direita={[3, 4]} vazio="Nenhum aditivo."
              linhas={q.data.aditivos.map((a) => [a.numero, TIPO_ADITIVO[a.tipo] ?? a.tipo, formatarData(a.data_assinatura), a.valor !== null ? formatarMoeda(a.valor) : '—',
                a.percentual !== null ? formatarPercentual(a.percentual) : '—', a.nova_vigencia_fim ? formatarData(a.nova_vigencia_fim) : '—', a.justificativa ?? '—'])} />
          </Secao>
          <Secao titulo={`Itens (${q.data.itens.length})`}>
            <MiniTabela cabecalho={['Descrição', 'Unidade', 'Quantidade', 'Valor unitário', 'Valor total']} direita={[2, 3, 4]} vazio="Itens não informados."
              linhas={q.data.itens.map((i) => [i.descricao, i.unidade ?? '—', formatarNumero(i.quantidade), formatarMoeda(i.valor_unitario), formatarMoeda(i.valor_total)])} />
          </Secao>
          <Secao titulo="Execução financeira">
            {q.data.financeiro
              ? <Numeros itens={[['Empenhado', formatarMoeda(q.data.financeiro.empenhado)], ['Liquidado', formatarMoeda(q.data.financeiro.liquidado)], ['Pago', formatarMoeda(q.data.financeiro.pago)]]} />
              : <p className="text-xs text-muted-foreground">Fonte financeira estadual ainda não integrada. Empenhado, liquidado e pago aparecem quando a integração for ativada.</p>}
          </Secao>
        </>
      )}
    </Dialogo>
  )
}
