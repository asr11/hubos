// @ts-nocheck
import {
  useState,
  useRef,
  useEffect,
  useMemo,
  type KeyboardEvent,
} from 'react'
import {
  FolderKanban,
  CheckSquare,
  Search,
  X,
  ChevronDown,
  Building2,
  Check,
  Layers,
  Wallet,
} from 'lucide-react'
import { useClients } from '../../hooks/useClients'
import { useProjects } from '../../hooks/useProjects'
import { useTasks } from '../../hooks/useTasks'
import { hubFetch } from '../../lib/hubFetch'
import type { Task, Project, Client, HoursBank } from '../../types'

export interface SmartComboboxProps {
  selectedProjectId: string | null
  selectedTaskId: string | null
  selectedClientId?: string | null
  selectedHoursBankId?: string | null
  onSelectAssignment: (assignment: {
    projectId: string | null
    taskId: string | null
    clientId?: string | null
    hoursBankId?: string | null
  }) => void
  onClear?: () => void
  disabled?: boolean
  compact?: boolean
  hasError?: boolean
  placeholder?: string
  className?: string
}

export function SmartCombobox({
  selectedProjectId,
  selectedTaskId,
  selectedClientId,
  selectedHoursBankId,
  onSelectAssignment,
  onClear,
  disabled = false,
  compact = false,
  hasError = false,
  placeholder = 'בחר פרויקט (משימה אופציונלית)...',
  className = '',
}: SmartComboboxProps) {
  const { clients } = useClients()
  const { projects } = useProjects()
  const { tasks } = useTasks()

  const [hoursBanks, setHoursBanks] = useState<HoursBank[]>([])
  const [isOpen, setIsOpen] = useState(false)
  const [searchQuery, setSearchQuery] = useState('')
  const [activeTab, setActiveTab] = useState<'all' | 'projects' | 'tasks' | 'clients' | 'banks'>('all')
  const [filterClientId, setFilterClientId] = useState<string | null>(null)

  const containerRef = useRef<HTMLDivElement>(null)
  const searchInputRef = useRef<HTMLInputElement>(null)

  // Close when clicking outside
  useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setIsOpen(false)
      }
    }
    if (isOpen) {
      document.addEventListener('mousedown', handleClickOutside)
      return () => document.removeEventListener('mousedown', handleClickOutside)
    }
  }, [isOpen])

  // Focus search input when opened
  useEffect(() => {
    if (isOpen) {
      const timer = setTimeout(() => {
        searchInputRef.current?.focus()
      }, 50)
      return () => clearTimeout(timer)
    } else {
      setSearchQuery('')
      setFilterClientId(null)
    }
  }, [isOpen])

  // Fetch hours banks
  useEffect(() => {
    let mounted = true
    hubFetch('/api/documents/hours_banks')
      .then((res: any) => (res.ok ? res.json() : []))
      .then((data) => {
        if (mounted && Array.isArray(data)) setHoursBanks(data)
      })
      .catch(() => {})
    return () => {
      mounted = false
    }
  }, [])

  // Lookup maps
  const projectMap = useMemo(() => {
    const map = new Map<string, Project>()
    for (const p of projects || []) {
      if (p && p.id) map.set(p.id, p)
    }
    return map
  }, [projects])

  const clientMap = useMemo(() => {
    const map = new Map<string, Client>()
    for (const c of clients || []) {
      if (c && c.id) map.set(c.id, c)
    }
    return map
  }, [clients])

  // Resolved entities
  const activeTask = useMemo(() => {
    if (!selectedTaskId) return null
    return (tasks || []).find((t) => t.id === selectedTaskId) || null
  }, [tasks, selectedTaskId])

  const activeBank = useMemo(() => {
    if (!selectedHoursBankId) return null
    return (hoursBanks || []).find((b) => b.id === selectedHoursBankId) || null
  }, [hoursBanks, selectedHoursBankId])

  const activeProject = useMemo(() => {
    if (selectedProjectId) {
      return projectMap.get(selectedProjectId) || null
    }
    if (activeTask?.projectId) {
      return projectMap.get(activeTask.projectId) || null
    }
    return null
  }, [selectedProjectId, activeTask, projectMap])

  const activeClient = useMemo(() => {
    if (selectedClientId) {
      return clientMap.get(selectedClientId) || null
    }
    if (activeProject?.clientId) {
      return clientMap.get(activeProject.clientId) || null
    }
    if (activeTask?.clientId) {
      return clientMap.get(activeTask.clientId) || null
    }
    if (activeBank?.clientId) {
      return clientMap.get(activeBank.clientId) || null
    }
    return null
  }, [selectedClientId, activeProject, activeTask, activeBank, clientMap])

  // Tasks filtered and sorted (open first)
  const sortedTasks = useMemo(() => {
    let list = Array.isArray(tasks) ? [...tasks] : []
    if (filterClientId) {
      const clientProjIds = new Set(
        (projects || []).filter((p) => p.clientId === filterClientId).map((p) => p.id),
      )
      list = list.filter(
        (t) => t.clientId === filterClientId || (t.projectId && clientProjIds.has(t.projectId)),
      )
    }
    return list.sort((a, b) => {
      if (a.status === 'done' && b.status !== 'done') return 1
      if (a.status !== 'done' && b.status === 'done') return -1
      return (b.createdAt || '').localeCompare(a.createdAt || '')
    })
  }, [tasks, filterClientId, projects])

  // Projects filtered
  const sortedProjects = useMemo(() => {
    let list = Array.isArray(projects) ? [...projects] : []
    if (filterClientId) {
      list = list.filter((p) => p.clientId === filterClientId)
    }
    return list.sort((a, b) => (a.name || '').localeCompare(b.name || ''))
  }, [projects, filterClientId])

  // Hours Banks filtered
  const sortedBanks = useMemo(() => {
    let list = Array.isArray(hoursBanks) ? [...hoursBanks] : []
    if (filterClientId) {
      list = list.filter((b) => b.clientId === filterClientId)
    }
    return list.sort((a, b) => (a.name || '').localeCompare(b.name || ''))
  }, [hoursBanks, filterClientId])

  // Search Filter
  const { filteredProjects, filteredTasks, filteredClients, filteredBanks, isSearching } = useMemo(() => {
    const q = searchQuery.toLowerCase().trim()
    if (!q) {
      return {
        filteredProjects: sortedProjects,
        filteredTasks: sortedTasks,
        filteredClients: Array.isArray(clients) ? clients : [],
        filteredBanks: sortedBanks,
        isSearching: false,
      }
    }

    const mProjects = (projects || []).filter((p) => {
      const nameMatch = p.name?.toLowerCase().includes(q)
      const client = p.clientId ? clientMap.get(p.clientId) : null
      const clientMatch = client?.name?.toLowerCase().includes(q)
      return nameMatch || clientMatch
    })

    const mTasks = (tasks || []).filter((t) => {
      const titleMatch = t.title?.toLowerCase().includes(q)
      const proj = t.projectId ? projectMap.get(t.projectId) : null
      const projMatch = proj?.name?.toLowerCase().includes(q)
      const client =
        (t.clientId ? clientMap.get(t.clientId) : null) ||
        (proj?.clientId ? clientMap.get(proj.clientId) : null)
      const clientMatch = client?.name?.toLowerCase().includes(q)
      return titleMatch || projMatch || clientMatch
    })

    const mClients = (clients || []).filter((c) => c.name?.toLowerCase().includes(q))

    const mBanks = (hoursBanks || []).filter((b) => {
      const nameMatch = b.name?.toLowerCase().includes(q)
      const client = b.clientId ? clientMap.get(b.clientId) : null
      const clientMatch = client?.name?.toLowerCase().includes(q)
      return nameMatch || clientMatch
    })

    return {
      filteredProjects: mProjects,
      filteredTasks: mTasks,
      filteredClients: mClients,
      filteredBanks: mBanks,
      isSearching: true,
    }
  }, [searchQuery, sortedProjects, sortedTasks, sortedBanks, projects, tasks, clients, hoursBanks, projectMap, clientMap])

  // Select project directly (task is optional / null)
  const handleSelectProject = (project: Project) => {
    onSelectAssignment({
      projectId: project.id,
      taskId: null, // Task selection is optional!
      clientId: project.clientId || null,
      hoursBankId: selectedHoursBankId || null,
    })
    setIsOpen(false)
  }

  // Select task (and its parent project)
  const handleSelectTask = (task: Task) => {
    let projId = task.projectId || selectedProjectId || null
    let clId = task.clientId || null

    if (!projId && task.clientId) {
      const clientProj = (projects || []).find((p) => p.clientId === task.clientId)
      if (clientProj) projId = clientProj.id
    }
    if (!projId && (projects || []).length > 0) {
      projId = projects[0].id
    }

    if (projId && !clId) {
      const parentProj = projectMap.get(projId)
      if (parentProj?.clientId) clId = parentProj.clientId
    }

    onSelectAssignment({
      projectId: projId,
      taskId: task.id,
      clientId: clId,
      hoursBankId: selectedHoursBankId || null,
    })
    setIsOpen(false)
  }

  // Select hours bank
  const handleSelectBank = (bank: HoursBank) => {
    const bankProjectId = (bank as any).projectId || (bank as any).project_id || null
    const clientProj = (projects || []).find((p) => p.clientId === bank.clientId)
    const targetProjId = bankProjectId || clientProj?.id || null

    onSelectAssignment({
      projectId: targetProjId,
      taskId: null,
      clientId: bank.clientId,
      hoursBankId: bank.id,
    })
    setIsOpen(false)
  }

  const executeClear = () => {
    if (onClear) {
      onClear()
    } else {
      onSelectAssignment({
        projectId: null,
        taskId: null,
        clientId: null,
        hoursBankId: null,
      })
    }
  }

  const handleClear = (e: React.MouseEvent) => {
    e.stopPropagation()
    executeClear()
  }

  const handleKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Escape') {
      setIsOpen(false)
    } else if (e.key === 'Enter') {
      // Select first project, task or bank if available
      if (filteredProjects.length > 0) {
        handleSelectProject(filteredProjects[0])
      } else if (filteredBanks.length > 0) {
        handleSelectBank(filteredBanks[0])
      } else if (filteredTasks.length > 0) {
        handleSelectTask(filteredTasks[0])
      }
    }
  }

  const hasAssignment = Boolean(activeProject || activeTask || selectedHoursBankId)

  return (
    <div className={`relative ${className}`} ref={containerRef} dir="rtl">
      {/* Trigger Button */}
      <button
        type="button"
        disabled={disabled}
        onClick={() => setIsOpen((prev) => !prev)}
        className={`group flex items-center justify-between gap-2 px-3 py-1.5 rounded-xl text-xs font-medium transition-all max-w-[340px] sm:max-w-[420px] cursor-pointer ${
          disabled ? 'opacity-50 cursor-not-allowed' : ''
        } ${
          hasError
            ? 'border border-rose-500 bg-rose-500/10 text-rose-600 dark:text-rose-400 ring-2 ring-rose-500/20'
            : activeTask
            ? 'border border-blue-500/30 bg-blue-500/10 text-blue-700 dark:text-blue-300 hover:bg-blue-500/15'
            : activeProject
            ? 'border border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300 hover:bg-amber-500/15'
            : selectedHoursBankId
            ? 'border border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 hover:bg-emerald-500/15'
            : 'border border-[var(--border)] bg-[var(--surface-muted)]/70 text-[var(--text-mid)] hover:border-emerald-500/40 hover:text-[var(--text-dark)] hover:bg-[var(--surface-hover)]'
        } ${compact ? 'py-1 px-2.5 text-[11px]' : ''}`}
        title={
          activeTask
            ? `משימה: ${activeTask.title} | פרויקט: ${activeProject?.name || 'לא משויך'}`
            : activeProject
            ? `פרויקט: ${activeProject.name}${activeBank ? ` | בנק שעות: ${activeBank.name}` : ''}`
            : activeBank
            ? `בנק שעות: ${activeBank.name}`
            : selectedHoursBankId
            ? 'בנק שעות נבחר'
            : placeholder
        }
      >
        <div className="flex items-center gap-1.5 min-w-0 truncate">
          {activeTask ? (
            <>
              <CheckSquare size={13} className="shrink-0 text-blue-500" />
              <div className="flex items-center gap-1 truncate">
                {activeProject && (
                  <span className="opacity-75 font-normal truncate">
                    {activeProject.name} /
                  </span>
                )}
                <span className="font-semibold truncate">{activeTask.title}</span>
              </div>
            </>
          ) : activeProject ? (
            <>
              <FolderKanban size={13} className="shrink-0 text-amber-500" />
              <div className="flex items-center gap-1 truncate">
                {activeClient && (
                  <span className="opacity-75 font-normal truncate text-[11px]">
                    🏢 {activeClient.name} •
                  </span>
                )}
                <span className="font-semibold truncate">{activeProject.name}</span>
                {activeBank ? (
                  <span className="text-[10px] opacity-90 px-1 py-0.2 rounded bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 shrink-0 font-medium">
                    {activeBank.name}
                  </span>
                ) : (
                  <span className="text-[10px] opacity-70 px-1 py-0.2 rounded bg-amber-500/20 shrink-0">
                    פרויקט בלבד
                  </span>
                )}
              </div>
            </>
          ) : activeBank || selectedHoursBankId ? (
            <>
              <Wallet size={13} className="shrink-0 text-emerald-500" />
              <div className="flex items-center gap-1 truncate">
                {activeClient && (
                  <span className="opacity-75 font-normal truncate text-[11px]">
                    🏢 {activeClient.name} •
                  </span>
                )}
                <span className="font-semibold truncate">{activeBank?.name || 'בנק שעות'}</span>
                <span className="text-[10px] opacity-90 px-1 py-0.2 rounded bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 shrink-0 font-medium">
                  בנק שעות
                </span>
              </div>
            </>
          ) : (
            <>
              <FolderKanban size={13} className="shrink-0 opacity-50" />
              <span className="truncate opacity-80">{placeholder}</span>
            </>
          )}
        </div>

        <div className="flex items-center gap-1 shrink-0 mr-1">
          {hasAssignment && !disabled && (
            <span
              role="button"
              tabIndex={0}
              onClick={handleClear}
              onKeyDown={(e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                  e.stopPropagation()
                  executeClear()
                }
              }}
              className="p-0.5 rounded-full hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-400 hover:text-rose-500 transition-colors"
              title="נקה שיוך"
            >
              <X size={11} />
            </span>
          )}
          <ChevronDown
            size={12}
            className={`opacity-60 transition-transform ${isOpen ? 'rotate-180' : ''}`}
          />
        </div>
      </button>

      {/* Floating Dropdown Picker */}
      {isOpen && (
        <div className="absolute top-full mt-1.5 right-0 w-80 sm:w-96 max-h-[460px] bg-[var(--bg-card)] border border-[var(--border)] rounded-2xl shadow-2xl p-2.5 z-50 flex flex-col gap-2 animate-in fade-in slide-in-from-top-1 text-right">
          {/* Search Box */}
          <div className="relative">
            <Search
              size={13}
              className="absolute right-3 top-1/2 -translate-y-1/2 text-[var(--text-light)]"
            />
            <input
              ref={searchInputRef}
              type="text"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              onKeyDown={handleKeyDown}
              placeholder="חפש פרויקט, משימה או לקוח..."
              className="w-full text-xs bg-[var(--surface-muted)] text-[var(--text-dark)] pr-8.5 pl-7 py-2 rounded-xl border border-[var(--border)] focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/30 transition-all placeholder:text-[var(--text-light)]"
            />
            {searchQuery && (
              <button
                type="button"
                onClick={() => setSearchQuery('')}
                className="absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--text-light)] hover:text-[var(--text-dark)] p-0.5"
              >
                <X size={12} />
              </button>
            )}
          </div>

          {/* Filter Pills / Tabs */}
          {!isSearching && (
            <div className="flex items-center gap-1 border-b border-[var(--border)] pb-1.5 text-xs">
              <button
                type="button"
                onClick={() => setActiveTab('all')}
                className={`flex items-center gap-1 px-2.5 py-1 rounded-lg font-medium transition-all ${
                  activeTab === 'all'
                    ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 font-bold'
                    : 'text-[var(--text-mid)] hover:bg-[var(--surface-hover)]'
                }`}
              >
                <Layers size={11} />
                <span>הכל</span>
              </button>

              <button
                type="button"
                onClick={() => setActiveTab('projects')}
                className={`flex items-center gap-1 px-2.5 py-1 rounded-lg font-medium transition-all ${
                  activeTab === 'projects'
                    ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400 font-bold'
                    : 'text-[var(--text-mid)] hover:bg-[var(--surface-hover)]'
                }`}
              >
                <FolderKanban size={11} />
                <span>פרויקטים</span>
                <span className="text-[10px] opacity-75">({sortedProjects.length})</span>
              </button>

              <button
                type="button"
                onClick={() => setActiveTab('banks')}
                className={`flex items-center gap-1 px-2.5 py-1 rounded-lg font-medium transition-all ${
                  activeTab === 'banks'
                    ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 font-bold'
                    : 'text-[var(--text-mid)] hover:bg-[var(--surface-hover)]'
                }`}
              >
                <Wallet size={11} />
                <span>בנקי שעות</span>
                <span className="text-[10px] opacity-75">({sortedBanks.length})</span>
              </button>

              <button
                type="button"
                onClick={() => setActiveTab('tasks')}
                className={`flex items-center gap-1 px-2.5 py-1 rounded-lg font-medium transition-all ${
                  activeTab === 'tasks'
                    ? 'bg-blue-500/10 text-blue-600 dark:text-blue-400 font-bold'
                    : 'text-[var(--text-mid)] hover:bg-[var(--surface-hover)]'
                }`}
              >
                <CheckSquare size={11} />
                <span>משימות</span>
                <span className="text-[10px] opacity-75">({sortedTasks.length})</span>
              </button>

              <button
                type="button"
                onClick={() => setActiveTab('clients')}
                className={`flex items-center gap-1 px-2.5 py-1 rounded-lg font-medium transition-all ${
                  activeTab === 'clients'
                    ? 'bg-purple-500/10 text-purple-600 dark:text-purple-400 font-bold'
                    : 'text-[var(--text-mid)] hover:bg-[var(--surface-hover)]'
                }`}
              >
                <Building2 size={11} />
                <span>לקוחות</span>
              </button>
            </div>
          )}

          {/* Active Client Filter Pill */}
          {filterClientId && (
            <div className="flex items-center justify-between bg-emerald-50 dark:bg-emerald-950/40 px-2.5 py-1 rounded-lg text-[11px] text-emerald-700 dark:text-emerald-300 border border-emerald-500/20">
              <div className="flex items-center gap-1.5 truncate">
                <Building2 size={11} className="shrink-0" />
                <span className="truncate">
                  סינון לפי לקוח: {clientMap.get(filterClientId)?.name || filterClientId}
                </span>
              </div>
              <button
                type="button"
                onClick={() => setFilterClientId(null)}
                className="text-[10px] underline hover:text-emerald-900 dark:hover:text-emerald-100 shrink-0"
              >
                הצג הכל
              </button>
            </div>
          )}

          {/* Options List */}
          <div className="overflow-y-auto max-h-72 space-y-1.5 px-0.5">
            {/* If No Results */}
            {filteredProjects.length === 0 &&
              filteredBanks.length === 0 &&
              filteredTasks.length === 0 &&
              filteredClients.length === 0 && (
                <div className="text-center py-8 text-xs text-[var(--text-light)]">
                  לא נמצאו פרויקטים, בנקי שעות, משימות או לקוחות תואמים
                </div>
              )}

            {/* HOURS BANKS SECTION */}
            {(activeTab === 'all' || activeTab === 'banks' || isSearching) &&
              filteredBanks.length > 0 && (
                <div className="mb-2">
                  <div className="text-[10px] font-bold text-emerald-600 dark:text-emerald-400 px-1.5 mb-1 flex items-center justify-between">
                    <span className="flex items-center gap-1">
                      <Wallet size={11} />
                      <span>בנקי שעות</span>
                    </span>
                    <span>({filteredBanks.length})</span>
                  </div>

                  <div className="space-y-1">
                    {filteredBanks.map((b) => {
                      const client = b.clientId ? clientMap.get(b.clientId) : null
                      const isSelected = selectedHoursBankId === b.id

                      return (
                        <button
                          key={`bank-${b.id}`}
                          type="button"
                          onClick={() => handleSelectBank(b)}
                          className={`w-full flex items-center justify-between px-2.5 py-2 rounded-xl text-xs text-right transition-all cursor-pointer ${
                            isSelected
                              ? 'bg-emerald-500/15 border border-emerald-500/40 text-emerald-900 dark:text-emerald-200 shadow-xs'
                              : 'hover:bg-[var(--surface-hover)] border border-transparent text-[var(--text-dark)]'
                          }`}
                        >
                          <div className="flex items-center gap-2 truncate flex-1">
                            <Wallet size={13} className="text-emerald-500 shrink-0" />
                            <div className="truncate">
                              <div className="font-semibold text-[var(--text-dark)] truncate">
                                {b.name}
                              </div>
                              {client && (
                                <div className="text-[10px] text-[var(--text-light)] truncate">
                                  🏢 {client.name}
                                </div>
                              )}
                            </div>
                          </div>

                          <div className="flex items-center gap-1.5 shrink-0 mr-2">
                            <span className="text-[10px] px-1.5 py-0.5 rounded-md bg-[var(--surface-muted)] text-[var(--text-light)]">
                              {b.totalHours} שעות
                            </span>
                            {isSelected ? (
                              <Check size={13} className="text-emerald-600" />
                            ) : (
                              <span className="text-[10px] text-emerald-600 font-medium opacity-0 group-hover:opacity-100 hover:opacity-100">
                                שייך ←
                              </span>
                            )}
                          </div>
                        </button>
                      )
                    })}
                  </div>
                </div>
              )}

            {/* PROJECTS SECTION (1-click direct project assignment - Task is OPTIONAL!) */}
            {(activeTab === 'all' || activeTab === 'projects' || isSearching) &&
              filteredProjects.length > 0 && (
                <div className="mb-2">
                  <div className="text-[10px] font-bold text-amber-600 dark:text-amber-400 px-1.5 mb-1 flex items-center justify-between">
                    <span className="flex items-center gap-1">
                      <FolderKanban size={11} />
                      <span>פרויקטים (שיוך ישיר - משימה אופציונלית)</span>
                    </span>
                    <span>({filteredProjects.length})</span>
                  </div>

                  <div className="space-y-1">
                    {filteredProjects.map((p) => {
                      const client = p.clientId ? clientMap.get(p.clientId) : null
                      const isSelected = selectedProjectId === p.id && !selectedTaskId
                      const taskCount = (tasks || []).filter((t) => t.projectId === p.id).length

                      return (
                        <button
                          key={`proj-${p.id}`}
                          type="button"
                          onClick={() => handleSelectProject(p)}
                          className={`w-full flex items-center justify-between px-2.5 py-2 rounded-xl text-xs text-right transition-all cursor-pointer ${
                            isSelected
                              ? 'bg-amber-500/15 border border-amber-500/40 text-amber-900 dark:text-amber-200 shadow-xs'
                              : 'hover:bg-[var(--surface-hover)] border border-transparent text-[var(--text-dark)]'
                          }`}
                        >
                          <div className="flex items-center gap-2 truncate flex-1">
                            <div
                              className="w-2.5 h-2.5 rounded-full shrink-0"
                              style={{ backgroundColor: p.color || '#F59E0B' }}
                            />
                            <div className="truncate">
                              <div className="font-semibold truncate">{p.name}</div>
                              {client && (
                                <div className="text-[10px] text-[var(--text-light)] truncate">
                                  🏢 {client.name}
                                </div>
                              )}
                            </div>
                          </div>

                          <div className="flex items-center gap-1.5 shrink-0 mr-2">
                            <span className="text-[10px] px-1.5 py-0.5 rounded-md bg-[var(--surface-muted)] text-[var(--text-light)]">
                              {taskCount} משימות
                            </span>
                            {isSelected ? (
                              <Check size={13} className="text-amber-600" />
                            ) : (
                              <span className="text-[10px] text-amber-600 font-medium opacity-0 group-hover:opacity-100 hover:opacity-100">
                                שייך ←
                              </span>
                            )}
                          </div>
                        </button>
                      )
                    })}
                  </div>
                </div>
              )}

            {/* TASKS SECTION (Optional granular task assignment) */}
            {(activeTab === 'all' || activeTab === 'tasks' || isSearching) &&
              filteredTasks.length > 0 && (
                <div className="mb-2">
                  <div className="text-[10px] font-bold text-blue-600 dark:text-blue-400 px-1.5 mb-1 flex items-center justify-between">
                    <span className="flex items-center gap-1">
                      <CheckSquare size={11} />
                      <span>משימות (שיוך מפורט אופציונלי)</span>
                    </span>
                    <span>({filteredTasks.length})</span>
                  </div>

                  <div className="space-y-1">
                    {filteredTasks.map((t) => {
                      const proj = t.projectId ? projectMap.get(t.projectId) : null
                      const client =
                        (t.clientId ? clientMap.get(t.clientId) : null) ||
                        (proj?.clientId ? clientMap.get(proj.clientId) : null)
                      const isSelected = selectedTaskId === t.id
                      const isDone = t.status === 'done'

                      return (
                        <button
                          key={`task-${t.id}`}
                          type="button"
                          onClick={() => handleSelectTask(t)}
                          className={`w-full flex flex-col gap-0.5 px-2.5 py-1.5 rounded-xl text-right text-xs transition-all cursor-pointer ${
                            isSelected
                              ? 'bg-blue-500/15 border border-blue-500/40 text-blue-900 dark:text-blue-200 shadow-xs'
                              : 'hover:bg-[var(--surface-hover)] border border-transparent text-[var(--text-dark)]'
                          }`}
                        >
                          <div className="flex items-center gap-1.5 font-medium">
                            <CheckSquare
                              size={12}
                              className={`shrink-0 ${
                                isSelected
                                  ? 'text-blue-600'
                                  : isDone
                                  ? 'text-slate-400'
                                  : 'text-blue-500'
                              }`}
                            />
                            <span
                              className={`truncate flex-1 ${
                                isDone ? 'line-through opacity-60' : 'font-medium'
                              }`}
                            >
                              {t.title}
                            </span>
                            {isSelected && (
                              <Check size={12} className="text-blue-600 shrink-0 mr-1" />
                            )}
                          </div>

                          {(proj || client) && (
                            <div className="text-[10px] text-[var(--text-light)] flex items-center gap-2 pr-4 truncate">
                              {proj && (
                                <span className="text-amber-600 dark:text-amber-400 truncate">
                                  📁 {proj.name}
                                </span>
                              )}
                              {client && (
                                <span className="text-purple-600 dark:text-purple-400 truncate">
                                  🏢 {client.name}
                                </span>
                              )}
                            </div>
                          )}
                        </button>
                      )
                    })}
                  </div>
                </div>
              )}

            {/* CLIENTS SECTION (Filter by client) */}
            {(activeTab === 'clients' || (isSearching && filteredClients.length > 0)) && (
              <div className="mb-2">
                <div className="text-[10px] font-bold text-purple-600 dark:text-purple-400 px-1.5 mb-1 flex items-center justify-between">
                  <span className="flex items-center gap-1">
                    <Building2 size={11} />
                    <span>לקוחות (לחץ לסינון פרויקטים)</span>
                  </span>
                  <span>({filteredClients.length})</span>
                </div>

                <div className="space-y-1">
                  {filteredClients.map((c) => {
                    const clientProjects = (projects || []).filter((p) => p.clientId === c.id)
                    return (
                      <button
                        key={`client-${c.id}`}
                        type="button"
                        onClick={() => {
                          setFilterClientId(c.id)
                          setActiveTab('projects')
                          setSearchQuery('')
                        }}
                        className="w-full flex items-center justify-between px-2.5 py-1.5 rounded-xl hover:bg-[var(--surface-hover)] text-xs text-right transition-all cursor-pointer border border-transparent"
                      >
                        <div className="flex items-center gap-2 truncate">
                          <Building2 size={12} className="text-purple-500 shrink-0" />
                          <span className="font-semibold text-[var(--text-dark)] truncate">
                            {c.name}
                          </span>
                        </div>
                        <span className="text-[10px] px-1.5 py-0.5 rounded bg-[var(--surface-muted)] text-[var(--text-light)] shrink-0">
                          {clientProjects.length} פרויקטים ←
                        </span>
                      </button>
                    )
                  })}
                </div>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  )
}
