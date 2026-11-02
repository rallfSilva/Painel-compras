import {
  BarChart3, Boxes, Building2, ClipboardList, FileSignature, FileSpreadsheet, FileText, Gauge, Layers, Library,
  LineChart, ListChecks, PackageSearch, Scale, Settings, ShoppingCart, Sprout, Store, Users, type LucideIcon,
} from 'lucide-react'

/** Os 19 módulos do menu principal (seção 5) e a etapa em que cada um é entregue (seção 33). */
export interface Modulo {
  numero: number
  slug: string
  nome: string
  icone: LucideIcon
  etapa: number
  descricao: string
}

export const MODULOS: Modulo[] = [
  { numero: 1, slug: '', nome: 'Dashboard Geral', icone: Gauge, etapa: 1, descricao: 'Painel das compras públicas com os indicadores consolidados.' },
  { numero: 2, slug: 'compras-totais', nome: 'Compras Totais', icone: ShoppingCart, etapa: 2, descricao: 'Visão consolidada e panorama das contratações por unidade contratante.' },
  { numero: 3, slug: 'compras-mpe', nome: 'Compras de MPE', icone: Store, etapa: 3, descricao: 'Contratações com MEI, ME, EPP e agricultura familiar.' },
  { numero: 4, slug: 'compras-alimentos', nome: 'Compras de Alimentos', icone: Sprout, etapa: 3, descricao: 'Gêneros alimentícios, Chamada Pública PNAE e agricultura familiar.' },
  { numero: 5, slug: 'licitacoes-rp', nome: 'Licitações para RP', icone: ClipboardList, etapa: 3, descricao: 'Formação de Atas de Registro de Preços, com 8 gráficos.' },
  { numero: 6, slug: 'aquisicoes-rp', nome: 'Aquisições por RP', icone: ListChecks, etapa: 3, descricao: 'Utilização e saldo das atas, por ata, item e órgão participante.' },
  { numero: 7, slug: 'itens-adjudicados', nome: 'Itens Adjudicados', icone: PackageSearch, etapa: 3, descricao: 'Tabela detalhada dos itens adjudicados.' },
  { numero: 8, slug: 'catalogo', nome: 'Catálogo de Bens e Serviços', icone: Library, etapa: 3, descricao: 'Catálogo centralizado, normalização e duplicidades.' },
  { numero: 9, slug: 'compras-classe', nome: 'Compras por Classe', icone: Layers, etapa: 3, descricao: 'Compras por grupo, classe, material e serviço.' },
  { numero: 10, slug: 'analise-total', nome: 'Análise do Total Comprado', icone: BarChart3, etapa: 3, descricao: 'Árvore de decomposição, amparo legal e rankings.' },
  { numero: 11, slug: 'pca', nome: 'Plano de Contratações Anual', icone: FileText, etapa: 4, descricao: 'Planejamento anual e comparação com o executado.' },
  { numero: 12, slug: 'processos', nome: 'Licitações e Processos', icone: Scale, etapa: 2, descricao: 'Cadastro centralizado e fases dos processos.' },
  { numero: 13, slug: 'contratos', nome: 'Contratos', icone: FileSignature, etapa: 4, descricao: 'Contratos, vigências e aditivos.' },
  { numero: 14, slug: 'fornecedores', nome: 'Fornecedores', icone: Users, etapa: 2, descricao: 'Cadastro e histórico de fornecimento.' },
  { numero: 15, slug: 'orgaos', nome: 'Órgãos e Unidades', icone: Building2, etapa: 2, descricao: 'Órgãos, unidades superiores e unidades compradoras.' },
  { numero: 16, slug: 'analise-precos', nome: 'Análise de Preços', icone: LineChart, etapa: 3, descricao: 'Comparação de preços e inteligência de mercado.' },
  { numero: 17, slug: 'relatorios', nome: 'Relatórios Gerenciais', icone: FileSpreadsheet, etapa: 4, descricao: 'Relatórios em Excel, CSV e PDF.' },
  { numero: 18, slug: 'importacao', nome: 'Importação de Dados', icone: Boxes, etapa: 5, descricao: 'Importação de planilhas e integrações (PNCP, Compras.gov.br).' },
  { numero: 19, slug: 'administracao', nome: 'Administração', icone: Settings, etapa: 4, descricao: 'Usuários, permissões, classificações e parâmetros.' },
]
