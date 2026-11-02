export interface Usuario {
  id: number
  nome: string
  email: string
  perfil: { codigo: string; nome: string } | null
  permissoes: string[]
}

export interface NoFiltro {
  key: string
  label: string
  group?: boolean
  children?: NoFiltro[] | null
}

export type NomeFiltro =
  | 'tempo' | 'porte' | 'sistematica' | 'natureza' | 'classe' | 'item' | 'comprador_local'
  | 'unidade' | 'rp' | 'publicacao' | 'amparo' | 'fornecedor' | 'fornecedor_local'

export type Periodo = 'todo' | 'ano_atual' | 'ano_anterior' | 'ultimos_5' | 'ultimos_10' | 'personalizado'

export interface Indicador {
  codigo: string
  nome: string
  unidade: 'BRL' | 'PERCENTUAL' | 'QUANTIDADE'
  valor: number | null
  informado: boolean
  valor_anterior: number | null
  variacao_percentual: number | null
  tendencia: 'alta' | 'baixa' | 'estavel' | null
  explicacao: {
    descricao: string
    formula: string
    fonte: string
    data_referencia: string
    criterio_inclusao: string | null
    criterio_exclusao: string | null
  } | null
}

export interface RespostaIndicadores {
  indicadores: Indicador[]
  periodo: string
  periodo_anterior: string | null
  opcoes: { economia_base: string; incluir_sem_disputa: boolean; dias_vencimento_contratos: number }
}

export interface Meta {
  sistema: string
  periodo_dados: { inicio: string | null; fim: string | null }
  ultima_atualizacao: string | null
  possui_dados_ficticios: boolean
  possui_dados_oficiais: boolean
}
