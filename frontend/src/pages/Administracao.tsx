import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Loader2, Pencil, Plus, Save, Search, Trash2 } from 'lucide-react'
import { useAuth } from '@/contexts/AuthContext'
import { api, ApiError } from '@/lib/api'
import { Abas, CabecalhoModulo } from '@/components/compras/CabecalhoModulo'
import { Nota, useCabecalhoExportacao } from '@/components/inteligencia/partes'
import { TabelaServidor } from '@/components/tabela/TabelaServidor'
import { TabelaAvancada } from '@/components/tabela/TabelaAvancada'
import type { Coluna } from '@/components/tabela/colunas'
import { Card, CardContent } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Caixa } from '@/components/ui/checkbox'
import { Dialogo } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Erro, Numeros } from '@/components/detalhes/Partes'
import { formatarNumero } from '@/lib/format'
import { cn } from '@/lib/utils'

interface Opcao { valor: string | number; rotulo: string }
interface Campo { nome: string; rotulo: string; tipo: 'texto' | 'numero' | 'booleano' | 'enum' | 'ref'; obrigatorio: boolean; opcoes: Opcao[] | null }
interface Recurso { recurso: string; titulo: string; grupo: string; criar: boolean; excluir: boolean; somente_leitura: string[]; campos: Campo[] }
interface PainelAdmin {
  cadastros: Recurso[]; usuarios: boolean; logs: boolean; importacoes: boolean
  resumo: Record<'usuarios' | 'usuarios_ativos' | 'orgaos' | 'unidades' | 'fornecedores' | 'integracoes_ativas', number>
}
type Registro = { id: number } & Record<string, string | number | boolean | null>
type Aba = 'cadastros' | 'usuarios' | 'perfis' | 'logs'

const mensagemErro = (e: unknown) => {
  if (e instanceof ApiError && e.erros) return Object.values(e.erros).flat().join(' ')
  return (e as Error)?.message ?? 'Erro ao salvar.'
}

function Rotulo({ texto, obrigatorio, children }: { texto: string; obrigatorio?: boolean; children: ReactNode }) {
  return (
    <label className="block text-xs">
      <span className="mb-1 block font-medium">{texto}{obrigatorio && <span className="text-danger"> *</span>}</span>
      {children}
    </label>
  )
}

const classeSelect = 'h-9 w-full rounded-md border border-input bg-surface px-2 text-sm'

/** Módulo 19 — Administração do sistema (seção 29): cadastros, classificações, parâmetros, usuários, perfis e logs. */
export function Administracao() {
  const q = useQuery({ queryKey: ['admin'], queryFn: () => api<PainelAdmin>('/admin') })
  const [aba, setAba] = useState<Aba>('cadastros')
  const abas = useMemo(() => {
    const d = q.data
    if (!d) return []
    return ([
      d.cadastros.length ? { id: 'cadastros', rotulo: 'Cadastros e parâmetros' } : null,
      d.usuarios ? { id: 'usuarios', rotulo: 'Usuários' } : null,
      d.usuarios ? { id: 'perfis', rotulo: 'Perfis e permissões' } : null,
      d.logs ? { id: 'logs', rotulo: 'Logs de auditoria' } : null,
    ].filter(Boolean) as { id: Aba; rotulo: string }[])
  }, [q.data])
  useEffect(() => { if (abas.length && !abas.some((a) => a.id === aba)) setAba(abas[0].id) }, [abas, aba])

  return (
    <div>
      <CabecalhoModulo titulo="Administração" descricao="Cadastros, classificações, parâmetros, usuários, perfis de acesso e auditoria." consulta="" semPeriodo />
      {q.isLoading ? <div className="flex h-40 items-center justify-center"><Loader2 className="size-6 animate-spin text-primary" /></div>
        : q.isError ? <Erro erro={q.error} />
        : q.data && (
          <>
            <Card className="mb-4">
              <CardContent className="pt-5">
                <Numeros itens={[
                  ['Usuários', `${formatarNumero(q.data.resumo.usuarios_ativos)} ativos de ${formatarNumero(q.data.resumo.usuarios)}`],
                  ['Órgãos', formatarNumero(q.data.resumo.orgaos)], ['Unidades compradoras', formatarNumero(q.data.resumo.unidades)],
                  ['Fornecedores', formatarNumero(q.data.resumo.fornecedores)], ['Integrações ativas', formatarNumero(q.data.resumo.integracoes_ativas)],
                ]} />
              </CardContent>
            </Card>
            {abas.length === 0 ? <Nota>Seu perfil não tem permissões administrativas.</Nota> : (
              <>
                <Abas<Aba> abas={abas} ativa={aba} aoMudar={setAba} />
                {aba === 'cadastros' && <Cadastros recursos={q.data.cadastros} />}
                {aba === 'usuarios' && <Usuarios />}
                {aba === 'perfis' && <Perfis />}
                {aba === 'logs' && <Logs />}
              </>
            )}
          </>
        )}
      <Nota className="mt-4">Toda inclusão, alteração e exclusão fica registrada no histórico de alterações e nos logs de auditoria, com o usuário, a data e os valores anterior e novo.</Nota>
    </div>
  )
}

