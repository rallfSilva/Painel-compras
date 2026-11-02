import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import mapas from '@/assets/geo/mapas.json'
import { api } from '@/lib/api'
import { paraQueryString, useFiltros } from '@/contexts/FiltrosContext'
import { GraficoAgrupado } from '@/components/compras/GraficosCompras'
import type { Agrupamento } from '@/components/compras/ListaBarras'
import { CartaoGrafico, Tabela } from '@/components/dashboard/Graficos'
import { GraficoEvolucao, type Evolucao } from '@/components/dashboard/Graficos'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Dialogo } from '@/components/ui/dialog'
import { Carregando, Erro, Numeros, Secao } from '@/components/detalhes/Partes'
import { ListaBarras } from '@/components/compras/ListaBarras'
import { formatarAbreviado, formatarMoeda, formatarNumero, formatarPercentual } from '@/lib/format'
import { cn } from '@/lib/utils'
import { Mapa, type AreaMapa } from './Mapa'
import { Composicao, EstadoPainel, SERIES, usePainel } from './partes'

interface Municipio {
  id: number; codigo_ibge: string; nome: string; regiao_id: number | null; regiao: string | null; contratado: number; percentual: number | null
  processos: number; unidades: number; percentual_fornecedores_mesmo_municipio: number | null; fornecedores: number; vendido: number; participacao_economica: number | null
  filtro: 'comprador_local'; chave: string
}
interface PorUf { uf: string | null; nome: string; contratado: number; fornecedores: number; processos: number; percentual: number | null; filtro: 'fornecedor_local' | null; chave: string | null }
interface PainelGeo {
  por_uf: PorUf[]; municipios: Municipio[]; por_regiao: Agrupamento[]
  fornecedores_por_regiao: { id: number; nome: string; fornecedores: number; contratado: number }[]
  local_outros: { grupo: string; nome: string; contratado: number; percentual: number | null; fornecedores: number }[]
}
interface FichaMunicipio {
  municipio: { id: number; nome: string; codigo_ibge: string; uf: string; regiao: string | null }
  resumo: { contratado: number | null; processos: number; fornecedores: number; unidades: number }
  orgaos: Agrupamento[]; fornecedores: Agrupamento[]; materiais: Agrupamento[]; modalidades: Agrupamento[]; evolucao: Evolucao[]
}

type Metrica = 'contratado' | 'processos' | 'fornecedores' | 'vendido'
const METRICAS: { id: Metrica; rotulo: string; descricao: string }[] = [
  { id: 'contratado', rotulo: 'Compras', descricao: 'Valor contratado pelas unidades compradoras sediadas no município' },
  { id: 'processos', rotulo: 'Processos', descricao: 'Quantidade de processos das unidades sediadas no município' },
  { id: 'fornecedores', rotulo: 'Fornecedores', descricao: 'Fornecedores sediados no município que venderam no recorte' },
  { id: 'vendido', rotulo: 'Participação econômica', descricao: 'Valor vendido pelos fornecedores sediados no município' },
]

/** Painel por estado da federação / região de planejamento (seção 9). */
export function PainelGeografico() {
  const { estado, aplicarCruzado } = useFiltros()
  const consulta = paraQueryString(estado)
  const q = usePainel<PainelGeo>('geografico', '/inteligencia/geografico', consulta)
  const [metrica, setMetrica] = useState<Metrica>('contratado')
  const [aberto, setAberto] = useState<{ id: number; nome: string } | null>(null)
  const geo = mapas as unknown as { fonte: string; roraima: { viewBox: string; municipios: { codigo_ibge: string; nome: string; path: string; cx: number; cy: number }[] }; brasil: { viewBox: string; ufs: { uf: string; nome: string; path: string }[] } }

  return (
    <EstadoPainel q={q}>
      {(d) => <Conteudo d={d} geo={geo} metrica={metrica} setMetrica={setMetrica} aoAbrir={setAberto} aoUf={(u) => u.filtro && u.chave && aplicarCruzado(u.filtro, u.chave, u.nome)}
        aberto={aberto} aoFechar={() => setAberto(null)} consulta={consulta} />}
    </EstadoPainel>
  )
}

