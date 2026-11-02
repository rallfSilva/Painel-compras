import { useState } from 'react'
import { CalendarClock, CircleAlert, TrendingDown, TrendingUp, X } from 'lucide-react'
import { paraQueryString, useFiltros } from '@/contexts/FiltrosContext'
import { useAuth } from '@/contexts/AuthContext'
import { BarraFiltros } from '@/components/filters/BarraFiltros'
import { CabecalhoModulo } from '@/components/compras/CabecalhoModulo'
import { BarraPercentual, EstadoPainel, GradeKpis, Nota, useCabecalhoExportacao, usePainel } from '@/components/inteligencia/partes'
import { TabelaAvancada } from '@/components/tabela/TabelaAvancada'
import type { Coluna } from '@/components/tabela/colunas'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Dica } from '@/components/ui/tooltip'
import { formatarData, formatarNumero, formatarPercentual } from '@/lib/format'
import { cn } from '@/lib/utils'

type CodigoSinal = 'ACIMA_100' | 'VENCIMENTO_PROXIMO' | 'ALTA_UTILIZACAO' | 'BAIXA_UTILIZACAO'
interface Ata {
  id: number; numero: string; numero_publicacao: string | null; objeto: string; gerenciador: string; situacao: string; vigencia_inicio: string; validade: string
  total_registrado: number | null; total_comprado: number | null; percentual_utilizado: number | null; itens_acima_100: number; sinalizadores: CodigoSinal[]
}
interface ItemReg {
  id: number | null; codigo: string | null; descricao: string; atas: number; quantidade_registrada: number; quantidade_utilizada: number; quantidade_disponivel: number
  total_registrado: number | null; total_comprado: number | null; percentual_utilizado: number | null; itens_acima_100: number
}
interface OrgaoPart {
  id: number; nome: string; orgao: string; papel: string; atas: number; valor_previsto: number | null; valor_adquirido: number | null
  percentual_saldo: number | null; percentual_utilizado_ata: number | null; ultima_adesao: string | null
}
interface PainelAquisicoes {
  indicadores: Record<string, number | null>
  parametros: { utilizacao_baixa: number; utilizacao_alta: number; dias_alerta_vencimento: number }
  sinalizadores: { codigo: CodigoSinal; nome: string; quantidade: number; descricao: string }[]
  por_ata: Ata[]; por_item: ItemReg[]; por_orgao: OrgaoPart[]
}

const SINAIS: Record<CodigoSinal, { Icone: typeof CircleAlert; cor: string; curto: string }> = {
  ACIMA_100: { Icone: CircleAlert, cor: 'text-danger', curto: 'Acima de 100%' },
  VENCIMENTO_PROXIMO: { Icone: CalendarClock, cor: 'text-warning', curto: 'Vence em breve' },
  ALTA_UTILIZACAO: { Icone: TrendingUp, cor: 'text-primary', curto: 'Alta utilização' },
  BAIXA_UTILIZACAO: { Icone: TrendingDown, cor: 'text-muted-foreground', curto: 'Baixa utilização' },
}

interface Selecao { ata?: { id: number; rotulo: string }; item?: { id: number; rotulo: string }; unidade?: { id: number; rotulo: string } }