/* ---------- Cadastros genéricos ---------- */

function Cadastros({ recursos }: { recursos: Recurso[] }) {
  const [sel, setSel] = useState(recursos[0].recurso)
  const r = recursos.find((x) => x.recurso === sel) ?? recursos[0]
  const grupos = useMemo(() => Object.entries(recursos.reduce<Record<string, Recurso[]>>((a, x) => ({ ...a, [x.grupo]: [...(a[x.grupo] ?? []), x] }), {})), [recursos])
  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-[240px_minmax(0,1fr)]">
      <Card className="self-start">
        <CardContent className="p-2">
          <label className="block p-2 lg:hidden">
            <span className="sr-only">Cadastro</span>
            <select className={classeSelect} value={sel} onChange={(e) => setSel(e.target.value)}>
              {grupos.map(([g, xs]) => <optgroup key={g} label={g}>{xs.map((x) => <option key={x.recurso} value={x.recurso}>{x.titulo}</option>)}</optgroup>)}
            </select>
          </label>
          <nav className="hidden lg:block" aria-label="Cadastros">
            {grupos.map(([g, xs]) => (
              <div key={g} className="mb-2">
                <p className="px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{g}</p>
                {xs.map((x) => (
                  <button key={x.recurso} onClick={() => setSel(x.recurso)} aria-current={sel === x.recurso}
                    className={cn('block w-full rounded-md px-3 py-1.5 text-left text-sm', sel === x.recurso ? 'bg-accent text-accent-foreground' : 'hover:bg-muted')}>{x.titulo}</button>
                ))}
              </div>
            ))}
          </nav>
        </CardContent>
      </Card>
      <TabelaCadastro key={r.recurso} r={r} />
    </div>
  )
}

function textoCampo(c: Campo, l: Registro): string {
  const v = l[c.nome]
  if (v === null || v === undefined || v === '') return '—'
  if (c.tipo === 'booleano') return v ? 'Sim' : 'Não'
  if (c.tipo === 'ref') return String(l[`${c.nome}_rotulo`] ?? v)
  if (c.tipo === 'enum') return c.opcoes?.find((o) => String(o.valor) === String(v))?.rotulo ?? String(v)
  return String(v)
}

