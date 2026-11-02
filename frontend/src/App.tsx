import { Navigate, Route, Routes } from 'react-router-dom'
import { Loader2 } from 'lucide-react'
import { useAuth } from '@/contexts/AuthContext'
import { FiltrosProvider } from '@/contexts/FiltrosContext'
import { LayoutPrincipal } from '@/components/layout/LayoutPrincipal'
import { Dashboard } from '@/pages/Dashboard'
import { Login } from '@/pages/Login'
import { ModuloPlanejado } from '@/pages/ModuloPlanejado'
import { lazy, Suspense, type ReactElement } from 'react'

// Páginas carregadas sob demanda, para a tela inicial abrir mais rápido.
const ComprasTotais = lazy(() => import('@/pages/ComprasTotais').then((m) => ({ default: m.ComprasTotais })))
const Processos = lazy(() => import('@/pages/Processos').then((m) => ({ default: m.Processos })))
const Fornecedores = lazy(() => import('@/pages/Fornecedores').then((m) => ({ default: m.Fornecedores })))
const Orgaos = lazy(() => import('@/pages/Orgaos').then((m) => ({ default: m.Orgaos })))
const aguardandoPagina = <div className="flex h-40 items-center justify-center"><Loader2 className="size-6 animate-spin text-primary" /></div>

/** Módulos já entregues; os demais exibem a página de módulo planejado. */
const PAGINAS: Record<string, ReactElement> = {
  'compras-totais': <ComprasTotais />,
  processos: <Processos />,
  fornecedores: <Fornecedores />,
  orgaos: <Orgaos />,
}
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
            <Route key={m.slug} path={m.slug} element={PAGINAS[m.slug] ? <Suspense fallback={aguardandoPagina}>{PAGINAS[m.slug]}</Suspense> : <ModuloPlanejado modulo={m} />} />
          ))}
          <Route path="*" element={<Navigate to="/" replace />} />
        </Route>
      </Routes>
    </FiltrosProvider>
  )
}
