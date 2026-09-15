import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../contexts/AuthContext'
import { useUsers } from '../hooks/useUsers'
import { usesSalesAgentPortal } from '../lib/salesAgent'

/**
 * עובדי צוות רגילים — סוכן מכירות מופנה ל־/agent ולא רואה את ממשק האב המלא.
 */
export function StaffRoutesGuard() {
  const { user } = useAuth()
  const { users, loading } = useUsers()
  const location = useLocation()
  const member = users.find((m) => m.id === (user?.uid ?? ''))
  if (loading) return <Outlet />
  if (user && usesSalesAgentPortal(member)) {
    const dest = `/agent${location.search ?? ''}`
    return <Navigate to={dest} replace />
  }
  return <Outlet />
}