function TabelaCadastro({ r }: { r: Recurso }) {
  const { pode } = useAuth()
  const cabecalho = useCabecalhoExportacao()
  const cliente = useQueryClient()
  const endpoint = `/admin/cadastros/${r.recurso}`
  const [editando, setEditando] = useState<Registro | 'novo' | null>(null)
  const opcoes = useQuery({
    queryKey: ['admin-opcoes', r.recurso],
    queryFn: () => api<{ opcoes: Record<string, Opcao[]> | null }>(`${endpoint}?com_opcoes=1&por_pagina=5`).then((x) => x.opcoes ?? {}),
    enabled: r.campos.some((c) => c.tipo === 'ref'), staleTime: 60_000,
  })
  const excluir = useMutation({
    mutationFn: (id: number) => api(`${endpoint}/${id}`, { method: 'DELETE' }),
    onSuccess: () => cliente.invalidateQueries({ queryKey: [endpoint] }),
    onError: (e) => window.alert(mensagemErro(e)),
  })
  const colunas = useMemo<Coluna<Registro>[]>(() => [
    ...r.campos.map((c): Coluna<Registro> => ({
      id: c.nome, titulo: c.rotulo, tipo: c.tipo === 'numero' ? 'numero' : 'texto', ordenavel: false,
      valor: (l) => (c.tipo === 'numero' ? (l[c.nome] as number | null) : textoCampo(c, l)), texto: c.tipo === 'numero' ? undefined : (l) => textoCampo(c, l),
      largura: c.nome === 'valor' || c.nome === 'nome' || c.nome === 'descricao' || c.nome === 'razao_social' ? 240 : undefined,
    })),
    { id: 'acoes', titulo: 'Ações', tipo: 'texto', ordenavel: false, valor: () => '',
      render: (l) => (
        <span className="flex gap-1">
          <Button variant="ghost" size="icon" className="size-7" aria-label="Editar" onClick={(e) => { e.stopPropagation(); setEditando(l) }}><Pencil /></Button>
          {r.excluir && <Button variant="ghost" size="icon" className="size-7" aria-label="Excluir" onClick={(e) => {
            e.stopPropagation()
            if (window.confirm('Excluir este registro? A exclusão é registrada nos logs de auditoria.')) excluir.mutate(l.id)
          }}><Trash2 /></Button>}
        </span>
      ) },
  ], [r, excluir])

  return (
    <Card className="min-w-0">
      <CardContent className="pt-5">
        <TabelaServidor<Registro> endpoint={endpoint} consulta="" colunas={colunas} colunasExportacao={colunas.slice(0, -1)} ordemPadrao={{ id: 'id', direcao: 'asc' }}
          placeholderPesquisa={`Pesquisar em ${r.titulo.toLowerCase()}…`} podeExportar={pode('relatorios.exportar')} cabecalhoExportacao={cabecalho(r.titulo)}
          aoClicarLinha={(l) => setEditando(l)}
          acoes={r.criar ? <Button size="sm" onClick={() => setEditando('novo')}><Plus /> Novo</Button> : undefined} />
      </CardContent>
      {editando && (
        <FormCadastro r={r} registro={editando === 'novo' ? null : editando} opcoes={opcoes.data ?? {}} aoFechar={() => setEditando(null)}
          aoSalvar={() => { setEditando(null); cliente.invalidateQueries({ queryKey: [endpoint] }); cliente.invalidateQueries({ queryKey: ['admin'] }) }} />
      )}
    </Card>
  )
}

