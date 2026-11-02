import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import App from './App'
import { AuthProvider } from '@/contexts/AuthContext'
import { TemaProvider } from '@/contexts/TemaContext'
import { TooltipProvider } from '@/components/ui/tooltip'
import './index.css'

const cliente = new QueryClient({ defaultOptions: { queries: { retry: 1, refetchOnWindowFocus: false } } })

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <BrowserRouter basename={import.meta.env.VITE_ROUTER_BASE || '/'}>
      <QueryClientProvider client={cliente}>
        <TemaProvider>
          <TooltipProvider>
            <AuthProvider>
              <App />
            </AuthProvider>
          </TooltipProvider>
        </TemaProvider>
      </QueryClientProvider>
    </BrowserRouter>
  </StrictMode>,
)
