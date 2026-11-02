import { useTema } from '@/contexts/TemaContext'
import logoClaro from '@/assets/brand/nexmind-claro.png'
import logoEscuro from '@/assets/brand/nexmind-escuro.png'
import { cn } from '@/lib/utils'

const URL_DESENVOLVEDOR = import.meta.env.VITE_DEVELOPER_URL || 'https://www.nexmind.net.br'

/**
 * Rodapé "Desenvolvido por" com a logo da NEXMIND (seção 4.3).
 * Usado no layout principal, na tela de login e nas páginas impressas.
 */
export function RodapeCreditos({ className }: { className?: string }) {
  const { tema } = useTema()
  return (
    <footer className={cn('flex flex-col items-center justify-center gap-2 border-t px-4 py-4 text-xs text-muted-foreground sm:flex-row sm:gap-3', className)}>
      <span>Desenvolvido por</span>
      <a
        href={URL_DESENVOLVEDOR}
        target="_blank"
        rel="noopener noreferrer"
        className="inline-flex rounded-sm transition-opacity hover:opacity-80"
        title="NEXMIND — Soluções em Tecnologia (abre em nova aba)"
      >
        <img src={tema === 'dark' ? logoEscuro : logoClaro} alt="NEXMIND — Soluções em Tecnologia" className="h-7 w-auto print:hidden" />
        <img src={logoClaro} alt="" aria-hidden className="hidden h-7 w-auto print:block" />
      </a>
    </footer>
  )
}
