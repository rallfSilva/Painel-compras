import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Filter } from 'lucide-react'
import { paraQueryString, useFiltros } from '@/contexts/FiltrosContext'
import { useAuth } from '@/contexts/AuthContext'
import { BarraFiltros } from '@/components/filters/BarraFiltros'
import { CabecalhoModulo } from '@/components/compras/CabecalhoModulo'
import { EstadoPainel, GradeKpis, MapaArvore, Nota, Rosca, SERIES, useCabecalhoExportacao, usePainel, type NoArvore } from '@/components/inteligencia/partes'
import { TabelaAvancada } from '@/components/tabela/TabelaAvancada'
import type { Coluna } from '@/components/tabela/colunas'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Dialogo } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Carregando, Erro, MiniTabela } from '@/components/detalhes/Partes'
import { api } from '@/lib/api'
import { formatarAbreviado, formatarMoeda, formatarNumero, formatarUnitario } from '@/lib/format'

interface LinhaClasse {
  id: number | null; codigo: string | null; classe: string; grupo_id: number | null; grupo: string; tipo: string | null; contratado: number; percentual: number | null
  itens: number; compras: number; processos: number; orgaos: number; fornecedores: number; filtro: 'classe' | null; chave: string | null
}
interface PainelClasses { resumo: { contratado: number; grupos: number; classes: number; materiais: number; servicos: number }; classes: LinhaClasse[] }
interface ItemClasse {
  id: number | null; codigo: string | null; descricao: string; unidade: string | null; quantidade: number | null; contratado: number
  preco_medio: number | null; preco_minimo: number | null; preco_maximo: number | null; frequencia: number; orgaos: number; chave: string | null
}

