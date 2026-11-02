import { createContext, useContext } from 'react'

export type TipoDetalhe = 'unidade' | 'processo' | 'fornecedor'
export type AbrirDetalhe = (tipo: TipoDetalhe, id: number, titulo?: string) => void

export const DetalhesCtx = createContext<AbrirDetalhe>(() => {})

/** Abre a ficha de uma unidade compradora, processo ou fornecedor. */
export const useDetalhes = () => useContext(DetalhesCtx)
