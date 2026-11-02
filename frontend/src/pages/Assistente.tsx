import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import { AlertTriangle, ArrowRight, Bot, Info, Lightbulb, Loader2, Send, Sparkles, Trash2 } from 'lucide-react'
import { paraQueryString, useFiltros } from '@/contexts/FiltrosContext'
import { api, ApiError } from '@/lib/api'
import type { NomeFiltro, Periodo } from '@/lib/tipos'
import { MODULOS } from '@/modulos'
import { Abas, CabecalhoModulo } from '@/components/compras/CabecalhoModulo'
import { BarraFiltros } from '@/components/filters/BarraFiltros'
import { Nota } from '@/components/inteligencia/partes'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { MiniTabela } from '@/components/detalhes/Partes'
import { formatarData, formatarMoeda, formatarNumero, formatarPercentual, formatarUnitario } from '@/lib/format'
import { cn } from '@/lib/utils'

type Formato = 'moeda' | 'unitario' | 'percentual' | 'numero' | 'data' | 'texto'
type Natureza = 'registrado' | 'estimativa' | 'sinalizacao'
interface Abrir { filtro: NomeFiltro; chave: string; rotulo: string }
interface Resposta {
  pergunta: string
  consulta: { codigo: string; titulo: string } | null
  interpretador: string
  resposta: string
  entendimento: string | null
  periodo: string | null
  sem_dados: boolean
  natureza: Natureza
  filtros: { filtro: string; rotulo: string; valor: string; origem: 'pergunta' | 'painel' | 'perfil' }[]
  indicadores: { codigo: string; nome: string; valor: number | null; formato: Formato; natureza: Natureza }[]
  tabela: { colunas: { chave: string; rotulo: string; formato: Formato }[]; linhas: (Record<string, string | number | null> & { _abrir?: Abrir | null })[] } | null
  fonte: string | null
  registros: { modulo: string; filtros: Abrir[]; periodo: Periodo | null } | null
  avisos: string[]
  sugestoes?: string[]
}
interface Configuracao {
  provedor: string
  modelo: string | null
  descricao: string
  consultas: { codigo: string; titulo: string; descricao: string; exemplo: string }[]
  aviso: string
}
interface Alerta { codigo: string; severidade: 'alta' | 'media' | 'baixa'; titulo: string; quantidade: number; descricao: string; recomendacao: string; modulo: string }
interface Alertas { gerado_em: string; periodo: string; alertas: Alerta[]; aviso: string }

const NATUREZA: Record<Natureza, { rotulo: string; cor: 'sucesso' | 'alerta' | 'primario'; dica: string }> = {
  registrado: { rotulo: 'Registrado', cor: 'sucesso', dica: 'Valor efetivamente registrado nos processos, atas ou contratos.' },
  estimativa: { rotulo: 'Estimativa', cor: 'primario', dica: 'Derivado do valor estimado da contratação; não é valor pago.' },
  sinalizacao: { rotulo: 'Sinalização', cor: 'alerta', dica: 'Alerta para análise humana; não é conclusão sobre irregularidade.' },
}
const SEVERIDADE = { alta: { rotulo: 'Prioridade alta', cor: 'perigo' }, media: { rotulo: 'Prioridade média', cor: 'alerta' }, baixa: { rotulo: 'Prioridade baixa', cor: 'neutro' } } as const
const INTERPRETADOR: Record<string, string> = { regras: 'Regras locais', ollama: 'Modelo local (Ollama)', openai: 'API externa', anthropic: 'API externa' }
const HISTORICO = 'sic-assistente-historico'

function formatar(v: string | number | null | undefined, f: Formato): string {
  if (f === 'moeda') return formatarMoeda(v)
  if (f === 'unitario') return formatarUnitario(v)
  if (f === 'percentual') return formatarPercentual(v)
  if (f === 'numero') return formatarNumero(v)
  if (f === 'data') return formatarData(v === null || v === undefined ? null : String(v))
  return v === null || v === undefined || v === '' ? 'Não informado' : String(v)
}

const nomeModulo = (slug: string) => MODULOS.find((m) => m.slug === slug)?.nome ?? slug

function lerHistorico(): Resposta[] {
  try { return JSON.parse(sessionStorage.getItem(HISTORICO) ?? '[]') as Resposta[] } catch { return [] }
}

