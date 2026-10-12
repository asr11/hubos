import {
  createContext,
  useContext,
  useState,
  useEffect,
  useCallback,
  useRef,
  type ReactNode,
} from 'react'
import { useAuth } from './AuthContext'
import { timeEntriesApi } from '../services/api'
import { hubFetch } from '../lib/hubFetch'

export interface TimeEntry {
  id: string
  project_id?: string | null
  client_id?: string | null
  user_id?: string | null
  task_id?: string | null
  hours_bank_id?: string | null
  duration_seconds: number
  duration?: number
  description?: string
  start_time?: string | null
  end_time?: string | null
  date: string
  project_name?: string | null
  project_color?: string | null
  task_title?: string | null
  client_name?: string | null
}

export interface TimerStopResult {
  success: boolean
  error?: string
  entry?: TimeEntry | null
}

export interface TimerAssignment {
  projectId: string | null
  taskId: string | null
  clientId?: string | null
  hoursBankId?: string | null
}

export interface TimerContextType {
  isRunning: boolean
  isPaused: boolean
  elapsedSeconds: number
  description: string
  selectedProjectId: string | null
  selectedTaskId: string | null
  selectedClientId: string | null
  selectedHoursBankId: string | null
  isSubmitting: boolean
  validationError: string | null
  lastSavedEntry: TimeEntry | null
  startTimer: (override?: {
    projectId?: string | null
    taskId?: string | null
    clientId?: string | null
    hoursBankId?: string | null
    hours_bank_id?: string | null
    description?: string
    project_id?: string | null
    task_id?: string | null
    client_id?: string | null
    title?: string
  }) => void
  pauseTimer: () => void
  resumeTimer: () => void
  stopTimer: () => Promise<TimerStopResult>
  discardTimer: () => void
  setDescription: (desc: string) => void
  setSelectedProjectId: (id: string | null) => void
  setSelectedTaskId: (id: string | null, autoProjectId?: string | null, autoClientId?: string | null) => void
  setSelectedClientId: (id: string | null) => void
  setSelectedHoursBankId: (id: string | null) => void
  setAssignment: (assignment: TimerAssignment) => void
  clearValidationError: () => void
  formatTime: (sec?: number) => string
}

const TimerContext = createContext<TimerContextType | null>(null)

export function formatTimerDigits(totalSec: number): string {
  const sec = Math.max(0, Math.floor(Number(totalSec) || 0))
  const hours = Math.floor(sec / 3600)
  const minutes = Math.floor((sec % 3600) / 60)
  const seconds = sec % 60
  return `${hours.toString().padStart(2, '0')}:${minutes
    .toString()
    .padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`
}

