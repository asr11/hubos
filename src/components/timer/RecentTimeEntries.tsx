import { useState, useEffect, useMemo, useCallback } from 'react'
import { Link } from 'react-router-dom'
import {
  Clock,
  Play,
  Trash2,
  RefreshCw,
  Search,
  FolderKanban,
  CheckCircle2,
  Building2,
  ArrowRightLeft,
  Calendar,
} from 'lucide-react'
import { useTimer, formatTimerDigits, type TimeEntry } from '../../contexts/TimerContext'
import { timeEntriesApi } from '../../services/api'
import { useProjects } from '../../hooks/useProjects'
import { useClients } from '../../hooks/useClients'
import { toastFsSuccess, toastFsError } from '../../lib/fsToast'

interface RecentTimeEntriesProps {
  className?: string
  compact?: boolean
  maxEntries?: number
  showSearch?: boolean
  title?: string
}

function formatHHMM(isoString?: string | null): string {
  if (!isoString) return ''
  try {
    const d = new Date(isoString)
    if (isNaN(d.getTime())) return ''
    return d.toLocaleTimeString('he-IL', { hour: '2-digit', minute: '2-digit', hour12: false })
  } catch {
    return ''
  }
}

function getTimeRangeDisplay(entry: TimeEntry): { startStr: string; endStr: string; rangeStr: string } {
  let startStr = formatHHMM(entry.start_time)
  let endStr = formatHHMM(entry.end_time)

  // Fallback: If start_time or end_time is missing, calculate from date & duration_seconds
  if (!startStr || !endStr) {
    if (entry.date) {
      try {
        const endDate = new Date(entry.date)
        if (!isNaN(endDate.getTime())) {
          const durationMs = Math.max(0, (entry.duration_seconds || 0) * 1000)
          const startDate = new Date(endDate.getTime() - durationMs)
          if (!startStr) startStr = formatHHMM(startDate.toISOString())
          if (!endStr) endStr = formatHHMM(endDate.toISOString())
        }
      } catch {}
    }
  }

  const rangeStr = startStr && endStr ? `${startStr} - ${endStr}` : startStr || endStr || '—'
  return { startStr, endStr, rangeStr }
}

function getDateKey(entry: TimeEntry): string {
  if (entry.start_time) {
    return entry.start_time.slice(0, 10)
  }
  if (entry.date) {
    return entry.date.slice(0, 10)
  }
  return new Date().toISOString().slice(0, 10)
}

function getDateGroupLabel(dateKey: string): string {
  try {
    const d = new Date(dateKey)
    if (isNaN(d.getTime())) return dateKey

    const today = new Date()
    const todayKey = today.toISOString().slice(0, 10)
    if (dateKey === todayKey) return 'היום'

    const yesterday = new Date()
    yesterday.setDate(yesterday.getDate() - 1)
    const yesterdayKey = yesterday.toISOString().slice(0, 10)
    if (dateKey === yesterdayKey) return 'אתמול'

    return d.toLocaleDateString('he-IL', {
      weekday: 'short',
      day: 'numeric',
      month: 'short',
      year: 'numeric',
    })
  } catch {
    return dateKey
  }
}

// Color palette for projects
const PROJECT_COLORS = [
  '#10B981', '#3B82F6', '#8B5CF6', '#F59E0B', '#EC4899', '#06B6D4', '#14B8A6', '#F97316'
]

function getProjectColor(projectId?: string | null, name?: string | null): string {
  if (!projectId && !name) return '#64748B'
  const str = (projectId || '') + (name || '')
  let hash = 0
  for (let i = 0; i < str.length; i++) {
    hash = str.charCodeAt(i) + ((hash << 5) - hash)
  }
  const index = Math.abs(hash) % PROJECT_COLORS.length
  return PROJECT_COLORS[index]
}

