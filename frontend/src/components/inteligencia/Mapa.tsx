import { useState } from 'react'
import { formatarAbreviado } from '@/lib/format'
import { cn } from '@/lib/utils'

export interface AreaMapa { id: string; nome: string; path: string; valor: number | null; cx?: number; cy?: number; rotulo?: string }

const PASSOS = [18, 36, 56, 76, 100]

/** Cor sequencial (um só tom, do claro ao escuro) a partir da série 1. */
const corPasso = (i: number) => `color-mix(in srgb, var(--serie-1) ${PASSOS[i]}%, hsl(var(--card)))`

/**
 * Mapa coroplético em SVG: cinco faixas de valor em escala sequencial, legenda, tooltip e áreas clicáveis
 * por teclado. Áreas sem valor ficam em cinza neutro.
 */
export function Mapa({ viewBox, areas, formatar, formatarLegenda = (v) => formatarAbreviado(v, false), aoClicar, selecionada, rotulo, titulosVisiveis = false, className }: {
  viewBox: string; areas: AreaMapa[]; formatar: (v: number | null) => string; formatarLegenda?: (v: number) => string; aoClicar?: (a: AreaMapa) => void
  selecionada?: string | null; rotulo: string; titulosVisiveis?: boolean; className?: string
}) {
  const [foco, setFoco] = useState<AreaMapa | null>(null)
  const max = Math.max(0, ...areas.map((a) => a.valor ?? 0))
  const passo = (v: number | null) => (v === null || v <= 0 || max <= 0 ? -1 : Math.min(4, Math.floor((v / max) * 5 - 1e-9)))
  const limites = PASSOS.map((_, i) => (max * (i + 1)) / 5)

  return (
    <div className={cn('relative', className)}>
      <svg viewBox={viewBox} role="group" aria-label={rotulo} className="h-auto w-full" onMouseLeave={() => setFoco(null)}>
        {areas.map((a) => {
          const p = passo(a.valor)
          const ativo = selecionada === a.id || foco?.id === a.id
          return (
            <path key={a.id} d={a.path}
              fill={p < 0 ? 'hsl(var(--muted))' : corPasso(p)}
              stroke={ativo ? 'hsl(var(--foreground))' : 'hsl(var(--card))'} strokeWidth={ativo ? 2 : 1} vectorEffect="non-scaling-stroke"
              className={cn('outline-none transition-[stroke]', aoClicar && 'cursor-pointer')}
              tabIndex={aoClicar ? 0 : -1} role={aoClicar ? 'button' : undefined}
              aria-label={`${a.nome}: ${formatar(a.valor)}`}
              onMouseEnter={() => setFoco(a)} onFocus={() => setFoco(a)} onBlur={() => setFoco(null)}
              onClick={aoClicar ? () => aoClicar(a) : undefined}
              onKeyDown={aoClicar ? (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); aoClicar(a) } } : undefined} />
          )
        })}
        {titulosVisiveis && areas.filter((a) => a.cx !== undefined).map((a) => (
          <text key={`t${a.id}`} x={a.cx} y={a.cy} textAnchor="middle" fontSize={11} className="pointer-events-none select-none"
            fill="hsl(var(--foreground))" stroke="hsl(var(--card))" strokeWidth={3} paintOrder="stroke">{a.rotulo ?? a.nome}</text>
        ))}
      </svg>
      {foco && (
        <div className="pointer-events-none absolute left-2 top-2 rounded-md border bg-popover px-3 py-2 text-xs shadow-lg" aria-hidden>
          <p className="font-semibold">{foco.nome}</p>
          <p className="tabular">{formatar(foco.valor)}</p>
          {aoClicar && <p className="mt-0.5 text-muted-foreground">Clique para detalhar</p>}
        </div>
      )}
      <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-muted-foreground" aria-label="Legenda do mapa">
        <span className="flex items-center gap-1"><span className="size-2.5 rounded-sm bg-muted" aria-hidden />Sem registro</span>
        {limites.map((l, i) => (
          <span key={i} className="flex items-center gap-1">
            <span className="size-2.5 rounded-sm" style={{ background: corPasso(i) }} aria-hidden />
            até {formatarLegenda(l)}
          </span>
        ))}
      </div>
    </div>
  )
}
