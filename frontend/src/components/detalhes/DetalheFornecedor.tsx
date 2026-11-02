import { useQuery } from '@tanstack/react-query'
import { Filter, ShieldAlert } from 'lucide-react'
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { api } from '@/lib/api'
import { paraQueryString, useFiltros } from '@/contexts/FiltrosContext'
import { formatarAbreviado, formatarData, formatarDocumento, formatarMoeda, formatarNumero, formatarPercentual } from '@/lib/format'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { COR, DicaGrafico, EIXO } from '@/components/dashboard/Graficos'
import { Campos, Carregando, Erro, MiniTabela, Numeros, Secao } from './Partes'

type Valor = string | number | null
interface Resposta {
  fornecedor: Record<string, Valor> & { id: number; razao_social: string; tipo_pessoa: 'F' | 'J'; ficticio: number | boolean; cnaes_secundarios: string[] }
  resumo: { contratado: number | null; processos_vencidos: number; itens: number; orgaos: number; participacoes: number; vitorias: number; taxa_vitoria: number | null }
  evolucao_anual: { ano: number; contratado: number; processos: number }[]
  orgaos: { id: number; sigla: string | null; nome: string; contratado: Valor; processos: number }[]
  itens: { id: number | null; codigo: string | null; descricao: string; quantidade: Valor; contratado: Valor; vezes: number }[]
  contratos: { id: number; numero: string; unidade: string; valor_inicial: Valor; vigencia_inicio: string; vigencia_fim: string; situacao: string }[]
  atas: { id: number; numero: string; orgao_gerenciador: string; vigencia_fim: string; situacao: string; itens: number; valor_registrado: Valor }[]
}

