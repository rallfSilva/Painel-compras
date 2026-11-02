import { useQuery } from '@tanstack/react-query'
import { CheckCircle2, Circle, CircleDot, ExternalLink, Paperclip, Trophy } from 'lucide-react'
import { api } from '@/lib/api'
import { formatarData, formatarDocumento, formatarMoeda, formatarNumero, formatarUnitario, NAO_INFORMADO } from '@/lib/format'
import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'
import { Campos, Carregando, Erro, MiniTabela, Numeros, Secao } from './Partes'
import { useDetalhes } from './useDetalhes'
import { BadgeSituacao } from '@/components/compras/Situacao'

type Valor = string | number | null
interface Resposta {
  processo: Record<string, Valor | boolean> & { id: number; unidade_compradora_id: number; objeto: string; situacao: string }
  vencedores: { id: number; nome: string }[]
  fases: { fase: string; nome: string; data_inicio: string | null; data_fim: string | null; estado: 'concluida' | 'em_andamento' | 'pendente' }[]
  itens: (Record<string, Valor> & { id: number; fornecedor_id: number | null })[]
  participantes: { fornecedor_id: number; fornecedor: string; fornecedor_documento: string; porte: string | null; vencedor: number | boolean; habilitado: number | boolean | null; valor_proposta: Valor }[]
  atas: { id: number; numero: string; vigencia_inicio: string; vigencia_fim: string; situacao: string; valor_registrado: Valor }[]
  contratos: { id: number; numero: string; fornecedor: string; valor_inicial: Valor; vigencia_inicio: string; vigencia_fim: string; situacao: string }[]
  anexos: { id: number; nome: string; tamanho: number | null; created_at: string }[]
}

