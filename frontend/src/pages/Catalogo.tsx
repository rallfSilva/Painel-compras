import { useMemo, useState, type ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, CheckCircle2, Copy, Pencil, Plus, XCircle } from 'lucide-react'
import { useAuth } from '@/contexts/AuthContext'
import { Abas, CabecalhoModulo } from '@/components/compras/CabecalhoModulo'
import { EstadoPainel, GradeKpis, Nota, useCabecalhoExportacao } from '@/components/inteligencia/partes'
import { TabelaServidor } from '@/components/tabela/TabelaServidor'
import type { Coluna } from '@/components/tabela/colunas'
import { Card, CardContent } from '@/components/ui/card'
import { Dialogo } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Caixa } from '@/components/ui/checkbox'
import { Campos, Carregando, Erro, MiniTabela, Secao } from '@/components/detalhes/Partes'
import { api, ApiError } from '@/lib/api'
import { formatarData, formatarNumero, formatarPercentual } from '@/lib/format'
import { cn } from '@/lib/utils'

interface ItemCatalogo {
  id: number; codigo: string; codigo_sufixo: string | null; descricao: string; nome_resumido: string | null; descricao_normalizada: string | null
  tipo: string; grupo_id: number | null; grupo: string | null; classe_id: number | null; classe: string | null; natureza_id: number | null; natureza: string | null
  subelemento_id: number | null; subelemento_codigo: string | null; subelemento: string | null; unidade_id: number | null; unidade: string | null
  especificacao: string | null; status: string; created_at: string; updated_at: string; ficticio: boolean; compras: number; duplicado: boolean
}
interface Opcoes {
  classes: { id: number; nome: string; grupo_id: number; grupo: string }[]; naturezas: { id: number; nome: string }[]
  subelementos: { id: number; codigo: string; nome: string }[]; unidades: { id: number; sigla: string; nome: string }[]; status: string[]
}
interface Resumo {
  itens: number; por_status: { status: string; quantidade: number }[]; grupos_duplicidade: number; itens_sem_compra: number
  equivalencias_pendentes: number; equivalencias_validadas: number; opcoes: Opcoes
}
interface Equivalencia {
  id: number; status: 'PROPOSTA' | 'VALIDADA' | 'REJEITADA'; justificativa: string | null; especificacoes_diferentes: boolean; created_at: string; validado_em: string | null
  item_id: number; item_codigo: string; item_descricao: string; equivalente_id: number; equivalente_codigo: string; equivalente_descricao: string
  proposto_por: string | null; validado_por: string | null
}
interface Relacionado { id: number; codigo: string; descricao: string; unidade: string | null; especificacao_diferente: boolean; semelhanca?: number; mesma_unidade?: boolean; compras?: number }
interface Detalhe {
  item: ItemCatalogo
  historico: { id: number; usuario: string | null; data: string; alteracoes: { campo: string; antes: unknown; depois: unknown }[] }[]
  duplicados: Relacionado[]; semelhantes: Relacionado[]; equivalencias: Equivalencia[]
}
interface GrupoDuplicidade { descricao_normalizada: string; unidade: string | null; especificacoes_diferentes: boolean; itens: (Relacionado & { status: string; especificacao: string | null })[] }

type Aba = 'itens' | 'duplicidades' | 'equivalencias'

const STATUS_COR: Record<string, 'sucesso' | 'neutro' | 'alerta'> = { ATIVO: 'sucesso', INATIVO: 'neutro', SUSPENSO: 'alerta' }
const STATUS_EQ: Record<Equivalencia['status'], { rotulo: string; cor: 'alerta' | 'sucesso' | 'neutro' }> = {
  PROPOSTA: { rotulo: 'Aguardando validação', cor: 'alerta' }, VALIDADA: { rotulo: 'Validada', cor: 'sucesso' }, REJEITADA: { rotulo: 'Rejeitada', cor: 'neutro' },
}
const rotuloStatus = (s: string) => s.charAt(0) + s.slice(1).toLowerCase()
const ROTULO_CAMPO: Record<string, string> = {
  codigo: 'Código', codigo_sufixo: 'Código + sufixo', descricao: 'Descrição', nome_resumido: 'Nome resumido', tipo: 'Tipo', classe_id: 'Classe',
  classificacao_natureza_id: 'Natureza', subelemento_despesa_id: 'Subelemento', unidade_medida_id: 'Unidade', especificacao: 'Especificação', status: 'Status',
  origem: 'Origem', ficticio: 'Fictício', id: 'ID',
}