/** Ficha do fornecedor: ao clicar em um fornecedor, mostrar seu histórico de fornecimento (seção 31). */
export function DetalheFornecedor({ id }: { id: number }) {
  const { estado, aplicarCruzado } = useFiltros()
  const consulta = paraQueryString(estado)
  const q = useQuery({ queryKey: ['fornecedor', id, consulta], queryFn: () => api<Resposta>(`/fornecedores/${id}?${consulta}`) })
  if (q.isLoading) return <Carregando />
  if (q.isError || !q.data) return <Erro erro={q.error} />
  const { fornecedor: f, resumo: r } = q.data
  const s = (k: string) => (f[k] === null || f[k] === undefined ? null : String(f[k]))

  return (
    <div>
      <Secao titulo="Cadastro" acoes={
        <Button size="sm" variant="outline" onClick={() => aplicarCruzado('fornecedor', `fo:${f.id}`, f.razao_social)}><Filter /> Filtrar o painel por este fornecedor</Button>
      }>
        <p className="mb-3 text-base font-semibold">{f.razao_social} {!!f.ficticio && <Badge cor="alerta" className="ml-1 align-middle">Fictício</Badge>}</p>
        {f.tipo_pessoa === 'F' && (
          <p className="mb-3 flex items-center gap-2 rounded-md bg-muted px-3 py-2 text-xs"><ShieldAlert className="size-4 text-warning" />
            Pessoa física: CPF e dados de contato são dados pessoais (LGPD) e só aparecem completos para perfis autorizados.</p>
        )}
        <Campos itens={[
          ['Nome fantasia', s('nome_fantasia')], [f.tipo_pessoa === 'F' ? 'CPF' : 'CNPJ', formatarDocumento(s('documento'))], ['Inscrição estadual', s('inscricao_estadual')],
          ['Porte empresarial', s('porte')], ['Natureza jurídica', s('natureza_juridica')], ['CNAE principal', s('cnae_principal')],
          ['CNAEs secundários', f.cnaes_secundarios?.length ? f.cnaes_secundarios.join(', ') : null], ['Situação cadastral', s('situacao_cadastral')],
          ['Data de abertura', s('data_abertura') ? formatarData(s('data_abertura')) : null], ['Município / UF', [s('municipio'), s('uf')].filter(Boolean).join(' / ') || null],
          ['Região', s('regiao')], ['Endereço', s('endereco')], ['CEP', s('cep')], ['E-mail', s('email')], ['Telefone', s('telefone')],
        ]} />
      </Secao>
      <Secao titulo="Dados analíticos no recorte">
        <Numeros itens={[
          ['Valor total contratado', formatarMoeda(r.contratado)], ['Processos vencidos', formatarNumero(r.processos_vencidos)], ['Itens fornecidos', formatarNumero(r.itens)],
          ['Órgãos atendidos', formatarNumero(r.orgaos)], ['Participações em licitações', formatarNumero(r.participacoes)], ['Vitórias', formatarNumero(r.vitorias)],
          ['Taxa de vitória', formatarPercentual(r.taxa_vitoria)], ['Contratos', formatarNumero(q.data.contratos.length)], ['Atas de RP', formatarNumero(q.data.atas.length)],
        ]} />
      </Secao>
      <Secao titulo="Evolução anual de fornecimento">
        {q.data.evolucao_anual.length ? (
          <div className="h-[200px]">
            <ResponsiveContainer>
              <BarChart data={q.data.evolucao_anual} margin={{ top: 4, right: 8, left: 8, bottom: 0 }}>
                <CartesianGrid vertical={false} stroke={COR.grade} />
                <XAxis dataKey="ano" tick={EIXO} axisLine={false} tickLine={false} />
                <YAxis tick={EIXO} axisLine={false} tickLine={false} width={60} tickFormatter={(v) => formatarAbreviado(v, false)} />
                <Tooltip cursor={{ fill: 'hsl(var(--muted))', opacity: 0.6 }} content={<DicaGrafico formatar={formatarMoeda} />} />
                <Bar dataKey="contratado" name="Contratado" fill={COR.s1} radius={[4, 4, 0, 0]} maxBarSize={28} />
              </BarChart>
            </ResponsiveContainer>
          </div>
        ) : <p className="text-xs text-muted-foreground">Sem fornecimento no recorte.</p>}
      </Secao>
      <div className="grid grid-cols-1 lg:grid-cols-2">
        <Secao titulo="Órgãos atendidos" className="lg:border-r">
          <MiniTabela cabecalho={['Órgão', 'Processos', 'Contratado']} direita={[1, 2]}
            linhas={q.data.orgaos.map((o) => [o.sigla ? `${o.sigla} — ${o.nome}` : o.nome, formatarNumero(o.processos), formatarMoeda(o.contratado)])} />
        </Secao>
        <Secao titulo="Itens fornecidos">
          <MiniTabela cabecalho={['Código', 'Descrição', 'Vezes', 'Qtde.', 'Contratado']} direita={[2, 3, 4]}
            linhas={q.data.itens.map((i) => [i.codigo ?? '—', i.descricao, formatarNumero(i.vezes), formatarNumero(i.quantidade), formatarMoeda(i.contratado)])} />
        </Secao>
        <Secao titulo="Histórico de contratos" className="lg:border-r">
          <MiniTabela cabecalho={['Contrato', 'Unidade', 'Vigência', 'Situação', 'Valor inicial']} direita={[4]}
            linhas={q.data.contratos.map((c) => [c.numero, c.unidade, `${formatarData(c.vigencia_inicio)} a ${formatarData(c.vigencia_fim)}`, c.situacao, formatarMoeda(c.valor_inicial)])} />
        </Secao>
        <Secao titulo="Participação em atas de registro de preços">
          <MiniTabela cabecalho={['Ata', 'Órgão gerenciador', 'Fim da vigência', 'Situação', 'Itens', 'Valor registrado']} direita={[4, 5]}
            linhas={q.data.atas.map((a) => [a.numero, a.orgao_gerenciador, formatarData(a.vigencia_fim), a.situacao, formatarNumero(a.itens), formatarMoeda(a.valor_registrado)])} />
        </Secao>
      </div>
    </div>
  )
}