function FormCadastro({ r, registro, opcoes, aoFechar, aoSalvar }: {
  r: Recurso; registro: Registro | null; opcoes: Record<string, Opcao[]>; aoFechar: () => void; aoSalvar: () => void
}) {
  const [valores, setValores] = useState<Record<string, string | number | boolean | null>>(() =>
    Object.fromEntries(r.campos.map((c) => [c.nome, registro ? registro[c.nome] ?? null : c.tipo === 'booleano' ? true : null])))
  const salvar = useMutation({
    mutationFn: () => api(`/admin/cadastros/${r.recurso}${registro ? `/${registro.id}` : ''}`, {
      method: registro ? 'PUT' : 'POST',
      body: JSON.stringify(Object.fromEntries(r.campos.filter((c) => !r.somente_leitura.includes(c.nome)).map((c) => [c.nome, valores[c.nome] === '' ? null : valores[c.nome]]))),
    }),
    onSuccess: aoSalvar,
  })
  const definir = (n: string, v: string | number | boolean | null) => setValores((x) => ({ ...x, [n]: v }))

  return (
    <Dialogo aberto aoFechar={aoFechar} className="max-w-2xl" titulo={`${registro ? 'Editar' : 'Novo'} · ${r.titulo}`} descricao="Campos com * são obrigatórios.">
      <form className="space-y-3 px-5 py-4" onSubmit={(e) => { e.preventDefault(); salvar.mutate() }}>
        {r.campos.map((c) => {
          const v = valores[c.nome]
          const bloqueado = r.somente_leitura.includes(c.nome)
          if (c.tipo === 'booleano') {
            return <label key={c.nome} className="flex items-center gap-2 text-xs"><Caixa marcado={!!v} aoMudar={(x) => definir(c.nome, x)} rotulo={c.rotulo} />{c.rotulo}</label>
          }
          const lista = c.tipo === 'enum' ? c.opcoes ?? [] : c.tipo === 'ref' ? opcoes[c.nome] ?? [] : null
          return (
            <Rotulo key={c.nome} texto={c.rotulo} obrigatorio={c.obrigatorio && !bloqueado}>
              {lista ? (
                <select className={classeSelect} value={v === null ? '' : String(v)} disabled={bloqueado} required={c.obrigatorio}
                  onChange={(e) => definir(c.nome, e.target.value === '' ? null : c.tipo === 'ref' ? Number(e.target.value) : e.target.value)}>
                  <option value="">—</option>
                  {lista.map((o) => <option key={o.valor} value={String(o.valor)}>{o.rotulo}</option>)}
                </select>
              ) : (
                <Input type={c.tipo === 'numero' ? 'number' : 'text'} value={v === null ? '' : String(v)} disabled={bloqueado} required={c.obrigatorio && !bloqueado}
                  onChange={(e) => definir(c.nome, c.tipo === 'numero' ? (e.target.value === '' ? null : Number(e.target.value)) : e.target.value)} />
              )}
            </Rotulo>
          )
        })}
        {salvar.isError && <p role="alert" className="rounded-md border border-danger/40 bg-danger/10 p-2 text-xs">{mensagemErro(salvar.error)}</p>}
        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="outline" onClick={aoFechar}>Cancelar</Button>
          <Button type="submit" disabled={salvar.isPending}>{salvar.isPending ? <Loader2 className="animate-spin" /> : <Save />} Salvar</Button>
        </div>
      </form>
    </Dialogo>
  )
}

/* ---------- Usuários ---------- */

interface UsuarioAdmin {
  id: number; nome: string; email: string; ativo: boolean; ultimo_acesso_em: string | null; perfil_id: number | null; perfil: string | null
  restrito_por_orgao: boolean; orgaos: { id: number; sigla: string | null; nome: string }[]
}
interface PerfilOpcao { id: number; codigo: string; nome: string; restrito_por_orgao: boolean }
interface RespUsuarios { usuarios: UsuarioAdmin[]; perfis: PerfilOpcao[]; orgaos: { id: number; sigla: string | null; nome: string }[] }

