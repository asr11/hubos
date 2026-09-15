export type UserRole = 'ceo' | 'finance' | 'project_manager' | 'sales' | 'admin' | 'user'

const PAGE_PERMISSIONS: Record<string, UserRole[]> = {
  '/': ['ceo', 'admin', 'finance', 'project_manager', 'sales'],
  '/clients': ['ceo', 'admin', 'finance', 'project_manager', 'sales'],
  '/suppliers': ['ceo', 'admin', 'finance'],
  '/finance': ['ceo', 'admin', 'finance'],
  '/finance/cashflow': ['ceo', 'admin', 'finance'],
  '/finance/documents': ['ceo', 'admin', 'finance'],
  '/finance/projects': ['ceo', 'admin', 'finance', 'project_manager'],
  '/finance/fixed-expenses': ['ceo', 'admin', 'finance'],
  '/finance/reconciliation': ['ceo', 'admin', 'finance'],
  '/finance/retainers': ['ceo', 'admin', 'finance'],
  '/finance/standing-orders': ['ceo', 'admin', 'finance'],
  '/income': ['ceo', 'admin', 'finance'],
  '/expenses': ['ceo', 'admin', 'finance'],
  '/collections': ['ceo', 'admin', 'finance'],
  '/projects': ['ceo', 'admin', 'project_manager'],
  '/tasks': ['ceo', 'admin', 'project_manager'],
  '/leads': ['ceo', 'admin', 'sales'],
  '/campaigns': ['ceo', 'admin', 'sales'],
  '/calendar': ['ceo', 'admin', 'project_manager', 'sales'],
  '/meeting-summaries': ['ceo', 'admin', 'project_manager'],
  '/hosting': ['ceo', 'admin', 'project_manager'],
  '/hourly-services': ['ceo', 'admin', 'project_manager'],
  '/vault': ['ceo', 'admin', 'project_manager'],
  '/users': ['ceo', 'admin'],
  '/agents-admin': ['ceo', 'admin'],
  '/agents-neural-network': ['ceo', 'admin'],
  '/prompt-library': ['ceo', 'admin', 'project_manager'],
  '/attendance': ['ceo', 'admin', 'finance'],
}

export function canAccessPage(role: UserRole | string | undefined, path: string): boolean {
  if (!role) return false
  if (role === 'ceo' || role === 'admin') return true
  const allowed = PAGE_PERMISSIONS[path]
  if (!allowed) return true
  return allowed.includes(role as UserRole)
}

export function getRoleLabel(role: string): string {
  const labels: Record<string, string> = {
    ceo: 'מנכ"לית',
    admin: 'מנהל מערכת',
    finance: 'כספים',
    project_manager: 'מנהל פרויקטים',
    sales: 'מכירות',
    user: 'משתמש',
  }
  return labels[role] || role
}