const simNao = (v: unknown) => (v ? 'Sim' : 'Não')
const link = (url: unknown, rotulo: string) => (typeof url === 'string' && url
  ? <a href={url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-primary hover:underline">{rotulo} <ExternalLink className="size-3" /></a>
  : null)

/** Ficha do processo com todos os campos da seção 21 e o acompanhamento das fases. */
export function DetalheProcesso({ id }: { id: number }) {
  const abrir = useDetalhes()
  const q = useQuery({ queryKey: ['processo', id], queryFn: () => api<Resposta>(`/processos/${id}`) })
  if (q.isLoading) return <Carregando />
  if (q.isError || !q.data) return <Erro erro={q.error} />
  const { processo: p, fases, itens, participantes, vencedores } = q.data
  const s = (k: string) => (p[k] === null || p[k] === undefined ? null : String(p[k]))

  return (
    <div>
      <Secao titulo="Identificação">
        <div className="mb-3 flex flex-wrap items-center gap-2">
          <p className="text-base font-semibold">Nº {s('numero_publicacao') ?? NAO_INFORMADO}</p>
          <BadgeSituacao situacao={p.situacao} />
          {!!p.registro_precos && <Badge cor="primario">Registro de preços</Badge>}
          {!!p.ficticio && <Badge cor="alerta">Fictício</Badge>}
        </div>
        <p className="mb-4 text-sm">{p.objeto}</p>
        <Campos itens={[
          ['ID interno', String(p.id)], ['Processo SEI', s('numero_processo_sei')], ['Processo administrativo', s('numero_processo_adm')],
          ['Número da licitação', s('numero_licitacao')], ['Ano', s('ano')],
          ['Órgão', `${s('orgao')}${p.orgao_sigla ? ` (${s('orgao_sigla')})` : ''}`], ['Unidade superior', s('unidade_superior')],
          ['Unidade compradora', <button key="u" className="text-left text-primary hover:underline" onClick={() => abrir('unidade', p.unidade_compradora_id, s('unidade') ?? undefined)}>{s('unidade')}</button>],
          ['Descrição', s('descricao')], ['Modalidade', s('modalidade')], ['Sistemática', s('sistematica')], ['Forma de aquisição', s('forma_aquisicao')],
          ['Forma de disputa', s('forma_disputa')], ['Critério de julgamento', s('criterio_julgamento')], ['Regime legal', s('regime_legal')],
          ['Fundamentação legal', s('amparo_legal')], ['Natureza da contratação', s('natureza')], ['Registro de preços?', simNao(p.registro_precos)],
          ['Julgamento por lote?', simNao(p.julgamento_lote)], ['Forma', p.eletronico ? 'Eletrônica' : 'Presencial'],
          ['Responsável', s('responsavel')], ['Pregoeiro / agente de contratação', s('agente_contratacao')],
          ['Fornecedor(es) vencedor(es)', vencedores.length ? (
            <span key="v" className="flex flex-wrap gap-x-2">
              {vencedores.map((v) => <button key={v.id} className="text-left text-primary hover:underline" onClick={() => abrir('fornecedor', v.id, v.nome)}>{v.nome}</button>)}
            </span>
          ) : null],
          ['Link para o PNCP', link(p.link_pncp, 'Abrir no PNCP')], ['Link para o edital', link(p.link_edital, 'Abrir edital')],
        ]} />
      </Secao>
      <Secao titulo="Valores e datas">
        <Numeros itens={[
          ['Valor estimado', formatarMoeda(p.valor_estimado as Valor)], ['Valor adjudicado', formatarMoeda(p.valor_adjudicado as Valor)],
          ['Valor homologado', formatarMoeda(p.valor_homologado as Valor)], ['Valor contratado', formatarMoeda(p.valor_contratado as Valor)],
          ['Qtde. de itens', formatarNumero(itens.length)], ['Participantes', formatarNumero(p.quantidade_participantes as Valor)],
          ['Abertura', formatarData(s('data_abertura'))], ['Publicação', formatarData(s('data_publicacao'))], ['Sessão', formatarData(s('data_sessao'))],
          ['Adjudicação', formatarData(s('data_adjudicacao'))], ['Homologação', formatarData(s('data_homologacao'))],
        ]} />
      </Secao>
      <Secao titulo="Acompanhamento das fases">
        <p className="mb-3 text-[11px] text-muted-foreground">Fases adaptadas ao procedimento ({s('sistematica')}) e ao regime legal.</p>
        <ol className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4">
          {fases.map((f, i) => {
            const Icone = f.estado === 'concluida' ? CheckCircle2 : f.estado === 'em_andamento' ? CircleDot : Circle
            return (
              <li key={f.fase} className={cn('flex gap-2 rounded-md border px-3 py-2 text-xs', f.estado === 'pendente' && 'opacity-60')}>
                <Icone className={cn('mt-0.5 size-4 shrink-0', f.estado === 'concluida' ? 'text-success' : f.estado === 'em_andamento' ? 'text-warning' : 'text-muted-foreground')} />
                <span>
                  <span className="block font-medium">{i + 1}. {f.nome}</span>
                  <span className="block text-muted-foreground">
                    {f.estado === 'pendente' ? 'Não iniciada' : f.estado === 'em_andamento' ? `Em andamento desde ${formatarData(f.data_inicio)}` : `${formatarData(f.data_inicio)} a ${formatarData(f.data_fim)}`}
                  </span>
                </span>
              </li>
            )
          })}
        </ol>
      </Secao>
      <Secao titulo={`Itens (${itens.length})`}>
        <MiniTabela
          cabecalho={['Item', 'Código', 'Descrição', 'Un.', 'Qtde.', 'Unit. estimado', 'Unit. adjudicado', 'Total estimado', 'Total contratado', 'Fornecedor', 'Situação']}
          direita={[4, 5, 6, 7, 8]}
          linhas={itens.map((x) => [
            x.numero_item, x.codigo_item ?? '—', x.descricao, x.unidade_medida ?? '—', formatarNumero(x.quantidade_adjudicada ?? x.quantidade_estimada),
            formatarUnitario(x.valor_unitario_estimado), formatarUnitario(x.valor_unitario_adjudicado), formatarMoeda(x.valor_total_estimado),
            formatarMoeda(x.valor_total_contratado),
            x.fornecedor_id ? <button key="f" className="text-left text-primary hover:underline" onClick={() => abrir('fornecedor', x.fornecedor_id!, String(x.fornecedor))}>{x.fornecedor}</button> : '—',
            <BadgeSituacao key="s" situacao={String(x.situacao)} />,
          ])}
        />
      </Secao>
      <Secao titulo={`Participantes (${participantes.length})`}>
        <MiniTabela
          cabecalho={['Fornecedor', 'CPF/CNPJ', 'Porte', 'Habilitado', 'Resultado']}
          linhas={participantes.map((x) => [
            x.fornecedor, formatarDocumento(x.fornecedor_documento), x.porte ?? NAO_INFORMADO,
            x.habilitado === null ? NAO_INFORMADO : simNao(x.habilitado),
            x.vencedor ? <span key="v" className="inline-flex items-center gap-1 font-medium text-success"><Trophy className="size-3.5" /> Vencedor</span> : 'Participante',
          ])}
          aoClicar={(i) => abrir('fornecedor', participantes[i].fornecedor_id, participantes[i].fornecedor)}
          vazio="Nenhum participante registrado."
        />
      </Secao>
      <div className="grid grid-cols-1 lg:grid-cols-2">
        <Secao titulo="Atas de registro de preços" className="lg:border-r">
          <MiniTabela cabecalho={['Ata', 'Vigência', 'Situação', 'Valor registrado']} direita={[3]}
            linhas={q.data.atas.map((a) => [a.numero, `${formatarData(a.vigencia_inicio)} a ${formatarData(a.vigencia_fim)}`, a.situacao, formatarMoeda(a.valor_registrado)])}
            vazio="Nenhuma ata gerada por este processo." />
        </Secao>
        <Secao titulo="Contratos">
          <MiniTabela cabecalho={['Contrato', 'Fornecedor', 'Vigência', 'Situação', 'Valor inicial']} direita={[4]}
            linhas={q.data.contratos.map((c) => [c.numero, c.fornecedor, `${formatarData(c.vigencia_inicio)} a ${formatarData(c.vigencia_fim)}`, c.situacao, formatarMoeda(c.valor_inicial)])}
            vazio="Nenhum contrato vinculado." />
        </Secao>
      </div>
      <Secao titulo="Documentos anexos">
        {q.data.anexos.length ? (
          <ul className="space-y-1 text-xs">{q.data.anexos.map((a) => <li key={a.id} className="flex items-center gap-2"><Paperclip className="size-3.5" /> {a.nome}</li>)}</ul>
        ) : <p className="text-xs text-muted-foreground">Nenhum documento anexado. Os anexos serão recebidos na importação e na integração com o PNCP (Etapa 5).</p>}
      </Secao>
    </div>
  )
}
