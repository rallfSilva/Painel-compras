import { paraQueryString, useFiltros } from '@/contexts/FiltrosContext'
import { useAuth } from '@/contexts/AuthContext'
import { BarraFiltros } from '@/components/filters/BarraFiltros'
import { CabecalhoModulo } from '@/components/compras/CabecalhoModulo'
import { GraficoAgrupado } from '@/components/compras/GraficosCompras'
import type { Agrupamento } from '@/components/compras/ListaBarras'
import { BarraPercentual, Composicao, Empilhado100, EstadoPainel, GradeKpis, SERIES, useCabecalhoExportacao, usePainel } from '@/components/inteligencia/partes'
import { TabelaAvancada } from '@/components/tabela/TabelaAvancada'
import type { Coluna } from '@/components/tabela/colunas'
import { CartaoGrafico, Tabela } from '@/components/dashboard/Graficos'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { useDetalhes } from '@/components/detalhes/useDetalhes'
import { formatarMoeda, formatarNumero, formatarPercentual } from '@/lib/format'

interface Categoria { categoria: 'MPE' | 'AF' | 'DEMAIS' | 'NI'; nome: string; contratado: number; fornecedores: number; processos: number; percentual: number | null }
interface OrgaoMpe extends Agrupamento { total: number; fornecedores_mpe: number }
interface Fornecedor { id: number; nome: string; porte: string; municipio: string | null; uf: string | null; local: boolean; contratado: number; processos: number; itens_beneficio: number }
interface PainelMpe {
  indicadores: Record<string, number | null>
  por_categoria: Categoria[]
  por_porte: Agrupamento[]
  por_municipio: Agrupamento[]
  por_orgao: OrgaoMpe[]
  evolucao_anual: { ano: number; total: number; mpe: number; agricultura_familiar: number; percentual_mpe: number | null; percentual_agricultura_familiar: number | null }[]
  localizacao: { grupo: string; nome: string; contratado: number; percentual: number | null }[]
  principais_fornecedores: Fornecedor[]
}

const COR_CATEGORIA = { MPE: SERIES[0], AF: SERIES[1], DEMAIS: SERIES[2], NI: SERIES[3] }