function Usuarios() {
  const cabecalho = useCabecalhoExportacao()
  const { pode } = useAuth()
  const cliente = useQueryClient()
  const [busca, setBusca] = useState('')
  const q = useQuery({ queryKey: ['admin-usuarios'], queryFn: () => api<RespUsuarios>('/admin/usuarios') })
  const [editando, setEditando] = useState<UsuarioAdmin | 'novo' | null>(null)
  const colunas: Coluna<UsuarioAdmin>[] = [
    { id: 'nome', titulo: 'Nome', tipo: 'texto', valor: (l) => l.nome, fixa: true, largura: 180 },
    { id: 'email', titulo: 'E-mail', tipo: 'texto', valor: (l) => l.email },
    { id: 'perfil', titulo: 'Perfil', tipo: 'texto', valor: (l) => l.perfil },
    { id: 'orgaos', titulo: 'Órgãos', tipo: 'texto', valor: (l) => (l.restrito_por_orgao ? l.orgaos.map((o) => o.sigla ?? o.nome).join(', ') : 'Todos'), ordenavel: false },
    { id: 'ativo', titulo: 'Situação', tipo: 'texto', valor: (l) => (l.ativo ? 'Ativo' : 'Inativo'), render: (l) => <Badge cor={l.ativo ? 'sucesso' : 'neutro'}>{l.ativo ? 'Ativo' : 'Inativo'}</Badge> },
    { id: 'ultimo_acesso_em', titulo: 'Último acesso', tipo: 'texto', valor: (l) => l.ultimo_acesso_em?.slice(0, 16).replace('T', ' ') ?? 'Nunca' },
  ]
  const t = busca.trim().toLowerCase()
  const dados = (q.data?.usuarios ?? []).filter((u) => !t || u.nome.toLowerCase().includes(t) || u.email.toLowerCase().includes(t))

  return (
    <Card>
      <CardContent className="pt-5">
        {q.isLoading ? <div className="flex h-24 items-center justify-center"><Loader2 className="size-5 animate-spin text-primary" /></div>
          : q.isError ? <Erro erro={q.error} />
          : (
            <TabelaAvancada<UsuarioAdmin> colunas={colunas} dados={dados} podeExportar={pode('relatorios.exportar')} cabecalhoExportacao={cabecalho('Usuários do sistema')}
              aoClicarLinha={(l) => setEditando(l)}
              acoes={<div className="flex items-center gap-2">
                <div className="relative"><Search className="absolute left-2.5 top-2.5 size-4 text-muted-foreground" /><Input className="h-9 w-56 pl-8" placeholder="Nome ou e-mail…" value={busca} onChange={(e) => setBusca(e.target.value)} aria-label="Pesquisar usuários" /></div>
                <Button size="sm" onClick={() => setEditando('novo')}><Plus /> Novo usuário</Button>
              </div>} />
          )}
      </CardContent>
      {editando && q.data && (
        <FormUsuario u={editando === 'novo' ? null : editando} perfis={q.data.perfis} orgaos={q.data.orgaos} aoFechar={() => setEditando(null)}
          aoSalvar={() => { setEditando(null); cliente.invalidateQueries({ queryKey: ['admin-usuarios'] }); cliente.invalidateQueries({ queryKey: ['admin'] }) }} />
      )}
    </Card>
  )
}

function FormUsuario({ u, perfis, orgaos, aoFechar, aoSalvar }: {
  u: UsuarioAdmin | null; perfis: PerfilOpcao[]; orgaos: RespUsuarios['orgaos']; aoFechar: () => void; aoSalvar: () => void
}) {
  const [nome, setNome] = useState(u?.nome ?? '')
  const [email, setEmail] = useState(u?.email ?? '')
  const [perfilId, setPerfilId] = useState<number | ''>(u?.perfil_id ?? '')
  const [ativo, setAtivo] = useState(u?.ativo ?? true)
  const [senha, setSenha] = useState('')
  const [sel, setSel] = useState<number[]>(u?.orgaos.map((o) => o.id) ?? [])
  const perfil = perfis.find((p) => p.id === perfilId)
  const salvar = useMutation({
    mutationFn: () => api(`/admin/usuarios${u ? `/${u.id}` : ''}`, {
      method: u ? 'PUT' : 'POST',
      body: JSON.stringify({ nome, email, perfil_id: perfilId || null, ativo, orgaos: perfil?.restrito_por_orgao ? sel : [], senha: senha || null }),
    }),
    onSuccess: aoSalvar,
  })
  return (
    <Dialogo aberto aoFechar={aoFechar} className="max-w-2xl" titulo={u ? `Editar usuário · ${u.nome}` : 'Novo usuário'} descricao="A senha precisa ter ao menos 8 caracteres, com letras e números.">
      <form className="space-y-3 px-5 py-4" onSubmit={(e) => { e.preventDefault(); salvar.mutate() }}>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Rotulo texto="Nome" obrigatorio><Input value={nome} onChange={(e) => setNome(e.target.value)} required /></Rotulo>
          <Rotulo texto="E-mail" obrigatorio><Input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required /></Rotulo>
          <Rotulo texto="Perfil de acesso" obrigatorio>
            <select className={classeSelect} value={perfilId} onChange={(e) => setPerfilId(e.target.value ? Number(e.target.value) : '')} required>
              <option value="">—</option>
              {perfis.map((p) => <option key={p.id} value={p.id}>{p.nome}</option>)}
            </select>
          </Rotulo>
          <Rotulo texto={u ? 'Nova senha (deixe em branco para manter)' : 'Senha'} obrigatorio={!u}>
            <Input type="password" autoComplete="new-password" value={senha} onChange={(e) => setSenha(e.target.value)} required={!u} />
          </Rotulo>
        </div>
        <label className="flex items-center gap-2 text-xs"><Caixa marcado={ativo} aoMudar={setAtivo} rotulo="Usuário ativo" />Usuário ativo</label>
        {perfil?.restrito_por_orgao && (
          <fieldset>
            <legend className="mb-1 text-xs font-medium">Órgãos que o usuário pode ver <span className="text-danger">*</span></legend>
            <div className="grid max-h-48 grid-cols-1 gap-1 overflow-auto rounded-md border p-2 sm:grid-cols-2">
              {orgaos.map((o) => (
                <label key={o.id} className="flex items-center gap-2 text-xs">
                  <Caixa marcado={sel.includes(o.id)} aoMudar={(x) => setSel((s) => (x ? [...s, o.id] : s.filter((y) => y !== o.id)))} rotulo={o.nome} />
                  <span className="truncate" title={o.nome}>{o.sigla ? `${o.sigla} · ` : ''}{o.nome}</span>
                </label>
              ))}
            </div>
          </fieldset>
        )}
        {u && senha && <p className="text-xs text-muted-foreground">Ao trocar a senha, as outras sessões do usuário são encerradas.</p>}
        {salvar.isError && <p role="alert" className="rounded-md border border-danger/40 bg-danger/10 p-2 text-xs">{mensagemErro(salvar.error)}</p>}
        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="outline" onClick={aoFechar}>Cancelar</Button>
          <Button type="submit" disabled={salvar.isPending}>{salvar.isPending ? <Loader2 className="animate-spin" /> : <Save />} Salvar</Button>
        </div>
      </form>
    </Dialogo>
  )
}