/** Módulo 8 — Catálogo de Bens e Serviços (seção 17). */
export function Catalogo() {
  const { pode } = useAuth()
  const podeEditar = pode('cadastros.gerenciar')
  const cabecalho = useCabecalhoExportacao()
  const [aba, setAba] = useState<Aba>('itens')
  const [status, setStatus] = useState<string[]>([])
  const [apenasDuplicados, setApenasDuplicados] = useState(false)
  const [aberto, setAberto] = useState<number | null>(null)
  const [editando, setEditando] = useState<ItemCatalogo | 'novo' | null>(null)
  const resumo = useQuery({ queryKey: ['catalogo-resumo'], queryFn: () => api<Resumo>('/catalogo/resumo') })

  const consulta = useMemo(() => {
    const p = new URLSearchParams()
    status.forEach((s) => p.append('status[]', s))
    if (apenasDuplicados) p.set('apenas_duplicados', '1')
    return p.toString()
  }, [status, apenasDuplicados])

  const colunas = useMemo<Coluna<ItemCatalogo>[]>(() => [
    { id: 'codigo', titulo: 'Código', tipo: 'texto', valor: (l) => l.codigo, fixa: true, largura: 100,
      render: (l) => <span><span className="font-medium text-primary">{l.codigo}</span>{l.codigo_sufixo && <span className="block text-[11px] text-muted-foreground">{l.codigo_sufixo}</span>}</span> },
    { id: 'descricao', titulo: 'Descrição', tipo: 'texto', valor: (l) => l.descricao, largura: 280,
      render: (l) => (
        <span className="flex items-start gap-1.5">
          <span className="line-clamp-2" title={l.descricao}>{l.descricao}</span>
          {l.duplicado && <Badge cor="alerta" className="shrink-0" title="Há outro item com a mesma descrição normalizada e a mesma unidade">Duplicidade</Badge>}
        </span>
      ) },
    { id: 'tipo', titulo: 'Tipo', tipo: 'texto', valor: (l) => l.tipo, ordenavel: false },
    { id: 'grupo', titulo: 'Grupo', tipo: 'texto', valor: (l) => l.grupo, ordenavel: false, largura: 170 },
    { id: 'classe', titulo: 'Classe', tipo: 'texto', valor: (l) => l.classe, largura: 170 },
    { id: 'natureza', titulo: 'Natureza', tipo: 'texto', valor: (l) => l.natureza, ordenavel: false },
    { id: 'subelemento', titulo: 'Subelemento', tipo: 'texto', valor: (l) => (l.subelemento_codigo ? `${l.subelemento_codigo} ${l.subelemento ?? ''}` : null), ordenavel: false, largura: 200 },
    { id: 'unidade', titulo: 'Un. fornecimento', tipo: 'texto', valor: (l) => l.unidade, ordenavel: false },
    { id: 'status', titulo: 'Status', tipo: 'texto', valor: (l) => rotuloStatus(l.status), ordenavel: false, render: (l) => <Badge cor={STATUS_COR[l.status] ?? 'neutro'}>{rotuloStatus(l.status)}</Badge> },
    { id: 'compras', titulo: 'Compras', dica: 'Linhas de itens de processos que usam este item do catálogo.', tipo: 'numero', valor: (l) => l.compras },
    { id: 'created_at', titulo: 'Cadastro', tipo: 'data', valor: (l) => l.created_at },
    { id: 'especificacao', titulo: 'Especificação', tipo: 'texto', valor: (l) => l.especificacao, ordenavel: false, largura: 220,
      render: (l) => <span className="line-clamp-2 text-muted-foreground" title={l.especificacao ?? undefined}>{l.especificacao ?? '—'}</span> },
  ], [])

  return (
    <div>
      <CabecalhoModulo titulo="Catálogo de Bens e Serviços" descricao="Catálogo centralizado: normalização das descrições, duplicidades, itens semelhantes, equivalências validadas e histórico de alterações." consulta="" semPeriodo
        acoes={podeEditar && <Button size="sm" onClick={() => setEditando('novo')}><Plus className="size-4" />Novo item</Button>} />
      <EstadoPainel q={resumo}>
        {(r) => (
          <GradeKpis colunas="xl:grid-cols-6" itens={[
            { rotulo: 'Itens no catálogo', valor: r.itens, tipo: 'numero', destaque: true },
            { rotulo: 'Ativos', valor: r.por_status.find((s) => s.status === 'ATIVO')?.quantidade ?? 0, tipo: 'numero' },
            { rotulo: 'Grupos de duplicidade', valor: r.grupos_duplicidade, tipo: 'numero', dica: 'Conjuntos de itens com a mesma descrição normalizada e a mesma unidade de fornecimento.' },
            { rotulo: 'Itens sem compra', valor: r.itens_sem_compra, tipo: 'numero', dica: 'Itens do catálogo que ainda não aparecem em nenhum processo.' },
            { rotulo: 'Equivalências pendentes', valor: r.equivalencias_pendentes, tipo: 'numero', dica: 'Propostas aguardando validação técnica.' },
            { rotulo: 'Equivalências validadas', valor: r.equivalencias_validadas, tipo: 'numero' },
          ]} />
        )}
      </EstadoPainel>
      <Nota>O catálogo não segue os filtros globais do painel: mostra todos os itens cadastrados. A normalização coloca a descrição em maiúsculas, remove acentos e pontuação, junta número e unidade (5 KG → 5KG) e padroniza abreviações (PCT → PACOTE, UNID → UN). Equivalência entre itens nunca é automática.</Nota>
      <Abas<Aba> abas={[{ id: 'itens', rotulo: 'Itens' }, { id: 'duplicidades', rotulo: 'Duplicidades' }, { id: 'equivalencias', rotulo: 'Equivalências' }]} ativa={aba} aoMudar={setAba} />

      {aba === 'itens' && (
        <Card>
          <CardContent className="pt-5">
            <div className="mb-3 flex flex-wrap items-center gap-2" role="group" aria-label="Filtros do catálogo">
              <span className="text-xs text-muted-foreground">Status:</span>
              {['ATIVO', 'INATIVO', 'SUSPENSO'].map((s) => {
                const ativo = status.includes(s)
                return (
                  <button key={s} aria-pressed={ativo} onClick={() => setStatus((x) => (ativo ? x.filter((y) => y !== s) : [...x, s]))}
                    className={cn('rounded-md border px-3 py-1.5 text-xs', ativo ? 'border-primary bg-accent text-accent-foreground' : 'hover:bg-muted')}>{rotuloStatus(s)}</button>
                )
              })}
              <label className="ml-2 flex items-center gap-2 text-xs"><Caixa marcado={apenasDuplicados} aoMudar={setApenasDuplicados} rotulo="Somente duplicidades" />Somente duplicidades</label>
            </div>
            <TabelaServidor<ItemCatalogo> endpoint="/catalogo" consulta={consulta} colunas={colunas} ordemPadrao={{ id: 'codigo', direcao: 'asc' }}
              placeholderPesquisa="Código, código + sufixo ou descrição…" aoClicarLinha={(l) => setAberto(l.id)}
              podeExportar={pode('relatorios.exportar')} cabecalhoExportacao={{ ...cabecalho('Catálogo de bens e serviços'), filtros: [...status.map(rotuloStatus), ...(apenasDuplicados ? ['Somente duplicidades'] : [])] }} />
          </CardContent>
        </Card>
      )}
      {aba === 'duplicidades' && <Duplicidades aoAbrir={setAberto} />}
      {aba === 'equivalencias' && <Equivalencias podeEditar={podeEditar} aoAbrir={setAberto} />}

      <Dialogo aberto={aberto !== null} aoFechar={() => setAberto(null)} titulo="Item do catálogo" descricao="Dados, duplicidades, itens semelhantes, equivalências e histórico de alterações.">
        {aberto !== null && <FichaItem id={aberto} podeEditar={podeEditar} aoEditar={(i) => { setAberto(null); setEditando(i) }} aoAbrir={setAberto} />}
      </Dialogo>
      <Dialogo aberto={editando !== null} aoFechar={() => setEditando(null)} titulo={editando === 'novo' ? 'Novo item do catálogo' : 'Editar item do catálogo'}
        descricao="Toda alteração fica registrada no histórico do item, com o valor anterior e o novo." className="max-w-2xl">
        {editando !== null && resumo.data && (
          <FormItem item={editando === 'novo' ? null : editando} opcoes={resumo.data.opcoes} aoSalvar={(id) => { setEditando(null); setAberto(id) }} />
        )}
      </Dialogo>
    </div>
  )
}

