import { ArrowDownRight, ArrowRight, ArrowUpRight, Info } from 'lucide-react'
import type { Indicador } from '@/lib/tipos'
import { formatarPercentual, formatarPorUnidade, NAO_INFORMADO } from '@/lib/format'
import { cn } from '@/lib/utils'
import { Dica } from '@/components/ui/tooltip'

/** Indicadores em que alta é desejável ou indesejável não são julgados: a seta só mostra a direção. */
export function CartaoIndicador({ indicador, destaque, aoAbrir, podeAbrir }: {
  indicador: Indicador
  destaque?: boolean
  aoAbrir: () => void
  podeAbrir: boolean
}) {
  const { nome, valor, valor_anterior, variacao_percentual, tendencia, unidade, explicacao, informado } = indicador
  const Seta = tendencia === 'alta' ? ArrowUpRight : tendencia === 'baixa' ? ArrowDownRight : ArrowRight

  const conteudo = (
    <>
      <div className="flex items-start justify-between gap-2">
        <p className="text-[12px] font-medium leading-snug text-muted-foreground">{nome}</p>
        {explicacao && (
          <Dica
            conteudo={
              <span className="block space-y-1">
                <span className="block">{explicacao.descricao}</span>
                <span className="block opacity-80"><strong>Fórmula:</strong> {explicacao.formula}</span>
                <span className="block opacity-80"><strong>Fonte:</strong> {explicacao.fonte}</span>
                {explicacao.criterio_exclusao && <span className="block opacity-80"><strong>Exclui:</strong> {explicacao.criterio_exclusao}</span>}
              </span>
            }
          >
            <span role="img" aria-label={`Como é calculado: ${nome}`} className="-m-1 rounded p-1 text-muted-foreground hover:text-foreground" onClick={(e) => e.stopPropagation()}>
              <Info className="size-3.5" />
            </span>
          </Dica>
        )}
      </div>
      <p className={cn('mt-1.5 font-semibold tabular leading-tight', unidade === 'BRL' ? 'whitespace-nowrap text-[17px]' : 'text-[22px]', !informado && 'text-base font-medium text-muted-foreground')}>
        {informado ? formatarPorUnidade(valor, unidade) : NAO_INFORMADO}
      </p>
      <div className="mt-2 flex min-h-[18px] items-center gap-1.5 text-[11px] text-muted-foreground">
        {tendencia ? (
          <>
            <span className={cn('inline-flex items-center gap-0.5 rounded px-1 font-medium', tendencia === 'estavel' ? 'bg-muted' : 'bg-accent text-accent-foreground')}>
              <Seta className="size-3" aria-hidden />
              {variacao_percentual !== null ? formatarPercentual(variacao_percentual) : tendencia === 'estavel' ? 'estável' : ''}
            </span>
            <span className="truncate">ant.: {formatarPorUnidade(valor_anterior, unidade)}</span>
          </>
        ) : (
          <span className="truncate">{!informado ? 'Fonte ainda não integrada' : 'Sem comparação de período'}</span>
        )}
      </div>
    </>
  )

  const classes = cn(
    'relative flex h-full flex-col rounded-lg border bg-card p-3.5 text-left shadow-[0_1px_2px_rgba(15,30,60,0.06)] transition-colors',
    destaque && 'border-destaque/50 before:absolute before:inset-y-3 before:left-0 before:w-[3px] before:rounded-r before:bg-destaque',
    podeAbrir && 'hover:border-primary/50 hover:bg-accent/30',
  )

  return podeAbrir ? (
    <button className={classes} onClick={aoAbrir} aria-label={`${nome}: abrir registros`}>{conteudo}</button>
  ) : (
    <div className={classes}>{conteudo}</div>
  )
}
