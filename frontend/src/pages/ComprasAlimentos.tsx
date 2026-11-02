import { CircleAlert, CircleCheck } from 'lucide-react'
import { paraQueryString, useFiltros } from '@/contexts/FiltrosContext'
import { useAuth } from '@/contexts/AuthContext'
import { BarraFiltros } from '@/components/filters/BarraFiltros'
import { CabecalhoModulo } from '@/components/compras/CabecalhoModulo'
import { GraficoAgrupado } from '@/components/compras/GraficosCompras'
import type { Agrupamento } from '@/components/compras/ListaBarras'
import { Composicao, EstadoPainel, GradeKpis, LinhasAno, Nota, SERIES, useCabecalhoExportacao, usePainel } from '@/components/inteligencia/partes'
import { TabelaAvancada } from '@/components/tabela/TabelaAvancada'
import type { Coluna } from '@/components/tabela/colunas'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { formatarMoeda, formatarNumero, formatarPercentual } from '@/lib/format'
import { COR } from '@/components/dashboard/Graficos'

interface Categoria { categoria: string; nome: string; contratado: number; processos: number; percentual: number | null; agricultura_familiar: number }
interface ItemAlimento {
  id: number | null; nome: string; codigo: string | null; unidade: string | null; categoria: string; perecivel: boolean | null
  contratado: number | null; quantidade: number | null; preco_medio: number | null; preco_minimo: number | null; preco_maximo: number | null
  frequencia: number; orgaos: number; fornecedores: number; percentual_agricultura_familiar: number | null; filtro: 'item' | null; chave: string | null
}
interface PainelAlimentos {
  indicadores: Record<string, number | null>
  pnae: { percentual_minimo: number; percentual_aproximado: number | null; atingido: boolean | null; base_legal: string; observacao: string }
  por_categoria: Categoria[]
  perecibilidade: { grupo: string; nome: string; contratado: number; percentual: number | null }[]
  por_item: ItemAlimento[]
  por_orgao: Agrupamento[]
  por_municipio_origem: Agrupamento[]
  por_fornecedor: Agrupamento[]
  evolucao_agricultura_familiar: { ano: number; total: number; pnae: number; agricultura_familiar: number; percentual: number | null; percentual_minimo: number }[]
}