function FichaItem({ id, podeEditar, aoEditar, aoAbrir }: { id: number; podeEditar: boolean; aoEditar: (i: ItemCatalogo) => void; aoAbrir: (id: number) => void }) {
  const cliente = useQueryClient()
  const q = useQuery({ queryKey: ['catalogo-item', id], queryFn: () => api<Detalhe>(`/catalogo/${id}`) })
  const propor = useMutation({
    mutationFn: (equivalente: number) => api('/catalogo/equivalencias', { method: 'POST', body: JSON.stringify({ item_id: id, equivalente_id: equivalente }) }),
    onSuccess: () => { cliente.invalidateQueries({ queryKey: ['catalogo-item', id] }); cliente.invalidateQueries({ queryKey: ['catalogo-resumo'] }); cliente.invalidateQueries({ queryKey: ['catalogo-equivalencias'] }) },
  })
  if (q.isLoading) return <Carregando />
  if (q.isError || !q.data) return <Erro erro={q.error} />
  const { item, duplicados, semelhantes, equivalencias, historico } = q.data
  const jaRelacionado = new Set(equivalencias.filter((e) => e.status !== 'REJEITADA').flatMap((e) => [e.item_id, e.equivalente_id]))
  const botaoPropor = (r: Relacionado) => podeEditar && !jaRelacionado.has(r.id) ? (
    <Button variant="outline" size="sm" disabled={propor.isPending} onClick={(e) => { e.stopPropagation(); propor.mutate(r.id) }}>Propor equivalência</Button>
  ) : jaRelacionado.has(r.id) ? <span className="text-[11px] text-muted-foreground">Já proposta</span> : null

  return (
    <div className="space-y-4 p-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-xs text-muted-foreground">{item.codigo}{item.codigo_sufixo ? ` · ${item.codigo_sufixo}` : ''} · {item.tipo}</p>
          <p className="text-base font-semibold">{item.descricao}</p>
          <p className="mt-0.5 font-mono text-[11px] text-muted-foreground" title="Descrição normalizada">{item.descricao_normalizada}</p>
        </div>
        <div className="flex items-center gap-2">
          <Badge cor={STATUS_COR[item.status] ?? 'neutro'}>{rotuloStatus(item.status)}</Badge>
          {podeEditar && <Button variant="outline" size="sm" onClick={() => aoEditar(item)}><Pencil className="size-3.5" />Editar</Button>}
        </div>
      </div>
      <Secao titulo="Classificação">
        <Campos itens={[
          ['Grupo', item.grupo ?? '—'], ['Classe', item.classe ?? '—'], ['Natureza', item.natureza ?? '—'],
          ['Subelemento', item.subelemento_codigo ? `${item.subelemento_codigo} ${item.subelemento ?? ''}` : '—'], ['Unidade de fornecimento', item.unidade ?? '—'],
          ['Compras', formatarNumero(item.compras)], ['Cadastro', formatarData(item.created_at)], ['Última alteração', formatarData(item.updated_at)],
        ]} />
        {item.especificacao && <p className="mt-3 whitespace-pre-line rounded-md bg-muted/50 p-3 text-xs">{item.especificacao}</p>}
      </Secao>
      {propor.isError && <p className="rounded-md border border-danger/40 bg-danger/10 p-3 text-xs">{(propor.error as Error).message}</p>}
      <Secao titulo={`Duplicidades (${duplicados.length})`}>
        <p className="mb-2 text-xs text-muted-foreground">Mesma descrição normalizada e mesma unidade de fornecimento.</p>
        <MiniTabela cabecalho={['Código', 'Descrição', 'Un.', 'Especificação', '']} vazio="Nenhuma duplicidade."
          linhas={duplicados.map((r) => [r.codigo, r.descricao, r.unidade ?? '—', r.especificacao_diferente ? <Diferente /> : 'Igual', botaoPropor(r)])}
          aoClicar={(k) => aoAbrir(duplicados[k].id)} />
      </Secao>
      <Secao titulo={`Itens semelhantes (${semelhantes.length})`}>
        <p className="mb-2 text-xs text-muted-foreground">Mesma classe e termos parecidos (semelhança de 50% ou mais). Semelhança não é equivalência: confira a especificação.</p>
        <MiniTabela cabecalho={['Código', 'Descrição', 'Un.', 'Semelhança', 'Especificação', '']} direita={[3]} vazio="Nenhum item semelhante."
          linhas={semelhantes.map((r) => [r.codigo, r.descricao, <span key="u" className={cn(!r.mesma_unidade && 'text-warning')}>{r.unidade ?? '—'}{!r.mesma_unidade && ' (outra unidade)'}</span>,
            formatarPercentual(r.semelhanca ?? null), r.especificacao_diferente ? <Diferente /> : 'Igual', botaoPropor(r)])}
          aoClicar={(k) => aoAbrir(semelhantes[k].id)} />
      </Secao>
      <Secao titulo={`Equivalências (${equivalencias.length})`}>
        <ListaEquivalencias dados={equivalencias} podeEditar={podeEditar} aoAbrir={aoAbrir} itemAtual={id} />
      </Secao>
      <Secao titulo="Histórico de alterações">
        {historico.length === 0 ? <p className="text-xs text-muted-foreground">Sem alterações registradas desde a carga inicial.</p> : (
          <ol className="space-y-3">
            {historico.map((h) => (
              <li key={h.id} className="rounded-md border p-3 text-xs">
                <p className="mb-1 font-medium">{formatarData(h.data)} · {h.usuario ?? 'Sistema'}</p>
                {h.alteracoes.length === 0 ? <p className="text-muted-foreground">Cadastro do item.</p> : (
                  <ul className="space-y-0.5">
                    {h.alteracoes.map((a) => (
                      <li key={a.campo}><span className="text-muted-foreground">{ROTULO_CAMPO[a.campo] ?? a.campo}:</span>{' '}
                        <span className="line-through opacity-70">{String(a.antes ?? '—')}</span> → <strong>{String(a.depois ?? '—')}</strong></li>
                    ))}
                  </ul>
                )}
              </li>
            ))}
          </ol>
        )}
      </Secao>
    </div>
  )
}

