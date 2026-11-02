import { useQuery } from '@tanstack/react-query'
import { CalendarRange, LogOut, Menu, Moon, RefreshCw, Sun } from 'lucide-react'
import { api } from '@/lib/api'
import type { Meta } from '@/lib/tipos'
import { formatarData } from '@/lib/format'
import { useAuth } from '@/contexts/AuthContext'
import { useTema } from '@/contexts/TemaContext'
import { Button } from '@/components/ui/button'
import { Dica } from '@/components/ui/tooltip'

export function useMeta() {
  return useQuery({ queryKey: ['meta'], queryFn: () => api<Meta>('/meta'), staleTime: 5 * 60_000 })
}

/** Cabeçalho com o período coberto pelos dados e a data da última atualização (seção 4.2). */
export function Cabecalho({ aoAbrirMenu }: { aoAbrirMenu: () => void }) {
  const { usuario, sair } = useAuth()
  const { tema, alternar } = useTema()
  const { data: meta } = useMeta()
  const inicio = meta?.periodo_dados.inicio?.slice(0, 4)
  const fim = meta?.periodo_dados.fim

  return (
    <header className="sticky top-0 z-20 flex h-16 items-center gap-3 border-b bg-surface/95 px-4 backdrop-blur sm:px-6">
      <Button variant="ghost" size="icon" className="lg:hidden" onClick={aoAbrirMenu} aria-label="Abrir menu">
        <Menu />
      </Button>
      <div className="min-w-0 flex-1">
        <p className="truncate text-[11px] font-medium uppercase tracking-wider text-muted-foreground">Governo de Roraima · SELC</p>
        <div className="flex flex-wrap items-center gap-x-4 gap-y-0.5 text-xs text-muted-foreground">
          {meta && (
            <>
              <span className="inline-flex items-center gap-1">
                <CalendarRange className="size-3.5" />
                {inicio && fim ? <>De {inicio} a {formatarData(fim)}</> : 'Sem dados carregados'}
              </span>
              <span className="hidden items-center gap-1 sm:inline-flex">
                <RefreshCw className="size-3.5" />
                Atualizado em {meta.ultima_atualizacao ? formatarData(meta.ultima_atualizacao) : 'Não informado'}
              </span>
            </>
          )}
        </div>
      </div>
      <Dica conteudo={tema === 'dark' ? 'Modo claro' : 'Modo escuro'}>
        <Button variant="ghost" size="icon" onClick={alternar} aria-label="Alternar tema">
          {tema === 'dark' ? <Sun /> : <Moon />}
        </Button>
      </Dica>
      <div className="hidden text-right sm:block">
        <p className="text-sm font-medium leading-tight">{usuario?.nome}</p>
        <p className="text-[11px] text-muted-foreground">{usuario?.perfil?.nome}</p>
      </div>
      <Dica conteudo="Sair">
        <Button variant="ghost" size="icon" onClick={sair} aria-label="Sair">
          <LogOut />
        </Button>
      </Dica>
    </header>
  )
}
