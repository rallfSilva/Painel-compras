import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react'
import { api, definirAoExpirar, obterToken, salvarToken } from '@/lib/api'
import type { Usuario } from '@/lib/tipos'

interface AuthCtx {
  usuario: Usuario | null
  carregando: boolean
  entrar: (email: string, senha: string) => Promise<void>
  sair: () => Promise<void>
  pode: (permissao: string) => boolean
}

const Ctx = createContext<AuthCtx | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [usuario, setUsuario] = useState<Usuario | null>(null)
  const [carregando, setCarregando] = useState(true)

  useEffect(() => {
    definirAoExpirar(() => setUsuario(null))
    if (!obterToken()) { setCarregando(false); return }
    api<{ usuario: Usuario }>('/auth/me')
      .then((r) => setUsuario(r.usuario))
      .catch(() => salvarToken(null))
      .finally(() => setCarregando(false))
  }, [])

  const entrar = useCallback(async (email: string, senha: string) => {
    const r = await api<{ token: string; usuario: Usuario }>('/auth/login', { method: 'POST', body: JSON.stringify({ email, password: senha }) })
    salvarToken(r.token)
    setUsuario(r.usuario)
  }, [])

  const sair = useCallback(async () => {
    try { await api('/auth/logout', { method: 'POST' }) } catch { /* token já inválido */ }
    salvarToken(null)
    setUsuario(null)
  }, [])

  const pode = useCallback((p: string) => !!usuario?.permissoes.includes(p), [usuario])

  return <Ctx.Provider value={{ usuario, carregando, entrar, sair, pode }}>{children}</Ctx.Provider>
}

export function useAuth() {
  const c = useContext(Ctx)
  if (!c) throw new Error('useAuth fora do AuthProvider')
  return c
}