const Diferente = () => <span className="inline-flex items-center gap-1 text-warning"><AlertTriangle className="size-3.5" aria-hidden />Diferente</span>

function Duplicidades({ aoAbrir }: { aoAbrir: (id: number) => void }) {
  const q = useQuery({ queryKey: ['catalogo-duplicidades'], queryFn: () => api<{ grupos: GrupoDuplicidade[] }>('/catalogo/duplicidades') })
  return (
    <EstadoPainel q={q}>
      {(d) => d.grupos.length === 0 ? <Card><CardContent className="py-10 text-center text-xs text-muted-foreground">Nenhuma duplicidade encontrada.</CardContent></Card> : (
        <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
          {d.grupos.map((g) => (
            <Card key={`${g.descricao_normalizada}|${g.unidade}`}>
              <CardContent className="pt-5">
                <p className="flex items-center gap-2 text-sm font-semibold"><Copy className="size-4 text-muted-foreground" aria-hidden />{g.descricao_normalizada}</p>
                <p className="mb-3 text-xs text-muted-foreground">Unidade {g.unidade ?? 'não informada'} · {g.itens.length} itens
                  {g.especificacoes_diferentes && <span className="text-warning"> · especificações diferentes: não unifique sem análise técnica</span>}</p>
                <MiniTabela cabecalho={['Código', 'Descrição original', 'Status', 'Compras']} direita={[3]}
                  linhas={g.itens.map((i) => [i.codigo, i.descricao, rotuloStatus(i.status), formatarNumero(i.compras ?? 0)])} aoClicar={(k) => aoAbrir(g.itens[k].id)} />
              </CardContent>
            </Card>
          ))}
        </div>
      )}
    </EstadoPainel>
  )
}