/** Módulo 9 — Compras por Classe de Material (seção 18). */
export function ComprasClasse() {
  const { estado, aplicarCruzado } = useFiltros()
  const { pode } = useAuth()
  const cabecalho = useCabecalhoExportacao()
  const consulta = paraQueryString(estado)
  const q = usePainel<PainelClasses>('classes', '/inteligencia/classes', consulta)
  const [aberta, setAberta] = useState<LinhaClasse | null>(null)

  const colunas = useMemo<Coluna<LinhaClasse>[]>(() => [
    { id: 'classe', titulo: 'Classe', tipo: 'texto', valor: (l) => l.classe, fixa: true, largura: 220,
      render: (l) => <span><span className="font-medium text-primary">{l.classe}</span><span className="block text-[11px] text-muted-foreground">{l.codigo}</span></span> },
    { id: 'grupo', titulo: 'Grupo', tipo: 'texto', valor: (l) => l.grupo, largura: 180 },
    { id: 'tipo', titulo: 'Tipo', tipo: 'texto', valor: (l) => l.tipo },
    { id: 'contratado', titulo: 'Contratado (R$)', tipo: 'moeda', valor: (l) => l.contratado, barra: true },
    { id: 'percentual', titulo: '% do total', tipo: 'percentual', valor: (l) => l.percentual },
    { id: 'itens', titulo: 'Itens', dica: 'Itens distintos do catálogo comprados na classe.', tipo: 'numero', valor: (l) => l.itens },
    { id: 'compras', titulo: 'Compras', dica: 'Linhas de itens com resultado (cada item em cada processo).', tipo: 'numero', valor: (l) => l.compras },
    { id: 'processos', titulo: 'Processos', tipo: 'numero', valor: (l) => l.processos },
    { id: 'orgaos', titulo: 'Órgãos', tipo: 'numero', valor: (l) => l.orgaos },
    { id: 'fornecedores', titulo: 'Fornecedores', tipo: 'numero', valor: (l) => l.fornecedores },
  ], [])

  return (
    <div>
      <CabecalhoModulo titulo="Compras por Classe" descricao="Valor contratado por grupo, classe, material e serviço, até os itens de cada classe." consulta={consulta} />
      <BarraFiltros />
      <EstadoPainel q={q}>
        {(d) => {
          const grupos = new Map<string, NoArvore>()
          for (const c of d.classes) {
            const k = c.grupo
            if (!grupos.has(k)) grupos.set(k, { id: c.grupo_id, nome: c.grupo, valor: 0, percentual: 0, filtro: 'classe', chave: c.grupo_id ? `g:${c.grupo_id}` : null, classes: [] })
            const g = grupos.get(k)!
            g.valor += c.contratado
            g.percentual = (g.percentual ?? 0) + (c.percentual ?? 0)
            g.classes!.push({ id: c.id, nome: c.classe, valor: c.contratado, percentual: c.percentual, filtro: c.filtro, chave: c.chave })
          }
          const arvore = [...grupos.values()].sort((a, b) => b.valor - a.valor)
          return (
            <>
              <GradeKpis itens={[
                { rotulo: 'Total contratado', valor: d.resumo.contratado, tipo: 'moeda', destaque: true },
                { rotulo: 'Materiais', valor: d.resumo.materiais, tipo: 'moeda', dica: 'Classes de grupos do tipo material.' },
                { rotulo: 'Serviços', valor: d.resumo.servicos, tipo: 'moeda', dica: 'Classes de grupos do tipo serviço.' },
                { rotulo: 'Grupos', valor: d.resumo.grupos, tipo: 'numero' },
                { rotulo: 'Classes', valor: d.resumo.classes, tipo: 'numero' },
              ]} />
              <div className="mb-4 grid grid-cols-1 gap-4 xl:grid-cols-3">
                <MapaArvore className="xl:col-span-2" titulo="Contratado por grupo e classe" subtitulo="Área proporcional ao valor · cor por grupo · clique em uma classe para filtrar" dados={arvore} />
                <Rosca titulo="Materiais x serviços" rotuloTotal="contratado" formatar={formatarMoeda} formatarCentro={(v) => formatarAbreviado(v)} dados={[
                  { chave: 'M', nome: 'Materiais', valor: d.resumo.materiais, cor: SERIES[0] },
                  { chave: 'S', nome: 'Serviços', valor: d.resumo.servicos, cor: SERIES[1] },
                ]} />
              </div>
              <Card>
                <CardHeader><div><CardTitle>Classes</CardTitle><p className="mt-0.5 text-xs text-muted-foreground">Clique numa classe para ver os itens, com quantidade e preço por unidade de fornecimento</p></div></CardHeader>
                <CardContent>
                  <TabelaAvancada<LinhaClasse> colunas={colunas} dados={d.classes} pesquisarEm={(l) => `${l.classe} ${l.grupo} ${l.codigo ?? ''}`}
                    placeholderPesquisa="Classe, grupo ou código…" aoClicarLinha={(l) => l.id && setAberta(l)} rotuloLinha={(l) => `Abrir itens da classe ${l.classe}`}
                    podeExportar={pode('relatorios.exportar')} cabecalhoExportacao={cabecalho('Compras por classe')} ordemInicial={[{ id: 'contratado', desc: true }]} />
                </CardContent>
              </Card>
              <Nota className="mt-4">Na classe, quantidades de unidades de fornecimento diferentes não são somadas. Quantidade e preço médio aparecem por item e unidade, ao abrir a classe.</Nota>
            </>
          )
        }}
      </EstadoPainel>
      <Dialogo aberto={!!aberta} aoFechar={() => setAberta(null)} titulo={aberta ? `${aberta.classe}` : ''}
        descricao={aberta ? `${aberta.grupo} · ${formatarMoeda(aberta.contratado)} contratados com os filtros atuais` : undefined}>
        {aberta?.id && (
          <ItensDaClasse id={aberta.id} consulta={consulta}
            aoFiltrar={() => { if (aberta.chave) aplicarCruzado('classe', aberta.chave, aberta.classe); setAberta(null) }} />
        )}
      </Dialogo>
    </div>
  )
}

function ItensDaClasse({ id, consulta, aoFiltrar }: { id: number; consulta: string; aoFiltrar: () => void }) {
  const { aplicarCruzado } = useFiltros()
  const q = useQuery({ queryKey: ['classe', id, consulta], queryFn: () => api<{ itens: ItemClasse[] }>(`/inteligencia/classes/${id}?${consulta}`) })
  if (q.isLoading) return <Carregando />
  if (q.isError) return <Erro erro={q.error} />
  const itens = q.data?.itens ?? []
  return (
    <div className="p-5">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <p className="text-xs text-muted-foreground">{formatarNumero(itens.length)} item(ns) e unidade(s) de fornecimento · clique num item para filtrar o painel</p>
        <Button variant="outline" size="sm" onClick={aoFiltrar}><Filter className="size-3.5" />Filtrar o painel por esta classe</Button>
      </div>
      <MiniTabela cabecalho={['Item', 'Un.', 'Quantidade', 'Preço médio', 'Mín.', 'Máx.', 'Contratado', 'Processos']} direita={[2, 3, 4, 5, 6, 7]}
        linhas={itens.map((i) => [`${i.codigo ?? ''} ${i.descricao}`.trim(), i.unidade ?? '—', formatarNumero(i.quantidade), formatarUnitario(i.preco_medio),
          formatarUnitario(i.preco_minimo), formatarUnitario(i.preco_maximo), formatarMoeda(i.contratado), formatarNumero(i.frequencia)])}
        aoClicar={(k) => { const i = itens[k]; if (i.chave) aplicarCruzado('item', i.chave, i.descricao) }} />
    </div>
  )
}