export function TimerProvider({ children }: { children: ReactNode }) {
  const { user } = useAuth()

  const [isRunning, setIsRunning] = useState(false)
  const [isPaused, setIsPaused] = useState(false)
  const [elapsedSeconds, setElapsedSeconds] = useState(0)
  const [description, setDescriptionState] = useState('')
  const [selectedProjectId, setSelectedProjectIdState] = useState<string | null>(null)
  const [selectedTaskId, setSelectedTaskIdState] = useState<string | null>(null)
  const [selectedClientId, setSelectedClientIdState] = useState<string | null>(null)
  const [selectedHoursBankId, setSelectedHoursBankIdState] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [validationError, setValidationError] = useState<string | null>(null)
  const [lastSavedEntry, setLastSavedEntry] = useState<TimeEntry | null>(null)

  // Stable references for timer interval & heartbeat to prevent re-creation render bugs
  const elapsedRef = useRef(0)
  elapsedRef.current = elapsedSeconds

  const stateRefs = useRef({
    userId: user?.uid || null,
    taskId: selectedTaskId,
    projectId: selectedProjectId,
    hoursBankId: selectedHoursBankId,
  })

  useEffect(() => {
    stateRefs.current = {
      userId: user?.uid || null,
      taskId: selectedTaskId,
      projectId: selectedProjectId,
      hoursBankId: selectedHoursBankId,
    }
  }, [user?.uid, selectedTaskId, selectedProjectId, selectedHoursBankId])

  // 1. Initial restore from localStorage
  useEffect(() => {
    try {
      const savedRunning = localStorage.getItem('hub_timer_running')
      const savedStartedAt = localStorage.getItem('hub_timer_startedAt')
      const savedPausedElapsed = localStorage.getItem('hub_timer_pausedElapsed')
      const savedNote = localStorage.getItem('hub_timer_note') || ''
      const savedProjectId = localStorage.getItem('hub_timer_projectId') || null
      const savedTaskId = localStorage.getItem('hub_timer_taskId') || null
      const savedClientId = localStorage.getItem('hub_timer_clientId') || null
      const savedHoursBankId = localStorage.getItem('hub_timer_hoursBankId') || null

      if (savedNote) setDescriptionState(savedNote)
      if (savedProjectId) setSelectedProjectIdState(savedProjectId)
      if (savedTaskId) setSelectedTaskIdState(savedTaskId)
      if (savedClientId) setSelectedClientIdState(savedClientId)
      if (savedHoursBankId) setSelectedHoursBankIdState(savedHoursBankId)

      if (savedRunning === 'true' && savedStartedAt) {
        const now = Date.now()
        const start = Number(savedStartedAt)
        if (!isNaN(start) && start > 0) {
          const elapsed = Math.max(0, Math.floor((now - start) / 1000))
          setElapsedSeconds(elapsed)
          setIsRunning(true)
          setIsPaused(false)
        }
      } else if (savedRunning === 'false' && savedPausedElapsed) {
        const elapsed = Number(savedPausedElapsed)
        if (!isNaN(elapsed) && elapsed >= 0) {
          setElapsedSeconds(elapsed)
          setIsRunning(false)
          setIsPaused(true)
        }
      }
    } catch (e) {
      console.error('Failed to restore timer from localStorage:', e)
    }
  }, [])

  // 2. Ticking interval when running
  useEffect(() => {
    let interval: ReturnType<typeof setInterval> | null = null

    if (isRunning) {
      interval = setInterval(() => {
        const startedAt = Number(localStorage.getItem('hub_timer_startedAt'))
        if (startedAt && !isNaN(startedAt) && startedAt > 0) {
          const current = Math.max(0, Math.floor((Date.now() - startedAt) / 1000))
          setElapsedSeconds(current)
        } else {
          setElapsedSeconds((prev) => prev + 1)
        }
      }, 1000)
    }

    return () => {
      if (interval) clearInterval(interval)
    }
  }, [isRunning])

  // 3. Heartbeat: sync every 60 seconds (stable useEffect without re-creation loops)
  useEffect(() => {
    if (!isRunning) return

    const heartbeatInterval = setInterval(async () => {
      const currentStartedAt = localStorage.getItem('hub_timer_startedAt')
      const currentElapsed = currentStartedAt
        ? Math.max(0, Math.floor((Date.now() - Number(currentStartedAt)) / 1000))
        : elapsedRef.current

      const { userId, taskId, projectId } = stateRefs.current

      try {
        await timeEntriesApi.heartbeat({
          user_id: userId,
          task_id: taskId,
          project_id: projectId,
          elapsed_seconds: currentElapsed,
          started_at: currentStartedAt,
        })
      } catch {
        // Silently ignore temporary network hiccups in heartbeat
      }
    }, 60000)

    return () => clearInterval(heartbeatInterval)
  }, [isRunning])

  // 4. Tab close / unload prevention when timer is actively running
  useEffect(() => {
    if (!isRunning) return

    const handleBeforeUnload = (e: BeforeUnloadEvent) => {
      e.preventDefault()
      e.returnValue = ''
    }

    window.addEventListener('beforeunload', handleBeforeUnload)
    return () => window.removeEventListener('beforeunload', handleBeforeUnload)
  }, [isRunning])

  // Description handler
  const setDescription = useCallback((desc: string) => {
    setDescriptionState(desc)
    localStorage.setItem('hub_timer_note', desc)
  }, [])

  // Project handler
  const setSelectedProjectId = useCallback((id: string | null) => {
    setSelectedProjectIdState(id)
    if (id) {
      localStorage.setItem('hub_timer_projectId', id)
      setValidationError(null)
    } else {
      localStorage.removeItem('hub_timer_projectId')
    }
  }, [])

  // Client handler
  const setSelectedClientId = useCallback((id: string | null) => {
    setSelectedClientIdState(id)
    if (id) {
      localStorage.setItem('hub_timer_clientId', id)
    } else {
      localStorage.removeItem('hub_timer_clientId')
    }
  }, [])

  // Hours Bank handler
  const setSelectedHoursBankId = useCallback((id: string | null) => {
    setSelectedHoursBankIdState(id)
    if (id) {
      localStorage.setItem('hub_timer_hoursBankId', id)
      setValidationError(null)
    } else {
      localStorage.removeItem('hub_timer_hoursBankId')
    }
  }, [])

  // Task handler (Task is optional!)
  const setSelectedTaskId = useCallback(
    (id: string | null, autoProjectId?: string | null, autoClientId?: string | null) => {
      setSelectedTaskIdState(id)
      if (id) {
        localStorage.setItem('hub_timer_taskId', id)
        if (autoProjectId) {
          setSelectedProjectIdState(autoProjectId)
          localStorage.setItem('hub_timer_projectId', autoProjectId)
        }
        if (autoClientId) {
          setSelectedClientIdState(autoClientId)
          localStorage.setItem('hub_timer_clientId', autoClientId)
        }
        setValidationError(null)
      } else {
        localStorage.removeItem('hub_timer_taskId')
      }
    },
    [],
  )

  // Full Assignment handler
  const setAssignment = useCallback((assignment: TimerAssignment) => {
    const { projectId, taskId, clientId, hoursBankId } = assignment

    setSelectedProjectIdState(projectId)
    if (projectId) {
      localStorage.setItem('hub_timer_projectId', projectId)
    } else {
      localStorage.removeItem('hub_timer_projectId')
    }

    setSelectedTaskIdState(taskId)
    if (taskId) {
      localStorage.setItem('hub_timer_taskId', taskId)
    } else {
      localStorage.removeItem('hub_timer_taskId')
    }

    if (clientId !== undefined) {
      setSelectedClientIdState(clientId)
      if (clientId) {
        localStorage.setItem('hub_timer_clientId', clientId)
      } else {
        localStorage.removeItem('hub_timer_clientId')
      }
    }

    if (hoursBankId !== undefined) {
      setSelectedHoursBankIdState(hoursBankId)
      if (hoursBankId) {
        localStorage.setItem('hub_timer_hoursBankId', hoursBankId)
      } else {
        localStorage.removeItem('hub_timer_hoursBankId')
      }
    }

    if (projectId || taskId || hoursBankId) {
      setValidationError(null)
    }
  }, [])

  const clearValidationError = useCallback(() => {
    setValidationError(null)
  }, [])

  // Start timer
  const startTimer = useCallback(
    (override?: {
      projectId?: string | null
      taskId?: string | null
      clientId?: string | null
      hoursBankId?: string | null
      hours_bank_id?: string | null
      description?: string
      project_id?: string | null
      task_id?: string | null
      client_id?: string | null
      title?: string
    }) => {
      setValidationError(null)

      const finalDesc =
        override?.description !== undefined
          ? override.description
          : override?.title !== undefined
            ? override.title
            : description
      const finalProj =
        override?.projectId !== undefined
          ? override.projectId
          : override?.project_id !== undefined
            ? override.project_id
            : selectedProjectId
      const finalTask =
        override?.taskId !== undefined
          ? override.taskId
          : override?.task_id !== undefined
            ? override.task_id
            : selectedTaskId
      const finalClient =
        override?.clientId !== undefined
          ? override.clientId
          : override?.client_id !== undefined
            ? override.client_id
            : selectedClientId
      const finalBank =
        override?.hoursBankId !== undefined
          ? override.hoursBankId
          : override?.hours_bank_id !== undefined
            ? override.hours_bank_id
            : selectedHoursBankId

      const isSwitchTaskOrBank = Boolean(
        (finalTask && finalTask !== selectedTaskId) ||
        (finalBank && finalBank !== selectedHoursBankId)
      )
      const currentElapsed = isSwitchTaskOrBank ? 0 : elapsedRef.current
      if (isSwitchTaskOrBank) {
        setElapsedSeconds(0)
      }
      const newStartedAt = Date.now() - currentElapsed * 1000

      localStorage.setItem('hub_timer_startedAt', newStartedAt.toString())
      localStorage.setItem('hub_timer_running', 'true')
      localStorage.removeItem('hub_timer_pausedElapsed')
      localStorage.setItem('hub_timer_note', finalDesc || '')
      if (finalProj) {
        localStorage.setItem('hub_timer_projectId', finalProj)
      } else {
        localStorage.removeItem('hub_timer_projectId')
      }
      if (finalTask) {
        localStorage.setItem('hub_timer_taskId', finalTask)
      } else {
        localStorage.removeItem('hub_timer_taskId')
      }
      if (finalClient) {
        localStorage.setItem('hub_timer_clientId', finalClient)
      } else {
        localStorage.removeItem('hub_timer_clientId')
      }
      if (finalBank) {
        localStorage.setItem('hub_timer_hoursBankId', finalBank)
      } else {
        localStorage.removeItem('hub_timer_hoursBankId')
      }

      if (override?.description !== undefined || override?.title !== undefined) {
        setDescriptionState(finalDesc || '')
      }
      if (override?.projectId !== undefined || override?.project_id !== undefined) {
        setSelectedProjectIdState(finalProj)
      }
      if (override?.taskId !== undefined || override?.task_id !== undefined) {
        setSelectedTaskIdState(finalTask)
      }
      if (override?.clientId !== undefined || override?.client_id !== undefined) {
        setSelectedClientIdState(finalClient)
      }
      if (override?.hoursBankId !== undefined || override?.hours_bank_id !== undefined) {
        setSelectedHoursBankIdState(finalBank)
      }

      setIsRunning(true)
      setIsPaused(false)
    },
    [description, selectedProjectId, selectedTaskId, selectedClientId, selectedHoursBankId],
  )

  // Pause timer
  const pauseTimer = useCallback(() => {
    const current = elapsedRef.current
    localStorage.setItem('hub_timer_pausedElapsed', current.toString())
    localStorage.setItem('hub_timer_running', 'false')
    setIsRunning(false)
    setIsPaused(true)
  }, [])

  // Resume timer
  const resumeTimer = useCallback(() => {
    startTimer()
  }, [startTimer])

  // Discard timer
  const discardTimer = useCallback(() => {
    setIsRunning(false)
    setIsPaused(false)
    setElapsedSeconds(0)
    setDescriptionState('')
    setSelectedProjectIdState(null)
    setSelectedTaskIdState(null)
    setSelectedClientIdState(null)
    setSelectedHoursBankIdState(null)
    setValidationError(null)

    localStorage.removeItem('hub_timer_startedAt')
    localStorage.removeItem('hub_timer_running')
    localStorage.removeItem('hub_timer_pausedElapsed')
    localStorage.removeItem('hub_timer_note')
    localStorage.removeItem('hub_timer_projectId')
    localStorage.removeItem('hub_timer_taskId')
    localStorage.removeItem('hub_timer_clientId')
    localStorage.removeItem('hub_timer_hoursBankId')
  }, [])

  // Stop timer & save (UX: Task selection is OPTIONAL! Hours Bank is valid assignment!)
  const stopTimer = useCallback(async (): Promise<TimerStopResult> => {
    const currentElapsed = elapsedRef.current
    if (currentElapsed <= 0) {
      discardTimer()
      return { success: true }
    }

    // Task is OPTIONAL. Project assignment OR Hours Bank assignment is required.
    const hasProject = Boolean(selectedProjectId && selectedProjectId.trim())
    const hasTask = Boolean(selectedTaskId && selectedTaskId.trim())
    const hasHoursBank = Boolean(selectedHoursBankId && selectedHoursBankId.trim())

    if (!hasProject && !hasTask && !hasHoursBank) {
      const err = 'נא לבחור פרויקט לשיוך שעות העבודה (בחירת משימה היא אופציונלית)'
      setValidationError(err)
      return { success: false, error: err }
    }

    setValidationError(null)
    setIsSubmitting(true)

    try {
      const sessionDuration = currentElapsed
      const now = Date.now()
      const savedStartedAt = Number(localStorage.getItem('hub_timer_startedAt'))
      const startTime =
        savedStartedAt && !isNaN(savedStartedAt) && savedStartedAt > 0
          ? new Date(savedStartedAt).toISOString()
          : new Date(now - sessionDuration * 1000).toISOString()
      const endTime = new Date(now).toISOString()

      const payload = {
        user_id: user?.uid || null,
        project_id: selectedProjectId || null,
        client_id: selectedClientId || null,
        task_id: selectedTaskId || null, // Task selection is optional!
        hours_bank_id: selectedHoursBankId || null,
        duration_seconds: sessionDuration,
        start_time: startTime,
        end_time: endTime,
        date: endTime,
        description: description.trim() || undefined,
      }

      const savedEntry = await timeEntriesApi.create(payload)

      // Auto-log to hours bank time_logs if bank is linked
      if (selectedHoursBankId) {
        try {
          const hoursSpent = Math.max(0.01, Math.round((sessionDuration / 3600) * 100) / 100)
          await hubFetch('/api/documents/time_logs', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              bankId: selectedHoursBankId,
              clientId: selectedClientId || '',
              date: endTime.split('T')[0],
              hours: hoursSpent,
              description: description.trim() || 'דיווח אוטומטי מטיימר',
            }),
          })
        } catch (bankErr) {
          console.warn('Failed to auto-log to hours bank:', bankErr)
        }
      }

      discardTimer()
      setLastSavedEntry(savedEntry as TimeEntry)

      if (typeof window !== 'undefined') {
        window.dispatchEvent(new CustomEvent('hub-time-entry-saved', { detail: savedEntry }))
      }

      return { success: true, entry: savedEntry as TimeEntry }
    } catch (err: unknown) {
      console.error('Error submitting time entry:', err)
      const errMsg = err instanceof Error ? (err as any).message : 'שגיאה בשמירת דיווח השעות לשרת'
      setValidationError(errMsg)
      return { success: false, error: errMsg }
    } finally {
      setIsSubmitting(false)
    }
  }, [
    selectedProjectId,
    selectedTaskId,
    selectedClientId,
    selectedHoursBankId,
    description,
    user?.uid,
    discardTimer,
  ])

  const formatTime = useCallback((sec?: number) => {
    return formatTimerDigits(sec !== undefined ? sec : elapsedRef.current)
  }, [])

  return (
    <TimerContext.Provider
      value={{
        isRunning,
        isPaused,
        elapsedSeconds,
        description,
        selectedProjectId,
        selectedTaskId,
        selectedClientId,
        selectedHoursBankId,
        isSubmitting,
        validationError,
        lastSavedEntry,
        startTimer,
        pauseTimer,
        resumeTimer,
        stopTimer,
        discardTimer,
        setDescription,
        setSelectedProjectId,
        setSelectedTaskId,
        setSelectedClientId,
        setSelectedHoursBankId,
        setAssignment,
        clearValidationError,
        formatTime,
      }}
    >
      {children}
    </TimerContext.Provider>
  )
}

export function useTimer(): TimerContextType {
  const context = useContext(TimerContext)
  if (!context) {
    throw new Error('useTimer must be used within a TimerProvider')
  }
  return context
}