function Equivalencias({ podeEditar, aoAbrir }: { podeEditar: boolean; aoAbrir: (id: number) => void }) {
  const [status, setStatus] = useState<'' | Equivalencia['status']>('')
  const q = useQuery({ queryKey: ['catalogo-equivalencias', status], queryFn: () => api<{ equivalencias: Equivalencia[] }>(`/catalogo/equivalencias${status ? `?status=${status}` : ''}`) })
  return (
    <Card>
      <CardContent className="pt-5">
        <div className="mb-3 flex flex-wrap gap-2" role="group" aria-label="Filtrar equivalências">
          {([['', 'Todas'], ['PROPOSTA', 'Aguardando validação'], ['VALIDADA', 'Validadas'], ['REJEITADA', 'Rejeitadas']] as const).map(([v, r]) => (
            <button key={v} aria-pressed={status === v} onClick={() => setStatus(v)}
              className={cn('rounded-md border px-3 py-1.5 text-xs', status === v ? 'border-primary bg-accent text-accent-foreground' : 'hover:bg-muted')}>{r}</button>
          ))}
        </div>
        <p className="mb-3 text-xs text-muted-foreground">Proponha equivalências na ficha de um item (duplicidades e semelhantes). A validação exige justificativa técnica e fica registrada na auditoria.</p>
        <EstadoPainel q={q}>{(d) => <ListaEquivalencias dados={d.equivalencias} podeEditar={podeEditar} aoAbrir={aoAbrir} />}</EstadoPainel>
      </CardContent>
    </Card>
  )
}