function Conteudo({ d, geo, metrica, setMetrica, aoAbrir, aoUf, aberto, aoFechar, consulta }: {
  d: PainelGeo; geo: { fonte: string; roraima: { viewBox: string; municipios: { codigo_ibge: string; nome: string; path: string; cx: number; cy: number }[] }; brasil: { viewBox: string; ufs: { uf: string; nome: string; path: string }[] } }
  metrica: Metrica; setMetrica: (m: Metrica) => void; aoAbrir: (m: { id: number; nome: string }) => void; aoUf: (u: PorUf) => void
  aberto: { id: number; nome: string } | null; aoFechar: () => void; consulta: string
}) {
  const porIbge = useMemo(() => new Map(d.municipios.map((m) => [m.codigo_ibge, m])), [d.municipios])
  const porUf = useMemo(() => new Map(d.por_uf.filter((u) => u.uf).map((u) => [u.uf as string, u])), [d.por_uf])
  const formatar = (v: number | null) => (metrica === 'contratado' || metrica === 'vendido' ? formatarMoeda(v) : formatarNumero(v))
  const areasRR: AreaMapa[] = geo.roraima.municipios.map((g) => {
    const m = porIbge.get(g.codigo_ibge)
    return { id: g.codigo_ibge, nome: g.nome, path: g.path, cx: g.cx, cy: g.cy, valor: m ? m[metrica] : null }
  })
  const areasBR: AreaMapa[] = geo.brasil.ufs.map((u) => ({ id: u.uf, nome: `${u.nome} (${u.uf})`, path: u.path, valor: porUf.get(u.uf)?.contratado ?? null }))
  const desc = METRICAS.find((m) => m.id === metrica)!.descricao
  const comprasMunicipio: Agrupamento[] = [...d.municipios].sort((a, b) => b.contratado - a.contratado)
    .map((m) => ({ id: m.id, nome: m.nome, contratado: m.contratado, processos: m.processos, percentual: m.percentual, filtro: m.filtro, chave: m.chave }))
  const participacao: Agrupamento[] = [...d.municipios].filter((m) => m.vendido > 0).sort((a, b) => b.vendido - a.vendido)
    .map((m) => ({ id: m.id, nome: m.nome, contratado: m.vendido, processos: m.fornecedores, percentual: m.participacao_economica, filtro: 'fornecedor_local', chave: `m:${m.id}` }))

  return (
    <>
      <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
        <Card>
          <CardHeader>
            <div><CardTitle>Mapa de Roraima</CardTitle><p className="mt-0.5 text-xs text-muted-foreground">{desc} · clique em um município para detalhar</p></div>
          </CardHeader>
          <CardContent>
            <div className="mb-3 inline-flex flex-wrap rounded-md border p-0.5 text-[11px]" role="group" aria-label="Métrica do mapa">
              {METRICAS.map((m) => (
                <button key={m.id} onClick={() => setMetrica(m.id)} aria-pressed={metrica === m.id}
                  className={cn('rounded px-2 py-1 text-muted-foreground', metrica === m.id && 'bg-muted font-medium text-foreground')}>{m.rotulo}</button>
              ))}
            </div>
            <Mapa viewBox={geo.roraima.viewBox} areas={areasRR} formatar={formatar} titulosVisiveis rotulo={`Mapa de Roraima: ${desc}`}
              formatarLegenda={(v) => (metrica === 'contratado' || metrica === 'vendido' ? formatarAbreviado(v) : formatarNumero(Math.round(v)))}
              aoClicar={(a) => { const m = porIbge.get(a.id); if (m) aoAbrir({ id: m.id, nome: m.nome }) }} className="mx-auto max-w-[460px]" />
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <div><CardTitle>Compras por estado (UF do fornecedor)</CardTitle><p className="mt-0.5 text-xs text-muted-foreground">Valor contratado de fornecedores sediados em cada UF · clique para filtrar</p></div>
          </CardHeader>
          <CardContent>
            <Mapa viewBox={geo.brasil.viewBox} areas={areasBR} formatar={formatarMoeda} formatarLegenda={(v) => formatarAbreviado(v)} rotulo="Mapa do Brasil por UF do fornecedor"
              aoClicar={(a) => { const u = porUf.get(a.id); if (u) aoUf(u) }} className="mx-auto max-w-[460px]" />
          </CardContent>
        </Card>
        <GraficoAgrupado titulo="Compras por região de planejamento" subtitulo="Região do município da unidade compradora · valor contratado (R$) · clique para filtrar" rotuloDimensao="Região" dados={d.por_regiao} />
        <Composicao titulo="Fornecedores locais x de outros estados" subtitulo="Percentual do valor contratado, pela UF de sede do fornecedor"
          dados={d.local_outros.map((l, k) => ({ chave: l.grupo, nome: `${l.nome} (${formatarNumero(l.fornecedores)})`, valor: l.contratado, percentual: l.percentual, cor: SERIES[l.grupo === 'NI' ? 3 : k] }))} />
        <GraficoAgrupado titulo="Compras por município" subtitulo="Município da unidade compradora · valor contratado (R$) · clique para filtrar" rotuloDimensao="Município" dados={comprasMunicipio} />
        <CartaoGrafico titulo="Participação econômica por município" subtitulo="Valor vendido por fornecedores sediados em cada município de RR · clique para filtrar"
          grafico={<ListaBarras dados={participacao} />}
          tabela={<Tabela cabecalho={['Município', 'Vendido', '%', 'Fornecedores']} linhas={participacao.map((m) => [m.nome, formatarMoeda(m.contratado), formatarPercentual(m.percentual), formatarNumero(m.processos)])} />} />
        <CartaoGrafico className="xl:col-span-2" titulo="Fornecedores por região" subtitulo="Fornecedores distintos sediados em cada região de planejamento"
          grafico={<ul className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4">{d.fornecedores_por_regiao.map((r) => (
            <li key={r.id} className="rounded-md border p-3"><p className="text-xs text-muted-foreground">{r.nome}</p><p className="text-xl font-semibold tabular">{formatarNumero(r.fornecedores)}</p><p className="text-[11px] text-muted-foreground tabular">{formatarAbreviado(r.contratado)} vendidos</p></li>
          ))}</ul>}
          tabela={<Tabela cabecalho={['Região', 'Fornecedores', 'Vendido']} linhas={d.fornecedores_por_regiao.map((r) => [r.nome, formatarNumero(r.fornecedores), formatarMoeda(r.contratado)])} />} />
      </div>
      <p className="mt-3 text-[11px] text-muted-foreground">Fontes das malhas: {geo.fonte} As regiões de planejamento vêm da tabela própria do Estado.</p>
      <Dialogo aberto={!!aberto} aoFechar={aoFechar} titulo={aberto ? `Município: ${aberto.nome}` : ''} descricao="Compras das unidades compradoras sediadas no município, com os filtros atuais.">
        {aberto && <FichaMunicipioConteudo id={aberto.id} consulta={consulta} />}
      </Dialogo>
    </>
  )
}

