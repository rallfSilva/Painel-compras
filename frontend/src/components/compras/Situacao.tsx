import { Badge } from '@/components/ui/badge'

const SITUACOES: Record<string, { rotulo: string; cor: 'neutro' | 'primario' | 'sucesso' | 'alerta' | 'perigo' }> = {
  EM_ANDAMENTO: { rotulo: 'Em andamento', cor: 'primario' },
  CONCLUIDO: { rotulo: 'Concluído', cor: 'sucesso' },
  DESERTO: { rotulo: 'Deserto', cor: 'alerta' },
  FRACASSADO: { rotulo: 'Fracassado', cor: 'alerta' },
  REVOGADO: { rotulo: 'Revogado', cor: 'neutro' },
  ANULADO: { rotulo: 'Anulado', cor: 'perigo' },
  SUSPENSO: { rotulo: 'Suspenso', cor: 'neutro' },
  ADJUDICADO: { rotulo: 'Adjudicado', cor: 'primario' },
  HOMOLOGADO: { rotulo: 'Homologado', cor: 'primario' },
  CONTRATADO: { rotulo: 'Contratado', cor: 'sucesso' },
  CANCELADO: { rotulo: 'Cancelado', cor: 'neutro' },
}

export const rotuloSituacao = (s: string) => SITUACOES[s]?.rotulo ?? s

export function BadgeSituacao({ situacao }: { situacao: string }) {
  const x = SITUACOES[situacao] ?? { rotulo: situacao, cor: 'neutro' as const }
  return <Badge cor={x.cor} className="whitespace-nowrap">{x.rotulo}</Badge>
}
