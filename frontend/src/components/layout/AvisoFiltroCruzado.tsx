import { useEffect } from 'react'
import { Filter, Undo2, X } from 'lucide-react'
import { useFiltros } from '@/contexts/FiltrosContext'

/** Aviso do filtro cruzado com opção de desfazer (seção 31). Some sozinho após alguns segundos. */
export function AvisoFiltroCruzado() {
  const { ultimoCruzado, desfazerCruzado, dispensarCruzado } = useFiltros()

  useEffect(() => {
    if (!ultimoCruzado) return
    const t = setTimeout(dispensarCruzado, 10000)
    return () => clearTimeout(t)
  }, [ultimoCruzado, dispensarCruzado])

  if (!ultimoCruzado) return null
  return (
    <div role="status" className="no-print fixed bottom-4 left-1/2 z-50 flex w-[calc(100vw-32px)] max-w-md -translate-x-1/2 items-center gap-3 rounded-lg border bg-popover px-4 py-3 text-sm shadow-xl">
      <Filter className="size-4 shrink-0 text-primary" />
      <span className="min-w-0 flex-1">Filtro aplicado: <strong className="break-words">{ultimoCruzado}</strong></span>
      <button onClick={desfazerCruzado} className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-primary hover:bg-accent">
        <Undo2 className="size-3.5" /> Desfazer
      </button>
      <button onClick={dispensarCruzado} aria-label="Fechar aviso" className="rounded p-1 text-muted-foreground hover:bg-muted"><X className="size-3.5" /></button>
    </div>
  )
}