function ListaEquivalencias({ dados, podeEditar, aoAbrir, itemAtual }: { dados: Equivalencia[]; podeEditar: boolean; aoAbrir: (id: number) => void; itemAtual?: number }) {
  const [decidindo, setDecidindo] = useState<Equivalencia | null>(null)
  if (!dados.length) return <p className="text-xs text-muted-foreground">Nenhuma equivalência.</p>
  const link = (id: number, codigo: string, descricao: string) => (
    id === itemAtual ? <span>{codigo} · {descricao}</span>
      : <button className="text-left text-primary hover:underline" onClick={() => aoAbrir(id)}>{codigo} · {descricao}</button>
  )
  return (
    <>
      <ul className="space-y-2">
        {dados.map((e) => (
          <li key={e.id} className="rounded-md border p-3 text-xs">
            <div className="flex flex-wrap items-start justify-between gap-2">
              <div className="min-w-0 space-y-0.5">
                <p>{link(e.item_id, e.item_codigo, e.item_descricao)}</p>
                <p className="text-muted-foreground">equivale a</p>
                <p>{link(e.equivalente_id, e.equivalente_codigo, e.equivalente_descricao)}</p>
              </div>
              <div className="flex flex-col items-end gap-1.5">
                <Badge cor={STATUS_EQ[e.status].cor}>{STATUS_EQ[e.status].rotulo}</Badge>
                {e.especificacoes_diferentes && <Diferente />}
              </div>
            </div>
            <p className="mt-2 text-muted-foreground">Proposta por {e.proposto_por ?? '—'} em {formatarData(e.created_at)}
              {e.validado_em && ` · decidida por ${e.validado_por ?? '—'} em ${formatarData(e.validado_em)}`}</p>
            {e.justificativa && <p className="mt-1 whitespace-pre-line">{e.justificativa}</p>}
            {podeEditar && e.status === 'PROPOSTA' && (
              <div className="mt-2"><Button size="sm" variant="outline" onClick={() => setDecidindo(e)}>Analisar proposta</Button></div>
            )}
          </li>
        ))}
      </ul>
      <Dialogo aberto={!!decidindo} aoFechar={() => setDecidindo(null)} titulo="Validação técnica da equivalência" className="max-w-xl"
        descricao="A equivalência só vale depois desta validação. Registre a justificativa técnica.">
        {decidindo && <FormDecisao eq={decidindo} aoConcluir={() => setDecidindo(null)} />}
      </Dialogo>
    </>
  )
}

