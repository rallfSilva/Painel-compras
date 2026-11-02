import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { paraQueryString, useFiltros } from '@/contexts/FiltrosContext'
import { useAuth } from '@/contexts/AuthContext'
import { CartaoGrafico, COR, DicaGrafico, EIXO, Tabela } from '@/components/dashboard/Graficos'
import { ListaBarras, type Agrupamento } from '@/components/compras/ListaBarras'
import { TabelaAvancada } from '@/components/tabela/TabelaAvancada'
import type { Coluna } from '@/components/tabela/colunas'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { MiniTabela } from '@/components/detalhes/Partes'
import { formatarMoeda, formatarNumero, formatarPercentual } from '@/lib/format'
import { EstadoPainel, GradeKpis, Nota, SERIES, useCabecalhoExportacao, usePainel, type Serie } from './partes'

interface LinhaSist {
  id: number; nome: string; tipo: string; tipo_nome: string; com_disputa: boolean; processos: number; percentual_processos: number | null
  contratado: number | null; economia: number | null; percentual_economia: number | null; percentual_disputa: number | null; media_participantes: number | null
  eletronicos: number; presenciais: number; registro_precos: number; situacoes: Record<string, number>; tempo_medio_dias: number | null
  filtro: 'sistematica'; chave: string
}
interface PainelSist {
  resumo: { processos: number; situacoes: { situacao: string; quantidade: number }[]; tempo_medio_dias: number | null; eletronicos: number; registro_precos: number; percentual_disputa: number | null }
  por_sistematica: LinhaSist[]
  por_forma: (Agrupamento & { sistematica: string })[]
  por_tipo: { tipo: string; nome: string; processos: number; contratado: number; percentual: number | null }[]
  evolucao_tipo: ({ ano: number } & Record<string, number>)[]
  evolucao_regime: { regimes: { codigo: string; nome: string }[]; anos: ({ ano: number } & Record<string, number>)[] }
  equivalencias: { origem: string; regime_origem: string; equivalente: string; regime_equivalente: string; observacao: string | null }[]
  serie_comparavel: { modalidades: string[]; linhas: { ano: number; modalidade: string; processos: number; regime_anterior: number }[] }
}