export function RecentTimeEntries({
  className = '',
  compact = false,
  maxEntries = 50,
  showSearch = true,
  title = 'יומן שעות שדווחו (Recent Entries)',
}: RecentTimeEntriesProps) {
  const { lastSavedEntry, startTimer } = useTimer()
  const { projects } = useProjects()
  const { clients } = useClients()

  const [entries, setEntries] = useState<TimeEntry[]>([])
  const [loading, setLoading] = useState(false)
  const [searchTerm, setSearchTerm] = useState('')
  const [deletingId, setDeletingId] = useState<string | null>(null)

  // Map lookups
  const projectMap = useMemo(() => {
    const map = new Map<string, { name: string; clientId?: string | null; color?: string }>()
    for (const p of Array.isArray(projects) ? projects : []) {
      if (p?.id) {
        map.set(p.id, {
          name: p.name || 'פרויקט',
          clientId: p.clientId || null,
          color: (p as any).color || undefined,
        })
      }
    }
    return map
  }, [projects])

  const clientMap = useMemo(() => {
    const map = new Map<string, string>()
    for (const c of Array.isArray(clients) ? clients : []) {
      if (c?.id) {
        map.set(c.id, c.name || '')
      }
    }
    return map
  }, [clients])

  const fetchEntries = useCallback(async () => {
    try {
      setLoading(true)
      const data = await timeEntriesApi.getAll()
      const list = Array.isArray(data) ? data : []
      setEntries(list)
    } catch (err: any) {
      console.error('Failed to fetch time entries:', err)
    } finally {
      setLoading(false)
    }
  }, [])

  // Auto-fetch on mount and auto-refresh when a new entry is saved
  useEffect(() => {
    fetchEntries()
  }, [fetchEntries, lastSavedEntry])

  useEffect(() => {
    const handleSaved = () => {
      fetchEntries()
    }
    window.addEventListener('hub-time-entry-saved', handleSaved)
    return () => window.removeEventListener('hub-time-entry-saved', handleSaved)
  }, [fetchEntries])

  // Delete an entry
  const handleDelete = async (id: string, e: React.MouseEvent) => {
    e.stopPropagation()
    try {
      setDeletingId(id)
      await timeEntriesApi.delete(id)
      setEntries((prev) => prev.filter((item: any) => item.id !== id))
      toastFsSuccess('רשומת הזמן נמחקה בהצלחה')
    } catch (err: any) {
      toastFsError('שגיאה במחיקת רשומת הזמן', err, 'deleteTimeEntry')
    } finally {
      setDeletingId(null)
    }
  }

  // Quick Resume: Load entry parameters into timer and start
  const handleResume = (entry: TimeEntry, e: React.MouseEvent) => {
    e.stopPropagation()
    startTimer({
      projectId: entry.project_id || null,
      taskId: entry.task_id || null,
      clientId: entry.client_id || null,
      description: entry.description || '',
    })
    toastFsSuccess(`הטיימר הופעל מחדש עבור "${entry.description || entry.project_name || 'סשן עבודה'}"`)
  }

  // Filter and limit
  const filteredEntries = useMemo(() => {
    let result = entries
    if (searchTerm.trim()) {
      const q = searchTerm.trim().toLowerCase()
      result = result.filter((item: any) => {
        const desc = (item.description || '').toLowerCase()
        const proj = (item.project_name || projectMap.get(item.project_id || '')?.name || '').toLowerCase()
        const task = (item.task_title || '').toLowerCase()
        const client = (item.client_name || clientMap.get(item.client_id || '') || '').toLowerCase()
        return desc.includes(q) || proj.includes(q) || task.includes(q) || client.includes(q)
      })
    }
    return result.slice(0, maxEntries)
  }, [entries, searchTerm, maxEntries, projectMap, clientMap])

  // Group by Date
  const groupedEntries = useMemo(() => {
    const groups: { [dateKey: string]: { dateKey: string; items: TimeEntry[]; totalSeconds: number } } = {}

    for (const item of filteredEntries) {
      const key = getDateKey(item)
      if (!groups[key]) {
        groups[key] = { dateKey: key, items: [], totalSeconds: 0 }
      }
      groups[key].items.push(item)
      groups[key].totalSeconds += Math.max(0, Number(item.duration_seconds) || 0)
    }

    return Object.values(groups).sort((a, b) => b.dateKey.localeCompare(a.dateKey))
  }, [filteredEntries])

  const totalFilteredSeconds = useMemo(() => {
    return filteredEntries.reduce((sum, item) => sum + Math.max(0, Number(item.duration_seconds) || 0), 0)
  }, [filteredEntries])

  return (
    <div
      dir="rtl"
      className={`rounded-2xl bg-[var(--bg-card)] border border-[var(--border)] shadow-xs transition-all ${className}`}
    >
      {/* Header */}
      <div className={`${compact ? 'p-3 sm:p-3.5' : 'p-4 sm:p-5'} border-b border-[var(--border)] flex flex-wrap items-center justify-between gap-3`}>
        <div className="flex items-center gap-3">
          <div className={`${compact ? 'w-8 h-8' : 'w-9 h-9'} rounded-xl bg-orange-500/10 border border-orange-500/20 text-orange-500 flex items-center justify-center shrink-0`}>
            <Clock size={compact ? 16 : 18} className="stroke-[2.2]" />
          </div>
          <div>
            <h2 className={`${compact ? 'text-sm sm:text-base' : 'text-base sm:text-lg'} font-bold text-[var(--text-dark)] flex items-center gap-2`}>
              <span>{title}</span>
              <span className="text-xs px-2 py-0.5 rounded-full bg-[var(--surface-muted)] text-[var(--text-mid)] font-mono font-semibold">
                {filteredEntries.length}
              </span>
            </h2>
            <p className="text-xs text-[var(--text-light)]">
              תצוגת זמני התחלה וסיום (Timesheet), משך פעילות ושיוך לפרויקטים
            </p>
          </div>
        </div>

        {/* Total & Controls */}
        <div className="flex items-center gap-3 mr-auto sm:mr-0">
          <div className="px-3 py-1.5 rounded-xl bg-[var(--surface-muted)] border border-[var(--border)] flex items-center gap-2 text-xs">
            <span className="text-[var(--text-light)]">סה״כ שעות:</span>
            <span className="font-mono font-bold text-emerald-500 text-sm">
              {formatTimerDigits(totalFilteredSeconds)}
            </span>
          </div>

          <button
            type="button"
            onClick={fetchEntries}
            disabled={loading}
            className="p-2 rounded-xl border border-[var(--border)] hover:bg-[var(--surface-hover)] text-[var(--text-mid)] transition-colors cursor-pointer disabled:opacity-50"
            title="רענן רשימה"
          >
            <RefreshCw size={15} className={loading ? 'animate-spin text-orange-500' : ''} />
          </button>
        </div>
      </div>

      {/* Filter / Search Bar */}
      {showSearch && entries.length > 5 && (
        <div className="px-4 py-2.5 border-b border-[var(--border)] bg-[var(--bg-main)]/50 flex items-center gap-2">
          <Search size={14} className="text-[var(--text-light)] shrink-0" />
          <input
            type="text"
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
            placeholder="סנן לפי תיאור, פרויקט, משימה או לקוח..."
            className="w-full bg-transparent text-xs text-[var(--text-dark)] placeholder:text-[var(--text-light)] focus:outline-none"
          />
          {searchTerm && (
            <button
              type="button"
              onClick={() => setSearchTerm('')}
              className="text-xs text-[var(--text-light)] hover:text-[var(--text-dark)] px-1"
            >
              נקה
            </button>
          )}
        </div>
      )}

      {/* Content List */}
      <div className="divide-y divide-[var(--border)]/70">
        {loading && entries.length === 0 ? (
          <div className="p-12 text-center text-xs text-[var(--text-light)] flex flex-col items-center justify-center gap-2">
            <RefreshCw size={20} className="animate-spin text-orange-500" />
            <span>טוען יומן שעות...</span>
          </div>
        ) : groupedEntries.length === 0 ? (
          <div className="p-10 text-center text-xs text-[var(--text-light)] flex flex-col items-center justify-center gap-2">
            <Clock size={28} className="text-slate-400 opacity-60" />
            <span className="font-medium">אין רשומות זמן להצגה</span>
            <span className="text-[11px] opacity-75">
              הפעל את הטיימר ולחץ "עצור" כדי לשמור דיווח ראשון
            </span>
          </div>
        ) : (
          groupedEntries.map((group) => {
            const dateLabel = getDateGroupLabel(group.dateKey)
            const dayDurationFormatted = formatTimerDigits(group.totalSeconds)

            return (
              <div key={group.dateKey} className="group/day">
                {/* Date Header (Clockify/Toggl style) */}
                <div className="bg-[var(--surface-muted)]/60 px-4 py-2 flex items-center justify-between text-xs font-semibold text-[var(--text-mid)] border-b border-[var(--border)]/50">
                  <div className="flex items-center gap-2">
                    <Calendar size={13} className="text-orange-500 shrink-0" />
                    <span>{dateLabel}</span>
                    <span className="text-[10px] text-[var(--text-light)] font-mono">({group.dateKey})</span>
                  </div>
                  <div className="flex items-center gap-2 font-mono">
                    <span className="text-[11px] text-[var(--text-light)]">סה״כ ליום:</span>
                    <span className="text-xs font-bold text-[var(--text-dark)]">{dayDurationFormatted}</span>
                  </div>
                </div>

                {/* Entry Rows */}
                <div className="divide-y divide-[var(--border)]/40">
                  {group.items.map((entry) => {
                    const resolvedProject =
                      entry.project_name ||
                      projectMap.get(entry.project_id || '')?.name ||
                      (entry.project_id ? 'פרויקט משויך' : 'ללא פרויקט')
                    const resolvedColor =
                      entry.project_color ||
                      projectMap.get(entry.project_id || '')?.color ||
                      getProjectColor(entry.project_id, resolvedProject)
                    const resolvedTask = entry.task_title || (entry.task_id ? 'משימה' : null)
                    const resolvedClient =
                      entry.client_name ||
                      (entry.client_id ? clientMap.get(entry.client_id) : null) ||
                      (entry.project_id ? clientMap.get(projectMap.get(entry.project_id)?.clientId || '') : null)

                    const resolvedClientId =
                      entry.client_id ||
                      (entry.project_id ? projectMap.get(entry.project_id)?.clientId : null) ||
                      null
                    const projectHref = entry.project_id
                      ? `/projects/${entry.project_id}`
                      : '/projects'
                    const clientHref = resolvedClientId
                      ? `/clients/${resolvedClientId}`
                      : resolvedClient
                      ? `/clients?search=${encodeURIComponent(resolvedClient)}`
                      : '/clients'

                    const { rangeStr, startStr, endStr } = getTimeRangeDisplay(entry)
                    const durationStr = formatTimerDigits(entry.duration_seconds || 0)
                    const isDeleting = deletingId === entry.id

                    return (
                      <div
                        key={entry.id}
                        className="px-3 sm:px-4 py-2.5 sm:py-3 hover:bg-[var(--surface-hover)]/70 transition-colors flex flex-wrap sm:flex-nowrap items-center justify-between gap-3 group"
                      >
                        {/* Right / Left Info: Play Button & Description & Badges */}
                        <div className="flex items-center gap-3 min-w-0 flex-1">
                          {/* Quick Play Button */}
                          <button
                            type="button"
                            onClick={(e) => handleResume(entry, e)}
                            className="w-7 h-7 rounded-lg bg-[var(--surface-muted)] hover:bg-orange-500 hover:text-white text-[var(--text-mid)] flex items-center justify-center transition-all shrink-0 cursor-pointer shadow-2xs group-hover:border-orange-500/30"
                            title="הפעל טיימר מחדש עם הגדרות אלו"
                          >
                            <Play size={12} className="fill-current mr-0.5" />
                          </button>

                          {/* Description & Tags */}
                          <div className="min-w-0 flex-1">
                            <div className="flex items-center gap-2">
                              <span
                                className={`text-xs sm:text-sm font-medium truncate ${
                                  entry.description ? 'text-[var(--text-dark)]' : 'text-[var(--text-light)] italic'
                                }`}
                              >
                                {entry.description || 'ללא תיאור'}
                              </span>
                            </div>

                            {/* Tags: Project, Task, Client */}
                            <div className="flex flex-wrap items-center gap-1.5 mt-1">
                              {/* Project Pill */}
                              <Link
                                to={projectHref}
                                onClick={(e) => e.stopPropagation()}
                                className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-semibold bg-[var(--surface-muted)] text-[var(--text-dark)] border border-[var(--border)] hover:bg-[var(--surface-hover)] hover:opacity-90 transition-all cursor-pointer shadow-2xs"
                                style={{ borderColor: `${resolvedColor}40` }}
                                title={entry.project_id ? `מעבר לפרויקט: ${resolvedProject}` : 'מעבר לרשימת פרויקטים'}
                              >
                                <span
                                  className="w-1.5 h-1.5 rounded-full shrink-0"
                                  style={{ backgroundColor: resolvedColor }}
                                />
                                <FolderKanban size={10} className="shrink-0 text-[var(--text-light)]" />
                                <span className="truncate max-w-[120px]">{resolvedProject}</span>
                              </Link>

                              {/* Task Pill */}
                              {resolvedTask && (
                                <span className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md text-[10px] text-[var(--text-mid)] bg-orange-500/10 border border-orange-500/20">
                                  <CheckCircle2 size={10} className="text-orange-500 shrink-0" />
                                  <span className="truncate max-w-[100px]">{resolvedTask}</span>
                                </span>
                              )}

                              {/* Client Pill */}
                              {resolvedClient && (
                                <Link
                                  to={clientHref}
                                  onClick={(e) => e.stopPropagation()}
                                  className="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md text-[10px] text-[var(--text-light)] bg-[var(--bg-main)] border border-[var(--border)] hover:text-[var(--text-dark)] hover:bg-[var(--surface-hover)] hover:border-[var(--border-strong,var(--border))] transition-all cursor-pointer shadow-2xs"
                                  title={resolvedClientId ? `מעבר ללקוח: ${resolvedClient}` : 'מעבר לרשימת לקוחות'}
                                >
                                  <Building2 size={10} className="shrink-0" />
                                  <span className="truncate max-w-[90px]">{resolvedClient}</span>
                                </Link>
                              )}
                            </div>
                          </div>
                        </div>

                        {/* Left / Center Info: Start & Stop Time Range + Duration + Delete */}
                        <div className="flex items-center gap-3 sm:gap-4 shrink-0 mr-auto sm:mr-0">
                          {/* Visual Start & Stop Time (Clockify/Toggl Timesheet Requirement!) */}
                          <div
                            className="flex items-center gap-1.5 text-xs text-[var(--text-mid)] font-mono bg-[var(--surface-muted)]/70 px-2.5 py-1 rounded-lg border border-[var(--border)]/70"
                            title={`התחלה: ${startStr || '—'} | סיום: ${endStr || '—'}`}
                          >
                            <ArrowRightLeft size={11} className="text-[var(--text-light)] shrink-0" />
                            <span className="font-semibold text-[var(--text-dark)]">{rangeStr}</span>
                          </div>

                          {/* Duration Display */}
                          <div className="font-mono text-xs sm:text-sm font-bold text-[var(--text-dark)] min-w-[62px] text-left">
                            {durationStr}
                          </div>

                          {/* Delete Button */}
                          <button
                            type="button"
                            disabled={isDeleting}
                            onClick={(e) => handleDelete(entry.id, e)}
                            className="opacity-60 group-hover:opacity-100 p-1.5 text-[var(--text-light)] hover:text-rose-600 hover:bg-rose-500/10 rounded-lg transition-all shrink-0 cursor-pointer disabled:opacity-30"
                            title="מחק רשומת זמן זו"
                          >
                            <Trash2 size={13} className={isDeleting ? 'animate-pulse text-rose-500' : ''} />
                          </button>
                        </div>
                      </div>
                    )
                  })}
                </div>
              </div>
            )
          })
        )}
      </div>
    </div>
  )
}
