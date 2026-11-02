import type { ReactNode } from 'react'
import { Loader2 } from 'lucide-react'
import { cn } from '@/lib/utils'
import { NAO_INFORMADO } from '@/lib/format'

export function Secao({ titulo, children, className, acoes }: { titulo: string; children: ReactNode; className?: string; acoes?: ReactNode }) {
  return (
    <section className={cn('border-b px-5 py-4 last:border-0', className)}>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{titulo}</h3>
        {acoes}
      </div>
      {children}
    </section>
  )
}

export function Campos({ itens }: { itens: [string, ReactNode][] }) {
  return (
    <dl className="grid grid-cols-1 gap-x-6 gap-y-2.5 text-xs sm:grid-cols-2 lg:grid-cols-3">
      {itens.map(([rotulo, valor]) => (
        <div key={rotulo} className="min-w-0">
          <dt className="text-muted-foreground">{rotulo}</dt>
          <dd className="mt-0.5 break-words font-medium">{valor === null || valor === undefined || valor === '' ? <span className="font-normal text-muted-foreground">{NAO_INFORMADO}</span> : valor}</dd>
        </div>
      ))}
    </dl>
  )
}

export function Numeros({ itens }: { itens: [string, string][] }) {
  return (
    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
      {itens.map(([r, v]) => (
        <div key={r} className="rounded-md border bg-surface px-3 py-2">
          <p className="text-[11px] text-muted-foreground">{r}</p>
          <p className="mt-0.5 whitespace-nowrap text-sm font-semibold tabular">{v}</p>
        </div>
      ))}
    </div>
  )
}

export function MiniTabela({ cabecalho, linhas, direita = [], aoClicar, vazio = 'Nenhum registro.' }: {
  cabecalho: string[]; linhas: ReactNode[][]; direita?: number[]; aoClicar?: (i: number) => void; vazio?: string
}) {
  if (!linhas.length) return <p className="text-xs text-muted-foreground">{vazio}</p>
  return (
    <div className="max-h-[320px] overflow-auto rounded-md border">
      <table className="w-full text-xs">
        <thead className="sticky top-0 bg-muted text-left text-[11px] text-muted-foreground">
          <tr>{cabecalho.map((c, i) => <th key={c} className={cn('px-2.5 py-1.5 font-semibold', direita.includes(i) && 'text-right')}>{c}</th>)}</tr>
        </thead>
        <tbody>
          {linhas.map((l, i) => (
            <tr key={i} className={cn('border-t', aoClicar && 'cursor-pointer hover:bg-muted/60')} onClick={aoClicar ? () => aoClicar(i) : undefined}
              tabIndex={aoClicar ? 0 : undefined} onKeyDown={aoClicar ? (e) => { if (e.key === 'Enter') aoClicar(i) } : undefined}>
              {l.map((v, j) => <td key={j} className={cn('px-2.5 py-1.5 align-top', direita.includes(j) && 'whitespace-nowrap text-right tabular')}>{v}</td>)}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

export const Carregando = () => <div className="flex h-48 items-center justify-center"><Loader2 className="size-6 animate-spin text-primary" /></div>
export const Erro = ({ erro }: { erro: unknown }) => <p className="m-5 rounded-md border border-danger/40 bg-danger/10 p-3 text-xs">Não foi possível carregar: {(erro as Error)?.message}</p>
