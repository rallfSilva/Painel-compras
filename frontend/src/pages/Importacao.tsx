import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CheckCircle2, Download, ExternalLink, FileUp, Loader2, Play, PlugZap, RotateCcw, Save, Trash2, TriangleAlert, Upload } from 'lucide-react'
import { useAuth } from '@/contexts/AuthContext'
import { api, ApiError } from '@/lib/api'
import { Abas, CabecalhoModulo } from '@/components/compras/CabecalhoModulo'
import { GradeKpis, Nota, useCabecalhoExportacao } from '@/components/inteligencia/partes'
import { TabelaServidor } from '@/components/tabela/TabelaServidor'
import type { Coluna } from '@/components/tabela/colunas'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Caixa } from '@/components/ui/checkbox'
import { Dialogo } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Erro, MiniTabela, Secao } from '@/components/detalhes/Partes'
import { formatarNumero } from '@/lib/format'
import { cn } from '@/lib/utils'

interface Campo { nome: string; rotulo: string; tipo: string; obrigatorio: boolean; exemplo?: string }
interface Tipo { codigo: string; titulo: string; descricao: string; campos: Campo[] }
interface Resumo { total: number; validos: number; invalidos: number; duplicados: number; novos: number; atualizacoes: number; exemplos_ignorados?: number; erros_por_campo: Record<string, number>; amostra: Record<string, unknown>[] }
interface Importacao {
  id: number; tipo: string; tipo_titulo: string; arquivo: string; formato: string | null; tamanho: number | null; origem: string; usuario: string | null
  status: Status; total_registros: number; total_importados: number; total_rejeitados: number; total_duplicados: number
  colunas: string[]; mapeamento: Record<string, string>; resumo: Resumo | null; mensagem: string | null; reprocessada_de_id: number | null
  arquivo_disponivel: boolean; criada_em: string; iniciada_em: string | null; concluida_em: string | null; amostra?: Record<string, unknown>[]
}
type Status = 'PENDENTE' | 'VALIDADO' | 'NA_FILA' | 'PROCESSANDO' | 'CONCLUIDO' | 'ERRO'
interface ErroLinha { id: number; linha: number | null; campo: string | null; mensagem: string; registro: Record<string, unknown> | null }
type Aba = 'nova' | 'historico' | 'integracoes'

const STATUS: Record<Status, { rotulo: string; cor: 'neutro' | 'primario' | 'sucesso' | 'alerta' | 'perigo' }> = {
  PENDENTE: { rotulo: 'Aguardando mapeamento', cor: 'neutro' }, VALIDADO: { rotulo: 'Validada', cor: 'primario' }, NA_FILA: { rotulo: 'Na fila', cor: 'alerta' },
  PROCESSANDO: { rotulo: 'Processando', cor: 'alerta' }, CONCLUIDO: { rotulo: 'Concluída', cor: 'sucesso' }, ERRO: { rotulo: 'Erro', cor: 'perigo' },
}
const ORIGENS: Record<string, string> = { IMPORTACAO: 'Arquivo', PNCP: 'PNCP', COMPRASGOV: 'Compras.gov.br', SISTEMA_ESTADUAL: 'Sistema estadual', NEXPLANGOV: 'NexPlanGov (API)' }
const classeSelect = 'h-9 w-full rounded-md border border-input bg-surface px-2 text-sm'
const mensagemErro = (e: unknown) => (e instanceof ApiError && e.erros ? Object.values(e.erros).flat().join(' ') : (e as Error)?.message ?? 'Erro.')
const dataHora = (s: string | null) => (s ? new Date(s.replace(' ', 'T')).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }) : '—')

