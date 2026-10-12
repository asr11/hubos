import { useEffect, useState } from 'react'
import { NavLink, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../contexts/AuthContext'
import { ToastHost } from './shared/Toast'
import { ErrorBoundary } from './shared/ErrorBoundary'
import { HubRealtimeBubbleHost } from './HubRealtimeBubbleHost'
import { LogOut, Menu, X } from 'lucide-react'
import { TogglTimerBar } from './timer/TogglTimerBar'

const navLinks = [
  { to: '/portal', label: 'פורטל וטיימר' },
  { to: '/tasks', label: 'משימות' },
  { to: '/clients', label: 'לקוחות' },
  { to: '/projects', label: 'פרויקטים' },
  { to: '/hours-banks', label: 'בנק שעות' },
  { to: '/finance/income', label: 'הכנסות' },
  { to: '/finance/expenses', label: 'הוצאות' },
]

export function EmployeeLayout() {
  const { user, signOutUser } = useAuth()
  const location = useLocation()
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false)

  const employeeName = user?.displayName?.trim() || user?.email?.split('@')[0] || 'עובד'
  const initial = employeeName.charAt(0).toUpperCase()

  // Ensure light mode for employee layout
  useEffect(() => {
    document.documentElement.classList.remove('dark')
    localStorage.setItem('theme', 'light')
  }, [])

  useEffect(() => {
    setMobileMenuOpen(false)
  }, [location.pathname])

  return (
    <>
      <div dir="rtl" className="flex min-h-[100dvh] flex-col bg-[var(--bg-main)] text-[var(--text-dark)]">
        {/* Top Navbar */}
        <header className="sticky top-0 z-50 flex items-center justify-between border-b border-[var(--border)] bg-[var(--bg-sidebar)] px-4 py-3 shadow-sm">
          <div className="flex items-center gap-4">
            <button
              onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
              className="md:hidden p-1 text-[var(--text-mid)] hover:text-[var(--text-dark)]"
            >
              {mobileMenuOpen ? <X size={24} /> : <Menu size={24} />}
            </button>
            <div className="text-xl font-bold tracking-tight text-[var(--text-dark)]">
              HUB OS
            </div>
            
            <nav className="hidden md:flex items-center gap-1 mr-6">
              {navLinks.map((link) => (
                <NavLink
                  key={link.to}
                  to={link.to}
                  className={({ isActive }) =>
                    `px-3 py-1.5 rounded-lg text-sm font-medium transition-colors ${
                      isActive 
                        ? 'bg-[#fff7ed] text-[#ea580c]' 
                        : 'text-[var(--text-mid)] hover:bg-[var(--surface-hover)]'
                    }`
                  }
                >
                  {link.label}
                </NavLink>
              ))}
            </nav>
          </div>

          <div className="flex items-center gap-4">
            <div className="flex items-center gap-2">
              <span className="hidden sm:inline text-sm font-medium text-[var(--text-mid)]">
                {employeeName}
              </span>
              <div className="flex h-9 w-9 items-center justify-center rounded-full bg-[var(--blue-light)] text-[var(--blue-mid)] font-bold border border-[var(--border)]">
                {initial}
              </div>
            </div>
            <button
              onClick={() => void signOutUser()}
              className="flex items-center gap-1.5 text-sm font-medium text-[var(--text-light)] hover:text-[var(--danger)] transition-colors"
            >
              <LogOut size={16} />
              <span className="hidden sm:inline">התנתק</span>
            </button>
          </div>
        </header>

        {/* Mobile Menu */}
        {mobileMenuOpen && (
          <div className="md:hidden border-b border-[var(--border)] bg-[var(--bg-sidebar)]">
            <nav className="flex flex-col p-2">
              {navLinks.map((link) => (
                <NavLink
                  key={link.to}
                  to={link.to}
                  className={({ isActive }) =>
                    `px-4 py-3 rounded-lg text-sm font-medium mb-1 transition-colors ${
                      isActive 
                        ? 'bg-[#fff7ed] text-[#ea580c]' 
                        : 'text-[var(--text-mid)] hover:bg-[var(--surface-hover)]'
                    }`
                  }
                >
                  {link.label}
                </NavLink>
              ))}
            </nav>
          </div>
        )}

        {/* Sticky Toggl Timer Bar */}
        <TogglTimerBar />

        {/* Main Content */}
        <main className="flex-1 w-full max-w-7xl mx-auto p-4 md:p-6 lg:p-8">
          <ErrorBoundary resetKey={location.pathname}>
            <Outlet />
          </ErrorBoundary>
        </main>
      </div>
      <ToastHost />
      <HubRealtimeBubbleHost />
    </>
  )
}
