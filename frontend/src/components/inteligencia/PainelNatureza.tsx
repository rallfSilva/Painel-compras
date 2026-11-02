import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { paraQueryString, useFiltros } from '@/contexts/FiltrosContext'
import { useAuth } from '@/contexts/AuthContext'
import { GraficoAgrupado } from '@/components/compras/GraficosCompras'
import type { Agrupamento } from '@/components/compras/ListaBarras'
import { TabelaAvancada } from '@/components/tabela/TabelaAvancada'
import type { Coluna } from '@/components/tabela/colunas'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Dialogo } from '@/components/ui/dialog'
import { Carregando, Erro, MiniTabela, Secao } from '@/components/detalhes/Partes'
import { useDetalhes } from '@/components/detalhes/useDetalhes'
import { formatarData, formatarDecimal, formatarMoeda, formatarNumero, formatarUnitario } from '@/lib/format'
import { cn } from '@/lib/utils'
import { EstadoPainel, LinhasAno, SERIES, useCabecalhoExportacao, usePainel } from './partes'

interface ItemRanking {
  id: number; codigo: string; descricao: string; tipo: string; unidade: string | null; classe: string | null; grupo: string | null
  quantidade: number; contratado: number | null; preco_medio: number | null; preco_minimo: number | null; preco_maximo: number | null
  frequencia: number; orgaos: number; filtro: 'item'; chave: string
}
interface PainelNat {
  por_tipo: Agrupamento[]; por_natureza_despesa: Agrupamento[]; por_elemento: Agrupamento[]; por_subelemento: Agrupamento[]
  por_grupo: Agrupamento[]; por_classe: Agrupamento[]; ranking_itens: ItemRanking[]
}
interface FichaItem {
  item: { id: number; codigo: string; descricao: string; especificacao: string | null; classe: string | null }
  evolucao_precos: { ano: number; unidade: string | null; preco_medio: number | null; preco_minimo: number; preco_maximo: number; compras: number }[]
  processos: { id: number; numero_publicacao: string | null; data_abertura: string; objeto: string; sistematica: string; orgao: string; quantidade: number | null; unidade: string | null; valor_unitario: number | null; contratado: number | null; situacao: string; fornecedor: string | null }[]
}

type Criterio = 'contratado' | 'quantidade' | 'frequencia'
const CRITERIOS: { id: Criterio; rotulo: string; titulo: string }[] = [
  { id: 'contratado', rotulo: 'Maior valor', titulo: 'Itens com maior valor financeiro' },
  { id: 'quantidade', rotulo: 'Mais comprados', titulo: 'Itens mais comprados (quantidade, na unidade de fornecimento)' },
  { id: 'frequencia', rotulo: 'Maior frequência', titulo: 'Itens com maior frequência de aquisição (nº de processos)' },
]

/** Painel por natureza da despesa / classe do catálogo (seção 10). */
export function PainelNatureza() {
  const { estado } = useFiltros()
  const consulta = paraQueryString(estado)
  const q = usePainel<PainelNat>('natureza', '/inteligencia/natureza', consulta)
  return <EstadoPainel q={q}>{(d) => <Conteudo d={d} consulta={consulta} />}</EstadoPainel>
}

