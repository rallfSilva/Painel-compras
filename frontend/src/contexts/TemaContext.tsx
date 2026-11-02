import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react'

type Tema = 'light' | 'dark'
const Ctx = createContext<{ tema: Tema; alternar: () => void }>({ tema: 'light', alternar: () => {} })

export function TemaProvider({ children }: { children: ReactNode }) {
  const [tema, setTema] = useState<Tema>(() => (document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light'))
  useEffect(() => {
    document.documentElement.dataset.theme = tema
    try { localStorage.setItem('sic-tema', tema) } catch { /* preferência só nesta sessão */ }
  }, [tema])
  const alternar = useCallback(() => setTema((t) => (t === 'dark' ? 'light' : 'dark')), [])
  return <Ctx.Provider value={{ tema, alternar }}>{children}</Ctx.Provider>
}

export const useTema = () => useContext(Ctx)
