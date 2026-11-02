import { useState, type FormEvent } from 'react'
import { Loader2, Lock } from 'lucide-react'
import { useAuth } from '@/contexts/AuthContext'
import { ApiError } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { RodapeCreditos } from '@/components/layout/RodapeCreditos'

export function Login() {
  const { entrar } = useAuth()
  const [email, setEmail] = useState('')
  const [senha, setSenha] = useState('')
  const [erro, setErro] = useState<string | null>(null)
  const [enviando, setEnviando] = useState(false)

  const enviar = async (e: FormEvent) => {
    e.preventDefault()
    setErro(null)
    setEnviando(true)
    try {
      await entrar(email, senha)
    } catch (err) {
      setErro(err instanceof ApiError ? (err.status === 429 ? 'Muitas tentativas. Aguarde um minuto.' : err.erros?.email?.[0] ?? err.message) : 'Não foi possível conectar ao servidor.')
    } finally {
      setEnviando(false)
    }
  }

  return (
    <div className="flex min-h-full flex-col bg-sidebar">
      <main className="flex flex-1 items-center justify-center px-4 py-10">
        <div className="w-full max-w-sm">
          <div className="mb-6 text-center text-sidebar-foreground">
            <div className="mx-auto mb-3 flex size-12 items-center justify-center rounded-xl bg-primary text-sm font-bold text-white">SIC</div>
            <h1 className="text-xl font-bold">SIC/SELC</h1>
            <p className="text-sm text-sidebar-muted">Inteligência de Compras Públicas · Governo de Roraima</p>
          </div>
          <form onSubmit={enviar} className="rounded-xl border bg-card p-6 shadow-xl">
            <label className="block text-sm font-medium">
              E-mail
              <Input type="email" required autoComplete="username" value={email} onChange={(e) => setEmail(e.target.value)} className="mt-1" />
            </label>
            <label className="mt-4 block text-sm font-medium">
              Senha
              <Input type="password" required autoComplete="current-password" value={senha} onChange={(e) => setSenha(e.target.value)} className="mt-1" />
            </label>
            {erro && <p className="mt-3 rounded-md bg-danger/10 px-3 py-2 text-sm text-danger" role="alert">{erro}</p>}
            <Button type="submit" className="mt-5 w-full" disabled={enviando}>
              {enviando ? <Loader2 className="animate-spin" /> : <Lock />} Entrar
            </Button>
          </form>
        </div>
      </main>
      <RodapeCreditos className="border-white/10 bg-card" />
    </div>
  )
}