function FormDecisao({ eq, aoConcluir }: { eq: Equivalencia; aoConcluir: () => void }) {
  const cliente = useQueryClient()
  const [justificativa, setJustificativa] = useState('')
  const [confirmo, setConfirmo] = useState(false)
  const m = useMutation({
    mutationFn: (decisao: 'VALIDAR' | 'REJEITAR') => api(`/catalogo/equivalencias/${eq.id}/decisao`, {
      method: 'POST', body: JSON.stringify({ decisao, justificativa, confirmar_especificacao: confirmo }),
    }),
    onSuccess: () => {
      for (const k of ['catalogo-equivalencias', 'catalogo-item', 'catalogo-resumo']) cliente.invalidateQueries({ queryKey: [k] })
      aoConcluir()
    },
  })
  const curta = justificativa.trim().length < 10
  return (
    <div className="space-y-3 p-5 text-xs">
      <p><strong>{eq.item_codigo}</strong> · {eq.item_descricao}</p>
      <p><strong>{eq.equivalente_codigo}</strong> · {eq.equivalente_descricao}</p>
      {eq.especificacoes_diferentes && (
        <div className="rounded-md border border-warning/50 bg-warning/10 p-3">
          <p className="flex items-center gap-1.5 font-medium"><AlertTriangle className="size-4" aria-hidden />Os itens têm especificações técnicas diferentes.</p>
          <label className="mt-2 flex items-start gap-2"><Caixa marcado={confirmo} aoMudar={setConfirmo} rotulo="Confirmo a análise técnica" />
            Confirmo que analisei as especificações e que a diferença não impede a equivalência.</label>
        </div>
      )}
      <label className="block">
        <span className="mb-1 block font-medium">Justificativa técnica (mínimo de 10 caracteres)</span>
        <textarea value={justificativa} onChange={(e) => setJustificativa(e.target.value)} rows={4}
          className="w-full rounded-md border border-input bg-surface p-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring" />
      </label>
      {m.isError && <p className="rounded-md border border-danger/40 bg-danger/10 p-2">{mensagemErro(m.error)}</p>}
      <div className="flex flex-wrap justify-end gap-2">
        <Button variant="outline" size="sm" disabled={curta || m.isPending} onClick={() => m.mutate('REJEITAR')}><XCircle className="size-3.5" />Rejeitar</Button>
        <Button size="sm" disabled={curta || m.isPending || (eq.especificacoes_diferentes && !confirmo)} onClick={() => m.mutate('VALIDAR')}><CheckCircle2 className="size-3.5" />Validar equivalência</Button>
      </div>
    </div>
  )
}

function mensagemErro(e: unknown): string {
  if (e instanceof ApiError && e.erros) return Object.values(e.erros).flat().join(' ')
  return (e as Error)?.message ?? 'Erro ao salvar.'
}

function Rotulo({ t, children, className }: { t: string; children: ReactNode; className?: string }) {
  return <label className={cn('block text-xs', className)}><span className="mb-1 block font-medium">{t}</span>{children}</label>
}