/** Modelo CSV: linha 1 com os nomes das colunas e linha 2 com um exemplo fictício preenchido (ignorado se enviado sem alterações). */
function baixarModelo(t: Tipo) {
  const esc = (s: string) => (/[";,\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s)
  const linhas = [t.campos.map((c) => esc(c.rotulo)).join(';')]
  if (t.campos.some((c) => c.exemplo)) linhas.push(t.campos.map((c) => esc(c.exemplo ?? '')).join(';'))
  const blob = new Blob(['﻿' + linhas.join('\r\n') + '\r\n'], { type: 'text/csv;charset=utf-8' })
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob)
  a.download = `modelo-${t.codigo.toLowerCase()}.csv`
  a.click()
  setTimeout(() => URL.revokeObjectURL(a.href), 1000)
}

/** Módulo 18 — Importação de dados e integrações (seção 26). */
export function Importacao() {
  const { pode } = useAuth()
  const abas = useMemo(() => ([
    pode('importacao.executar') ? { id: 'nova', rotulo: 'Nova importação' } : null,
    pode('importacao.executar') ? { id: 'historico', rotulo: 'Histórico' } : null,
    pode('integracoes.gerenciar') ? { id: 'integracoes', rotulo: 'Integrações' } : null,
  ].filter(Boolean) as { id: Aba; rotulo: string }[]), [pode])
  const [aba, setAba] = useState<Aba>(abas[0]?.id ?? 'nova')
  const [atual, setAtual] = useState<number | null>(null)

  return (
    <div>
      <CabecalhoModulo titulo="Importação de dados" descricao="Carga de planilhas (XLSX, XLS, CSV, JSON) com validação antes de gravar, e integrações com PNCP, Compras.gov.br e sistema financeiro." consulta="" semPeriodo />
      {abas.length === 0 ? <Nota>Seu perfil não tem permissão para importar dados nem gerenciar integrações.</Nota> : (
        <>
          <Abas<Aba> abas={abas} ativa={aba} aoMudar={setAba} />
          {aba === 'nova' && <NovaImportacao id={atual} aoMudarId={setAtual} aoVerHistorico={() => setAba('historico')} />}
          {aba === 'historico' && <Historico aoAbrir={(id) => { setAtual(id); setAba('nova') }} />}
          {aba === 'integracoes' && <Integracoes />}
        </>
      )}
    </div>
  )
}

/* ---------- Assistente de importação ---------- */

function useTipos() {
  return useQuery({ queryKey: ['importacao-tipos'], queryFn: () => api<{ tipos: Tipo[] }>('/importacoes/tipos').then((r) => r.tipos), staleTime: Infinity })
}

function Passos({ passo }: { passo: number }) {
  const nomes = ['Arquivo', 'Mapeamento', 'Validação', 'Processamento']
  return (
    <ol className="mb-4 flex flex-wrap gap-2 text-xs" aria-label="Etapas da importação">
      {nomes.map((n, i) => (
        <li key={n} aria-current={i === passo ? 'step' : undefined}
          className={cn('flex items-center gap-1.5 rounded-full border px-3 py-1', i === passo ? 'border-primary bg-accent font-medium text-accent-foreground' : i < passo ? 'text-foreground' : 'text-muted-foreground')}>
          <span className={cn('flex size-4 items-center justify-center rounded-full text-[10px]', i < passo ? 'bg-primary text-primary-foreground' : 'border')}>{i < passo ? '✓' : i + 1}</span>{n}
        </li>
      ))}
    </ol>
  )
}

function NovaImportacao({ id, aoMudarId, aoVerHistorico }: { id: number | null; aoMudarId: (id: number | null) => void; aoVerHistorico: () => void }) {
  const tipos = useTipos()
  const cliente = useQueryClient()
  const [tipo, setTipo] = useState('')
  const [arquivo, setArquivo] = useState<File | null>(null)
  const [amostra, setAmostra] = useState<Record<string, unknown>[]>([])
  const [mapa, setMapa] = useState<Record<string, string>>({})
  const [editandoMapa, setEditandoMapa] = useState(false)

  const q = useQuery({
    queryKey: ['importacao', id], queryFn: () => api<Importacao>(`/importacoes/${id}`), enabled: id !== null,
    refetchInterval: (x) => (x.state.data && ['NA_FILA', 'PROCESSANDO'].includes(x.state.data.status) ? 2000 : false),
  })
  const imp = q.data
  useEffect(() => { if (imp) setMapa(imp.mapeamento ?? {}) }, [imp?.id, imp?.status]) // eslint-disable-line react-hooks/exhaustive-deps

  const enviar = useMutation({
    mutationFn: () => {
      const fd = new FormData()
      fd.append('tipo', tipo)
      fd.append('arquivo', arquivo!)
      return api<Importacao>('/importacoes', { method: 'POST', body: fd })
    },
    onSuccess: (r) => { setAmostra(r.amostra ?? []); cliente.setQueryData(['importacao', r.id], r); setEditandoMapa(true); aoMudarId(r.id) },
  })
  const validar = useMutation({
    mutationFn: () => api<Importacao>(`/importacoes/${id}/validar`, { method: 'POST', body: JSON.stringify({ mapeamento: mapa }) }),
    onSuccess: (r) => { cliente.setQueryData(['importacao', r.id], r); cliente.invalidateQueries({ queryKey: [`/importacoes/${r.id}/erros`] }); setEditandoMapa(false) },
  })
  const confirmar = useMutation({
    mutationFn: () => api<Importacao>(`/importacoes/${id}/confirmar`, { method: 'POST' }),
    onSuccess: (r) => { cliente.setQueryData(['importacao', r.id], r); cliente.invalidateQueries({ queryKey: ['/importacoes'] }) },
  })
  const descartar = useMutation({
    mutationFn: () => api(`/importacoes/${id}`, { method: 'DELETE' }),
    onSuccess: () => { reiniciar(); cliente.invalidateQueries({ queryKey: ['/importacoes'] }) },
  })
  const reiniciar = () => { aoMudarId(null); setArquivo(null); setAmostra([]); setMapa({}); setEditandoMapa(false); enviar.reset(); validar.reset(); confirmar.reset() }

  if (tipos.isLoading) return <div className="flex h-40 items-center justify-center"><Loader2 className="size-6 animate-spin text-primary" /></div>
  if (tipos.isError) return <Erro erro={tipos.error} />
  const defTipo = tipos.data!.find((t) => t.codigo === (imp?.tipo ?? tipo))

  // Passo 1 — arquivo
  if (id === null) {
    return (
      <div className="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_380px]">
        <Card>
          <CardContent className="space-y-4 pt-5">
            <Passos passo={0} />
            <label className="block text-xs">
              <span className="mb-1 block font-medium">O que você vai importar?</span>
              <select className={classeSelect} value={tipo} onChange={(e) => setTipo(e.target.value)}>
                <option value="">Escolha o tipo de dado…</option>
                {tipos.data!.map((t) => <option key={t.codigo} value={t.codigo}>{t.titulo}</option>)}
              </select>
            </label>
            {defTipo && <p className="text-xs text-muted-foreground">{defTipo.descricao}</p>}
            <label className={cn('flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed px-4 py-10 text-center text-sm transition-colors hover:bg-muted/50', arquivo && 'border-primary')}>
              <FileUp className="size-7 text-muted-foreground" />
              {arquivo ? <span className="font-medium">{arquivo.name} · {formatarNumero(Math.ceil(arquivo.size / 1024))} KB</span> : <span>Clique para escolher o arquivo (XLSX, XLS, CSV ou JSON, até 20 MB)</span>}
              <input type="file" className="sr-only" accept=".xlsx,.xls,.csv,.json" onChange={(e) => setArquivo(e.target.files?.[0] ?? null)} />
            </label>
            {enviar.isError && <p role="alert" className="rounded-md border border-danger/40 bg-danger/10 p-2 text-xs">{mensagemErro(enviar.error)}</p>}
            <div className="flex justify-end">
              <Button disabled={!tipo || !arquivo || enviar.isPending} onClick={() => enviar.mutate()}>
                {enviar.isPending ? <Loader2 className="animate-spin" /> : <Upload />} Enviar e ler colunas
              </Button>
            </div>
          </CardContent>
        </Card>
        <Card className="self-start">
          <CardHeader><CardTitle>{defTipo ? `Campos · ${defTipo.titulo}` : 'Como funciona'}</CardTitle></CardHeader>
          <CardContent className="text-xs">
            {defTipo ? (
              <>
                <ul className="mb-3 max-h-[360px] space-y-1 overflow-auto">
                  {defTipo.campos.map((c) => <li key={c.nome}>{c.rotulo}{c.obrigatorio && <span className="text-danger"> *</span>}</li>)}
                </ul>
                <Button variant="outline" size="sm" onClick={() => baixarModelo(defTipo)}><Download /> Baixar modelo CSV</Button>
                <p className="mt-2 text-muted-foreground">A segunda linha do modelo traz um exemplo fictício preenchido para orientar o formato. Substitua-o pelos seus dados; se for enviado sem alterações, ele é ignorado na importação.</p>
              </>
            ) : (
              <ol className="list-decimal space-y-1.5 pl-4 text-muted-foreground">
                <li>Escolha o tipo e envie o arquivo; a primeira linha deve ter os nomes das colunas.</li>
                <li>Confira a ligação de cada coluna com o campo do sistema.</li>
                <li>O sistema valida todas as linhas e mostra as inconsistências antes de gravar.</li>
                <li>Confirme: a gravação roda em segundo plano e fica no histórico.</li>
              </ol>
            )}
          </CardContent>
        </Card>
      </div>
    )
  }

  if (q.isLoading || !imp) return q.isError ? <Erro erro={q.error} /> : <div className="flex h-40 items-center justify-center"><Loader2 className="size-6 animate-spin text-primary" /></div>
  const mapeando = imp.status === 'PENDENTE' || editandoMapa
  const passo = mapeando ? 1 : imp.status === 'VALIDADO' ? 2 : 3
  const usados = new Set(Object.values(mapa))
  const faltam = (defTipo?.campos ?? []).filter((c) => c.obrigatorio && !usados.has(c.nome))

  return (
    <Card>
      <CardContent className="pt-5">
        <Passos passo={passo} />
        <div className="mb-4 flex flex-wrap items-center justify-between gap-2 text-sm">
          <p><span className="font-medium">{imp.tipo_titulo}</span> · {imp.arquivo} · {formatarNumero(imp.total_registros)} linhas</p>
          <Badge cor={STATUS[imp.status].cor}>{STATUS[imp.status].rotulo}</Badge>
        </div>

        {mapeando && defTipo && (
          <>
            <p className="mb-3 text-xs text-muted-foreground">Ligue cada coluna do arquivo a um campo do sistema. As sugestões vêm dos nomes das colunas; colunas sem ligação são ignoradas.</p>
            <div className="overflow-auto rounded-md border">
              <table className="w-full text-xs">
                <thead className="bg-muted text-left text-[11px] text-muted-foreground">
                  <tr><th className="px-3 py-2 font-semibold">Coluna do arquivo</th><th className="px-3 py-2 font-semibold">Exemplos</th><th className="w-[300px] px-3 py-2 font-semibold">Campo do sistema</th></tr>
                </thead>
                <tbody>
                  {imp.colunas.map((col) => (
                    <tr key={col} className="border-t">
                      <td className="px-3 py-1.5 font-medium">{col}</td>
                      <td className="max-w-[320px] truncate px-3 py-1.5 text-muted-foreground">{amostra.slice(0, 3).map((a) => String(a[col] ?? '')).filter(Boolean).join(' · ') || '—'}</td>
                      <td className="px-3 py-1.5">
                        <select className={cn(classeSelect, 'h-8')} aria-label={`Campo para a coluna ${col}`} value={mapa[col] ?? ''}
                          onChange={(e) => setMapa((m) => { const x = { ...m }; if (e.target.value) x[col] = e.target.value; else delete x[col]; return x })}>
                          <option value="">— ignorar —</option>
                          {defTipo.campos.map((c) => <option key={c.nome} value={c.nome} disabled={usados.has(c.nome) && mapa[col] !== c.nome}>{c.rotulo}{c.obrigatorio ? ' *' : ''}</option>)}
                        </select>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {faltam.length > 0 && <p className="mt-3 flex items-start gap-1.5 text-xs text-warning"><TriangleAlert className="mt-0.5 size-3.5 shrink-0" />Campos obrigatórios sem coluna: {faltam.map((c) => c.rotulo).join(', ')}.</p>}
            {validar.isError && <p role="alert" className="mt-3 rounded-md border border-danger/40 bg-danger/10 p-2 text-xs">{mensagemErro(validar.error)}</p>}
            <div className="mt-4 flex flex-wrap justify-end gap-2">
              <Button variant="ghost" disabled={descartar.isPending} onClick={() => descartar.mutate()}><Trash2 /> Descartar</Button>
              <Button disabled={faltam.length > 0 || validar.isPending} onClick={() => validar.mutate()}>{validar.isPending ? <Loader2 className="animate-spin" /> : <CheckCircle2 />} Validar todas as linhas</Button>
            </div>
          </>
        )}

        {!mapeando && imp.resumo && (
          <>
            <ResumoValidacao imp={imp} tipo={defTipo} />
            {imp.status === 'VALIDADO' && (
              <>
                {confirmar.isError && <p role="alert" className="mt-3 rounded-md border border-danger/40 bg-danger/10 p-2 text-xs">{mensagemErro(confirmar.error)}</p>}
                <div className="mt-4 flex flex-wrap items-center justify-end gap-2">
                  <p className="mr-auto text-xs text-muted-foreground">Serão gravadas {formatarNumero(imp.resumo.validos)} linhas ({formatarNumero(imp.resumo.novos)} novas e {formatarNumero(imp.resumo.atualizacoes)} atualizações). As linhas com erro ficam de fora.</p>
                  <Button variant="ghost" disabled={descartar.isPending} onClick={() => descartar.mutate()}><Trash2 /> Descartar</Button>
                  <Button variant="outline" onClick={() => setEditandoMapa(true)}>Voltar ao mapeamento</Button>
                  <Button disabled={imp.resumo.validos === 0 || confirmar.isPending} onClick={() => confirmar.mutate()}>{confirmar.isPending ? <Loader2 className="animate-spin" /> : <Play />} Confirmar importação</Button>
                </div>
              </>
            )}
          </>
        )}

        {['NA_FILA', 'PROCESSANDO', 'CONCLUIDO', 'ERRO'].includes(imp.status) && (
          <div className="mt-4 rounded-md border p-4 text-sm">
            {imp.status === 'NA_FILA' || imp.status === 'PROCESSANDO' ? (
              <p className="flex items-center gap-2"><Loader2 className="size-4 animate-spin text-primary" />{imp.status === 'NA_FILA' ? 'Na fila de processamento…' : 'Gravando os registros…'}</p>
            ) : imp.status === 'CONCLUIDO' ? (
              <p className="flex items-center gap-2"><CheckCircle2 className="size-4 text-success" />Importação concluída: {formatarNumero(imp.total_importados)} registros gravados e {formatarNumero(imp.total_rejeitados)} rejeitados. Os painéis já refletem os novos dados.</p>
            ) : (
              <p className="flex items-center gap-2 text-danger"><TriangleAlert className="size-4" />A importação falhou: {imp.mensagem}</p>
            )}
            {imp.status === 'NA_FILA' && <p className="mt-2 text-xs text-muted-foreground">Se continuar na fila, confira se o processador está em execução no servidor (php artisan queue:work).</p>}
            <div className="mt-3 flex gap-2">
              <Button variant="outline" size="sm" onClick={reiniciar}>Nova importação</Button>
              <Button variant="ghost" size="sm" onClick={aoVerHistorico}>Ver histórico</Button>
            </div>
          </div>
        )}
      </CardContent>
    </Card>
  )
}

function ResumoValidacao({ imp, tipo }: { imp: Importacao; tipo?: Tipo }) {
  const r = imp.resumo!
  const rotulo = (n: string) => tipo?.campos.find((c) => c.nome === n)?.rotulo ?? (n === 'linha' ? 'Linha inteira' : n)
  const colunasAmostra = (tipo?.campos ?? []).filter((c) => Object.values(imp.mapeamento).includes(c.nome))
  return (
    <>
      <GradeKpis colunas="xl:grid-cols-6" itens={[
        { rotulo: 'Linhas no arquivo', valor: r.total, tipo: 'numero' },
        { rotulo: 'Válidas', valor: r.validos, tipo: 'numero', destaque: true },
        { rotulo: 'Com inconsistência', valor: r.invalidos, tipo: 'numero' },
        { rotulo: 'Duplicadas no arquivo', valor: r.duplicados, tipo: 'numero', dica: 'Mesma chave de outra linha do arquivo; só a primeira é importada.' },
        { rotulo: 'Registros novos', valor: r.novos, tipo: 'numero' },
        { rotulo: 'Atualizações', valor: r.atualizacoes, tipo: 'numero', dica: 'Já existem no sistema e serão atualizados (não duplicados).' },
      ]} />
      {(r.exemplos_ignorados ?? 0) > 0 && <Nota className="mt-4">A linha de exemplo do modelo CSV foi enviada sem alterações e foi ignorada (não entra na contagem nem é gravada).</Nota>}
      <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
        <Secao titulo="Pré-visualização das linhas válidas" className="rounded-md border">
          <MiniTabela vazio="Nenhuma linha válida." cabecalho={['Linha', ...colunasAmostra.map((c) => c.rotulo)]}
            linhas={r.amostra.map((a) => [String(a.linha), ...colunasAmostra.map((c) => (a[c.nome] === null || a[c.nome] === undefined ? '—' : typeof a[c.nome] === 'boolean' ? (a[c.nome] ? 'Sim' : 'Não') : String(a[c.nome])))])} />
        </Secao>
        <Secao titulo="Inconsistências por campo" className="rounded-md border">
          <MiniTabela vazio="Nenhuma inconsistência." cabecalho={['Campo', 'Ocorrências']} direita={[1]}
            linhas={Object.entries(r.erros_por_campo).map(([c, n]) => [rotulo(c), formatarNumero(n)])} />
        </Secao>
      </div>
      {r.invalidos > 0 && <RelatorioErros id={imp.id} titulo={`${imp.tipo_titulo} · ${imp.arquivo}`} rotulo={rotulo} />}
    </>
  )
}

function RelatorioErros({ id, titulo, rotulo }: { id: number; titulo: string; rotulo: (n: string) => string }) {
  const cabecalho = useCabecalhoExportacao()
  const colunas: Coluna<ErroLinha>[] = [
    { id: 'linha', titulo: 'Linha', tipo: 'numero', valor: (l) => l.linha, ordenavel: false, largura: 70 },
    { id: 'campo', titulo: 'Campo', tipo: 'texto', valor: (l) => (l.campo ? rotulo(l.campo) : 'Linha inteira'), ordenavel: false },
    { id: 'mensagem', titulo: 'Inconsistência', tipo: 'texto', valor: (l) => l.mensagem, ordenavel: false, largura: 420 },
    { id: 'registro', titulo: 'Conteúdo da linha', tipo: 'texto', ordenavel: false, largura: 320,
      valor: (l) => (l.registro ? Object.entries(l.registro).filter(([, v]) => v !== null && v !== '').map(([k, v]) => `${k}: ${v}`).join(' | ') : ''),
      render: (l) => <span className="line-clamp-2 text-[11px] text-muted-foreground">{l.registro ? Object.entries(l.registro).filter(([, v]) => v !== null && v !== '').map(([k, v]) => `${k}: ${v}`).join(' | ') : '—'}</span> },
  ]
  return (
    <div className="mt-4">
      <h3 className="mb-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Relatório de inconsistências</h3>
      <TabelaServidor<ErroLinha> endpoint={`/importacoes/${id}/erros`} consulta="" colunas={colunas} ordemPadrao={{ id: 'linha', direcao: 'asc' }}
        placeholderPesquisa="Nº da linha ou texto da inconsistência…" podeExportar cabecalhoExportacao={cabecalho(`Inconsistências · ${titulo}`)} />
    </div>
  )
}

/* ---------- Histórico ---------- */

interface LinhaHistorico {
  id: number; tipo: string; tipo_titulo: string; arquivo: string; origem: string; status: Status; total_registros: number; total_importados: number
  total_rejeitados: number; total_duplicados: number; mensagem: string | null; created_at: string; concluida_em: string | null; usuario: string | null; reprocessada_de_id: number | null
}

function Historico({ aoAbrir }: { aoAbrir: (id: number) => void }) {
  const cabecalho = useCabecalhoExportacao()
  const { pode } = useAuth()
  const cliente = useQueryClient()
  const [status, setStatus] = useState<Status[]>([])
  const [detalhe, setDetalhe] = useState<number | null>(null)
  const consulta = status.map((s) => `situacao[]=${s}`).join('&')
  const colunas: Coluna<LinhaHistorico>[] = [
    { id: 'id', titulo: 'Nº', tipo: 'numero', valor: (l) => l.id, ordenavel: false, largura: 60 },
    { id: 'created_at', titulo: 'Data', tipo: 'texto', valor: (l) => dataHora(l.created_at), ordenavel: false, largura: 130 },
    { id: 'tipo_titulo', titulo: 'Tipo', tipo: 'texto', valor: (l) => l.tipo_titulo, ordenavel: false, largura: 200 },
    { id: 'arquivo', titulo: 'Arquivo ou consulta', tipo: 'texto', valor: (l) => l.arquivo, ordenavel: false, largura: 240 },
    { id: 'origem', titulo: 'Origem', tipo: 'texto', valor: (l) => ORIGENS[l.origem] ?? l.origem, ordenavel: false },
    { id: 'usuario', titulo: 'Usuário', tipo: 'texto', valor: (l) => l.usuario ?? 'Agendamento', ordenavel: false },
    { id: 'total_registros', titulo: 'Registros', tipo: 'numero', valor: (l) => l.total_registros, ordenavel: false },
    { id: 'total_importados', titulo: 'Importados', tipo: 'numero', valor: (l) => l.total_importados, ordenavel: false },
    { id: 'total_rejeitados', titulo: 'Rejeitados', tipo: 'numero', valor: (l) => l.total_rejeitados, ordenavel: false },
    { id: 'status', titulo: 'Situação', tipo: 'texto', valor: (l) => STATUS[l.status]?.rotulo ?? l.status, ordenavel: false,
      render: (l) => <Badge cor={STATUS[l.status]?.cor ?? 'neutro'}>{STATUS[l.status]?.rotulo ?? l.status}</Badge> },
  ]
  return (
    <Card>
      <CardContent className="pt-5">
        <div className="mb-3 flex flex-wrap gap-2" role="group" aria-label="Filtrar por situação">
          {(Object.keys(STATUS) as Status[]).map((k) => {
            const ativo = status.includes(k)
            return <button key={k} aria-pressed={ativo} onClick={() => setStatus((x) => (ativo ? x.filter((y) => y !== k) : [...x, k]))}
              className={cn('rounded-md border px-3 py-1.5 text-xs', ativo ? 'border-primary bg-accent text-accent-foreground' : 'hover:bg-muted')}>{STATUS[k].rotulo}</button>
          })}
        </div>
        <TabelaServidor<LinhaHistorico> endpoint="/importacoes" consulta={consulta} colunas={colunas} ordemPadrao={{ id: 'id', direcao: 'desc' }}
          placeholderPesquisa="Nome do arquivo…" podeExportar={pode('relatorios.exportar')} cabecalhoExportacao={cabecalho('Histórico de importações')}
          aoClicarLinha={(l) => setDetalhe(l.id)} />
      </CardContent>
      {detalhe !== null && <DetalheImportacao id={detalhe} aoFechar={() => setDetalhe(null)} aoAbrir={(id) => { setDetalhe(null); aoAbrir(id) }}
        aoMudar={() => cliente.invalidateQueries({ queryKey: ['/importacoes'] })} />}
    </Card>
  )
}

function DetalheImportacao({ id, aoFechar, aoAbrir, aoMudar }: { id: number; aoFechar: () => void; aoAbrir: (id: number) => void; aoMudar: () => void }) {
  const tipos = useTipos()
  const q = useQuery({ queryKey: ['importacao', id], queryFn: () => api<Importacao>(`/importacoes/${id}`) })
  const reprocessar = useMutation({ mutationFn: () => api<Importacao>(`/importacoes/${id}/reprocessar`, { method: 'POST' }), onSuccess: (r) => { aoMudar(); aoAbrir(r.id) } })
  const excluir = useMutation({ mutationFn: () => api(`/importacoes/${id}`, { method: 'DELETE' }), onSuccess: () => { aoMudar(); aoFechar() } })
  const i = q.data
  const tipo = tipos.data?.find((t) => t.codigo === i?.tipo)
  return (
    <Dialogo aberto aoFechar={aoFechar} titulo={i ? `Importação nº ${i.id} · ${i.tipo_titulo}` : 'Importação'} descricao={i ? `${i.arquivo} · ${ORIGENS[i.origem] ?? i.origem} · ${i.usuario ?? 'agendamento'} · ${dataHora(i.criada_em)}` : undefined}>
      {q.isLoading ? <div className="flex h-40 items-center justify-center"><Loader2 className="size-6 animate-spin text-primary" /></div> : q.isError ? <Erro erro={q.error} /> : i && (
        <div className="px-5 py-4">
          <div className="mb-3 flex flex-wrap items-center gap-2">
            <Badge cor={STATUS[i.status].cor}>{STATUS[i.status].rotulo}</Badge>
            {i.reprocessada_de_id && <span className="text-xs text-muted-foreground">Reprocessamento da importação nº {i.reprocessada_de_id}</span>}
            <span className="ml-auto flex gap-2">
              {['PENDENTE', 'VALIDADO'].includes(i.status) && <Button size="sm" variant="outline" onClick={() => aoAbrir(i.id)}>Continuar</Button>}
              {['CONCLUIDO', 'ERRO'].includes(i.status) && i.arquivo_disponivel && (
                <Button size="sm" variant="outline" disabled={reprocessar.isPending} onClick={() => reprocessar.mutate()}><RotateCcw /> Reprocessar</Button>
              )}
              {['PENDENTE', 'VALIDADO', 'ERRO'].includes(i.status) && i.total_importados === 0 && (
                <Button size="sm" variant="ghost" disabled={excluir.isPending} onClick={() => { if (window.confirm('Excluir esta importação e o arquivo enviado?')) excluir.mutate() }}><Trash2 /> Excluir</Button>
              )}
            </span>
          </div>
          {(reprocessar.isError || excluir.isError) && <p role="alert" className="mb-3 rounded-md border border-danger/40 bg-danger/10 p-2 text-xs">{mensagemErro(reprocessar.error ?? excluir.error)}</p>}
          {i.mensagem && <p className="mb-3 rounded-md border border-danger/40 bg-danger/10 p-2 text-xs">{i.mensagem}</p>}
          {i.resumo ? <ResumoValidacao imp={i} tipo={tipo} /> : <p className="text-xs text-muted-foreground">Ainda não validada.</p>}
          {i.status === 'CONCLUIDO' && <p className="mt-3 text-xs text-muted-foreground">Gravados {formatarNumero(i.total_importados)} de {formatarNumero(i.total_registros)} · concluída em {dataHora(i.concluida_em)}.</p>}
        </div>
      )}
    </Dialogo>
  )
}

/* ---------- Integrações ---------- */

interface Parametro { nome: string; rotulo: string; tipo: 'texto' | 'numero' | 'booleano' | 'json'; padrao: unknown; ajuda?: string }
interface LogSinc { id: number; status: string; registros_recebidos: number; registros_gravados: number; mensagem: string | null; iniciado_em: string; finalizado_em: string | null; usuario: string | null }
interface Integracao {
  codigo: string; nome: string; descricao: string; documentacao: string; ativa: boolean; ultima_sincronizacao_em: string | null; token_configurado: boolean
  definicao: Parametro[]; parametros: Record<string, unknown>; logs: LogSinc[]; em_execucao: boolean
}
const STATUS_SINC: Record<string, { rotulo: string; cor: 'neutro' | 'alerta' | 'sucesso' | 'perigo' }> = {
  NA_FILA: { rotulo: 'Na fila', cor: 'alerta' }, EXECUTANDO: { rotulo: 'Executando', cor: 'alerta' }, CONCLUIDO: { rotulo: 'Concluída', cor: 'sucesso' }, ERRO: { rotulo: 'Erro', cor: 'perigo' },
}

function Integracoes() {
  const q = useQuery({
    queryKey: ['integracoes'], queryFn: () => api<{ integracoes: Integracao[] }>('/integracoes').then((r) => r.integracoes),
    refetchInterval: (x) => (x.state.data?.some((i) => i.em_execucao) ? 3000 : false),
  })
  if (q.isLoading) return <div className="flex h-40 items-center justify-center"><Loader2 className="size-6 animate-spin text-primary" /></div>
  if (q.isError) return <Erro erro={q.error} />
  return (
    <>
      <div className="grid grid-cols-1 gap-4 2xl:grid-cols-2">
        {q.data!.map((i) => <CartaoIntegracao key={i.codigo} i={i} />)}
      </div>
      <Nota className="mt-4">As sincronizações rodam em segundo plano e as ativas são executadas diariamente às 2h (agendador do Laravel). Os registros coletados passam pela mesma validação da importação de arquivos e aparecem no histórico, com as inconsistências. Tokens de acesso ficam só no arquivo .env do servidor.</Nota>
    </>
  )
}

function CartaoIntegracao({ i }: { i: Integracao }) {
  const cliente = useQueryClient()
  const [valores, setValores] = useState<Record<string, unknown>>(i.parametros)
  const [ativa, setAtiva] = useState(i.ativa)
  const [de, setDe] = useState('')
  const [ate, setAte] = useState('')
  const [teste, setTeste] = useState<{ ok: boolean; mensagem: string } | null>(null)
  const atualizar = (r: { integracoes: Integracao[] }) => cliente.setQueryData(['integracoes'], r.integracoes)
  const salvar = useMutation({ mutationFn: () => api<{ integracoes: Integracao[] }>(`/integracoes/${i.codigo}`, { method: 'PUT', body: JSON.stringify({ ativa, parametros: valores }) }), onSuccess: atualizar })
  const testar = useMutation({ mutationFn: () => api<{ ok: boolean; mensagem: string }>(`/integracoes/${i.codigo}/testar`, { method: 'POST' }), onSuccess: setTeste, onError: (e) => setTeste({ ok: false, mensagem: mensagemErro(e) }) })
  const sincronizar = useMutation({
    mutationFn: () => api<{ integracoes: Integracao[] }>(`/integracoes/${i.codigo}/sincronizar`, { method: 'POST', body: JSON.stringify({ de: de || null, ate: ate || null }) }),
    onSuccess: (r) => { atualizar(r); cliente.invalidateQueries({ queryKey: ['/importacoes'] }) },
  })
  const texto = (v: unknown) => (typeof v === 'string' ? v : v === null || v === undefined ? '' : JSON.stringify(v, null, 2))

  return (
    <Card>
      <CardHeader>
        <div className="min-w-0">
          <CardTitle className="flex flex-wrap items-center gap-2"><PlugZap className="size-4 text-primary" />{i.nome}<Badge cor={i.ativa ? 'sucesso' : 'neutro'}>{i.ativa ? 'Ativa' : 'Inativa'}</Badge></CardTitle>
          <p className="mt-1 text-xs text-muted-foreground">{i.descricao}</p>
          <p className="mt-1 text-xs text-muted-foreground">Última sincronização: {dataHora(i.ultima_sincronizacao_em)}
            {i.documentacao && <> · <a href={i.documentacao} target="_blank" rel="noreferrer" className="inline-flex items-center gap-0.5 text-primary hover:underline">documentação da API <ExternalLink className="size-3" /></a></>}
            {i.codigo !== 'PNCP' && <> · token {i.token_configurado ? 'configurado' : 'não configurado (opcional)'}</>}</p>
        </div>
      </CardHeader>
      <CardContent className="space-y-3">
        <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); salvar.mutate() }}>
          <label className="flex items-center gap-2 text-xs"><Caixa marcado={ativa} aoMudar={setAtiva} rotulo="Integração ativa" />Ativa (entra na sincronização diária)</label>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            {i.definicao.map((p) => (
              <label key={p.nome} className={cn('block text-xs', (p.tipo === 'json' || p.nome === 'url' || p.nome === 'base_url') && 'sm:col-span-2')}>
                <span className="mb-1 block font-medium">{p.rotulo}</span>
                {p.tipo === 'booleano' ? (
                  <select className={classeSelect} value={valores[p.nome] ? '1' : '0'} onChange={(e) => setValores((v) => ({ ...v, [p.nome]: e.target.value === '1' }))}><option value="1">Sim</option><option value="0">Não</option></select>
                ) : p.tipo === 'json' ? (
                  <textarea className="min-h-[110px] w-full rounded-md border border-input bg-surface p-2 font-mono text-xs" value={texto(valores[p.nome])}
                    onChange={(e) => setValores((v) => ({ ...v, [p.nome]: e.target.value }))} spellCheck={false} />
                ) : (
                  <Input type={p.tipo === 'numero' ? 'number' : 'text'} value={texto(valores[p.nome])} onChange={(e) => setValores((v) => ({ ...v, [p.nome]: e.target.value }))} />
                )}
                {p.ajuda && <span className="mt-0.5 block text-[11px] text-muted-foreground">{p.ajuda}</span>}
              </label>
            ))}
          </div>
          {salvar.isError && <p role="alert" className="rounded-md border border-danger/40 bg-danger/10 p-2 text-xs">{mensagemErro(salvar.error)}</p>}
          {salvar.isSuccess && <p className="text-xs text-success">Configuração salva.</p>}
          <div className="flex flex-wrap gap-2">
            <Button type="submit" size="sm" disabled={salvar.isPending}><Save /> Salvar</Button>
            <Button type="button" size="sm" variant="outline" disabled={testar.isPending} onClick={() => testar.mutate()}>{testar.isPending ? <Loader2 className="animate-spin" /> : <PlugZap />} Testar conexão</Button>
          </div>
          {teste && <p role="status" className={cn('rounded-md border p-2 text-xs', teste.ok ? 'border-success/40 bg-success/10' : 'border-warning/40 bg-warning/10')}>{teste.mensagem}</p>}
        </form>
        <div className="flex flex-wrap items-end gap-2 border-t pt-3">
          <label className="text-xs"><span className="mb-1 block font-medium">De</span><Input type="date" className="h-8 w-40" value={de} onChange={(e) => setDe(e.target.value)} /></label>
          <label className="text-xs"><span className="mb-1 block font-medium">Até</span><Input type="date" className="h-8 w-40" value={ate} onChange={(e) => setAte(e.target.value)} /></label>
          <Button size="sm" disabled={sincronizar.isPending || i.em_execucao} onClick={() => sincronizar.mutate()}>
            {sincronizar.isPending || i.em_execucao ? <Loader2 className="animate-spin" /> : <Play />} Sincronizar agora
          </Button>
          <span className="text-[11px] text-muted-foreground">Sem datas: últimos {String(i.parametros.dias ?? 30)} dias.</span>
        </div>
        {sincronizar.isError && <p role="alert" className="rounded-md border border-danger/40 bg-danger/10 p-2 text-xs">{mensagemErro(sincronizar.error)}</p>}
        <MiniTabela vazio="Nenhuma sincronização ainda." cabecalho={['Início', 'Situação', 'Recebidos', 'Gravados', 'Resultado']} direita={[2, 3]}
          linhas={i.logs.map((l) => [dataHora(l.iniciado_em), <Badge key="s" cor={STATUS_SINC[l.status]?.cor ?? 'neutro'}>{STATUS_SINC[l.status]?.rotulo ?? l.status}</Badge>,
            formatarNumero(l.registros_recebidos), formatarNumero(l.registros_gravados), <span key="m" className="line-clamp-2" title={l.mensagem ?? ''}>{l.mensagem ?? '—'}</span>])} />
      </CardContent>
    </Card>
  )
}