/** Painel por sistemática de aquisição / tipo de processamento (seção 11). */
export function PainelSistematica({ base, incluirSemDisputa }: { base?: string; incluirSemDisputa?: boolean }) {
  const { estado, aplicarCruzado } = useFiltros()
  const { pode } = useAuth()
  const cabecalho = useCabecalhoExportacao()
  const consulta = paraQueryString(estado, { economia_base: base, incluir_sem_disputa: incluirSemDisputa === undefined ? undefined : incluirSemDisputa ? '1' : '0' })
  const q = usePainel<PainelSist>('sistematica', '/inteligencia/sistematica', consulta)

  return (
    <EstadoPainel q={q}>
      {(d) => {
        const r = d.resumo
        const sit = Object.fromEntries(r.situacoes.map((s) => [s.situacao, s.quantidade]))
        const tipos = d.evolucao_tipo.map((e) => ({ ano: e.ano, lic: e.LICITACAO ?? 0, dir: e.CONTRATACAO_DIRETA ?? 0, cha: e.CHAMADA_PUBLICA ?? 0, dem: (e.PROCEDIMENTO_AUXILIAR ?? 0) + (e.OUTRO ?? 0) }))
        const regimes = d.evolucao_regime.anos.map((e) => ({ ano: e.ano, ant: (e.L8666 ?? 0) + (e.L10520 ?? 0), novo: e.L14133 ?? 0, dem: (e.L11947 ?? 0) + (e.L13303 ?? 0) + (e.OUTRO ?? 0) }))
        const anos = [...new Set(d.serie_comparavel.linhas.map((l) => l.ano))]
        const mods = d.serie_comparavel.modalidades
        const celula = (ano: number, m: string) => d.serie_comparavel.linhas.find((l) => l.ano === ano && l.modalidade === m)
        const colunas: Coluna<LinhaSist>[] = [
          { id: 'nome', titulo: 'Sistemática', tipo: 'texto', valor: (l) => l.nome, largura: 190, fixa: true },
          { id: 'tipo', titulo: 'Classificação', tipo: 'texto', valor: (l) => l.tipo_nome },
          { id: 'processos', titulo: 'Processos', tipo: 'numero', valor: (l) => l.processos },
          { id: 'contratado', titulo: 'Valor contratado', tipo: 'moeda', valor: (l) => l.contratado, barra: true },
          { id: 'economia', titulo: 'Economia', tipo: 'moeda', valor: (l) => l.economia },
          { id: 'percentual_economia', titulo: '% economia', dica: 'Procedimentos sem disputa ficam fora, salvo se o critério de economia os incluir.', tipo: 'percentual', valor: (l) => l.percentual_economia },
          { id: 'percentual_disputa', titulo: '% disputa efetiva', dica: 'Processos com 2 ou mais participantes / processos da sistemática × 100.', tipo: 'percentual', valor: (l) => l.percentual_disputa },
          { id: 'eletronicos', titulo: 'Eletrônicos', tipo: 'numero', valor: (l) => l.eletronicos },
          { id: 'presenciais', titulo: 'Presenciais', tipo: 'numero', valor: (l) => l.presenciais },
          { id: 'concluidos', titulo: 'Concluídos', tipo: 'numero', valor: (l) => l.situacoes.CONCLUIDO },
          { id: 'desertos', titulo: 'Desertos', tipo: 'numero', valor: (l) => l.situacoes.DESERTO },
          { id: 'fracassados', titulo: 'Fracassados', tipo: 'numero', valor: (l) => l.situacoes.FRACASSADO },
          { id: 'revogados', titulo: 'Revogados', tipo: 'numero', valor: (l) => l.situacoes.REVOGADO },
          { id: 'anulados', titulo: 'Anulados', tipo: 'numero', valor: (l) => l.situacoes.ANULADO },
          { id: 'tempo', titulo: 'Tempo médio (dias)', dica: 'Da abertura à homologação, nos processos concluídos.', tipo: 'decimal', valor: (l) => l.tempo_medio_dias },
        ]
        return (
          <>
            <GradeKpis colunas="xl:grid-cols-4 2xl:grid-cols-8" itens={[
              { rotulo: 'Processos', valor: r.processos, tipo: 'numero', destaque: true },
              { rotulo: 'Concluídos', valor: sit.CONCLUIDO, tipo: 'numero' },
              { rotulo: 'Desertos', valor: sit.DESERTO, tipo: 'numero' },
              { rotulo: 'Fracassados', valor: sit.FRACASSADO, tipo: 'numero' },
              { rotulo: 'Revogados', valor: sit.REVOGADO, tipo: 'numero' },
              { rotulo: 'Anulados', valor: sit.ANULADO, tipo: 'numero' },
              { rotulo: 'Tempo médio de conclusão (dias)', valor: r.tempo_medio_dias, tipo: 'numero', dica: 'Média de dias entre a abertura e a homologação dos processos concluídos.' },
              { rotulo: '% disputa efetiva', valor: r.percentual_disputa, tipo: 'percentual', dica: 'Processos com 2 ou mais participantes / total de processos × 100.' },
            ]} />
            <Card className="mb-4">
              <CardHeader><div><CardTitle>Quantidade, valor e economia por sistemática</CardTitle><p className="mt-0.5 text-xs text-muted-foreground">Clique em uma linha para filtrar o painel pela sistemática</p></div></CardHeader>
              <CardContent>
                <TabelaAvancada colunas={colunas} dados={d.por_sistematica} podeExportar={pode('relatorios.exportar')} cabecalhoExportacao={cabecalho('Compras por sistemática de aquisição')}
                  aoClicarLinha={(l) => aplicarCruzado(l.filtro, l.chave, l.nome)} rotuloLinha={(l) => `Filtrar por ${l.nome}`} />
              </CardContent>
            </Card>
            <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
              <CartaoGrafico titulo="Classificação do procedimento" subtitulo="Modalidade de licitação, contratação direta, chamada pública e procedimento auxiliar · valor contratado"
                grafico={<ListaBarras dados={d.por_tipo.map((t) => ({ id: null, nome: t.nome, contratado: t.contratado, processos: t.processos, percentual: t.percentual, filtro: null, chave: null }))} />}
                tabela={<Tabela cabecalho={['Classificação', 'Processos', 'Contratado']} linhas={d.por_tipo.map((t) => [t.nome, formatarNumero(t.processos), formatarMoeda(t.contratado)])} />} />
              <CartaoGrafico titulo="Forma de processamento" subtitulo="Eletrônica, presencial e demais formas · valor contratado · clique para filtrar"
                grafico={<ListaBarras dados={d.por_forma.map((f) => ({ ...f, nome: f.nome }))} />}
                tabela={<Tabela cabecalho={['Forma', 'Sistemática', 'Processos', 'Contratado']} linhas={d.por_forma.map((f) => [f.nome, f.sistematica, formatarNumero(f.processos), formatarMoeda(f.contratado)])} />} />
              <Empilhado titulo="Evolução por classificação do procedimento" subtitulo="Quantidade de processos por ano" dados={tipos} series={[
                { chave: 'lic', nome: 'Licitação', cor: SERIES[0] }, { chave: 'dir', nome: 'Contratação direta', cor: SERIES[1] },
                { chave: 'cha', nome: 'Chamada pública', cor: SERIES[2] }, { chave: 'dem', nome: 'Demais', cor: SERIES[3] },
              ]} />
              <Empilhado titulo="Evolução por regime legal" subtitulo="Quantidade de processos por ano e regime" dados={regimes} series={[
                { chave: 'ant', nome: 'Lei 8.666 / Lei 10.520', cor: SERIES[2] }, { chave: 'novo', nome: 'Lei 14.133', cor: SERIES[0] }, { chave: 'dem', nome: 'Demais (PNAE, estatais, outras)', cor: SERIES[3] },
              ]} />
              <Card>
                <CardHeader><div><CardTitle>Tabela de equivalência entre regimes</CardTitle><p className="mt-0.5 text-xs text-muted-foreground">Usada para comparar séries históricas sem perder a classificação original</p></div></CardHeader>
                <CardContent>
                  <MiniTabela cabecalho={['Modalidade de origem', 'Regime', 'Equivale a', 'Observação']}
                    linhas={d.equivalencias.map((e) => [e.origem, e.regime_origem, `${e.equivalente} (${e.regime_equivalente})`, e.observacao ?? '—'])} />
                </CardContent>
              </Card>
              <Card>
                <CardHeader><div><CardTitle>Série comparável por modalidade</CardTitle><p className="mt-0.5 text-xs text-muted-foreground">Processos por modalidade equivalente da Lei 14.133; entre parênteses, quantos vieram do regime anterior</p></div></CardHeader>
                <CardContent>
                  <MiniTabela cabecalho={['Ano', ...mods]} direita={mods.map((_, k) => k + 1)}
                    linhas={anos.map((a) => [a, ...mods.map((m) => { const c = celula(a, m); return c ? `${formatarNumero(c.processos)}${c.regime_anterior ? ` (${formatarNumero(c.regime_anterior)})` : ''}` : '—' })])} />
                </CardContent>
              </Card>
            </div>
            <Nota className="mt-4">A situação, a forma eletrônica/presencial e o tempo de conclusão vêm do processo; valores e economia vêm dos itens. {formatarPercentual(r.processos ? (r.eletronicos / r.processos) * 100 : null)} dos processos foram eletrônicos e {formatarNumero(r.registro_precos)} usaram Registro de Preços.</Nota>
          </>
        )
      }}
    </EstadoPainel>
  )
}