function FichaMunicipioConteudo({ id, consulta }: { id: number; consulta: string }) {
  const q = useQuery({ queryKey: ['municipio', id, consulta], queryFn: () => api<FichaMunicipio>(`/inteligencia/municipios/${id}?${consulta}`) })
  if (q.isLoading) return <Carregando />
  if (q.isError || !q.data) return <Erro erro={q.error} />
  const f = q.data
  return (
    <div>
      <Secao titulo="Resumo">
        <Numeros itens={[['Valor contratado', formatarMoeda(f.resumo.contratado)], ['Processos', formatarNumero(f.resumo.processos)], ['Fornecedores', formatarNumero(f.resumo.fornecedores)], ['Unidades compradoras', formatarNumero(f.resumo.unidades)]]} />
        <p className="mt-2 text-[11px] text-muted-foreground">Região de planejamento: {f.municipio.regiao ?? 'Não informada'} · código IBGE {f.municipio.codigo_ibge}</p>
      </Secao>
      <div className="grid grid-cols-1 lg:grid-cols-2">
        <Secao titulo="Órgãos compradores"><ListaBarras dados={f.orgaos} /></Secao>
        <Secao titulo="Fornecedores"><ListaBarras dados={f.fornecedores} /></Secao>
        <Secao titulo="Principais materiais (classes)"><ListaBarras dados={f.materiais} /></Secao>
        <Secao titulo="Modalidades utilizadas"><ListaBarras dados={f.modalidades} /></Secao>
      </div>
      <Secao titulo="Evolução histórica"><GraficoEvolucao dados={f.evolucao} className="border-0 shadow-none" /></Secao>
    </div>
  )
}
