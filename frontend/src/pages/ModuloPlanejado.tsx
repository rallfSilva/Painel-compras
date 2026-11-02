import { Link } from 'react-router-dom'
import { CalendarClock } from 'lucide-react'
import type { Modulo } from '@/modulos'
import { Card } from '@/components/ui/card'
import { Button } from '@/components/ui/button'

const ETAPAS: Record<number, string> = {
  2: 'Etapa 2 — Compras', 3: 'Etapa 3 — Inteligência', 4: 'Etapa 4 — Planejamento e Contratos',
  5: 'Etapa 5 — Integração', 6: 'Etapa 6 — Inteligência Artificial',
}

/** Página dos módulos previstos para as próximas etapas do plano de desenvolvimento (seção 33). */
export function ModuloPlanejado({ modulo }: { modulo: Modulo }) {
  return (
    <Card className="mx-auto mt-6 max-w-2xl p-8 text-center">
      <div className="mx-auto mb-4 flex size-12 items-center justify-center rounded-full bg-accent text-accent-foreground">
        <modulo.icone className="size-6" />
      </div>
      <h1 className="text-xl font-bold">{modulo.nome}</h1>
      <p className="mt-2 text-sm text-muted-foreground">{modulo.descricao}</p>
      <p className="mt-5 inline-flex items-center gap-2 rounded-full bg-muted px-3 py-1 text-xs font-medium">
        <CalendarClock className="size-3.5" /> Previsto na {ETAPAS[modulo.etapa]}
      </p>
      <p className="mt-4 text-xs text-muted-foreground">
        A estrutura de dados deste módulo já existe no banco. As telas serão entregues na etapa indicada, respeitando os filtros globais.
      </p>
      <Button asChild variant="outline" className="mt-6"><Link to="/">Voltar ao Dashboard</Link></Button>
    </Card>
  )
}