/* ---------- Perfis e permissões ---------- */

interface RespPerfis {
  permissoes: { id: number; codigo: string; descricao: string }[]
  perfis: { id: number; codigo: string; nome: string; restrito_por_orgao: boolean; usuarios: number; permissoes: string[] }[]
}

function Perfis() {
  const cliente = useQueryClient()
  const q = useQuery({ queryKey: ['admin-perfis'], queryFn: () => api<RespPerfis>('/admin/perfis') })
  const [rascunho, setRascunho] = useState<Record<number, string[]>>({})
  const salvar = useMutation({
    mutationFn: ({ id, permissoes }: { id: number; permissoes: string[] }) => api<RespPerfis>(`/admin/perfis/${id}`, { method: 'PUT', body: JSON.stringify({ permissoes }) }),
    onSuccess: (d, v) => { cliente.setQueryData(['admin-perfis'], d); setRascunho((r) => { const x = { ...r }; delete x[v.id]; return x }) },
    onError: (e) => window.alert(mensagemErro(e)),
  })
  if (q.isLoading) return <div className="flex h-24 items-center justify-center"><Loader2 className="size-5 animate-spin text-primary" /></div>
  if (q.isError || !q.data) return <Erro erro={q.error} />
  const { perfis, permissoes } = q.data
  const atual = (id: number) => rascunho[id] ?? perfis.find((p) => p.id === id)!.permissoes

  return (
    <Card>
      <CardContent className="pt-5">
        <p className="mb-3 text-xs text-muted-foreground">Marque as permissões de cada perfil e clique em salvar na coluna. O Administrador Geral mantém todas as permissões.</p>
        <div className="overflow-auto rounded-md border">
          <table className="w-full text-xs">
            <thead className="bg-muted text-[11px] text-muted-foreground">
              <tr>
                <th className="sticky left-0 z-10 min-w-[220px] bg-muted px-3 py-2 text-left font-semibold">Permissão</th>
                {perfis.map((p) => (
                  <th key={p.id} className="min-w-[110px] px-2 py-2 text-center font-semibold">
                    <span className="block">{p.nome}</span>
                    <span className="block font-normal">{p.usuarios} usuário{p.usuarios === 1 ? '' : 's'}</span>
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {permissoes.map((pm) => (
                <tr key={pm.codigo} className="border-t">
                  <th scope="row" className="sticky left-0 z-10 bg-card px-3 py-1.5 text-left font-normal">
                    <span className="block font-medium">{pm.descricao}</span>
                    <code className="text-[10px] text-muted-foreground">{pm.codigo}</code>
                  </th>
                  {perfis.map((p) => {
                    const lista = atual(p.id)
                    return (
                      <td key={p.id} className="px-2 py-1.5 text-center">
                        <span className="inline-flex justify-center">
                          <Caixa marcado={p.codigo === 'ADMIN' || lista.includes(pm.codigo)} rotulo={`${pm.descricao} — ${p.nome}`} className={cn(p.codigo === 'ADMIN' && 'opacity-60')}
                            aoMudar={(x) => p.codigo !== 'ADMIN' && setRascunho((r) => ({ ...r, [p.id]: x ? [...lista, pm.codigo] : lista.filter((c) => c !== pm.codigo) }))} />
                        </span>
                      </td>
                    )
                  })}
                </tr>
              ))}
              <tr className="border-t bg-muted/40">
                <td className="sticky left-0 z-10 bg-muted/40 px-3 py-2" />
                {perfis.map((p) => (
                  <td key={p.id} className="px-2 py-2 text-center">
                    {rascunho[p.id] && (
                      <Button size="sm" disabled={salvar.isPending} onClick={() => salvar.mutate({ id: p.id, permissoes: rascunho[p.id] })}><Save /> Salvar</Button>
                    )}
                  </td>
                ))}
              </tr>
            </tbody>
          </table>
        </div>
      </CardContent>
    </Card>
  )
}

/* ---------- Logs ---------- */

interface Log { id: number; acao: string; recurso: string | null; recurso_id: number | null; ip: string | null; detalhes: string | null; created_at: string; usuario: string | null }

function Logs() {
  const cabecalho = useCabecalhoExportacao()
  const { pode } = useAuth()
  const [acao, setAcao] = useState('')
  const colunas: Coluna<Log>[] = [
    { id: 'created_at', titulo: 'Data e hora', tipo: 'texto', valor: (l) => l.created_at?.slice(0, 19).replace('T', ' '), ordenavel: false, fixa: true, largura: 150 },
    { id: 'usuario', titulo: 'Usuário', tipo: 'texto', valor: (l) => l.usuario ?? 'Sistema', ordenavel: false },
    { id: 'acao', titulo: 'Ação', tipo: 'texto', valor: (l) => l.acao, ordenavel: false, render: (l) => <code className="text-[11px]">{l.acao}</code> },
    { id: 'recurso', titulo: 'Recurso', tipo: 'texto', valor: (l) => (l.recurso ? `${l.recurso}${l.recurso_id ? ` #${l.recurso_id}` : ''}` : null), ordenavel: false },
    { id: 'ip', titulo: 'IP', tipo: 'texto', valor: (l) => l.ip, ordenavel: false },
    { id: 'detalhes', titulo: 'Detalhes', tipo: 'texto', valor: (l) => l.detalhes, ordenavel: false, largura: 320,
      render: (l) => <span className="line-clamp-2 break-all font-mono text-[11px] text-muted-foreground" title={l.detalhes ?? ''}>{l.detalhes ?? '—'}</span> },
  ]
  const consulta = acao ? `acao=${encodeURIComponent(acao)}` : ''
  return (
    <Card>
      <CardContent className="pt-5">
        <TabelaServidor<Log> endpoint="/admin/logs" consulta={consulta} colunas={colunas} ordemPadrao={{ id: 'id', direcao: 'desc' }}
          placeholderPesquisa="Ação, recurso ou usuário…" podeExportar={pode('relatorios.exportar')} cabecalhoExportacao={cabecalho('Logs de auditoria', acao ? [`Ação: ${acao}`] : [])}
          acoes={
            <select className={cn(classeSelect, 'w-48')} value={acao} onChange={(e) => setAcao(e.target.value)} aria-label="Tipo de ação">
              <option value="">Todas as ações</option>
              <option value="auth">Acesso (login e logout)</option>
              <option value="cadastros">Cadastros</option>
              <option value="usuarios">Usuários</option>
              <option value="perfis">Perfis</option>
              <option value="relatorio">Relatórios</option>
              <option value="exportacao">Exportações</option>
            </select>
          } />
      </CardContent>
    </Card>
  )
}
