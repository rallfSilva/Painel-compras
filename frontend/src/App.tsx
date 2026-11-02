import { Navigate, Route, Routes } from 'react-router-dom'
import { Loader2 } from 'lucide-react'
import { useAuth } from '@/contexts/AuthContext'
import { FiltrosProvider } from '@/contexts/FiltrosContext'
import { LayoutPrincipal } from '@/components/layout/LayoutPrincipal'
import { Dashboard } from '@/pages/Dashboard'
import { Login } from '@/pages/Login'
import { ModuloPlanejado } from '@/pages/ModuloPlanejado'
import { MODULOS } from '@/modulos'

export default function App() {
  const { usuario, carregando } = useAuth()

  if (carregando) {
    return <div className="flex h-full items-center justify-center"><Loader2 className="size-7 animate-spin text-primary" /></div>
  }
  if (!usuario) return <Login />

  return (
    <FiltrosProvider>
      <Routes>
        <Route element={<LayoutPrincipal />}>
          <Route index element={<Dashboard />} />
          {MODULOS.filter((m) => m.slug).map((m) => (
            <Route key={m.slug} path={m.slug} element={<ModuloPlanejado modulo={m} />} />
          ))}
          <Route path="*" element={<Navigate to="/" replace />} />
        </Route>
      </Routes>
    </FiltrosProvider>
  )
}
