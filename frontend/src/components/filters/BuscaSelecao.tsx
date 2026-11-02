import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Loader2, Search, X } from 'lucide-react'
import { api } from '@/lib/api'
import type { NoFiltro } from '@/lib/tipos'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { Caixa } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { GatilhoFiltro, resumoSelecao } from './ArvoreSelecao'

/** Filtro pesquisável para campos de alta cardinalidade (item, fornecedor, publicação). */
export function BuscaSelecao({ titulo, tipo, selecionadas, rotulos, aoMudar, dica }: {
  titulo: string
  tipo: 'item' | 'fornecedor' | 'publicacao'
  selecionadas: string[]
  rotulos: Record<string, string>
  aoMudar: (chaves: string[], rotulos: Record<string, string>) => void
  dica: string
}) {
  const [termo, setTermo] = useState('')
  const [busca, setBusca] = useState('')
  useEffect(() => { const t = setTimeout(() => setBusca(termo.trim()), 300); return () => clearTimeout(t) }, [termo])

  const { data, isFetching } = useQuery({
    queryKey: ['busca-filtro', tipo, busca],
    queryFn: () => api<{ resultados: NoFiltro[] }>(`/filtros/buscar/${tipo}?q=${encodeURIComponent(busca)}`),
    enabled: busca.length >= 2,
    staleTime: 60_000,
  })

  const alternar = (no: NoFiltro) => {
    const tem = selecionadas.includes(no.key)
    aoMudar(tem ? selecionadas.filter((k) => k !== no.key) : [...selecionadas, no.key], { [no.key]: no.label })
  }

  return (
    <Popover>
      <PopoverTrigger className="w-full rounded-md">
        <GatilhoFiltro titulo={titulo} resumo={resumoSelecao(selecionadas, rotulos)} ativo={selecionadas.length > 0} />
      </PopoverTrigger>
      <PopoverContent className="w-[min(400px,calc(100vw-24px))] p-0">
        <div className="border-b p-2">
          <div className="relative">
            <Search className="absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
            <Input autoFocus value={termo} onChange={(e) => setTermo(e.target.value)} placeholder={dica} className="pl-8" />
            {isFetching && <Loader2 className="absolute right-2.5 top-2.5 size-4 animate-spin text-muted-foreground" />}
          </div>
        </div>
        {selecionadas.length > 0 && (
          <div className="flex flex-wrap gap-1 border-b p-2">
            {selecionadas.map((k) => (
              <span key={k} className="inline-flex max-w-full items-center gap-1 rounded bg-accent px-2 py-0.5 text-xs text-accent-foreground">
                <span className="truncate">{rotulos[k] ?? k}</span>
                <button onClick={() => aoMudar(selecionadas.filter((s) => s !== k), {})} aria-label="Remover"><X className="size-3" /></button>
              </span>
            ))}
          </div>
        )}
        <ul className="max-h-[300px] overflow-y-auto p-1.5">
          {busca.length < 2 && <li className="p-3 text-center text-xs text-muted-foreground">Digite ao menos 2 caracteres.</li>}
          {busca.length >= 2 && !isFetching && !data?.resultados.length && <li className="p-3 text-center text-xs text-muted-foreground">Nada encontrado.</li>}
          {data?.resultados.map((no) => (
            <li key={no.key}>
              <label className="flex cursor-pointer items-start gap-2 rounded px-2 py-1.5 text-[13px] hover:bg-muted">
                <Caixa className="mt-0.5" marcado={selecionadas.includes(no.key)} aoMudar={() => alternar(no)} rotulo={no.label} />
                <span className="leading-snug">{no.label}</span>
              </label>
            </li>
          ))}
        </ul>
        <div className="flex justify-end border-t px-3 py-2">
          <Button variant="ghost" size="sm" disabled={!selecionadas.length} onClick={() => aoMudar([], {})}>Limpar</Button>
        </div>
      </PopoverContent>
    </Popover>
  )
}
