import type { NoFiltro } from '@/lib/tipos'

/** Funções puras da seleção em árvore (testáveis sem React). */

export function indexar(nos: NoFiltro[]) {
  const pais = new Map<string, string | null>()
  const porChave = new Map<string, NoFiltro>()
  const visitar = (lista: NoFiltro[], pai: string | null) => {
    for (const n of lista) {
      pais.set(n.key, pai)
      porChave.set(n.key, n)
      if (n.children) visitar(n.children, n.key)
    }
  }
  visitar(nos, null)
  return { pais, porChave }
}

export function descendentes(no: NoFiltro): string[] {
  return (no.children ?? []).flatMap((c) => [c.key, ...descendentes(c)])
}

export function ancestrais(chave: string, pais: Map<string, string | null>): string[] {
  const lista: string[] = []
  let p = pais.get(chave) ?? null
  while (p) { lista.push(p); p = pais.get(p) ?? null }
  return lista
}

/** Nó está marcado diretamente ou por um ancestral marcado. */
export function marcadoPorHeranca(chave: string, selecao: Set<string>, pais: Map<string, string | null>): boolean {
  return selecao.has(chave) || ancestrais(chave, pais).some((a) => selecao.has(a))
}

/**
 * Marca ou desmarca um nó.
 * - Marcar um nó remove os descendentes já marcados (o nó pai já os cobre).
 * - Desmarcar um nó coberto por um ancestral troca o ancestral pelos irmãos restantes, nível a nível.
 * Nós de agrupamento (group) não são enviados ao servidor: marcar um grupo marca seus filhos.
 */
export function alternar(chave: string, selecionadas: string[], nos: NoFiltro[]): string[] {
  const { pais, porChave } = indexar(nos)
  const no = porChave.get(chave)
  if (!no) return selecionadas
  let sel = new Set(selecionadas)

  if (no.group) {
    const filhos = (no.children ?? []).map((c) => c.key)
    const todos = filhos.every((f) => marcadoPorHeranca(f, sel, pais))
    for (const f of filhos) {
      for (const d of [f, ...descendentes(porChave.get(f)!)]) sel.delete(d)
      if (!todos) sel.add(f)
    }
    return [...sel]
  }

  if (marcadoPorHeranca(chave, sel, pais)) {
    if (sel.has(chave)) {
      sel.delete(chave)
    } else {
      // Desce do ancestral marcado até o nó, expandindo os irmãos
      const caminho = [chave, ...ancestrais(chave, pais)].reverse()
      const idx = caminho.findIndex((c) => sel.has(c))
      for (let i = idx; i < caminho.length - 1; i++) {
        const atual = porChave.get(caminho[i])!
        sel.delete(atual.key)
        for (const filho of atual.children ?? []) if (filho.key !== caminho[i + 1]) sel.add(filho.key)
      }
    }
  } else {
    for (const d of descendentes(no)) sel.delete(d)
    sel.add(chave)
    sel = new Set([...sel].filter((s) => !porChave.get(s)?.group))
  }
  return [...sel]
}

/** Estado visual do checkbox: marcado, parcial (algum descendente marcado) ou vazio. */
export function estadoNo(no: NoFiltro, sel: Set<string>, pais: Map<string, string | null>): boolean | 'indeterminate' {
  if (marcadoPorHeranca(no.key, sel, pais)) return true
  if (descendentes(no).some((d) => sel.has(d))) return 'indeterminate'
  return false
}

/** Filtra a árvore pelo texto, mantendo os ancestrais dos nós encontrados. */
export function filtrarArvore(nos: NoFiltro[], termo: string): NoFiltro[] {
  const t = normalizar(termo)
  if (!t) return nos
  const out: NoFiltro[] = []
  for (const n of nos) {
    const filhos = n.children ? filtrarArvore(n.children, termo) : []
    if (normalizar(n.label).includes(t) || filhos.length) out.push({ ...n, children: filhos.length ? filhos : n.children })
  }
  return out
}

export function normalizar(s: string) {
  return s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
}