/** Aplica os filtros da resposta (somados aos do painel) e abre o módulo com os registros. */
function useAbrirRegistros() {
  const { estado, definirSelecao, definirPeriodo } = useFiltros()
  const navegar = useNavigate()
  return (modulo: string, filtros: Abrir[], periodo: Periodo | null) => {
    const porFiltro = new Map<NomeFiltro, Abrir[]>()
    filtros.forEach((f) => porFiltro.set(f.filtro, [...(porFiltro.get(f.filtro) ?? []), f]))
    if (periodo) definirPeriodo(periodo)
    porFiltro.forEach((lista, filtro) => {
      const atuais = filtro === 'tempo' || filtro === 'unidade' || filtro === 'classe' || filtro === 'item' ? [] : (estado.selecoes[filtro] ?? [])
      definirSelecao(filtro, [...new Set([...atuais, ...lista.map((l) => l.chave)])], Object.fromEntries(lista.map((l) => [l.chave, l.rotulo])))
    })
    navegar(`/${modulo}`)
  }
}

function CartaoResposta({ r }: { r: Resposta }) {
  const abrir = useAbrirRegistros()
  const natureza = NATUREZA[r.natureza] ?? NATUREZA.registrado
  const tabela = r.tabela
  const comAbrir = !!tabela?.linhas.some((l) => l._abrir) && !!r.registros

  return (
    <Card>
      <CardHeader className="flex-col items-start gap-2">
        <p className="text-sm font-semibold">{r.pergunta}</p>
        <div className="flex flex-wrap gap-1.5">
          {r.consulta && <Badge cor="primario">{r.consulta.titulo}</Badge>}
          {!r.sem_dados && <Badge cor={natureza.cor} title={natureza.dica}>{natureza.rotulo}</Badge>}
          {r.sem_dados && <Badge cor="neutro">Sem dados suficientes</Badge>}
          <Badge cor="neutro" title="Como a pergunta foi interpretada. Os números vêm sempre das consultas do sistema.">
            <Bot className="size-3" /> {INTERPRETADOR[r.interpretador] ?? r.interpretador}
          </Badge>
        </div>
      </CardHeader>
      <CardContent className="space-y-4">
        <p className={cn('text-[15px] leading-relaxed', r.sem_dados && 'text-muted-foreground')}>{r.resposta}</p>

        {r.indicadores.length > 0 && (
          <dl className="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-4">
            {r.indicadores.map((i) => (
              <div key={i.codigo} className="rounded-md border px-3 py-2">
                <dt className="flex items-center justify-between gap-2 text-[11px] text-muted-foreground">
                  <span>{i.nome}</span>
                  <span title={NATUREZA[i.natureza]?.dica} className="shrink-0 rounded bg-muted px-1 text-[10px]">{NATUREZA[i.natureza]?.rotulo}</span>
                </dt>
                <dd className="mt-0.5 text-base font-semibold tabular">{formatar(i.valor, i.formato)}</dd>
              </div>
            ))}
          </dl>
        )}

        {tabela && tabela.linhas.length > 0 && (
          <div>
            {comAbrir && <p className="mb-1.5 text-[11px] text-muted-foreground">Clique em uma linha para abrir os registros dela em {nomeModulo(r.registros!.modulo)}.</p>}
            <MiniTabela
              cabecalho={tabela.colunas.map((c) => c.rotulo)}
              direita={tabela.colunas.map((c, i) => (['moeda', 'unitario', 'percentual', 'numero'].includes(c.formato) ? i : -1)).filter((i) => i >= 0)}
              linhas={tabela.linhas.map((l) => tabela.colunas.map((c) => formatar(l[c.chave], c.formato)))}
              aoClicar={comAbrir ? (i) => {
                const l = tabela.linhas[i]
                if (l._abrir && r.registros) abrir(r.registros.modulo, [...r.registros.filtros.filter((f) => f.filtro !== l._abrir!.filtro), l._abrir], r.registros.periodo)
              } : undefined}
            />
          </div>
        )}

        <dl className="grid grid-cols-1 gap-x-6 gap-y-2 text-xs sm:grid-cols-2">
          <div><dt className="text-muted-foreground">O que foi entendido</dt><dd className="font-medium">{r.entendimento ?? 'Pergunta não relacionada a uma consulta'}</dd></div>
          <div><dt className="text-muted-foreground">Período analisado</dt><dd className="font-medium">{r.periodo ?? 'Não se aplica'}</dd></div>
          <div className="sm:col-span-2">
            <dt className="text-muted-foreground">Filtros utilizados</dt>
            <dd className="mt-1 flex flex-wrap gap-1.5">
              {r.filtros.length === 0 && <span className="font-medium">Nenhum (todos os dados do seu acesso)</span>}
              {r.filtros.map((f, i) => (
                <Badge key={i} cor={f.origem === 'pergunta' ? 'primario' : 'neutro'} title={f.origem === 'pergunta' ? 'Extraído da pergunta' : f.origem === 'painel' ? 'Filtro ativo no painel' : 'Restrição do seu perfil'}>
                  {f.rotulo}: {f.valor}
                </Badge>
              ))}
            </dd>
          </div>
          {r.fonte && <div className="sm:col-span-2"><dt className="text-muted-foreground">Fonte dos dados</dt><dd>{r.fonte}</dd></div>}
        </dl>

        {r.sugestoes && r.sugestoes.length > 0 && (
          <div>
            <p className="mb-1.5 text-xs text-muted-foreground">Exemplos de perguntas que o assistente responde:</p>
            <ul className="list-disc space-y-0.5 pl-5 text-xs">{r.sugestoes.map((s) => <li key={s}>{s}</li>)}</ul>
          </div>
        )}

        {r.avisos.length > 0 && (
          <ul className="space-y-1">
            {r.avisos.map((a) => (
              <li key={a} className="flex gap-1.5 text-[11px] text-muted-foreground"><Info className="mt-0.5 size-3.5 shrink-0" />{a}</li>
            ))}
          </ul>
        )}

        {r.registros && !r.sem_dados && (
          <Button variant="outline" size="sm" onClick={() => abrir(r.registros!.modulo, r.registros!.filtros, r.registros!.periodo)}>
            Abrir registros em {nomeModulo(r.registros.modulo)} <ArrowRight />
          </Button>
        )}
      </CardContent>
    </Card>
  )
}

