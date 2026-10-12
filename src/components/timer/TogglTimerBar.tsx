import {
  useState,
  type KeyboardEvent,
} from 'react'
import {
  Play,
  Square,
  Pause,
  Trash2,
  AlertCircle,
  Clock,
  ChevronUp,
  ChevronDown,
} from 'lucide-react'
import { useTimer, formatTimerDigits } from '../../contexts/TimerContext'
import { SmartCombobox } from './SmartCombobox'
import { RecentTimeEntries } from './RecentTimeEntries'
import { toastFsSuccess, toastFsError } from '../../lib/fsToast'

export function TogglTimerBar() {
  const {
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
    startTimer,
    pauseTimer,
    resumeTimer,
    stopTimer,
    discardTimer,
    setDescription,
    setAssignment,
    clearValidationError,
  } = useTimer()

  const [showRecentEntries, setShowRecentEntries] = useState(false)

  const handleStop = async () => {
    const res = await stopTimer()
    if (!res.success) {
      toastFsError(res.error || 'שגיאה בעצירת הטיימר', null, 'TimerBar')
    } else {
      toastFsSuccess('שעות העבודה נשמרו בהצלחה במערכת!')
      setShowRecentEntries(true)
    }
  }

  const handleKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter') {
      if (!isRunning) {
        startTimer()
      }
    }
  }

  return (
    <div
      dir="rtl"
      className="sticky top-0 z-30 w-full bg-[var(--bg-card)]/95 backdrop-blur-md border-b border-[var(--border)] px-3 sm:px-6 py-2 transition-all shadow-xs"
    >
      <div className="flex flex-wrap sm:flex-nowrap items-center justify-between gap-3 min-h-[46px]">
        {/* Left / Main Section: Status Indicator, Description Input & Smart Combobox */}
        <div className="flex-1 flex flex-wrap sm:flex-nowrap items-center gap-2.5 min-w-0 w-full sm:w-auto">
          {/* Active Status Pulse Dot */}
          <div className="flex items-center gap-1.5 shrink-0" title={isRunning ? 'טיימר פעיל' : isPaused ? 'טיימר מושהה' : 'טיימר מושבת'}>
            <span
              className={`h-2.5 w-2.5 rounded-full transition-all ${
                isRunning
                  ? 'bg-emerald-500 shadow-xs shadow-emerald-500 animate-pulse'
                  : isPaused
                  ? 'bg-amber-500'
                  : 'bg-slate-300 dark:bg-slate-600'
              }`}
            />
          </div>

          {/* Description Input */}
          <div className="flex-1 min-w-[140px] max-w-sm sm:max-w-md relative">
            <input
              type="text"
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              onKeyDown={handleKeyDown}
              placeholder="על מה אתה עובד עכשיו?..."
              className="w-full bg-transparent px-3 py-1.5 text-sm text-[var(--text-dark)] placeholder:text-[var(--text-light)] focus:outline-none focus:bg-[var(--surface-hover)] rounded-xl transition-colors border border-transparent focus:border-[var(--border)]"
            />
          </div>

          {/* Smart Combobox (Task is optional! Direct project or bank selection supported) */}
          <div className="shrink-0">
            <SmartCombobox
              selectedProjectId={selectedProjectId}
              selectedTaskId={selectedTaskId}
              selectedClientId={selectedClientId}
              selectedHoursBankId={selectedHoursBankId}
              onSelectAssignment={(assignment) => {
                setAssignment(assignment)
                clearValidationError()
              }}
              onClear={() => {
                setAssignment({ projectId: null, taskId: null, clientId: null, hoursBankId: null })
              }}
              hasError={Boolean(validationError && !selectedProjectId && !selectedTaskId && !selectedHoursBankId)}
              placeholder="שייך לפרויקט (משימה אופציונלית)..."
            />
          </div>
        </div>

        {/* Right / Clock & Actions Section */}
        <div className="flex items-center gap-3 shrink-0 mr-auto sm:mr-0">
          {/* Digital Timer Display */}
          <div
            className={`font-mono text-lg sm:text-xl font-bold tracking-wider tabular-nums transition-colors ${
              isRunning
                ? 'text-orange-500 dark:text-orange-400'
                : isPaused
                ? 'text-amber-500'
                : 'text-[var(--text-mid)]'
            }`}
          >
            {formatTimerDigits(elapsedSeconds)}
          </div>

          {/* Controls */}
          <div className="flex items-center gap-1.5">
            {!isRunning ? (
              <button
                type="button"
                onClick={() => (isPaused ? resumeTimer() : startTimer())}
                className="flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 text-white font-bold text-xs sm:text-sm shadow-xs active:scale-95 transition-all cursor-pointer"
                title={isPaused ? 'המשך טיימר' : 'התחל טיימר מיד'}
              >
                <Play size={14} className="fill-current" />
                <span>{isPaused ? 'המשך' : 'התחל'}</span>
              </button>
            ) : (
              <button
                type="button"
                onClick={pauseTimer}
                className="flex items-center justify-center p-1.5 rounded-xl border border-amber-500/40 text-amber-500 hover:bg-amber-500/10 transition-colors cursor-pointer"
                title="השהה טיימר"
              >
                <Pause size={15} />
              </button>
            )}

            {(isRunning || (isPaused && elapsedSeconds > 0)) && (
              <button
                type="button"
                disabled={isSubmitting}
                onClick={handleStop}
                className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs sm:text-sm shadow-xs active:scale-95 transition-all cursor-pointer disabled:opacity-50"
                title="עצור ושמור דיווח שעות"
              >
                <Square size={13} className="fill-current" />
                <span>{isSubmitting ? 'שומר...' : 'עצור'}</span>
              </button>
            )}

            {(isRunning || elapsedSeconds > 0) && (
              <button
                type="button"
                onClick={discardTimer}
                className="flex items-center justify-center p-1.5 rounded-xl text-[var(--text-light)] hover:text-rose-500 hover:bg-rose-500/10 transition-colors cursor-pointer"
                title="בטל סשן נוכחי ללא שמירה"
              >
                <Trash2 size={14} />
              </button>
            )}

            {/* Toggle Recent Timesheet */}
            <button
              type="button"
              onClick={() => setShowRecentEntries((prev) => !prev)}
              className={`flex items-center gap-1 px-2.5 py-1.5 rounded-xl border text-xs font-semibold transition-colors cursor-pointer ${
                showRecentEntries
                  ? 'bg-orange-500/15 border-orange-500/40 text-orange-500'
                  : 'border-[var(--border)] text-[var(--text-mid)] hover:bg-[var(--surface-hover)]'
              }`}
              title={showRecentEntries ? 'סגור יומן שעות' : 'הצג יומן שעות אחרונות'}
            >
              <Clock size={13} className={showRecentEntries ? 'text-orange-500' : 'text-[var(--text-light)]'} />
              <span className="hidden sm:inline">יומן שעות</span>
              {showRecentEntries ? <ChevronUp size={12} /> : <ChevronDown size={12} />}
            </button>
          </div>
        </div>
      </div>

      {/* Collapsible Recent Time Entries Panel directly below Timer */}
      {showRecentEntries && (
        <div className="mt-3 pt-3 border-t border-[var(--border)] max-h-[380px] overflow-y-auto animate-in fade-in slide-in-from-top-2">
          <RecentTimeEntries
            compact={true}
            maxEntries={15}
            showSearch={true}
            title="יומן דיווחי שעות אחרונים"
            className="border-0 shadow-none bg-transparent"
          />
        </div>
      )}

      {/* Validation Alert */}
      {validationError && (
        <div className="mt-2 flex items-center justify-between px-3 py-1.5 rounded-xl bg-rose-500/15 border border-rose-500/30 text-rose-600 dark:text-rose-300 text-xs animate-in fade-in slide-in-from-top-1">
          <div className="flex items-center gap-2">
            <AlertCircle size={14} className="shrink-0 text-rose-500" />
            <span className="font-semibold">{validationError}</span>
          </div>
          <button
            type="button"
            onClick={clearValidationError}
            className="text-rose-500 hover:text-rose-700 p-0.5 rounded text-[11px] cursor-pointer"
          >
            הבנתי
          </button>
        </div>
      )}
    </div>
  )
}