/** Módulo 6 — Aquisições por Registro de Preços (seção 15). */
export function AquisicoesRp() {
  const { estado } = useFiltros()
  const { pode } = useAuth()
  const cabecalho = useCabecalhoExportacao()
  const [sel, setSel] = useState<Selecao>({})
  const [sinal, setSinal] = useState<CodigoSinal | null>(null)
  const extra = new URLSearchParams()
  if (sel.ata) extra.set('ata_id', String(sel.ata.id))
  if (sel.item) extra.set('item_id', String(sel.item.id))
  if (sel.unidade) extra.set('unidade_id', String(sel.unidade.id))
  const consulta = paraQueryString(estado)
  const q = usePainel<PainelAquisicoes>('aquisicoes-rp', `/inteligencia/aquisicoes-rp?${extra.toString()}`, consulta)
  const alternar = (k: keyof Selecao, v: { id: number; rotulo: string }) => setSel((s) => ({ ...s, [k]: s[k]?.id === v.id ? undefined : v }))
  const exportarCom = (t: string) => cabecalho(t, [sel.ata && `Ata ${sel.ata.rotulo}`, sel.item && `Item ${sel.item.rotulo}`, sel.unidade && `Órgão ${sel.unidade.rotulo}`].filter(Boolean) as string[])

  return (
    <div>
      <CabecalhoModulo titulo="Aquisições por Registro de Preços" descricao="Utilização efetiva das atas: o que foi registrado, o que foi comprado e o saldo disponível." consulta={consulta} />
      <BarraFiltros />
      <EstadoPainel q={q}>
        {(d) => {
          const i = d.indicadores
          const par = d.parametros
          const atas = sinal ? d.por_ata.filter((a) => a.sinalizadores.includes(sinal)) : d.por_ata
          const colAtas: Coluna<Ata>[] = [
            { id: 'numero_publicacao', titulo: 'Nº publicação', tipo: 'texto', valor: (l) => l.numero_publicacao, largura: 110, fixa: true },
            { id: 'numero', titulo: 'Nº ata', tipo: 'texto', valor: (l) => l.numero },
            { id: 'objeto', titulo: 'Objeto', tipo: 'texto', valor: (l) => l.objeto, largura: 260, render: (l) => <span className="line-clamp-2" title={l.objeto}>{l.objeto}</span> },
            { id: 'situacao', titulo: 'Situação', tipo: 'texto', valor: (l) => (l.situacao === 'VIGENTE' ? 'Vigente' : l.situacao === 'EXPIRADA' ? 'Expirada' : l.situacao) },
            { id: 'validade', titulo: 'Validade', tipo: 'data', valor: (l) => l.validade },
            { id: 'percentual_utilizado', titulo: '% utilizado', dica: 'Total comprado / total registrado × 100.', tipo: 'percentual', valor: (l) => l.percentual_utilizado, largura: 170,
              render: (l) => <BarraPercentual valor={l.percentual_utilizado} alerta={l.itens_acima_100 > 0} /> },
            { id: 'sinais', titulo: 'Sinalizadores', tipo: 'texto', valor: (l) => l.sinalizadores.map((s) => SINAIS[s].curto).join(', '), render: (l) => <Sinais lista={l.sinalizadores} /> },
          ]
          const colItens: Coluna<ItemReg>[] = [
            { id: 'codigo', titulo: 'Código', tipo: 'texto', valor: (l) => l.codigo, largura: 90, fixa: true },
            { id: 'descricao', titulo: 'Descrição do item', tipo: 'texto', valor: (l) => l.descricao, largura: 240 },
            { id: 'qtd', titulo: 'Qtd. registrada', tipo: 'decimal', valor: (l) => l.quantidade_registrada },
            { id: 'qtd_u', titulo: 'Qtd. utilizada', tipo: 'decimal', valor: (l) => l.quantidade_utilizada },
            { id: 'qtd_d', titulo: 'Qtd. disponível', tipo: 'decimal', valor: (l) => l.quantidade_disponivel },
            { id: 'percentual_utilizado', titulo: '% utilizado', tipo: 'percentual', valor: (l) => l.percentual_utilizado, largura: 170,
              render: (l) => <BarraPercentual valor={l.percentual_utilizado} alerta={l.itens_acima_100 > 0} /> },
          ]
          const colOrgaos: Coluna<OrgaoPart>[] = [
            { id: 'nome', titulo: 'Órgão participante', tipo: 'texto', valor: (l) => `${l.nome} (${l.orgao})`, largura: 220, fixa: true },
            { id: 'papel', titulo: 'Papel', tipo: 'texto', valor: (l) => l.papel },
            { id: 'percentual_saldo', titulo: '% saldo', dica: '(valor previsto para o órgão − valor adquirido) / valor previsto × 100. Negativo indica aquisição acima do previsto.', tipo: 'percentual', valor: (l) => l.percentual_saldo },
            { id: 'percentual_utilizado_ata', titulo: '% da quantidade da ata utilizada', dica: 'Valor adquirido pelo órgão / valor registrado dos itens em que participa × 100.', tipo: 'percentual', valor: (l) => l.percentual_utilizado_ata, largura: 170,
              render: (l) => <BarraPercentual valor={l.percentual_utilizado_ata} /> },
            { id: 'valor_adquirido', titulo: 'Valor adquirido', tipo: 'moeda', valor: (l) => l.valor_adquirido, barra: true },
            { id: 'ultima_adesao', titulo: 'Data da adesão', tipo: 'data', valor: (l) => l.ultima_adesao },
          ]
          return (
            <>
              <GradeKpis colunas="xl:grid-cols-4 2xl:grid-cols-7" itens={[
                { rotulo: 'Total estimado RP', valor: i.total_estimado, tipo: 'moeda', dica: 'Σ quantidade registrada × valor unitário estimado.' },
                { rotulo: 'Total registrado', valor: i.total_registrado, tipo: 'moeda', dica: 'Σ quantidade registrada × valor unitário registrado.' },
                { rotulo: 'Total comprado', valor: i.total_comprado, tipo: 'moeda', destaque: true, dica: 'Σ valor das utilizações das atas (efetivamente adquirido).' },
                { rotulo: '% utilizado', valor: i.percentual_utilizado, tipo: 'percentual', dica: 'Total comprado / total registrado × 100.' },
                { rotulo: 'Economia estimada RP', valor: i.economia_estimada, tipo: 'moeda', nota: `${formatarPercentual(i.percentual_economia)} do estimado`, dica: 'Total estimado RP − total registrado. % Economia RP = economia estimada / total estimado RP × 100.' },
                { rotulo: 'Economia efetiva', valor: i.economia_efetiva, tipo: 'moeda', dica: 'Σ quantidade utilizada × (unitário estimado − unitário registrado): a economia aplicada apenas ao que foi comprado.' },
                { rotulo: 'Atas', valor: i.atas, tipo: 'numero' },
                { rotulo: 'Itens registrados', valor: i.itens_registrados, tipo: 'numero' },
                { rotulo: 'Itens do catálogo', valor: i.itens_catalogo, tipo: 'numero' },
                { rotulo: 'Fornecedores RP', valor: i.fornecedores, tipo: 'numero' },
                { rotulo: 'Gestores de atas', valor: i.gestores, tipo: 'numero' },
                { rotulo: 'Órgãos participantes', valor: i.orgaos_participantes, tipo: 'numero', nota: `${formatarNumero(i.orgaos_aderentes)} órgãos aderentes` },
                { rotulo: 'Participações', valor: i.participacoes, tipo: 'numero', dica: 'Vínculos órgão participante (ou aderente) × item registrado.' },
              ]} />

              <section aria-label="Sinalizadores" className="mb-4">
                <p className="mb-2 text-xs text-muted-foreground">Sinalizadores para análise humana (clique para filtrar as atas). Baixa utilização: abaixo de {par.utilizacao_baixa}% · alta: a partir de {par.utilizacao_alta}% · vencimento: até {par.dias_alerta_vencimento} dias.</p>
                <div className="grid grid-cols-1 gap-2 min-[480px]:grid-cols-2 xl:grid-cols-4">
                  {d.sinalizadores.map((s) => {
                    const { Icone, cor } = SINAIS[s.codigo]
                    const ativo = sinal === s.codigo
                    return (
                      <button key={s.codigo} aria-pressed={ativo} onClick={() => setSinal(ativo ? null : s.codigo)}
                        className={cn('flex items-start gap-3 rounded-lg border bg-card p-3 text-left hover:bg-muted/60', ativo && 'border-primary bg-accent/40')}>
                        <Icone className={cn('mt-0.5 size-4 shrink-0', cor)} aria-hidden />
                        <span className="min-w-0">
                          <span className="flex items-baseline gap-2"><span className="text-sm font-medium">{s.nome}</span><strong className="tabular">{formatarNumero(s.quantidade)}</strong></span>
                          <span className="mt-0.5 block text-[11px] leading-snug text-muted-foreground">{s.descricao}</span>
                        </span>
                      </button>
                    )
                  })}
                </div>
              </section>
              <Nota>Os sinalizadores indicam necessidade de análise, nunca conclusão automática de irregularidade.</Nota>

              {(sel.ata || sel.item || sel.unidade) && (
                <div className="mb-3 flex flex-wrap items-center gap-2 text-xs" aria-live="polite">
                  <span className="text-muted-foreground">Seleção entre tabelas:</span>
                  {(['ata', 'item', 'unidade'] as const).map((k) => sel[k] && (
                    <button key={k} onClick={() => setSel((s) => ({ ...s, [k]: undefined }))} className="inline-flex items-center gap-1 rounded-md border border-primary bg-accent px-2 py-1 text-accent-foreground">
                      {k === 'ata' ? 'Ata' : k === 'item' ? 'Item' : 'Órgão'}: {sel[k]!.rotulo} <X className="size-3" aria-label="Remover" />
                    </button>
                  ))}
                  <button className="px-1 text-primary hover:underline" onClick={() => setSel({})}>Limpar seleção</button>
                </div>
              )}

              <div className="grid grid-cols-1 gap-4">
                <Card>
                  <CardHeader><div><CardTitle>Aquisições por ata</CardTitle><p className="mt-0.5 text-xs text-muted-foreground">Vigentes primeiro, pelo vencimento mais próximo; depois as expiradas · clique para filtrar as outras tabelas{sinal ? ` · sinalizador: ${SINAIS[sinal].curto}` : ''}</p></div></CardHeader>
                  <CardContent>
                    <TabelaAvancada colunas={colAtas} dados={atas} podeExportar={pode('relatorios.exportar')} cabecalhoExportacao={exportarCom('Aquisições por ata')}
                      pesquisarEm={(l) => `${l.numero} ${l.numero_publicacao ?? ''} ${l.objeto} ${l.gerenciador}`}
                      aoClicarLinha={(l) => alternar('ata', { id: l.id, rotulo: l.numero })} selecionada={(l) => sel.ata?.id === l.id} rotuloLinha={(l) => `Selecionar ata ${l.numero}`} />
                  </CardContent>
                </Card>
                <div className="grid grid-cols-1 gap-4 2xl:grid-cols-2">
                  <Card>
                    <CardHeader><div><CardTitle>Aquisições por item registrado</CardTitle><p className="mt-0.5 text-xs text-muted-foreground">Clique para filtrar as outras tabelas</p></div></CardHeader>
                    <CardContent>
                      <TabelaAvancada colunas={colItens} dados={d.por_item} podeExportar={pode('relatorios.exportar')} cabecalhoExportacao={exportarCom('Aquisições por item registrado')}
                        pesquisarEm={(l) => `${l.codigo ?? ''} ${l.descricao}`}
                        aoClicarLinha={(l) => { if (l.id !== null) alternar('item', { id: l.id, rotulo: l.codigo ?? l.descricao }) }} selecionada={(l) => sel.item?.id === l.id} rotuloLinha={(l) => `Selecionar item ${l.descricao}`} />
                    </CardContent>
                  </Card>
                  <Card>
                    <CardHeader><div><CardTitle>Aquisições por órgão participante</CardTitle><p className="mt-0.5 text-xs text-muted-foreground">Gerenciador, participantes e aderentes · clique para filtrar as outras tabelas</p></div></CardHeader>
                    <CardContent>
                      <TabelaAvancada colunas={colOrgaos} dados={d.por_orgao} podeExportar={pode('relatorios.exportar')} cabecalhoExportacao={exportarCom('Aquisições por órgão participante')}
                        pesquisarEm={(l) => `${l.nome} ${l.orgao}`}
                        aoClicarLinha={(l) => alternar('unidade', { id: l.id, rotulo: l.orgao })} selecionada={(l) => sel.unidade?.id === l.id} rotuloLinha={(l) => `Selecionar ${l.nome}`} />
                    </CardContent>
                  </Card>
                </div>
              </div>
              <p className="mt-3 text-[11px] text-muted-foreground">Situação e validade consideram a data de hoje ({formatarData(new Date().toISOString().slice(0, 10))}).</p>
            </>
          )
        }}
      </EstadoPainel>
    </div>
  )
}

function Sinais({ lista }: { lista: CodigoSinal[] }) {
  if (!lista.length) return <span className="text-muted-foreground">—</span>
  return (
    <span className="flex flex-wrap gap-1">
      {lista.map((s) => {
        const { Icone, cor, curto } = SINAIS[s]
        return (
          <Dica key={s} conteudo={curto}>
            <span className={cn('inline-flex items-center gap-1 rounded border px-1 py-0.5 text-[10px] font-medium', cor)}><Icone className="size-3" aria-hidden />{curto}</span>
          </Dica>
        )
      })}
    </span>
  )
}