function Perguntar({ consulta, config }: { consulta: string; config?: Configuracao }) {
  const { totalAtivos } = useFiltros()
  const [texto, setTexto] = useState('')
  const [historico, setHistorico] = useState<Resposta[]>(lerHistorico)
  useEffect(() => {
    try { sessionStorage.setItem(HISTORICO, JSON.stringify(historico.slice(0, 20))) } catch { /* armazenamento indisponível */ }
  }, [historico])

  const m = useMutation({
    mutationFn: (pergunta: string) => api<Resposta>(`/ia/perguntar?${consulta}`, { method: 'POST', body: JSON.stringify({ pergunta }) }),
    onSuccess: (r) => { setHistorico((h) => [r, ...h].slice(0, 20)); setTexto('') },
  })
  const enviar = (p: string) => { if (p.trim().length >= 3 && !m.isPending) m.mutate(p.trim()) }
  const aoEnviar = (e: FormEvent) => { e.preventDefault(); enviar(texto) }

  return (
    <div className="space-y-4">
      <Card>
        <CardContent className="space-y-3 pt-5">
          <form onSubmit={aoEnviar} className="flex flex-col gap-2 sm:flex-row">
            <label htmlFor="pergunta" className="sr-only">Pergunta</label>
            <textarea id="pergunta" value={texto} onChange={(e) => setTexto(e.target.value)} maxLength={500} rows={2}
              onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); enviar(texto) } }}
              placeholder="Ex.: Quanto o Governo de Roraima comprou de material de informática em 2025?"
              className="min-h-[44px] flex-1 resize-y rounded-md border border-input bg-surface px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring" />
            <Button type="submit" disabled={m.isPending || texto.trim().length < 3} className="sm:self-start">
              {m.isPending ? <Loader2 className="animate-spin" /> : <Send />} Perguntar
            </Button>
          </form>
          {m.isError && <p className="text-xs text-danger">{m.error instanceof ApiError ? m.error.message : 'Não foi possível consultar.'}</p>}
          <p className="flex items-start gap-1.5 text-[11px] text-muted-foreground">
            <Sparkles className="mt-0.5 size-3.5 shrink-0" />
            <span>
              {config?.descricao ?? 'Carregando configuração…'}
              {totalAtivos > 0 && <> As respostas consideram os {totalAtivos} filtro(s) ativo(s) do painel, além do que a pergunta citar.</>}
            </span>
          </p>
          {config && (
            <div className="flex flex-wrap gap-1.5">
              {config.consultas.map((c) => (
                <button key={c.codigo} type="button" onClick={() => enviar(c.exemplo)} disabled={m.isPending} title={c.descricao}
                  className="rounded-full border px-2.5 py-1 text-left text-[11px] text-muted-foreground transition-colors hover:border-primary hover:text-foreground disabled:opacity-50">
                  {c.exemplo}
                </button>
              ))}
            </div>
          )}
        </CardContent>
      </Card>

      {historico.length > 0 && (
        <div className="flex justify-end">
          <Button variant="ghost" size="sm" onClick={() => setHistorico([])}><Trash2 /> Limpar histórico</Button>
        </div>
      )}
      {historico.map((r, i) => <CartaoResposta key={`${historico.length - i}-${r.pergunta}`} r={r} />)}
      {historico.length === 0 && !m.isPending && (
        <p className="py-8 text-center text-sm text-muted-foreground">Faça uma pergunta ou escolha um dos exemplos acima.</p>
      )}
    </div>
  )
}

