import type { NomeFiltro } from '@/lib/tipos'
import { formatarMoeda, formatarNumero, formatarPercentual } from '@/lib/format'
import { useFiltros } from '@/contexts/FiltrosContext'

export interface Agrupamento {
  id: number | null
  nome: string
  contratado: number
  processos: number
  percentual: number | null
  filtro: NomeFiltro | null
  chave: string | null
  agrupado?: boolean
}

/** Lista compacta com barras proporcionais; clicar em um item aplica o filtro cruzado. */
export function ListaBarras({ dados, vazio = 'Sem dados para os filtros atuais.' }: { dados: Agrupamento[]; vazio?: string }) {
  const { aplicarCruzado } = useFiltros()
  const max = Math.max(1, ...dados.map((d) => d.contratado))
  if (!dados.length) return <p className="py-4 text-center text-xs text-muted-foreground">{vazio}</p>
  return (
    <ul className="space-y-2.5">
      {dados.map((d) => {
        const clicavel = !!(d.filtro && d.chave)
        const conteudo = (
          <>
            <span className="flex items-baseline justify-between gap-3 text-xs">
              <span className="min-w-0 truncate font-medium" title={d.nome}>{d.nome}</span>
              <span className="shrink-0 tabular text-muted-foreground">
                <span className="font-semibold text-foreground">{formatarMoeda(d.contratado)}</span> · {formatarPercentual(d.percentual)} · {formatarNumero(d.processos)} proc.
              </span>
            </span>
            <span className="mt-1 block h-2 rounded-full bg-muted" aria-hidden>
              <span className="block h-2 rounded-full" style={{ width: `${(d.contratado / max) * 100}%`, background: d.agrupado ? 'var(--serie-neutra)' : 'var(--serie-1)' }} />
            </span>
          </>
        )
        return (
          <li key={d.chave ?? d.nome}>
            {clicavel ? (
              <button type="button" className="group block w-full rounded text-left hover:bg-muted/60" onClick={() => aplicarCruzado(d.filtro!, d.chave!, d.nome)}
                title="Clique para filtrar o painel por este item">
                {conteudo}
                <span className="sr-only">Filtrar por {d.nome}</span>
              </button>
            ) : <div>{conteudo}</div>}
          </li>
        )
      })}
    </ul>
  )
}