/** Módulo 3 — Compras de MPE (seção 12). */
export function ComprasMpe() {
  const { estado } = useFiltros()
  const { pode } = useAuth()
  const abrir = useDetalhes()
  const cabecalho = useCabecalhoExportacao()
  const consulta = paraQueryString(estado)
  const q = usePainel<PainelMpe>('mpe', '/inteligencia/mpe', consulta)

  return (
    <div>
      <CabecalhoModulo titulo="Compras de MPE" descricao="Contratações com microempreendedores individuais, microempresas, empresas de pequeno porte e agricultura familiar." consulta={consulta} />
      <BarraFiltros />
      <EstadoPainel q={q}>
        {(d) => {
          const i = d.indicadores
          const evolucao = d.evolucao_anual.map((e) => {
            const af = e.percentual_agricultura_familiar ?? 0
            const mpe = e.percentual_mpe ?? 0
            return { ...e, mpe_pct: mpe, af_pct: af, demais_pct: Math.max(0, Math.round((100 - mpe - af) * 100) / 100) }
          })
          const colunas: Coluna<Fornecedor>[] = [
            { id: 'nome', titulo: 'Fornecedor', tipo: 'texto', valor: (l) => l.nome, largura: 240, fixa: true },
            { id: 'porte', titulo: 'Porte', tipo: 'texto', valor: (l) => l.porte },
            { id: 'local', titulo: 'Localização', tipo: 'texto', valor: (l) => (l.municipio ? `${l.municipio}/${l.uf}` : null),
              render: (l) => <span>{l.municipio ? `${l.municipio}/${l.uf}` : '—'}{l.local && <span className="ml-1.5 rounded bg-accent px-1 text-[10px] font-semibold text-accent-foreground">RR</span>}</span> },
            { id: 'contratado', titulo: 'Contratado', tipo: 'moeda', valor: (l) => l.contratado, barra: true },
            { id: 'processos', titulo: 'Processos', tipo: 'numero', valor: (l) => l.processos },
            { id: 'itens_beneficio', titulo: 'Itens com benefício', dica: 'Itens exclusivos para MPE ou de cota reservada vencidos pelo fornecedor.', tipo: 'numero', valor: (l) => l.itens_beneficio },
          ]
          return (
            <>
              <GradeKpis itens={[
                { rotulo: 'Valor contratado de MPE', valor: i.valor_mpe, tipo: 'moeda', destaque: true, dica: 'Σ valor contratado dos itens vencidos por MEI, ME ou EPP.' },
                { rotulo: '% de participação MPE', valor: i.percentual_mpe, tipo: 'percentual', dica: 'Valor contratado de MPE / valor contratado dos itens com fornecedor informado × 100.' },
                { rotulo: 'Compras exclusivas', valor: i.valor_exclusivos, tipo: 'moeda', nota: `${formatarNumero(i.itens_exclusivos)} itens em ${formatarNumero(i.processos_exclusivos)} processos`, dica: 'Itens com participação exclusiva de MPE (LC 123/2006, art. 48, I).' },
                { rotulo: 'Itens com cota reservada', valor: i.valor_cota_reservada, tipo: 'moeda', nota: `${formatarNumero(i.itens_cota_reservada)} itens`, dica: 'Valor contratado dos itens com cota reservada para MPE (LC 123/2006, art. 48, III).' },
                { rotulo: 'Agricultura familiar', valor: i.valor_agricultura_familiar, tipo: 'moeda', nota: `${formatarPercentual(i.percentual_agricultura_familiar)} do total · ${formatarNumero(i.fornecedores_agricultura_familiar)} fornecedores`, dica: 'Produtores familiares (PAF) e cooperativas, em categoria própria, fora das MPE.' },
                { rotulo: 'Fornecedores MPE', valor: i.fornecedores_mpe, tipo: 'numero' },
                { rotulo: 'Processos vencidos por MPE', valor: i.processos_vencidos_mpe, tipo: 'numero', nota: `${formatarPercentual(i.percentual_processos_mpe)} dos processos com resultado` },
                { rotulo: 'Fornecedores MPE de Roraima', valor: i.fornecedores_mpe_rr, tipo: 'numero' },
                { rotulo: '% do valor MPE com empresas de RR', valor: i.percentual_mpe_rr, tipo: 'percentual', dica: 'Valor contratado de MPE sediadas em Roraima / valor contratado de MPE × 100.' },
              ]} />
              <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                <Composicao titulo="Participação por categoria de porte" subtitulo="Percentual do valor contratado dos itens com fornecedor informado"
                  dados={d.por_categoria.map((c) => ({ chave: c.categoria, nome: c.nome, valor: c.contratado, percentual: c.percentual, cor: COR_CATEGORIA[c.categoria] }))} />
                <Composicao titulo="MPE locais e de outros estados" subtitulo="Valor contratado de MPE pela localização do fornecedor"
                  dados={d.localizacao.map((l, k) => ({ chave: l.grupo, nome: l.nome, valor: l.contratado, percentual: l.percentual, cor: SERIES[k] }))} />
                <Empilhado100 className="xl:col-span-2" titulo="Evolução anual da participação"
                  subtitulo="Percentual do valor contratado de cada ano: MPE, agricultura familiar e demais portes (inclui não informado)"
                  dados={evolucao} series={[
                    { chave: 'mpe_pct', nome: 'MPE', cor: SERIES[0], valor: 'mpe' },
                    { chave: 'af_pct', nome: 'Agricultura familiar', cor: SERIES[1], valor: 'agricultura_familiar' },
                    { chave: 'demais_pct', nome: 'Demais', cor: SERIES[3] },
                  ]} />
                <GraficoAgrupado titulo="Compras por porte" rotuloDimensao="Porte" dados={d.por_porte} />
                <GraficoAgrupado titulo="Distribuição por município (MPE)" subtitulo="Município do fornecedor MPE · valor contratado (R$) · clique para filtrar" rotuloDimensao="Município" dados={d.por_municipio} />
                <CartaoGrafico className="xl:col-span-2" titulo="Distribuição por órgão" subtitulo="Valor contratado com MPE e participação no total do órgão · clique para filtrar"
                  grafico={<OrgaosMpe dados={d.por_orgao} />}
                  tabela={<Tabela cabecalho={['Órgão', 'MPE', 'Total', '% MPE', 'Fornecedores MPE']} linhas={d.por_orgao.map((o) => [o.nome, formatarMoeda(o.contratado), formatarMoeda(o.total), formatarPercentual(o.percentual), formatarNumero(o.fornecedores_mpe)])} />} />
              </div>
              <Card className="mt-4">
                <CardHeader><div><CardTitle>Principais fornecedores MPE</CardTitle><p className="mt-0.5 text-xs text-muted-foreground">20 maiores por valor contratado · o selo RR indica empresa sediada em Roraima · clique para ver a ficha</p></div></CardHeader>
                <CardContent>
                  <TabelaAvancada colunas={colunas} dados={d.principais_fornecedores} podeExportar={pode('relatorios.exportar')}
                    cabecalhoExportacao={cabecalho('Principais fornecedores MPE')} aoClicarLinha={(l) => abrir('fornecedor', l.id, l.nome)}
                    rotuloLinha={(l) => `Abrir ficha de ${l.nome}`} pesquisarEm={(l) => `${l.nome} ${l.municipio ?? ''}`} />
                </CardContent>
              </Card>
            </>
          )
        }}
      </EstadoPainel>
    </div>
  )
}

function OrgaosMpe({ dados }: { dados: OrgaoMpe[] }) {
  const { aplicarCruzado } = useFiltros()
  return (
    <div className="relative overflow-x-auto">
      <table className="w-full min-w-[520px] text-xs">
        <thead className="text-left text-[11px] uppercase tracking-wide text-muted-foreground">
          <tr className="border-b"><th className="py-2 pr-2 font-semibold">Órgão</th><th className="py-2 pr-2 text-right font-semibold">Contratado MPE</th><th className="w-[40%] py-2 font-semibold">% MPE no órgão</th></tr>
        </thead>
        <tbody>
          {dados.map((o) => (
            <tr key={o.id ?? o.nome} className="cursor-pointer border-b last:border-0 hover:bg-muted/60" tabIndex={0}
              onClick={() => o.filtro && o.chave && aplicarCruzado(o.filtro, o.chave, o.nome)}
              onKeyDown={(e) => { if (e.key === 'Enter' && o.filtro && o.chave) aplicarCruzado(o.filtro, o.chave, o.nome) }}>
              <td className="py-2 pr-2 font-medium">{o.nome}</td>
              <td className="py-2 pr-2 text-right tabular">{formatarMoeda(o.contratado)}</td>
              <td className="py-2"><BarraPercentual valor={o.percentual} /></td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
