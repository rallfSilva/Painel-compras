import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Filter, Scale } from 'lucide-react'
import { api } from '@/lib/api'
import { paraQueryString, useFiltros } from '@/contexts/FiltrosContext'
import { formatarMoeda, formatarNumero, formatarPercentual } from '@/lib/format'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { GraficoEvolucao, type Evolucao } from '@/components/dashboard/Graficos'
import { ListaBarras, type Agrupamento } from '@/components/compras/ListaBarras'
import { Campos, Carregando, Erro, Numeros, Secao } from './Partes'

interface Resposta {
  unidade: {
    id: number; nome: string; codigo: string | null; uasg: string | null; ficticio: boolean | number; orgao_id: number; orgao: string; orgao_sigla: string | null
    orgao_cnpj: string | null; orgao_tipo: string; unidade_superior: string | null; municipio: string | null; regiao: string | null
  }
  indicadores: Record<string, number | null>
  evolucao_anual: Evolucao[]
  por_modalidade: Agrupamento[]
  por_fornecedor: Agrupamento[]
  por_grupo_material: Agrupamento[]
}

/** Ficha da unidade compradora: ao clicar em um órgão, mostrar suas compras (seção 31). */
export function DetalheUnidade({ id, aoFechar }: { id: number; aoFechar: () => void }) {
  const { estado, aplicarCruzado } = useFiltros()
  const navegar = useNavigate()
  const consulta = paraQueryString(estado)
  const q = useQuery({ queryKey: ['unidade', id, consulta], queryFn: () => api<Resposta>(`/unidades/${id}?${consulta}`) })
  if (q.isLoading) return <Carregando />
  if (q.isError || !q.data) return <Erro erro={q.error} />
  const { unidade: u, indicadores: i } = q.data

  const filtrar = (ir?: string) => {
    aplicarCruzado('unidade', `u:${u.id}`, u.nome)
    aoFechar()
    if (ir) navegar(ir)
  }

  return (
    <div>
      <Secao titulo="Cadastro" acoes={
        <div className="flex flex-wrap gap-2">
          <Button size="sm" variant="outline" onClick={() => filtrar()}><Filter /> Filtrar o painel por esta unidade</Button>
          <Button size="sm" variant="outline" onClick={() => filtrar('/processos')}><Scale /> Ver processos</Button>
        </div>
      }>
        <p className="mb-3 text-base font-semibold">{u.nome} {!!u.ficticio && <Badge cor="alerta" className="ml-1 align-middle">Fictício</Badge>}</p>
        <Campos itens={[
          ['Órgão', `${u.orgao}${u.orgao_sigla ? ` (${u.orgao_sigla})` : ''}`], ['Unidade superior', u.unidade_superior], ['Tipo do órgão', u.orgao_tipo],
          ['Código / UASG', [u.codigo, u.uasg].filter(Boolean).join(' / ') || null], ['Município', u.municipio], ['Região de planejamento', u.regiao],
        ]} />
      </Secao>
      <Secao titulo="Indicadores no recorte">
        <Numeros itens={[
          ['Total estimado', formatarMoeda(i.total_estimado)], ['Total contratado', formatarMoeda(i.total_contratado)],
          ['Economia', formatarMoeda(i.economia)], ['% Economia', formatarPercentual(i.percentual_economia)], ['Processos', formatarNumero(i.processos)],
          ['Itens comprados', formatarNumero(i.itens_comprados)], ['Fornecedores', formatarNumero(i.fornecedores)], ['Atas de RP', formatarNumero(i.qtd_atas)],
          ['Contratos vigentes', formatarNumero(i.contratos_vigentes)],
        ]} />
      </Secao>
      <div className="border-b px-5 py-4"><GraficoEvolucao dados={q.data.evolucao_anual} className="" /></div>
      <div className="grid grid-cols-1 lg:grid-cols-3">
        <Secao titulo="Por modalidade / sistemática" className="lg:border-r"><ListaBarras dados={q.data.por_modalidade} /></Secao>
        <Secao titulo="Principais fornecedores" className="lg:border-r"><ListaBarras dados={q.data.por_fornecedor} /></Secao>
        <Secao titulo="Por grupo de material ou serviço"><ListaBarras dados={q.data.por_grupo_material} /></Secao>
      </div>
    </div>
  )
}