/** Barras empilhadas de quantidade por ano (mesma grandeza em todas as séries). */
function Empilhado({ titulo, subtitulo, dados, series }: { titulo: string; subtitulo: string; dados: ({ ano: number } & Record<string, number>)[]; series: Serie[] }) {
  return (
    <CartaoGrafico titulo={titulo} subtitulo={subtitulo}
      grafico={
        <div className="h-[260px]">
          <ResponsiveContainer>
            <BarChart data={dados} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
              <CartesianGrid vertical={false} stroke={COR.grade} />
              <XAxis dataKey="ano" tick={EIXO} axisLine={false} tickLine={false} />
              <YAxis tick={EIXO} axisLine={false} tickLine={false} width={40} allowDecimals={false} />
              <Tooltip cursor={{ fill: 'hsl(var(--muted))', opacity: 0.6 }} content={<DicaGrafico formatar={formatarNumero} />} />
              <Legend iconType="square" iconSize={10} wrapperStyle={{ fontSize: 12 }} />
              {series.map((s, i) => <Bar key={s.chave} dataKey={s.chave} name={s.nome} stackId="t" fill={s.cor} stroke="hsl(var(--card))" strokeWidth={1} radius={i === series.length - 1 ? [4, 4, 0, 0] : undefined} maxBarSize={36} />)}
            </BarChart>
          </ResponsiveContainer>
        </div>
      }
      tabela={<Tabela cabecalho={['Ano', ...series.map((s) => s.nome)]} linhas={dados.map((d) => [d.ano, ...series.map((s) => formatarNumero(d[s.chave]))])} />}
    />
  )
}

