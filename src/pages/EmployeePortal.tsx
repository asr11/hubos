// @ts-nocheck
import { useState, useEffect, useMemo, useCallback } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../contexts/AuthContext'
import { useTimer, formatTimerDigits } from '../contexts/TimerContext'
import { useProjects } from '../hooks/useProjects'
import { tasksApi, timeEntriesApi, usersApi } from '../services/api'
import {
  Play,
  Square,
  Pause,
  Clock,
  Coins,
  CheckCircle2,
  ListTodo,
  TrendingUp,
  Sparkles,
  AlertCircle,
  Timer,
  LogOut,
  Plus,
  RefreshCw,
  Users,
  FolderKanban,
  CheckSquare,
  Wallet,
  ArrowUpRight,
  ArrowDownRight
} from 'lucide-react'

interface TaskItem {
  id: string
  title: string
  description?: string | null
  status: string
  projectId?: string | null
  assignee?: string | null
  createdAt?: string
}

interface TimeEntryItem {
  id: string
  project_id: string
  user_id?: string | null
  task_id?: string | null
  duration_seconds: number
  description?: string
  date: string
}

export function EmployeePortal() {
  const { user, signOutUser } = useAuth()
  const { projects } = useProjects()
  const {
    isRunning: timerRunning,
    isPaused,
    elapsedSeconds,
    description: customNote,
    setDescription: setCustomNote,
    selectedProjectId,
    setSelectedProjectId,
    selectedTaskId,
    setSelectedTaskId,
    startTimer,
    pauseTimer,
    resumeTimer,
    stopTimer,
    discardTimer,
    isSubmitting,
    validationError,
    clearValidationError,
  } = useTimer()

  const employeeName = user?.displayName || localStorage.getItem('employeeName') || 'עובד'
  const [fetchedRate, setFetchedRate] = useState<number | null>(null)
  const hourlyRate = fetchedRate !== null ? fetchedRate : ((user?.rate != null && user.rate > 0) ? user.rate : 350)

  // רשימת משימות ורשומות זמן
  const [tasks, setTasks] = useState<TaskItem[]>([])
  const [timeEntries, setTimeEntries] = useState<TimeEntryItem[]>([])
  const [isLoading, setIsLoading] = useState(true)
  const [successToast, setSuccessToast] = useState<string | null>(null)
  const [errorMessage, setErrorMessage] = useState<string | null>(null)

  const [isCreatingTask, setIsCreatingTask] = useState(false)
  const [newTaskTitle, setNewTaskTitle] = useState('')

  // עדכון שגיאת ולידציה מקומית
  useEffect(() => {
    if (validationError) {
      setErrorMessage(validationError)
    }
  }, [validationError])

  // טעינת משימות מ-API
  const fetchTasks = useCallback(async () => {
    try {
      const data = await tasksApi.getAll()
      const taskList: TaskItem[] = Array.isArray(data) ? data : []
      setTasks(taskList)
      if (taskList.length > 0 && !selectedTaskId) {
        setSelectedTaskId(taskList[0].id, taskList[0].projectId || null)
      }
    } catch (err) {
      console.error('Failed to load tasks:', err)
    }
  }, [selectedTaskId, setSelectedTaskId])

  // טעינת רשומות זמן שנשמרו מ-API
  const fetchTimeEntries = useCallback(async () => {
    try {
      const data = await timeEntriesApi.getAll()
      setTimeEntries(Array.isArray(data) ? data : [])
    } catch (err) {
      console.error('Failed to load time entries:', err)
    }
  }, [])

  useEffect(() => {
    setIsLoading(true)
    const fetchProfile = async () => {
      try {
        const allUsers = await usersApi.getAll()
        const myProfile = allUsers.find((u: any) => u.id === user?.uid)
        if (myProfile && myProfile.rate !== undefined) {
          setFetchedRate(myProfile.rate)
        }
      } catch (err) {}
    }
    Promise.all([fetchTasks(), fetchTimeEntries(), fetchProfile()]).finally(() => {
      setIsLoading(false)
    })
  }, [fetchTasks, fetchTimeEntries, user?.uid])

  // חישוב רווחים בזמן אמת לפי 350₪/שעה
  const activeSessionEarnings = useMemo(() => {
    return (elapsedSeconds * hourlyRate) / 3600
  }, [elapsedSeconds, hourlyRate])

  // חישוב רווחים מתוך רשומות שנשמרו היום
  const savedTodayEarnings = useMemo(() => {
    const todayStr = new Date().toISOString().slice(0, 10)
    return (Array.isArray(timeEntries) ? timeEntries : [])
      .filter(entry => {
        if (!entry?.date) return false
        try {
          return entry.date.startsWith(todayStr) || new Date(entry.date).toISOString().slice(0, 10) === todayStr
        } catch {
          return false
        }
      })
      .reduce((sum, entry) => sum + ((Number(entry.duration_seconds) || 0) * (Number(hourlyRate) || 0)) / 3600, 0)
  }, [timeEntries, hourlyRate])

  // סך רווחים כולל (שנשמר היום + הסשן החי כרגע)
  const totalTodayEarnings = useMemo(() => {
    return savedTodayEarnings + activeSessionEarnings
  }, [savedTodayEarnings, activeSessionEarnings])

  // משימה נבחרת כרגע
  const selectedTask = useMemo(() => {
    return tasks.find(t => t.id === selectedTaskId) || null
  }, [tasks, selectedTaskId])

  // פרויקט נבחר כרגע
  const activeProject = useMemo(() => {
    return projects.find(p => p.id === selectedProjectId) || null
  }, [projects, selectedProjectId])

  // התחלת טיימר
  const handleStartTimer = (taskIdToSelect?: string) => {
    if (taskIdToSelect) {
      const task = tasks.find(t => t.id === taskIdToSelect)
      setSelectedTaskId(taskIdToSelect, task?.projectId || null)
    }
    if (isPaused) {
      resumeTimer()
    } else {
      startTimer()
    }
    setSuccessToast(null)
    setErrorMessage(null)
    clearValidationError()
  }

  // השהיית טיימר
  const handlePauseTimer = () => {
    pauseTimer()
  }

  // עצירת טיימר ושליחה ל-API
  const handleStopAndSubmit = async () => {
    if (elapsedSeconds <= 0) {
      discardTimer()
      return
    }

    const sessionDuration = elapsedSeconds
    const sessionEarned = activeSessionEarnings
    const taskName = selectedTask ? selectedTask.title : customNote || 'פעילות'

    const res = await stopTimer()
    if (!res.success) {
      setErrorMessage(res.error || 'נא לבחור פרויקט לשיוך שעות (משימה אופציונלית)')
      setSuccessToast(null)
      return
    }

    setSuccessToast(`נשמרו בהצלחה ${formatTimerDigits(sessionDuration)} (רווח: ₪${sessionEarned.toFixed(2)}) עבור ${taskName}!`)
    setErrorMessage(null)
    await fetchTimeEntries()
  }

  // הוספת משימה חדשה
  const handleCreateTask = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!newTaskTitle.trim()) return

    try {
      const newTask = await tasksApi.create({
        title: newTaskTitle.trim(),
        assignee: employeeName,
        status: 'todo',
        projectId: selectedProjectId || undefined,
      })

      if (newTask && newTask.id) {
        setTasks(prev => [newTask, ...prev])
        setSelectedTaskId(newTask.id, selectedProjectId || null)
        setNewTaskTitle('')
        setIsCreatingTask(false)
        setSuccessToast(`המשימה "${newTask.title || newTaskTitle.trim()}" נוצרה ונבחרה!`)
      }
    } catch (err: any) {
      console.error('Failed to create task:', err)
      setErrorMessage(err?.message || 'שגיאה ביצירת המשימה')
    }
  }

  // עדכון סטטוס משימה
  const handleToggleTaskStatus = async (task: TaskItem) => {
    const newStatus = task.status === 'done' ? 'todo' : 'done'
    try {
      await tasksApi.update(task.id, { status: newStatus })
      setTasks(prev => prev.map(t => (t.id === task.id ? { ...t, status: newStatus } : t)))
    } catch (err: any) {
      console.error('Failed to update task:', err)
      setErrorMessage(err?.message || 'שגיאה בעדכון סטטוס המשימה')
    }
  }

  return (
    <div dir="rtl" className="min-h-screen bg-[var(--bg-main)] text-[var(--text-dark)] p-4 sm:p-8 font-sans selection:bg-orange-500 selection:text-[var(--text-dark)]">
      <div className="max-w-6xl mx-auto space-y-6">
        
        {/* הודעות משוב (Alerts / Toasts) */}
        {successToast && (
          <div className="p-4 rounded-2xl bg-emerald-950/80 border border-emerald-500/40 text-emerald-300 flex items-center justify-between shadow-xl animate-in fade-in slide-in-from-top-2">
            <div className="flex items-center gap-3">
              <CheckCircle2 className="w-5 h-5 text-emerald-400 shrink-0" />
              <span className="font-semibold text-sm sm:text-base">{successToast}</span>
            </div>
            <button 
              onClick={() => setSuccessToast(null)}
              className="text-emerald-400 hover:text-[var(--text-dark)] text-xs px-2 py-1 rounded bg-emerald-900/60"
            >
              סגור
            </button>
          </div>
        )}

        {errorMessage && (
          <div className="p-4 rounded-2xl bg-red-950/80 border border-red-500/40 text-red-300 flex items-center justify-between shadow-xl">
            <div className="flex items-center gap-3">
              <AlertCircle className="w-5 h-5 text-red-400 shrink-0" />
              <span className="font-semibold text-sm">{errorMessage}</span>
            </div>
            <button 
              onClick={() => setErrorMessage(null)}
              className="text-red-400 hover:text-[var(--text-dark)] text-xs px-2 py-1 rounded bg-red-900/60"
            >
              סגור
            </button>
          </div>
        )}

        {/* סרגל עליון (Header) */}
        <header className="flex flex-wrap gap-4 justify-between items-center bg-[var(--bg-card)]/70 backdrop-blur-xl p-6 rounded-3xl border border-[var(--border)] shadow-2xl">
          <div className="flex items-center gap-4">
            <div className="w-12 h-12 rounded-2xl bg-gradient-to-tr from-orange-600 to-amber-500 flex items-center justify-center text-[var(--text-dark)] font-bold text-xl shadow-lg shadow-orange-500/30">
              {employeeName.charAt(0)}
            </div>
            <div>
              <div className="flex items-center gap-2">
                <h1 className="text-2xl sm:text-3xl font-black bg-gradient-to-l from-orange-400 via-amber-300 to-white bg-clip-text text-transparent">
                  שלום, {employeeName}!
                </h1>
                <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                  <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-ping" />
                  מחובר
                </span>
              </div>
              <p className="text-[var(--text-light)] text-xs sm:text-sm mt-0.5">פורטל עובד חכם ומעקב שעות בזמן אמת</p>
            </div>
          </div>

          <div className="flex items-center gap-3">
            <div className="hidden sm:flex flex-col items-end px-4 py-2 rounded-2xl bg-[var(--surface-muted)]/60 border border-[var(--border-dark)]/60">
              <span className="text-xs text-[var(--text-light)]">תעריף שעתי מוגדר</span>
              <span className="text-sm font-bold text-orange-400">₪{hourlyRate} / שעה</span>
            </div>
            <button 
              type="button"
              onClick={() => signOutUser()}
              className="flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-[var(--surface-muted)] hover:bg-red-950/80 hover:border-red-500/40 border border-[var(--border-dark)]/80 text-[var(--text-mid)] hover:text-red-300 transition-all text-sm font-medium cursor-pointer"
            >
              <LogOut className="w-4 h-4" />
              <span>התנתק</span>
            </button>
          </div>
        </header>

        {/* סרגל ניווט מהיר למסכי המערכת */}
        <nav className="flex items-center gap-2 overflow-x-auto p-2 bg-[var(--bg-card)]/60 backdrop-blur-md rounded-2xl border border-[var(--border)] text-sm">
          <Link
            to="/portal"
            className="flex items-center gap-2 px-4 py-2 rounded-xl bg-orange-500/20 text-orange-400 font-bold border border-orange-500/30 shrink-0"
          >
            <Timer className="w-4 h-4" />
            <span>פורטל וטיימר</span>
          </Link>
          <Link
            to="/tasks"
            className="flex items-center gap-2 px-4 py-2 rounded-xl text-[var(--text-mid)] hover:text-[var(--text-dark)] hover:bg-[var(--surface-muted)]/80 font-medium transition-all shrink-0"
          >
            <CheckSquare className="w-4 h-4 text-blue-400" />
            <span>משימות</span>
          </Link>
          <Link
            to="/projects"
            className="flex items-center gap-2 px-4 py-2 rounded-xl text-[var(--text-mid)] hover:text-[var(--text-dark)] hover:bg-[var(--surface-muted)]/80 font-medium transition-all shrink-0"
          >
            <FolderKanban className="w-4 h-4 text-purple-400" />
            <span>פרויקטים</span>
          </Link>
          <Link
            to="/clients"
            className="flex items-center gap-2 px-4 py-2 rounded-xl text-[var(--text-mid)] hover:text-[var(--text-dark)] hover:bg-[var(--surface-muted)]/80 font-medium transition-all shrink-0"
          >
            <Users className="w-4 h-4 text-emerald-400" />
            <span>לקוחות</span>
          </Link>
          <Link
            to="/hours-banks"
            className="flex items-center gap-2 px-4 py-2 rounded-xl text-[var(--text-mid)] hover:text-[var(--text-dark)] hover:bg-[var(--surface-muted)]/80 font-medium transition-all shrink-0"
          >
            <Wallet className="w-4 h-4 text-amber-400" />
            <span>בנק שעות</span>
          </Link>
          <Link
            to="/income"
            className="flex items-center gap-2 px-4 py-2 rounded-xl text-[var(--text-mid)] hover:text-[var(--text-dark)] hover:bg-[var(--surface-muted)]/80 font-medium transition-all shrink-0"
          >
            <ArrowUpRight className="w-4 h-4 text-emerald-400" />
            <span>הכנסות</span>
          </Link>
          <Link
            to="/expenses"
            className="flex items-center gap-2 px-4 py-2 rounded-xl text-[var(--text-mid)] hover:text-[var(--text-dark)] hover:bg-[var(--surface-muted)]/80 font-medium transition-all shrink-0"
          >
            <ArrowDownRight className="w-4 h-4 text-rose-400" />
            <span>הוצאות</span>
          </Link>
        </nav>

        {/* אזור ראשי: ווידג'ט טיימר ורווחים חיים + בחירת משימה */}
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
          
          {/* כרטיס טיימר ורווחים (Hero Gamification Widget) */}
          <div className="lg:col-span-7 flex flex-col justify-between p-6 sm:p-8 rounded-3xl bg-gradient-to-b from-slate-900 via-slate-900 to-slate-950 border border-[var(--border)] relative overflow-hidden shadow-2xl">
            {/* אפקט זוהר דינמי ברקע בזמן פעילות הטיימר */}
            <div 
              className={`absolute -top-24 -right-24 w-80 h-80 rounded-full blur-3xl pointer-events-none transition-all duration-700 ${
                timerRunning ? 'bg-orange-500/20 animate-pulse' : 'bg-orange-500/5'
              }`} 
            />

            <div>
              {/* כותרת עליונה של הווידג'ט */}
              <div className="flex items-center justify-between pb-6 border-b border-[var(--border)]/80">
                <div className="flex items-center gap-2">
                  <Coins className="w-6 h-6 text-amber-400" />
                  <span className="text-sm sm:text-base font-semibold text-[var(--text-mid)]">חישוב רווחים בזמן אמת</span>
                </div>
                <div className="flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-orange-500/10 text-orange-400 border border-orange-500/20">
                  <Sparkles className="w-3.5 h-3.5" />
                  <span>₪{hourlyRate} לשעה (₪{(hourlyRate / 60).toFixed(2)} לדקה)</span>
                </div>
              </div>

              {/* שעון דיגיטלי ורווח חי */}
              <div className="my-8 flex flex-col items-center justify-center text-center">
                {/* תצוגת שעון דיגיטלי */}
                <div className="flex items-center gap-3 text-[var(--text-light)] mb-2">
                  <Timer className={`w-5 h-5 ${timerRunning ? 'text-orange-400 animate-spin' : ''}`} />
                  <span className="text-xs uppercase tracking-wider font-semibold">זמן סשן נוכחי</span>
                </div>
                <div className={`text-6xl sm:text-7xl font-timer font-mono font-black tracking-wider transition-colors ${
                  timerRunning ? 'text-orange-400' : 'text-[var(--text-mid)]'
                }`}>
                  {formatTimerDigits(elapsedSeconds)}
                </div>

                {/* רווח בזמן אמת של הסשן */}
                <div className="mt-4 px-6 py-2.5 rounded-2xl bg-[var(--surface-muted)]/60 border border-[var(--border-dark)]/60 flex items-center gap-3">
                  <span className="text-xs sm:text-sm text-[var(--text-light)]">רווח בסשן זה:</span>
                  <span className="text-xl sm:text-2xl font-black text-emerald-400 font-timer font-mono">
                    ₪{activeSessionEarnings.toFixed(2)}
                  </span>
                </div>

                {timerRunning && (
                  <div className="mt-2 text-xs text-[var(--text-light)] flex items-center gap-1">
                    ☁️ נשמר אוטומטית
                  </div>
                )}

                {/* הצגת משימה ופרויקט פעילים */}
                <div className="mt-4 text-xs sm:text-sm text-[var(--text-light)] flex flex-wrap items-center justify-center gap-2">
                  <span>משויך ל:</span>
                  {selectedTask ? (
                    <span className="px-3 py-1 rounded-lg bg-orange-500/10 text-orange-300 font-medium border border-orange-500/20">
                      משימה: {selectedTask.title}
                    </span>
                  ) : (
                    <span className="px-3 py-1 rounded-lg bg-slate-500/10 text-slate-400 font-medium border border-slate-500/20">
                      ללא משימה ספציפית (אופציונלי)
                    </span>
                  )}
                  {activeProject ? (
                    <span className="px-3 py-1 rounded-lg bg-emerald-500/10 text-emerald-300 font-medium border border-emerald-500/20 flex items-center gap-1.5">
                      <span
                        className="w-2 h-2 rounded-full shrink-0"
                        style={{ backgroundColor: activeProject.color || '#10B981' }}
                      />
                      פרויקט: {activeProject.name}
                    </span>
                  ) : (
                    <span className="px-3 py-1 rounded-lg bg-amber-500/10 text-amber-300 font-medium border border-amber-500/20">
                      ללא שיוך פרויקט
                    </span>
                  )}
                </div>
              </div>
            </div>

            {/* כפתורי שליטה: Play / Stop / Pause */}
            <div className="pt-6 border-t border-[var(--border)]/80">
              <div className="flex flex-wrap items-center justify-center gap-4">
                
                {/* כפתור Play / התחל */}
                {!timerRunning ? (
                  <button
                    type="button"
                    onClick={() => handleStartTimer()}
                    className="flex-1 min-w-[160px] py-4 px-6 rounded-2xl bg-gradient-to-r from-orange-500 to-amber-500 hover:from-orange-600 hover:to-amber-600 text-slate-950 font-black text-lg flex items-center justify-center gap-3 shadow-xl shadow-orange-500/25 transition-all hover:scale-[1.02] active:scale-[0.98] cursor-pointer"
                  >
                    <Play className="w-6 h-6 fill-slate-950" />
                    <span>{elapsedSeconds > 0 ? 'המשך טיימר' : 'התחל עבודה'}</span>
                  </button>
                ) : (
                  <button
                    type="button"
                    onClick={handlePauseTimer}
                    className="flex-1 min-w-[140px] py-4 px-6 rounded-2xl bg-[var(--surface-muted)] hover:bg-slate-700 text-amber-400 border border-amber-500/30 font-bold text-base flex items-center justify-center gap-2.5 transition-all cursor-pointer"
                  >
                    <Pause className="w-5 h-5" />
                    <span>השהה טיימר</span>
                  </button>
                )}

                {/* כפתור Stop / סיים ושמור ל-API */}
                <button
                  type="button"
                  disabled={elapsedSeconds === 0 || isSubmitting}
                  onClick={handleStopAndSubmit}
                  className={`flex-1 min-w-[160px] py-4 px-6 rounded-2xl font-bold text-base flex items-center justify-center gap-2.5 transition-all ${
                    elapsedSeconds > 0 && !isSubmitting
                      ? 'bg-red-600 hover:bg-red-500 text-[var(--text-dark)] shadow-lg shadow-red-600/30 cursor-pointer hover:scale-[1.02]'
                      : 'bg-[var(--surface-muted)]/50 text-slate-500 border border-[var(--border)] cursor-not-allowed'
                  }`}
                >
                  {isSubmitting ? (
                    <>
                      <RefreshCw className="w-5 h-5 animate-spin" />
                      <span>שומר לשרת...</span>
                    </>
                  ) : (
                    <>
                      <Square className="w-5 h-5 fill-current" />
                      <span>עצור ודווח שעות</span>
                    </>
                  )}
                </button>

              </div>

              {/* הערת עבודה אופציונלית */}
              <div className="mt-4">
                <input
                  type="text"
                  placeholder="הערה אופציונלית לדיווח השעות (לדוגמה: שיחת סיכום עם לקוח)"
                  value={customNote}
                  onChange={e => setCustomNote(e.target.value)}
                  className="w-full px-4 py-2.5 rounded-xl bg-[var(--bg-main)]/60 border border-[var(--border)] text-sm text-[var(--text-dark)] placeholder-slate-500 focus:outline-none focus:border-orange-500/50 transition-colors"
                />
              </div>

              {/* בחירת פרויקט מהירה (חובה לעצירה) */}
              <div className="mt-3">
                <div className="flex items-center justify-between text-xs text-[var(--text-light)] mb-1">
                  <span>פרויקט משויך (חובה לשמירה ועצירה):</span>
                  {activeProject && (
                    <button
                      type="button"
                      onClick={() => setSelectedProjectId(null)}
                      className="text-orange-400 hover:underline"
                    >
                      נקה
                    </button>
                  )}
                </div>
                <select
                  value={selectedProjectId || ''}
                  onChange={(e) => setSelectedProjectId(e.target.value || null)}
                  className="w-full px-4 py-2 rounded-xl bg-[var(--bg-main)]/60 border border-[var(--border)] text-sm text-[var(--text-dark)] focus:outline-none focus:border-orange-500/50 transition-colors"
                >
                  <option value="">-- בחר פרויקט --</option>
                  {projects.map((p) => (
                    <option key={p.id} value={p.id}>
                      {p.name}
                    </option>
                  ))}
                </select>
              </div>
            </div>

          </div>

          {/* כרטיס סיכום רווחי היום (Daily Summary Card) */}
          <div className="lg:col-span-5 flex flex-col gap-6">
            
            {/* קלף רווחי היום */}
            <div className="p-6 rounded-3xl bg-[var(--bg-card)]/80 border border-[var(--border)] shadow-xl relative overflow-hidden">
              <div className="flex items-center justify-between mb-4">
                <div className="flex items-center gap-2 text-[var(--text-light)] text-sm font-medium">
                  <TrendingUp className="w-4 h-4 text-emerald-400" />
                  <span>סך רווחי היום</span>
                </div>
                <span className="text-xs px-2.5 py-1 rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 font-bold">
                  הכנסה פעילה
                </span>
              </div>

              <div className="text-4xl sm:text-5xl font-black text-[var(--text-dark)] tracking-tight flex items-baseline gap-1">
                <span className="text-emerald-400">₪</span>
                <span>{totalTodayEarnings.toFixed(2)}</span>
              </div>

              <div className="mt-4 pt-4 border-t border-[var(--border)] flex justify-between text-xs text-[var(--text-light)]">
                <span>נשמר במערכת: ₪{savedTodayEarnings.toFixed(2)}</span>
                <span>בסשן הנוכחי: ₪{activeSessionEarnings.toFixed(2)}</span>
              </div>
            </div>

            {/* כרטיס בחירת משימה מהירה */}
            <div className="flex-1 p-6 rounded-3xl bg-[var(--bg-card)]/80 border border-[var(--border)] shadow-xl flex flex-col">
              <div className="flex items-center justify-between mb-4">
                <div className="flex items-center gap-2 text-[var(--text-mid)] font-bold text-base">
                  <ListTodo className="w-5 h-5 text-orange-400" />
                  <span>משימה פעילה לדיווח</span>
                </div>
                <button
                  type="button"
                  onClick={() => setIsCreatingTask(!isCreatingTask)}
                  className="text-xs font-semibold text-orange-400 hover:text-orange-300 flex items-center gap-1 cursor-pointer"
                >
                  <Plus className="w-3.5 h-3.5" />
                  <span>משימה חדשה</span>
                </button>
              </div>

              {/* טופס הוספת משימה מהירה */}
              {isCreatingTask && (
                <form onSubmit={handleCreateTask} className="mb-4 p-3 rounded-2xl bg-[var(--bg-main)] border border-[var(--border)] space-y-2">
                  <input
                    type="text"
                    placeholder="שם המשימה החדשה..."
                    value={newTaskTitle}
                    onChange={e => setNewTaskTitle(e.target.value)}
                    className="w-full px-3 py-2 rounded-xl bg-[var(--bg-card)] border border-[var(--border-dark)] text-sm text-[var(--text-dark)] focus:outline-none focus:border-orange-500"
                    autoFocus
                  />
                  <div className="flex justify-end gap-2">
                    <button
                      type="button"
                      onClick={() => setIsCreatingTask(false)}
                      className="px-3 py-1 rounded-lg text-xs text-[var(--text-light)] hover:text-[var(--text-dark)]"
                    >
                      ביטול
                    </button>
                    <button
                      type="submit"
                      className="px-3 py-1 rounded-lg text-xs font-bold bg-orange-500 hover:bg-orange-600 text-slate-950"
                    >
                      שמור
                    </button>
                  </div>
                </form>
              )}

              {/* רשימת בחירת משימות */}
              <div className="flex-1 overflow-y-auto max-h-[220px] space-y-2 pr-1 custom-scrollbar">
                {isLoading ? (
                  <div className="text-center py-8 text-slate-500 text-xs flex items-center justify-center gap-2">
                    <RefreshCw className="w-4 h-4 animate-spin" />
                    <span>טוען משימות...</span>
                  </div>
                ) : tasks.length === 0 ? (
                  <div className="text-center py-8 text-slate-500 text-xs">
                    אין כרגע משימות משויכות. צור משימה חדשה או עבוד במעקב כללי.
                  </div>
                ) : (
                  tasks.map(task => {
                    const isSelected = selectedTaskId === task.id
                    return (
                      <div
                        key={task.id}
                        onClick={() => setSelectedTaskId(task.id, task.projectId || null)}
                        className={`p-3 rounded-2xl transition-all cursor-pointer border flex items-center justify-between gap-3 ${
                          isSelected
                            ? 'bg-orange-500/10 border-orange-500/60 shadow-lg shadow-orange-500/10'
                            : 'bg-[var(--bg-main)]/40 hover:bg-[var(--surface-muted)]/60 border-[var(--border)]'
                        }`}
                      >
                        <div className="flex items-center gap-2.5 min-w-0">
                          <button
                            type="button"
                            onClick={(e) => {
                              e.stopPropagation()
                              handleToggleTaskStatus(task)
                            }}
                            className={`w-5 h-5 rounded-lg border flex items-center justify-center transition-colors ${
                              task.status === 'done'
                                ? 'bg-emerald-500 border-emerald-500 text-slate-950'
                                : 'border-[var(--border-dark)] hover:border-orange-500'
                            }`}
                          >
                            {task.status === 'done' && <CheckCircle2 className="w-4 h-4" />}
                          </button>
                          <span className={`text-sm truncate ${
                            task.status === 'done' ? 'line-through text-slate-500' : isSelected ? 'font-bold text-orange-200' : 'text-[var(--text-mid)]'
                          }`}>
                            {task.title}
                          </span>
                        </div>

                        <div className="flex items-center gap-2 shrink-0">
                          {isSelected && (
                            <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-orange-500 text-slate-950">
                              נבחרה
                            </span>
                          )}
                          {!timerRunning && (
                            <button
                              type="button"
                              onClick={(e) => {
                                e.stopPropagation()
                                handleStartTimer(task.id)
                              }}
                              className="p-1.5 rounded-lg bg-[var(--surface-muted)] hover:bg-orange-500 hover:text-slate-950 text-[var(--text-light)] transition-colors"
                              title="התחל לעבוד על משימה זו"
                            >
                              <Play className="w-3.5 h-3.5 fill-current" />
                            </button>
                          )}
                        </div>
                      </div>
                    )
                  })
                )}
              </div>

            </div>

          </div>

        </div>

        {/* היסטוריית דיווחי שעות אחרונים (Recent Time Tracking Activity) */}
        <div className="p-6 sm:p-8 rounded-3xl bg-[var(--bg-card)]/60 border border-[var(--border)] shadow-xl">
          <div className="flex items-center justify-between mb-6">
            <div className="flex items-center gap-3">
              <Clock className="w-6 h-6 text-orange-400" />
              <div>
                <h2 className="text-xl font-bold text-[var(--text-dark)]">יומן דיווחי שעות אחרונים</h2>
                <p className="text-xs text-[var(--text-light)]">ריכוז כל השעות והרווחים ששודרו לשרת</p>
              </div>
            </div>
            <button
              type="button"
              onClick={fetchTimeEntries}
              className="p-2 rounded-xl bg-[var(--surface-muted)] hover:bg-slate-700 text-[var(--text-mid)] transition-colors"
              title="רענן רשימה"
            >
              <RefreshCw className="w-4 h-4" />
            </button>
          </div>

          <div className="overflow-x-auto">
            {timeEntries.length === 0 ? (
              <div className="text-center py-12 text-slate-500 text-sm">
                טרם דווחו שעות עבודה. הפעל את הטיימר ולחץ "עצור ודווח שעות" כדי להתחיל.
              </div>
            ) : (
              <table className="w-full text-right border-collapse">
                <thead>
                  <tr className="border-b border-[var(--border)] text-[var(--text-light)] text-xs uppercase tracking-wider">
                    <th className="pb-3 pr-4 font-semibold">תיאור / משימה</th>
                    <th className="pb-3 px-4 font-semibold">משך זמן</th>
                    <th className="pb-3 px-4 font-semibold">רווח מחושב</th>
                    <th className="pb-3 pl-4 font-semibold">תאריך ושעה</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-800/60 text-sm">
                  {(Array.isArray(timeEntries) ? timeEntries : []).slice(0, 10).map((entry) => {
                    const dur = Math.max(0, Number(entry.duration_seconds) || 0)
                    const earned = (dur * hourlyRate) / 3600
                    const mins = Math.floor(dur / 60)
                    const secs = dur % 60
                    let dateDisplay = 'היום'
                    if (entry.date) {
                      const d = new Date(entry.date)
                      dateDisplay = isNaN(d.getTime()) ? String(entry.date) : d.toLocaleString('he-IL')
                    }
                    return (
                      <tr key={entry.id} className="hover:bg-[var(--surface-muted)]/30 transition-colors">
                        <td className="py-3.5 pr-4 font-medium text-[var(--text-dark)]">
                          {entry.description || 'עבודה שוטפת'}
                        </td>
                        <td className="py-3.5 px-4 font-timer font-mono text-[var(--text-mid)]">
                          {mins > 0 ? `${mins} דק' ` : ''}{secs} שנ'
                        </td>
                        <td className="py-3.5 px-4 font-semibold text-emerald-400 font-timer font-mono">
                          ₪{earned.toFixed(2)}
                        </td>
                        <td className="py-3.5 pl-4 text-xs text-[var(--text-light)]">
                          {dateDisplay}
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            )}
          </div>
        </div>

      </div>
    </div>
  )
}
