import type { ReactNode } from 'react'
import { Printer } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { useIndicadores } from './FaixaIndicadores'

/** Título do módulo com o período analisado e o botão de impressão. */
export function CabecalhoModulo({ titulo, descricao, consulta, acoes, semPeriodo }: { titulo: string; descricao?: string; consulta: string; acoes?: ReactNode; semPeriodo?: boolean }) {
  const q = useIndicadores(consulta)
  return (
    <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
      <div className="min-w-0">
        <h1 className="text-lg font-bold uppercase tracking-tight sm:text-xl">{titulo}</h1>
        {descricao && <p className="mt-0.5 text-sm text-muted-foreground">{descricao}</p>}
        {!semPeriodo && <p className="mt-0.5 text-sm text-muted-foreground">
          Período: <span className="font-medium text-foreground">{q.data?.periodo ?? '…'}</span>
          {q.data?.periodo_anterior && <> · comparado com {q.data.periodo_anterior}</>}
        </p>}
      </div>
      <div className="no-print flex items-center gap-2">
        {acoes}
        <Button variant="outline" size="sm" onClick={() => window.print()}><Printer /> Imprimir</Button>
      </div>
    </div>
  )
}

/** Abas simples e acessíveis dos módulos. */
export function Abas<T extends string>({ abas, ativa, aoMudar }: { abas: { id: T; rotulo: string }[]; ativa: T; aoMudar: (id: T) => void }) {
  return (
    <div role="tablist" className="no-print mb-4 flex gap-1 overflow-x-auto border-b">
      {abas.map((a) => (
        <button key={a.id} role="tab" aria-selected={ativa === a.id} onClick={() => aoMudar(a.id)}
          className={`-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium transition-colors ${ativa === a.id ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground'}`}>
          {a.rotulo}
        </button>
      ))}
    </div>
  )
}
