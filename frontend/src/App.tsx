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
const ComprasMpe = lazy(() => import('@/pages/ComprasMpe').then((m) => ({ default: m.ComprasMpe })))
const ComprasAlimentos = lazy(() => import('@/pages/ComprasAlimentos').then((m) => ({ default: m.ComprasAlimentos })))
const LicitacoesRp = lazy(() => import('@/pages/LicitacoesRp').then((m) => ({ default: m.LicitacoesRp })))
const AquisicoesRp = lazy(() => import('@/pages/AquisicoesRp').then((m) => ({ default: m.AquisicoesRp })))
const Orgaos = lazy(() => import('@/pages/Orgaos').then((m) => ({ default: m.Orgaos })))
const ItensAdjudicados = lazy(() => import('@/pages/ItensAdjudicados').then((m) => ({ default: m.ItensAdjudicados })))
const Catalogo = lazy(() => import('@/pages/Catalogo').then((m) => ({ default: m.Catalogo })))
const ComprasClasse = lazy(() => import('@/pages/ComprasClasse').then((m) => ({ default: m.ComprasClasse })))
const AnaliseTotal = lazy(() => import('@/pages/AnaliseTotal').then((m) => ({ default: m.AnaliseTotal })))
const AnalisePrecos = lazy(() => import('@/pages/AnalisePrecos').then((m) => ({ default: m.AnalisePrecos })))
const Pca = lazy(() => import('@/pages/Pca').then((m) => ({ default: m.Pca })))
const Contratos = lazy(() => import('@/pages/Contratos').then((m) => ({ default: m.Contratos })))
const Relatorios = lazy(() => import('@/pages/Relatorios').then((m) => ({ default: m.Relatorios })))
const Importacao = lazy(() => import('@/pages/Importacao').then((m) => ({ default: m.Importacao })))
const Administracao = lazy(() => import('@/pages/Administracao').then((m) => ({ default: m.Administracao })))
const aguardandoPagina = <div className="flex h-40 items-center justify-center"><Loader2 className="size-6 animate-spin text-primary" /></div>

/** Módulos já entregues; os demais exibem a página de módulo planejado. */
const PAGINAS: Record<string, ReactElement> = {
  'compras-totais': <ComprasTotais />,
  'compras-mpe': <ComprasMpe />,
  'compras-alimentos': <ComprasAlimentos />,
  'licitacoes-rp': <LicitacoesRp />,
  'aquisicoes-rp': <AquisicoesRp />,
  processos: <Processos />,
  fornecedores: <Fornecedores />,
  orgaos: <Orgaos />,
  'itens-adjudicados': <ItensAdjudicados />,
  catalogo: <Catalogo />,
  'compras-classe': <ComprasClasse />,
  'analise-total': <AnaliseTotal />,
  'analise-precos': <AnalisePrecos />,
  pca: <Pca />,
  contratos: <Contratos />,
  relatorios: <Relatorios />,
  importacao: <Importacao />,
  administracao: <Administracao />,
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
