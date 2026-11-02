const BASE = import.meta.env.VITE_API_URL || '/api'
const CHAVE_TOKEN = 'sic-token'

export class ApiError extends Error {
  status: number
  erros?: Record<string, string[]>
  constructor(status: number, message: string, erros?: Record<string, string[]>) {
    super(message)
    this.status = status
    this.erros = erros
  }
}

let aoExpirar: (() => void) | null = null
export const definirAoExpirar = (fn: () => void) => { aoExpirar = fn }

export function obterToken(): string | null {
  try { return sessionStorage.getItem(CHAVE_TOKEN) } catch { return null }
}
export function salvarToken(token: string | null) {
  try { if (token) sessionStorage.setItem(CHAVE_TOKEN, token); else sessionStorage.removeItem(CHAVE_TOKEN) } catch { /* armazenamento indisponível */ }
}

export async function api<T>(caminho: string, opcoes: RequestInit = {}): Promise<T> {
  const token = obterToken()
  const resp = await fetch(`${BASE}${caminho}`, {
    ...opcoes,
    headers: {
      Accept: 'application/json',
      ...(opcoes.body && !(opcoes.body instanceof FormData) ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...opcoes.headers,
    },
  })
  if (resp.status === 401 && token) {
    salvarToken(null)
    aoExpirar?.()
  }
  const corpo = resp.headers.get('content-type')?.includes('application/json') ? await resp.json() : null
  if (!resp.ok) {
    throw new ApiError(resp.status, corpo?.message || `Erro ${resp.status} ao acessar o servidor.`, corpo?.errors)
  }
  return corpo as T
}