function Conteudo({ d, consulta }: { d: PainelNat; consulta: string }) {
  const { pode } = useAuth()
  const cabecalho = useCabecalhoExportacao()
  const [criterio, setCriterio] = useState<Criterio>('contratado')
  const [item, setItem] = useState<ItemRanking | null>(null)
  const top = useMemo(() => [...d.ranking_itens].sort((a, b) => (b[criterio] ?? 0) - (a[criterio] ?? 0)).slice(0, 10), [d.ranking_itens, criterio])
  const formatar = (v: number) => (criterio === 'contratado' ? formatarMoeda(v) : criterio === 'quantidade' ? formatarDecimal(v) : formatarNumero(v))
  const maxTop = Math.max(1, ...top.map((t) => t[criterio] ?? 0))

  const colunas: Coluna<ItemRanking>[] = [
    { id: 'pos', titulo: '#', tipo: 'numero', valor: (l) => d.ranking_itens.indexOf(l) + 1, largura: 44, fixa: true },
    { id: 'codigo', titulo: 'Código', tipo: 'texto', valor: (l) => l.codigo, largura: 80, fixa: true },
    { id: 'descricao', titulo: 'Descrição', tipo: 'texto', valor: (l) => l.descricao, largura: 240 },
    { id: 'classe', titulo: 'Classe', tipo: 'texto', valor: (l) => l.classe, largura: 180 },
    { id: 'unidade', titulo: 'Unid.', tipo: 'texto', valor: (l) => l.unidade },
    { id: 'quantidade', titulo: 'Quantidade', tipo: 'decimal', valor: (l) => l.quantidade },
    { id: 'preco_medio', titulo: 'Valor unitário médio', tipo: 'unitario', valor: (l) => l.preco_medio },
    { id: 'contratado', titulo: 'Valor total', tipo: 'moeda', valor: (l) => l.contratado, barra: true },
    { id: 'frequencia', titulo: 'Frequência', dica: 'Processos em que o item foi comprado.', tipo: 'numero', valor: (l) => l.frequencia },
    { id: 'orgaos', titulo: 'Órgãos', tipo: 'numero', valor: (l) => l.orgaos },
  ]

  return (
    <>
      <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
        <GraficoAgrupado titulo="Compras por tipo de aquisição" subtitulo="Material de consumo, permanente, serviços, obras… · valor contratado (R$) · clique para filtrar" rotuloDimensao="Tipo" dados={d.por_tipo} />
        <GraficoAgrupado titulo="Compras por natureza da despesa" rotuloDimensao="Natureza" dados={d.por_natureza_despesa} />
        <GraficoAgrupado titulo="Compras por elemento de despesa" rotuloDimensao="Elemento" dados={d.por_elemento} />
        <GraficoAgrupado titulo="Compras por subelemento de despesa" rotuloDimensao="Subelemento" dados={d.por_subelemento} />
        <GraficoAgrupado titulo="Compras por grupo" subtitulo="Grupo de material ou serviço · valor contratado (R$) · clique para filtrar" rotuloDimensao="Grupo" dados={d.por_grupo} />
        <GraficoAgrupado titulo="Compras por classe" subtitulo="Classe de material ou serviço · valor contratado (R$) · clique para filtrar" rotuloDimensao="Classe" dados={d.por_classe} />
        <Card className="xl:col-span-2">
          <CardHeader>
            <div><CardTitle>{CRITERIOS.find((c) => c.id === criterio)!.titulo}</CardTitle><p className="mt-0.5 text-xs text-muted-foreground">10 primeiros entre os 50 itens de maior valor · clique para ver a evolução de preços e os processos</p></div>
          </CardHeader>
          <CardContent>
            <div className="mb-3 inline-flex rounded-md border p-0.5 text-[11px]" role="group" aria-label="Critério do ranking">
              {CRITERIOS.map((c) => (
                <button key={c.id} onClick={() => setCriterio(c.id)} aria-pressed={criterio === c.id}
                  className={cn('rounded px-2 py-1 text-muted-foreground', criterio === c.id && 'bg-muted font-medium text-foreground')}>{c.rotulo}</button>
              ))}
            </div>
            <ul className="space-y-2.5">
              {top.map((t) => (
                <li key={`${t.id}-${t.unidade}`}>
                  <button type="button" className="block w-full rounded text-left hover:bg-muted/60" onClick={() => setItem(t)}>
                    <span className="flex items-baseline justify-between gap-3 text-xs">
                      <span className="min-w-0 truncate font-medium" title={t.descricao}>{t.descricao} <span className="font-normal text-muted-foreground">({t.unidade ?? '—'})</span></span>
                      <span className="shrink-0 font-semibold tabular">{formatar(t[criterio] ?? 0)}</span>
                    </span>
                    <span className="mt-1 block h-2 rounded-full bg-muted" aria-hidden><span className="block h-2 rounded-full" style={{ width: `${((t[criterio] ?? 0) / maxTop) * 100}%`, background: 'var(--serie-1)' }} /></span>
                  </button>
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>
      </div>
      <Card className="mt-4">
        <CardHeader><div><CardTitle>Ranking dos 50 itens mais adquiridos</CardTitle><p className="mt-0.5 text-xs text-muted-foreground">Por valor contratado, por item e unidade de fornecimento · clique para detalhar até os processos</p></div></CardHeader>
        <CardContent>
          <TabelaAvancada colunas={colunas} dados={d.ranking_itens} podeExportar={pode('relatorios.exportar')} cabecalhoExportacao={cabecalho('Ranking dos 50 itens mais adquiridos')}
            pesquisarEm={(l) => `${l.codigo} ${l.descricao} ${l.classe ?? ''}`} aoClicarLinha={setItem} rotuloLinha={(l) => `Detalhar ${l.descricao}`} />
        </CardContent>
      </Card>
      <Dialogo aberto={!!item} aoFechar={() => setItem(null)} titulo={item ? `${item.codigo} · ${item.descricao}` : ''} descricao="Evolução dos preços e processos em que o item foi comprado, com os filtros atuais.">
        {item && <FichaItemConteudo id={item.id} consulta={consulta} />}
      </Dialogo>
    </>
  )
}

function FichaItemConteudo({ id, consulta }: { id: number; consulta: string }) {
  const abrir = useDetalhes()
  const q = useQuery({ queryKey: ['item-inteligencia', id, consulta], queryFn: () => api<FichaItem>(`/inteligencia/itens/${id}?${consulta}`) })
  if (q.isLoading) return <Carregando />
  if (q.isError || !q.data) return <Erro erro={q.error} />
  const f = q.data
  const unidades = [...new Set(f.evolucao_precos.map((e) => e.unidade ?? '—'))]
  return (
    <div>
      {unidades.map((u) => (
        <Secao key={u} titulo={`Evolução dos preços · unidade ${u}`}>
          <LinhasAno titulo="Preço unitário adjudicado" subtitulo="Médio, mínimo e máximo por ano (R$)" className="border-0 shadow-none" sufixoEixo=""
            formatar={formatarUnitario} dados={f.evolucao_precos.filter((e) => (e.unidade ?? '—') === u)} series={[
              { chave: 'preco_medio', nome: 'Médio', cor: SERIES[0] }, { chave: 'preco_minimo', nome: 'Mínimo', cor: SERIES[2] }, { chave: 'preco_maximo', nome: 'Máximo', cor: SERIES[1] },
            ]} />
        </Secao>
      ))}
      <Secao titulo={`Processos (${f.processos.length}${f.processos.length >= 100 ? ', os 100 mais recentes' : ''})`}>
        <MiniTabela cabecalho={['Publicação', 'Abertura', 'Órgão', 'Sistemática', 'Qtd.', 'Unitário', 'Contratado', 'Fornecedor']} direita={[4, 5, 6]}
          linhas={f.processos.map((p) => [p.numero_publicacao ?? '—', formatarData(p.data_abertura), p.orgao, p.sistematica, `${formatarDecimal(p.quantidade)} ${p.unidade ?? ''}`, formatarUnitario(p.valor_unitario), formatarMoeda(p.contratado), p.fornecedor ?? '—'])}
          aoClicar={(k) => abrir('processo', f.processos[k].id, `Processo ${f.processos[k].numero_publicacao ?? f.processos[k].id}`)} />
      </Secao>
    </div>
  )
}