/** Módulo 4 — Compras de Alimentos (seção 13). */
export function ComprasAlimentos() {
  const { estado, aplicarCruzado } = useFiltros()
  const { pode } = useAuth()
  const cabecalho = useCabecalhoExportacao()
  const consulta = paraQueryString(estado)
  const q = usePainel<PainelAlimentos>('alimentos', '/inteligencia/alimentos', consulta)

  return (
    <div>
      <CabecalhoModulo titulo="Compras de Alimentos" descricao="Gêneros alimentícios, Chamada Pública PNAE e agricultura familiar." consulta={consulta} />
      <BarraFiltros />
      <EstadoPainel q={q}>
        {(d) => {
          const i = d.indicadores
          const colunas: Coluna<ItemAlimento>[] = [
            { id: 'nome', titulo: 'Item', tipo: 'texto', valor: (l) => l.nome, largura: 220, fixa: true },
            { id: 'categoria', titulo: 'Categoria', tipo: 'texto', valor: (l) => l.categoria },
            { id: 'perecivel', titulo: 'Conservação', tipo: 'texto', valor: (l) => (l.perecivel === null ? null : l.perecivel ? 'Perecível' : 'Não perecível') },
            { id: 'unidade', titulo: 'Unid.', tipo: 'texto', valor: (l) => l.unidade },
            { id: 'contratado', titulo: 'Valor adquirido', tipo: 'moeda', valor: (l) => l.contratado, barra: true },
            { id: 'quantidade', titulo: 'Quantidade', tipo: 'decimal', valor: (l) => l.quantidade },
            { id: 'preco_medio', titulo: 'Preço médio', dica: 'Σ valor adjudicado / Σ quantidade adjudicada, na mesma unidade de fornecimento.', tipo: 'unitario', valor: (l) => l.preco_medio },
            { id: 'preco_minimo', titulo: 'Preço mín.', tipo: 'unitario', valor: (l) => l.preco_minimo },
            { id: 'preco_maximo', titulo: 'Preço máx.', tipo: 'unitario', valor: (l) => l.preco_maximo },
            { id: 'frequencia', titulo: 'Frequência', dica: 'Quantidade de processos em que o item foi comprado.', tipo: 'numero', valor: (l) => l.frequencia },
            { id: 'orgaos', titulo: 'Órgãos', tipo: 'numero', valor: (l) => l.orgaos },
            { id: 'fornecedores', titulo: 'Fornecedores', tipo: 'numero', valor: (l) => l.fornecedores },
            { id: 'af', titulo: '% agric. familiar', tipo: 'percentual', valor: (l) => l.percentual_agricultura_familiar },
          ]
          const p = d.pnae
          return (
            <>
              <GradeKpis itens={[
                { rotulo: 'Valor adquirido', valor: i.valor, tipo: 'moeda', destaque: true, dica: 'Σ valor contratado dos itens de alimentos (categoria de alimento no catálogo ou natureza "Gêneros alimentícios").' },
                { rotulo: 'Via Chamada Pública PNAE', valor: i.valor_pnae, tipo: 'moeda', nota: `${formatarPercentual(i.percentual_pnae)} do valor de alimentos` },
                { rotulo: 'De PAF e cooperativas', valor: i.valor_paf, tipo: 'moeda', nota: `${formatarPercentual(i.percentual_paf)} do valor de alimentos`, dica: 'Fornecedores de porte PAF (produtor/agricultor familiar) ou cooperativa.' },
                { rotulo: 'Perecíveis', valor: i.valor_pereciveis, tipo: 'moeda' },
                { rotulo: 'Não perecíveis', valor: i.valor_nao_pereciveis, tipo: 'moeda' },
                { rotulo: 'Itens distintos', valor: i.itens, tipo: 'numero' },
                { rotulo: 'Órgãos compradores', valor: i.orgaos, tipo: 'numero' },
                { rotulo: 'Fornecedores', valor: i.fornecedores, tipo: 'numero' },
                { rotulo: 'Municípios de origem', valor: i.municipios_origem, tipo: 'numero', dica: 'Municípios distintos de sede dos fornecedores.' },
                { rotulo: 'Processos (frequência)', valor: i.processos, tipo: 'numero' },
              ]} />

              <Card className="mb-4">
                <CardHeader><div><CardTitle>Agricultura familiar no PNAE</CardTitle><p className="mt-0.5 text-xs text-muted-foreground">Percentual mínimo de {formatarPercentual(p.percentual_minimo)} ({p.base_legal})</p></div></CardHeader>
                <CardContent>
                  <div className="flex flex-wrap items-center gap-4">
                    <div className="min-w-[220px] flex-1">
                      <div className="relative h-3 rounded-full bg-muted" role="img" aria-label={`${formatarPercentual(p.percentual_aproximado)} de agricultura familiar; mínimo ${formatarPercentual(p.percentual_minimo)}`}>
                        <div className="h-3 rounded-full" style={{ width: `${Math.min(100, p.percentual_aproximado ?? 0)}%`, background: COR.s1 }} />
                        <div className="absolute -top-1 h-5 w-0.5 bg-foreground" style={{ left: `${p.percentual_minimo}%` }} aria-hidden />
                      </div>
                      <p className="mt-1.5 text-[11px] text-muted-foreground">A marca vertical indica o mínimo de {formatarPercentual(p.percentual_minimo)}.</p>
                    </div>
                    <div className="text-right">
                      <p className="text-2xl font-semibold tabular">{formatarPercentual(p.percentual_aproximado)}</p>
                      {p.atingido !== null && (
                        <p className={`flex items-center justify-end gap-1 text-xs font-medium ${p.atingido ? 'text-success' : 'text-warning'}`}>
                          {p.atingido ? <CircleCheck className="size-3.5" aria-hidden /> : <CircleAlert className="size-3.5" aria-hidden />}
                          {p.atingido ? 'Acima do mínimo (aproximação)' : 'Abaixo do mínimo (requer análise)'}
                        </p>
                      )}
                    </div>
                  </div>
                  <Nota className="mb-0 mt-3">{p.observacao}</Nota>
                </CardContent>
              </Card>

              <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                <GraficoAgrupado titulo="Compras por categoria" subtitulo="Valor contratado (R$) por categoria de alimento"
                  rotuloDimensao="Categoria" dados={d.por_categoria.map((c) => ({ id: null, nome: c.nome, contratado: c.contratado, processos: c.processos, percentual: c.percentual, filtro: null, chave: null }))} />
                <Composicao titulo="Perecíveis e não perecíveis" subtitulo="Percentual do valor de alimentos"
                  dados={d.perecibilidade.map((x, k) => ({ chave: x.grupo, nome: x.nome, valor: x.contratado, percentual: x.percentual, cor: SERIES[x.grupo === 'NI' ? 3 : k] }))} />
                <LinhasAno className="xl:col-span-2" titulo="Participação da agricultura familiar por ano"
                  subtitulo="Percentual do valor de alimentos adquirido via Chamada Pública PNAE ou de PAF/cooperativas (aproximação)"
                  dados={d.evolucao_agricultura_familiar} series={[{ chave: 'percentual', nome: '% agricultura familiar', cor: SERIES[0] }]}
                  referencia={{ valor: p.percentual_minimo, rotulo: `Mínimo legal (${formatarPercentual(p.percentual_minimo)})` }} />
                <GraficoAgrupado titulo="Órgãos compradores" rotuloDimensao="Órgão" dados={d.por_orgao} />
                <GraficoAgrupado titulo="Município de origem" subtitulo="Município do fornecedor · valor contratado (R$) · clique para filtrar" rotuloDimensao="Município" dados={d.por_municipio_origem} />
                <GraficoAgrupado className="xl:col-span-2" titulo="Fornecedores de alimentos" rotuloDimensao="Fornecedor" dados={d.por_fornecedor} />
              </div>

              <Card className="mt-4">
                <CardHeader><div><CardTitle>Itens de alimentos</CardTitle><p className="mt-0.5 text-xs text-muted-foreground">Preços por item e unidade de fornecimento, sem comparar unidades diferentes · clique em uma linha para filtrar o painel pelo item</p></div></CardHeader>
                <CardContent>
                  <TabelaAvancada colunas={colunas} dados={d.por_item} podeExportar={pode('relatorios.exportar')} cabecalhoExportacao={cabecalho('Itens de alimentos')}
                    pesquisarEm={(l) => `${l.nome} ${l.codigo ?? ''} ${l.categoria}`} ordemInicial={[{ id: 'contratado', desc: true }]}
                    aoClicarLinha={(l) => { if (l.filtro && l.chave) aplicarCruzado(l.filtro, l.chave, l.nome) }} rotuloLinha={(l) => `Filtrar por ${l.nome}`}
                    rodape={<span>{formatarNumero(d.por_item.length)} itens · total {formatarMoeda(d.por_item.reduce((s, x) => s + (x.contratado ?? 0), 0))}</span>} />
                </CardContent>
              </Card>
            </>
          )
        }}
      </EstadoPainel>
    </div>
  )
}