function PainelAlertas({ consulta }: { consulta: string }) {
  const abrir = useAbrirRegistros()
  const q = useQuery({ queryKey: ['ia-alertas', consulta], queryFn: () => api<Alertas>(`/ia/alertas?${consulta}`) })
  if (q.isLoading) return <div className="flex h-40 items-center justify-center"><Loader2 className="size-6 animate-spin text-primary" /></div>
  if (q.isError || !q.data) return <p className="rounded-md border border-danger/40 bg-danger/10 p-3 text-xs">Não foi possível gerar os alertas.</p>
  const d = q.data

  return (
    <div className="space-y-3">
      <p className="text-xs text-muted-foreground">Análise automática do recorte ({d.periodo}), gerada em {formatarData(d.gerado_em)} com os parâmetros da Administração.</p>
      {d.alertas.length === 0 && <p className="py-8 text-center text-sm text-muted-foreground">Nenhum alerta no recorte atual.</p>}
      <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
        {d.alertas.map((a) => (
          <Card key={a.codigo}>
            <CardHeader className="items-start">
              <div className="min-w-0">
                <Badge cor={SEVERIDADE[a.severidade].cor}><AlertTriangle className="size-3" />{SEVERIDADE[a.severidade].rotulo}</Badge>
                <CardTitle className="mt-1.5">{a.titulo}</CardTitle>
              </div>
              <span className="shrink-0 text-2xl font-semibold tabular">{formatarNumero(a.quantidade)}</span>
            </CardHeader>
            <CardContent className="space-y-2 text-sm">
              <p className="text-muted-foreground">{a.descricao}</p>
              <p className="flex gap-1.5"><Lightbulb className="mt-0.5 size-4 shrink-0 text-primary" /><span><span className="font-medium">Recomendação: </span>{a.recomendacao}</span></p>
              <Button variant="outline" size="sm" onClick={() => abrir(a.modulo, [], null)}>Ver em {nomeModulo(a.modulo)} <ArrowRight /></Button>
            </CardContent>
          </Card>
        ))}
      </div>
      <Nota>{d.aviso}</Nota>
    </div>
  )
}

/** Etapa 6 — Assistente em linguagem natural, alertas e recomendações gerenciais (seção 32). */
export function Assistente() {
  const { estado } = useFiltros()
  const consulta = paraQueryString(estado)
  const [aba, setAba] = useState<'perguntar' | 'alertas'>('perguntar')
  const config = useQuery({ queryKey: ['ia-config'], queryFn: () => api<Configuracao>('/ia'), staleTime: 5 * 60_000 })

  return (
    <div>
      <CabecalhoModulo titulo="Assistente de consultas" semPeriodo consulta={consulta}
        descricao="Perguntas em linguagem natural respondidas com os dados do sistema, além de alertas e recomendações automáticos." />
      <BarraFiltros />
      <Abas abas={[{ id: 'perguntar', rotulo: 'Perguntar' }, { id: 'alertas', rotulo: 'Alertas e recomendações' }]} ativa={aba} aoMudar={setAba} />
      {aba === 'perguntar' ? <Perguntar consulta={consulta} config={config.data} /> : <PainelAlertas consulta={consulta} />}
      {aba === 'perguntar' && config.data && <Nota className="mt-4">{config.data.aviso}</Nota>}
    </div>
  )
}
