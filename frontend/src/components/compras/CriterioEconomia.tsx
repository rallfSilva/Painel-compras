const BASES = [
  { valor: 'contratado', rotulo: 'contratado' },
  { valor: 'homologado', rotulo: 'homologado' },
  { valor: 'adjudicado', rotulo: 'adjudicado' },
]

/** Escolha explícita da base de comparação da economia (seção 8: base identificada e configurável). */
export function CriterioEconomia({ base, incluirSemDisputa, aoMudarBase, aoMudarSemDisputa }: {
  base: string; incluirSemDisputa: boolean; aoMudarBase: (b: string) => void; aoMudarSemDisputa: (v: boolean) => void
}) {
  return (
    <div className="no-print mb-4 flex flex-wrap items-center gap-x-5 gap-y-2 rounded-lg border border-dashed px-4 py-2.5 text-xs text-muted-foreground">
      <span className="font-medium text-foreground">Critério da economia:</span>
      <label className="flex items-center gap-2">
        Comparar o estimado com o valor
        <select className="h-7 rounded border border-input bg-surface px-2 text-xs text-foreground" value={base} onChange={(e) => aoMudarBase(e.target.value)}>
          {BASES.map((b) => <option key={b.valor} value={b.valor}>{b.rotulo}</option>)}
        </select>
      </label>
      <label className="flex items-center gap-2">
        <input type="checkbox" className="size-3.5 accent-[hsl(var(--primary))]" checked={incluirSemDisputa} onChange={(e) => aoMudarSemDisputa(e.target.checked)} />
        Incluir procedimentos sem disputa de preço
      </label>
    </div>
  )
}
