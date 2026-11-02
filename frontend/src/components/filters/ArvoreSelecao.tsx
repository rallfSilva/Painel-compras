import { useMemo, useState } from 'react'
import { ChevronDown, ChevronRight, Search } from 'lucide-react'
import type { NoFiltro } from '@/lib/tipos'
import { cn } from '@/lib/utils'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { Caixa } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { alternar, estadoNo, filtrarArvore, indexar } from './arvore'

export function GatilhoFiltro({ titulo, resumo, ativo }: { titulo: string; resumo: string; ativo: boolean }) {
  return (
    <span className={cn('flex h-[52px] w-full flex-col items-start justify-center rounded-md border bg-surface px-3 text-left transition-colors hover:border-primary/50', ativo && 'border-primary/60 bg-accent/40')}>
      <span className="text-[10.5px] font-medium uppercase tracking-wide text-muted-foreground">{titulo}</span>
      <span className="flex w-full items-center gap-1 text-[13px]">
        <span className={cn('flex-1 truncate', ativo ? 'font-medium text-foreground' : 'text-muted-foreground')}>{resumo}</span>
        <ChevronDown className="size-3.5 shrink-0 text-muted-foreground" />
      </span>
    </span>
  )
}

export function resumoSelecao(chaves: string[], rotulos: Record<string, string>) {
  if (!chaves.length) return 'Todos'
  if (chaves.length === 1) return rotulos[chaves[0]] ?? chaves[0]
  return `${chaves.length} selecionados`
}

/** Filtro hierárquico com expansão, busca e seleção múltipla (seção 6.3, comportamento dos filtros). */
export function ArvoreSelecao({ titulo, nos, selecionadas, rotulos, aoMudar }: {
  titulo: string
  nos: NoFiltro[]
  selecionadas: string[]
  rotulos: Record<string, string>
  aoMudar: (chaves: string[], rotulos: Record<string, string>) => void
}) {
  const [termo, setTermo] = useState('')
  const [expandidos, setExpandidos] = useState<Set<string>>(new Set())
  const { pais, porChave } = useMemo(() => indexar(nos), [nos])
  const visiveis = useMemo(() => filtrarArvore(nos, termo), [nos, termo])
  const sel = useMemo(() => new Set(selecionadas), [selecionadas])

  const mudar = (chave: string) => {
    const novas = alternar(chave, selecionadas, nos)
    const r: Record<string, string> = {}
    for (const c of novas) r[c] = porChave.get(c)?.label ?? c
    aoMudar(novas, r)
  }

  const renderizar = (lista: NoFiltro[], nivel: number) => (
    <ul role={nivel === 0 ? 'tree' : 'group'}>
      {lista.map((no) => {
        const temFilhos = !!no.children?.length
        const aberto = expandidos.has(no.key) || (!!termo && temFilhos)
        return (
          <li key={no.key} role="treeitem" aria-expanded={temFilhos ? aberto : undefined}>
            <div className="flex items-center gap-1.5 rounded px-1 py-1 hover:bg-muted" style={{ paddingLeft: 4 + nivel * 16 }}>
              {temFilhos ? (
                <button
                  className="rounded p-0.5 text-muted-foreground hover:text-foreground"
                  onClick={() => setExpandidos((e) => { const n = new Set(e); if (n.has(no.key)) n.delete(no.key); else n.add(no.key); return n })}
                  aria-label={aberto ? `Recolher ${no.label}` : `Expandir ${no.label}`}
                >
                  {aberto ? <ChevronDown className="size-3.5" /> : <ChevronRight className="size-3.5" />}
                </button>
              ) : <span className="w-[18px]" />}
              <label className="flex flex-1 cursor-pointer items-center gap-2 text-[13px]">
                <Caixa marcado={estadoNo(no, sel, pais)} aoMudar={() => mudar(no.key)} rotulo={no.label} />
                <span className={cn('leading-snug', no.group && 'font-medium')}>{no.label}</span>
              </label>
            </div>
            {temFilhos && aberto && renderizar(no.children!, nivel + 1)}
          </li>
        )
      })}
    </ul>
  )

  return (
    <Popover>
      <PopoverTrigger className="w-full rounded-md">
        <GatilhoFiltro titulo={titulo} resumo={resumoSelecao(selecionadas, rotulos)} ativo={selecionadas.length > 0} />
      </PopoverTrigger>
      <PopoverContent className="w-[min(380px,calc(100vw-24px))] p-0">
        <div className="border-b p-2">
          <div className="relative">
            <Search className="absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
            <Input autoFocus value={termo} onChange={(e) => setTermo(e.target.value)} placeholder={`Pesquisar em ${titulo.toLowerCase()}`} className="pl-8" />
          </div>
        </div>
        <div className="max-h-[340px] overflow-y-auto p-1.5">
          {visiveis.length ? renderizar(visiveis, 0) : <p className="p-3 text-center text-xs text-muted-foreground">Nenhuma opção encontrada.</p>}
        </div>
        <div className="flex items-center justify-between border-t px-3 py-2">
          <span className="text-xs text-muted-foreground">{selecionadas.length ? `${selecionadas.length} selecionado(s)` : 'Todos'}</span>
          <Button variant="ghost" size="sm" disabled={!selecionadas.length} onClick={() => aoMudar([], {})}>Limpar</Button>
        </div>
      </PopoverContent>
    </Popover>
  )
}