function FormItem({ item, opcoes, aoSalvar }: { item: ItemCatalogo | null; opcoes: Opcoes; aoSalvar: (id: number) => void }) {
  const cliente = useQueryClient()
  const [f, setF] = useState({
    codigo: item?.codigo ?? '', codigo_sufixo: item?.codigo_sufixo ?? '', descricao: item?.descricao ?? '', nome_resumido: item?.nome_resumido ?? '',
    tipo: item ? (item.tipo === 'Serviço' ? 'S' : 'M') : 'M', classe_id: item?.classe_id ? String(item.classe_id) : '',
    classificacao_natureza_id: item?.natureza_id ? String(item.natureza_id) : '', subelemento_despesa_id: item?.subelemento_id ? String(item.subelemento_id) : '',
    unidade_medida_id: item?.unidade_id ? String(item.unidade_id) : '', especificacao: item?.especificacao ?? '', status: item?.status ?? 'ATIVO',
  })
  const m = useMutation({
    mutationFn: () => {
      const corpo = Object.fromEntries(Object.entries(f).map(([k, v]) => [k, v === '' ? null : k.endsWith('_id') ? Number(v) : v]))
      return api<Detalhe>(item ? `/catalogo/${item.id}` : '/catalogo', { method: item ? 'PUT' : 'POST', body: JSON.stringify(corpo) })
    },
    onSuccess: (d) => {
      for (const k of ['catalogo-resumo', 'catalogo-item', 'catalogo-duplicidades']) cliente.invalidateQueries({ queryKey: [k] })
      cliente.invalidateQueries({ queryKey: ['/catalogo'] })
      aoSalvar(d.item.id)
    },
  })
  const campo = (k: keyof typeof f) => ({ value: f[k], onChange: (e: { target: { value: string } }) => setF((x) => ({ ...x, [k]: e.target.value })) })
  const select = 'h-9 w-full rounded-md border border-input bg-surface px-2 text-sm'
  return (
    <form className="grid grid-cols-1 gap-3 p-5 sm:grid-cols-2" onSubmit={(e) => { e.preventDefault(); m.mutate() }}>
      <Rotulo t="Código *"><Input required maxLength={30} {...campo('codigo')} /></Rotulo>
      <Rotulo t="Código + sufixo"><Input maxLength={40} placeholder="Gerado com a unidade se vazio" {...campo('codigo_sufixo')} /></Rotulo>
      <Rotulo t="Descrição *" className="sm:col-span-2"><Input required minLength={3} maxLength={500} {...campo('descricao')} /></Rotulo>
      <Rotulo t="Nome resumido"><Input maxLength={160} {...campo('nome_resumido')} /></Rotulo>
      <Rotulo t="Tipo *"><select className={select} {...campo('tipo')}><option value="M">Material</option><option value="S">Serviço</option></select></Rotulo>
      <Rotulo t="Classe *"><select required className={select} {...campo('classe_id')}><option value="">Selecione…</option>
        {opcoes.classes.map((c) => <option key={c.id} value={c.id}>{c.grupo} › {c.nome}</option>)}</select></Rotulo>
      <Rotulo t="Unidade de fornecimento"><select className={select} {...campo('unidade_medida_id')}><option value="">Não informada</option>
        {opcoes.unidades.map((u) => <option key={u.id} value={u.id}>{u.sigla} · {u.nome}</option>)}</select></Rotulo>
      <Rotulo t="Natureza"><select className={select} {...campo('classificacao_natureza_id')}><option value="">Não informada</option>
        {opcoes.naturezas.map((n) => <option key={n.id} value={n.id}>{n.nome}</option>)}</select></Rotulo>
      <Rotulo t="Subelemento de despesa"><select className={select} {...campo('subelemento_despesa_id')}><option value="">Não informado</option>
        {opcoes.subelementos.map((s) => <option key={s.id} value={s.id}>{s.codigo} {s.nome}</option>)}</select></Rotulo>
      <Rotulo t="Status *"><select className={select} {...campo('status')}>{opcoes.status.map((s) => <option key={s} value={s}>{rotuloStatus(s)}</option>)}</select></Rotulo>
      <Rotulo t="Especificação técnica" className="sm:col-span-2">
        <textarea rows={4} maxLength={5000} className="w-full rounded-md border border-input bg-surface p-2 text-sm" {...campo('especificacao')} />
      </Rotulo>
      {m.isError && <p className="rounded-md border border-danger/40 bg-danger/10 p-2 text-xs sm:col-span-2">{mensagemErro(m.error)}</p>}
      <div className="flex justify-end sm:col-span-2"><Button type="submit" disabled={m.isPending}>{item ? 'Salvar alterações' : 'Cadastrar item'}</Button></div>
    </form>
  )
}
