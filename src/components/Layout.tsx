import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { NavLink, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../contexts/AuthContext'
import { canAccessPage } from '../lib/permissions'
import { useInvoices } from '../hooks/useInvoices'
import { useProjects } from '../hooks/useProjects'
import { useLeads } from '../hooks/useLeads'
import { useUsers } from '../hooks/useUsers'
import { useHubStore } from '../store/useStore'
import type { TeamMember } from '../types'
import { HubRealtimeBubbleHost } from './HubRealtimeBubbleHost'
import { ToastHost } from './shared/Toast'
import { QuickActionsFloat } from './QuickActionsFloat'
import { QuickCalendarSlider } from './QuickCalendarSlider'
import { QuickTasksSlider } from './QuickTasksSlider'
import { MeetingDrawer } from './MeetingDrawer'
import { TaskDrawer } from './TaskDrawer'
import { ErrorBoundary } from './shared/ErrorBoundary'

const workspaceLinks = [
  { label: 'Drive', href: 'https://drive.google.com', dot: '#FCD34D' },
  { label: 'Gmail', href: 'https://mail.google.com', dot: '#FCA5A5' },
  { label: 'Calendar', href: 'https://calendar.google.com', dot: '#F97316' },
  { label: 'Meet', href: 'https://meet.google.com', dot: '#93C5FD' },
  { label: 'Docs', href: 'https://docs.google.com', dot: '#A78BFA' },
] as const


const navBase =
  'flex items-center gap-2.5 rounded-lg px-4 py-2.5 text-[15px] transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#F97316] focus-visible:ring-offset-2 focus-visible:ring-offset-[#1E293B]'

const navIconClass =
  'h-[17px] w-[17px] shrink-0 text-current opacity-90 [&>svg]:h-full [&>svg]:w-full'

/** אייקוני קו דקים — ללא אימוג'י, מתאימים ל־RTL */
function NavGlyph({ children }: { children: ReactNode }) {
  return (
    <span className={navIconClass} aria-hidden>
      <svg
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth={1.65}
        strokeLinecap="round"
        strokeLinejoin="round"
      >
        {children}
      </svg>
    </span>
  )
}

function navSubLinkClass(isActive: boolean, nested = false): string {
  return [
    'flex min-h-[36px] w-full items-center rounded-md text-[13.5px] leading-snug transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#F97316] focus-visible:ring-offset-2 focus-visible:ring-offset-[#1E293B]',
    nested ? 'pe-2 ps-5' : 'px-3 py-1.5',
    isActive
      ? 'bg-[#F97316] font-extrabold text-[#0F172A] shadow-md shadow-[#F97316]/30'
      : 'font-semibold text-slate-100 hover:bg-white/15 hover:text-white',
  ].join(' ')
}

function NavSubPanel({ children }: { children: ReactNode }) {
  return (
    <div className="border-t border-white/15 px-2 pb-2.5 pt-2">
      <div className="rounded-lg bg-black/40 border border-white/10 p-1.5 space-y-0.5">
        {children}
      </div>
    </div>
  )
}

function NavExpandChevron({ open }: { open: boolean }) {
  return (
    <span
      className={`ms-auto shrink-0 text-slate-200 transition-transform duration-200 ${open ? 'rotate-180 text-white' : ''}`}
      aria-hidden
    >
      <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="2.2">
        <path d="M6 9l6 6 6-6" strokeLinecap="round" strokeLinejoin="round" />
      </svg>
    </span>
  )
}

function NavExpandableGroup({
  label,
  icon,
  isSectionActive,
  open,
  onToggle,
  children,
}: {
  label: string
  icon: ReactNode
  isSectionActive: boolean
  open: boolean
  onToggle: () => void
  children: ReactNode
}) {
  const highlighted = open || isSectionActive
  return (
    <div
      className={`flex flex-col overflow-hidden rounded-lg transition-all duration-200 ${
        highlighted ? 'bg-white/10 border border-white/10' : ''
      }`}
    >
      <button
        type="button"
        onClick={onToggle}
        aria-expanded={open}
        className={`${navBase} w-full text-right ${
          highlighted
            ? 'font-bold text-white bg-white/10'
            : 'font-semibold text-slate-100 hover:bg-white/10 hover:text-white'
        }`}
      >
        {icon}
        <span className="min-w-0 flex-1 truncate text-[15px]">{label}</span>
        <NavExpandChevron open={open} />
      </button>
      {open ? <NavSubPanel>{children}</NavSubPanel> : null}
    </div>
  )
}

function NavSubLink({
  to,
  label,
  end,
  nested,
  onClick,
  badge,
}: {
  to: string
  label: string
  end?: boolean
  nested?: boolean
  onClick?: () => void
  badge?: ReactNode
}) {
  return (
    <NavLink to={to} end={end} onClick={onClick} className={({ isActive }) => navSubLinkClass(isActive, nested)}>
      <span className="flex w-full min-w-0 items-center justify-between gap-2">
        <span className="truncate">{label}</span>
        {badge}
      </span>
    </NavLink>
  )
}

export function Layout() {
  const location = useLocation()
  const [mobileNavOpen, setMobileNavOpen] = useState(false)
  const setSelectedClient = useHubStore((s) => s.setSelectedClient)
  const { users: teamMembers } = useUsers()

  const { invoices } = useInvoices()
  const overdueInvoiceCount = invoices.filter((i) => i.status === 'overdue').length
  const { projects } = useProjects()
  const activeProjects = projects.filter(
    (p) => p.status === 'active' || p.status === 'paused'
  ).length
  const { leads } = useLeads()
  const activeLeads = leads.filter(
    (l) =>
      l.status === 'new' ||
      l.status === 'specification' ||
      l.status === 'quote_sent' ||
      l.status === 'follow_up'
  ).length
  
  const { user, signOutUser } = useAuth()
  const can = (path: string) => canAccessPage(user?.role, path)

  const bizNavActive =
    location.pathname === '/' ||
    location.pathname.startsWith('/clients') ||
    location.pathname.startsWith('/suppliers') ||
    location.pathname.startsWith('/projects') ||
    location.pathname.startsWith('/tasks') ||
    location.pathname.startsWith('/leads') ||
    location.pathname.startsWith('/campaigns') ||
    location.pathname.startsWith('/calendar') ||
    location.pathname.startsWith('/attendance') ||
    location.pathname.startsWith('/vault')

  const financeNavActive =
    location.pathname.startsWith('/finance') ||
    location.pathname.startsWith('/collections') ||
    location.pathname.startsWith('/hosting')

  const toolsNavActive =
    location.pathname.startsWith('/users') ||
    location.pathname.startsWith('/agents')

  const [bizOpen, setBizOpen] = useState(bizNavActive)
  const [financeOpen, setFinanceOpen] = useState(financeNavActive)
  const [toolsOpen, setToolsOpen] = useState(toolsNavActive)

  useEffect(() => {
    if (bizNavActive) setBizOpen(true)
  }, [bizNavActive])

  useEffect(() => {
    if (financeNavActive) setFinanceOpen(true)
  }, [financeNavActive])

  useEffect(() => {
    if (toolsNavActive) setToolsOpen(true)
  }, [toolsNavActive])

  useEffect(() => {
    setMobileNavOpen(false)
  }, [location.pathname])

  useEffect(() => {
    if (!mobileNavOpen) return
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setMobileNavOpen(false)
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [mobileNavOpen])

  useEffect(() => {
    if (!mobileNavOpen) return
    const prev = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => {
      document.body.style.overflow = prev
    }
  }, [mobileNavOpen])

  const closeMobileNav = () => setMobileNavOpen(false)

  const mainUser: TeamMember = useMemo(() => {
    if (!user) {
      return {
        id: '',
        name: 'משתמש',
        avatar: '?',
        color: '#2563EB',
        role: '',
      }
    }
    const fromFs = teamMembers.find((m) => m.id === user.uid)
    if (fromFs) return fromFs
    const name =
      user.displayName?.trim() ||
      user.email?.split('@')[0] ||
      'משתמש'
    return {
      id: user.uid,
      name,
      email: user.email ?? undefined,
      avatar: name.slice(0, 2),
      color: '#2563EB',
      role: 'מחובר',
    }
  }, [user, teamMembers])

  return (
    <>
      <div
        dir="rtl"
        className="flex min-h-[100dvh] flex-col bg-[var(--bg-main)] md:h-[100dvh] md:flex-row md:overflow-hidden"
      >
        <header
          className="sticky top-0 z-[45] flex shrink-0 items-center gap-3 border-b border-white/10 bg-[var(--bg-sidebar)] px-3 py-2.5 shadow-sm md:hidden"
          style={{ paddingTop: 'max(0.5rem, env(safe-area-inset-top, 0px))' }}
        >
          <button
            type="button"
            onClick={() => setMobileNavOpen(true)}
            className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-white/15 bg-white/5 text-white transition hover:bg-white/10 active:scale-[0.98]"
            aria-label="פתח תפריט ניווט"
            aria-expanded={mobileNavOpen}
            aria-controls="app-sidebar"
          >
            <span className="sr-only">פתח תפריט</span>
            <svg
              viewBox="0 0 24 24"
              className="h-6 w-6"
              fill="none"
              stroke="currentColor"
              strokeWidth="1.75"
              strokeLinecap="round"
              aria-hidden
            >
              <path d="M4 7h16M4 12h16M4 17h16" />
            </svg>
          </button>
          <div className="flex items-center gap-2">
            <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br from-[#F97316] to-[#EA580C] font-extrabold text-white text-sm shadow-md shadow-[#F97316]/20">
              H
            </div>
            <span className="text-base font-black tracking-tight text-white">HUB <span className="text-[#F97316]">OS</span></span>
          </div>
          <span className="min-w-0 flex-1 truncate text-left text-xs font-semibold text-white/50">
            האב מערכות מתקדמות
          </span>
        </header>

        {mobileNavOpen ? (
          <button
            type="button"
            className="fixed inset-0 z-[57] cursor-default border-0 bg-black/50 p-0 md:hidden"
            aria-label="סגור תפריט ניווט"
            onClick={closeMobileNav}
          />
        ) : null}

        <aside
          id="app-sidebar"
          role="navigation"
          aria-label="ניווט ראשי"
          className={`flex flex-col bg-[var(--bg-sidebar)] transition-transform duration-200 ease-out max-md:fixed max-md:inset-y-0 max-md:right-0 max-md:z-[58] max-md:h-[100dvh] max-md:w-[min(100vw-0.75rem,300px)] max-md:overflow-y-auto max-md:border-l max-md:border-white/[0.08] max-md:shadow-[-8px_0_32px_rgba(0,0,0,0.25)] md:static md:z-auto md:h-full md:w-[220px] md:shrink-0 md:translate-x-0 md:overflow-y-auto md:border-b-0 md:border-l md:border-white/[0.08] md:shadow-[-4px_0_24px_rgba(0,0,0,0.06)] ${
            mobileNavOpen ? 'max-md:translate-x-0' : 'max-md:pointer-events-none max-md:translate-x-full'
          }`}
          style={{
            paddingBottom: 'max(0.75rem, env(safe-area-inset-bottom, 0px))',
            paddingTop: 'max(0px, env(safe-area-inset-top, 0px))',
          }}
          onClick={(e) => {
            const t = e.target as HTMLElement
            if (t.closest('a[href]')) closeMobileNav()
          }}
        >
          <div className="flex flex-1 flex-col gap-5 p-4">
            <div className="relative border-b border-white/10 pb-4">
              <button
                type="button"
                onClick={closeMobileNav}
                className="absolute left-0 top-0 flex h-9 w-9 items-center justify-center rounded-lg border border-white/15 text-lg font-light leading-none text-white/80 transition hover:bg-white/10 md:hidden"
                aria-label="סגור תפריט"
              >
                ×
              </button>
              
              <div className="flex flex-col items-center justify-center gap-1.5 pt-1">
                <div className="flex items-center gap-2.5">
                  <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-[#F97316] to-[#EA580C] font-black text-white text-xl shadow-lg shadow-[#F97316]/30">
                    H
                  </div>
                  <div className="flex flex-col text-right">
                    <div className="flex items-center gap-1.5">
                      <span className="text-xl font-black tracking-tight text-white">HUB</span>
                      <span className="rounded bg-[#F97316]/20 px-1.5 py-0.5 text-[10px] font-bold tracking-widest text-[#F97316] uppercase border border-[#F97316]/30">OS</span>
                    </div>
                    <span className="text-[10px] font-medium text-white/50 tracking-wide">ניהול טכנולוגיות דיגיטל</span>
                  </div>
                </div>
              </div>

              <p className="mt-4 text-center text-[11px] font-semibold text-white/55">
                שם ארגון:
              </p>
              <p className="mt-0.5 text-center text-[14px] font-bold leading-snug text-[#F97316]">
                האב מערכות מתקדמות
              </p>
            </div>

            <nav className="flex flex-col gap-1.5">
              {/* ניהול עסקי (Biz) */}
              <NavExpandableGroup
                label="ניהול עסקי"
                isSectionActive={bizNavActive}
                open={bizOpen}
                onToggle={() => setBizOpen((v) => !v)}
                icon={
                  <NavGlyph>
                    <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2" />
                    <circle cx="9" cy="7" r="4" />
                    <path d="M23 21v-2a4 4 0 00-3-3.87" />
                  </NavGlyph>
                }
              >
                {can('/') && <NavSubLink to="/" label="לוח בקרה" end />}
                {can('/clients') && <NavSubLink to="/clients" label="לקוחות" />}
                {can('/suppliers') && <NavSubLink to="/suppliers" label="ספקים" />}
                {can('/projects') && (
                  <NavSubLink
                    to="/projects"
                    label={activeProjects > 0 ? `פרויקטים (${activeProjects})` : "פרויקטים"}
                    onClick={() => setSelectedClient(null)}
                  />
                )}
                {can('/tasks') && <NavSubLink to="/tasks" label="משימות" />}
                {can('/leads') && (
                  <NavSubLink 
                    to="/leads" 
                    label={activeLeads > 0 ? `לידים (${activeLeads})` : "לידים"} 
                  />
                )}
                {can('/campaigns') && <NavSubLink to="/campaigns" label="קמפיינים" />}
                {can('/calendar') && <NavSubLink to="/calendar" label="יומן" end />}
                {can('/attendance') && <NavSubLink to="/attendance" label="נוכחות" />}
                {can('/vault') && <NavSubLink to="/vault" label="כספת סיסמאות" />}
              </NavExpandableGroup>

              {/* פיננסים (Finance) */}
              {(can('/finance') || can('/collections') || can('/hosting')) && (
                <NavExpandableGroup
                  label="פיננסים"
                  isSectionActive={financeNavActive}
                  open={financeOpen}
                  onToggle={() => setFinanceOpen((v) => !v)}
                  icon={
                    <NavGlyph>
                      <line x1="12" y1="1" x2="12" y2="23" />
                      <path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6" />
                    </NavGlyph>
                  }
                >
                  {can('/finance') && <NavSubLink to="/finance" label="ניהול פיננסי" end />}
                  {can('/collections') && (
                    <NavSubLink
                      to="/collections"
                      label="גביה"
                      badge={
                        overdueInvoiceCount > 0 ? (
                          <span className="flex h-5 min-w-[20px] shrink-0 items-center justify-center rounded-full bg-[#EF4444] px-1.5 text-[10px] font-bold text-white">
                            {overdueInvoiceCount > 99 ? '99+' : overdueInvoiceCount}
                          </span>
                        ) : undefined
                      }
                    />
                  )}
                  {can('/hosting') && <NavSubLink to="/hosting" label="Shadow IT ואחסון" />}
                </NavExpandableGroup>
              )}

              {/* הגדרות וכלים (Tools) */}
              {(can('/users') || can('/agents-admin')) && (
                <NavExpandableGroup
                  label="הגדרות וכלים"
                  isSectionActive={toolsNavActive}
                  open={toolsOpen}
                  onToggle={() => setToolsOpen((v) => !v)}
                  icon={
                    <NavGlyph>
                      <rect x="2" y="2" width="20" height="8" rx="2" ry="2" />
                      <rect x="2" y="14" width="20" height="8" rx="2" ry="2" />
                      <line x1="6" y1="6" x2="6.01" y2="6" />
                      <line x1="6" y1="18" x2="6.01" y2="18" />
                    </NavGlyph>
                  }
                >
                  {can('/users') && <NavSubLink to="/users" label="משתמשים" />}
                  {can('/agents') && <NavSubLink to="/agents" label="סוכנים חכמים (Agents)" />}
                  {can('/agents/network') && <NavSubLink to="/agents/network" label="רשת סוכנים (Network)" />}
                  {can('/ceo') && <NavSubLink to="/ceo" label="Digital CEO" />}
                  {can('/ai-roi') && <NavSubLink to="/ai-roi" label="AI יחס המרה ויעילות" />}
                </NavExpandableGroup>
              )}
            </nav>

            <div className="mt-auto space-y-2 border-t border-[rgba(255,255,255,0.1)] pt-4">
              <p className="text-[9px] font-semibold uppercase tracking-[2px] text-[rgba(255,255,255,0.3)]">
                GOOGLE WORKSPACE
              </p>
              <ul className="space-y-2">
                {workspaceLinks.map((l) => (
                  <li key={l.label}>
                    <a
                      href={l.href}
                      target="_blank"
                      rel="noreferrer"
                      className="group flex items-center gap-2 text-[12px] text-[rgba(255,255,255,0.55)] transition hover:text-white"
                    >
                      <span
                        className="h-1.5 w-1.5 shrink-0 rounded-full transition group-hover:brightness-125 group-hover:drop-shadow-[0_0_6px_rgba(255,255,255,0.9)]"
                        style={{ backgroundColor: l.dot }}
                      />
                      {l.label}
                    </a>
                  </li>
                ))}
              </ul>
            </div>
          </div>

          <div className="flex flex-col gap-2 border-t border-[rgba(255,255,255,0.1)] p-4">
            <div className="flex items-center gap-2">
              <span
                className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white"
                style={{
                  backgroundColor: `${mainUser.color}44`,
                  border: `1.5px solid ${mainUser.color}`,
                }}
              >
                {mainUser.avatar}
              </span>
              <div className="min-w-0 flex-1">
                <div className="truncate text-sm font-semibold text-white">
                  {mainUser.name}
                </div>
                <div className="truncate text-[12px] font-medium text-slate-300">
                  {mainUser.role === 'admin' || mainUser.role === 'superadmin'
                    ? 'מנהל מערכת'
                    : mainUser.role === 'employee'
                      ? 'עובד'
                      : mainUser.role || mainUser.email || ''}
                </div>
              </div>
            </div>
            <button
              type="button"
              onClick={() => void signOutUser()}
              className="w-full rounded-lg border border-white/30 bg-white/10 py-1.5 text-center text-xs font-bold text-white transition hover:bg-white/20 active:scale-[0.98]"
            >
              התנתק
            </button>
          </div>
        </aside>

        <main className="relative min-h-0 w-full min-w-0 flex-1 overflow-y-auto overflow-x-hidden bg-[var(--bg-main)]">
          <QuickActionsFloat />
          <QuickTasksSlider />
          <QuickCalendarSlider />
          <TaskDrawer />
          <MeetingDrawer />
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
