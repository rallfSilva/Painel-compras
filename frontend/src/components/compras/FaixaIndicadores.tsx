import { useMemo, useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Loader2 } from 'lucide-react'
import { api } from '@/lib/api'
import type { Indicador, RespostaIndicadores } from '@/lib/tipos'
import { useAuth } from '@/contexts/AuthContext'
import { CartaoIndicador } from '@/components/dashboard/CartaoIndicador'
import { DialogoRegistros } from '@/components/dashboard/DialogoRegistros'

export function useIndicadores(consulta: string) {
  return useQuery({
    queryKey: ['indicadores', consulta],
    queryFn: () => api<RespostaIndicadores>(`/dashboard/indicadores?${consulta}`),
    placeholderData: keepPreviousData,
  })
}

/** Faixa de indicadores própria de cada módulo (seção 5), com o mesmo cálculo e detalhamento do Dashboard. */
export function FaixaIndicadores({ codigos, consulta, monetarios = [] }: { codigos: string[]; consulta: string; monetarios?: string[] }) {
  const { pode } = useAuth()
  const q = useIndicadores(consulta)
  const [aberto, setAberto] = useState<Indicador | null>(null)
  const porCodigo = useMemo(() => new Map(q.data?.indicadores.map((i) => [i.codigo, i])), [q.data])

  if (q.isLoading) return <div className="flex h-24 items-center justify-center"><Loader2 className="size-5 animate-spin text-primary" /></div>
  if (q.isError) return <p className="mb-4 rounded-md border border-danger/40 bg-danger/10 p-3 text-xs">Não foi possível carregar os indicadores: {(q.error as Error).message}</p>

  const mon = codigos.filter((c) => monetarios.includes(c))
  const outros = codigos.filter((c) => !monetarios.includes(c))
  const cartoes = (lista: string[]) => lista.map((c) => {
    const i = porCodigo.get(c)
    return i ? <CartaoIndicador key={c} indicador={i} destaque={c === 'percentual_economia'} podeAbrir={pode('registros.ver')} aoAbrir={() => setAberto(i)} /> : null
  })

  return (
    <section aria-label="Indicadores do módulo" className={q.isFetching ? 'opacity-70 transition-opacity' : 'transition-opacity'}>
      {mon.length > 0 && <div className="mb-3 grid grid-cols-1 gap-3 min-[480px]:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-5">{cartoes(mon)}</div>}
      {outros.length > 0 && <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">{cartoes(outros)}</div>}
      <DialogoRegistros indicador={aberto} consulta={consulta} aoFechar={() => setAberto(null)} />
    </section>
  )
}
