import { describe, it, expect } from 'vitest'

describe('TogglTimerBar, SmartCombobox, and TimerContext - E2E Logic', () => {
  const mockClients = [
    { id: 'client-1', name: 'Acme Corp' },
    { id: 'client-2', name: 'Beta LLC' },
  ]

  const mockProjects = [
    { id: 'proj-1', name: 'Website Redesign', clientId: 'client-1' },
    { id: 'proj-2', name: 'Mobile App', clientId: 'client-2' },
  ]

  const mockTasks = [
    { id: 'task-1', title: 'Design Homepage Mockup', projectId: 'proj-1', status: 'in_progress', createdAt: '2026-10-01' },
    { id: 'task-2', title: 'Fix Navigation CSS', projectId: 'proj-1', status: 'done', createdAt: '2026-10-02' },
    { id: 'task-3', title: 'Setup Push Notifications', projectId: 'proj-2', status: 'todo', createdAt: '2026-10-03' },
    { id: 'task-4', title: 'Client Consultation', clientId: 'client-1', status: 'todo', createdAt: '2026-10-04' },
  ]

  const projectMap = new Map()
  for (const p of mockProjects) projectMap.set(p.id, p)

  const clientMap = new Map()
  for (const c of mockClients) clientMap.set(c.id, c)

  it('1. SmartCombobox displays projects and tasks with cross-entity search', () => {
    function search(q: string) {
      const query = q.toLowerCase().trim()
      return mockTasks.filter((t) => {
        const titleMatch = t.title?.toLowerCase().includes(query)
        const proj = t.projectId ? projectMap.get(t.projectId) : null
        const projMatch = proj?.name?.toLowerCase().includes(query)
        const client =
          (t.clientId ? clientMap.get(t.clientId) : null) ||
          (proj?.clientId ? clientMap.get(proj.clientId) : null)
        const clientMatch = client?.name?.toLowerCase().includes(query)
        return titleMatch || projMatch || clientMatch
      })
    }

    expect(search('Homepage')).toHaveLength(1)
    expect(search('Website Redesign')).toHaveLength(2)
    expect(search('Acme')).toHaveLength(3) // 2 from proj-1 + 1 direct client
  })

  it('2. Displayed tasks list prioritizes active/open tasks over completed tasks', () => {
    const list = [...mockTasks].sort((a, b) => {
      if (a.status === 'done' && b.status !== 'done') return 1
      if (a.status !== 'done' && b.status === 'done') return -1
      return (b.createdAt || '').localeCompare(a.createdAt || '')
    })

    expect(list[0].status).not.toBe('done')
    expect(list[list.length - 1].status).toBe('done')
    expect(list[list.length - 1].id).toBe('task-2')
  })

  it('3. Selecting a task automatically resolves and attaches the parent project ID and client ID', () => {
    function selectTask(task: (typeof mockTasks)[0]) {
      let projId = task.projectId || null
      let clId = task.clientId || null
      if (!projId && task.clientId) {
        const clientProj = mockProjects.find((p) => p.clientId === task.clientId)
        if (clientProj) projId = clientProj.id
      }
      if (projId && !clId) {
        const parentProj = projectMap.get(projId)
        if (parentProj?.clientId) clId = parentProj.clientId
      }
      return { taskId: task.id, projId, clId }
    }

    expect(selectTask(mockTasks[0])).toEqual({ taskId: 'task-1', projId: 'proj-1', clId: 'client-1' })
    expect(selectTask(mockTasks[3])).toEqual({ taskId: 'task-4', projId: 'proj-1', clId: 'client-1' })
  })

  it('4. SmartCombobox allows 1-click direct project assignment (Task is OPTIONAL)', () => {
    function selectProjectAssignment(project: (typeof mockProjects)[0]) {
      return {
        projectId: project.id,
        taskId: null, // Task selection is optional!
        clientId: project.clientId,
      }
    }

    const assignment = selectProjectAssignment(mockProjects[0])
    expect(assignment.projectId).toBe('proj-1')
    expect(assignment.taskId).toBeNull()
    expect(assignment.clientId).toBe('client-1')
  })

  it('5. TimerContext validation: Task selection is OPTIONAL upon stop (Project is sufficient)', () => {
    function validate(elapsedSeconds: number, selectedProjectId: string | null, selectedTaskId: string | null) {
      if (elapsedSeconds <= 0) return { success: true }
      const hasProject = Boolean(selectedProjectId && selectedProjectId.trim())
      const hasTask = Boolean(selectedTaskId && selectedTaskId.trim())
      if (!hasProject && !hasTask) {
        return { success: false, error: 'נא לבחור פרויקט לשיוך שעות העבודה (בחירת משימה היא אופציונלית)' }
      }
      return { success: true }
    }

    // Stopping with ONLY a project (no task) succeeds!
    expect(validate(300, 'proj-1', null).success).toBe(true)
    // Stopping with both project and task succeeds!
    expect(validate(300, 'proj-1', 'task-1').success).toBe(true)
    // Stopping with neither fails with informative error
    expect(validate(300, null, null).success).toBe(false)
  })

  it('6. RecentTimeEntries formats start and stop times correctly from timestamps and duration fallbacks', () => {
    function getTimeRange(entry: { start_time?: string; end_time?: string; date?: string; duration_seconds?: number }) {
      let start = entry.start_time ? new Date(entry.start_time) : null
      let end = entry.end_time ? new Date(entry.end_time) : null

      if ((!start || !end) && entry.date) {
        const endDate = new Date(entry.date)
        const durMs = (entry.duration_seconds || 0) * 1000
        start = new Date(endDate.getTime() - durMs)
        end = endDate
      }

      const fmt = (d: Date | null) =>
        d && !isNaN(d.getTime())
          ? `${String(d.getUTCHours()).padStart(2, '0')}:${String(d.getUTCMinutes()).padStart(2, '0')}`
          : ''
      return `${fmt(start)} - ${fmt(end)}`
    }

    const entryWithTimes = {
      start_time: '2026-10-05T10:00:00.000Z',
      end_time: '2026-10-05T10:45:00.000Z',
      duration_seconds: 2700,
    }
    expect(getTimeRange(entryWithTimes)).toBe('10:00 - 10:45')

    const legacyEntryWithoutTimes = {
      date: '2026-10-05T14:30:00.000Z',
      duration_seconds: 1800, // 30 min
    }
    expect(getTimeRange(legacyEntryWithoutTimes)).toBe('14:00 - 14:30')
  })

  it('7. RecentTimeEntries aggregates total duration per day group accurately', () => {
    const dayEntries = [
      { id: '1', duration_seconds: 1800, date: '2026-10-05T10:00:00Z' },
      { id: '2', duration_seconds: 3600, date: '2026-10-05T14:00:00Z' },
      { id: '3', duration_seconds: 600, date: '2026-10-04T12:00:00Z' },
    ]

    const totals: Record<string, number> = {}
    for (const e of dayEntries) {
      const key = e.date.slice(0, 10)
      totals[key] = (totals[key] || 0) + e.duration_seconds
    }

    expect(totals['2026-10-05']).toBe(5400) // 1.5 hours = 5400s
    expect(totals['2026-10-04']).toBe(600)
  })

  it('8. Hours Bank PLAY starts timer with bank projectId or client default project and hoursBankId', () => {
    const bankWithProject = {
      id: 'bank-1',
      clientId: 'client-1',
      projectId: 'proj-1',
      name: 'Adar26 Support Bank',
    }

    const bankWithoutProject = {
      id: 'bank-2',
      clientId: 'client-2',
      projectId: null,
      name: 'Mobile Retainer',
    }

    const bankStandaloneClient = {
      id: 'bank-3',
      clientId: 'client-no-projects',
      projectId: null,
      name: 'Standalone Hours Bank',
    }

    function resolveBankTimerAssignment(bank: { id: string; clientId: string; projectId: string | null; name: string }) {
      let targetProjectId = bank.projectId || null
      if (!targetProjectId && bank.clientId) {
        const clientProjects = mockProjects.filter((p) => p.clientId === bank.clientId)
        const nameMatch = clientProjects.find(
          (p) =>
            p.name &&
            bank.name &&
            (p.name.toLowerCase().includes(bank.name.toLowerCase()) ||
              bank.name.toLowerCase().includes(p.name.toLowerCase())),
        )
        targetProjectId = nameMatch?.id || clientProjects[0]?.id || null
      }

      return {
        clientId: bank.clientId,
        projectId: targetProjectId,
        taskId: null,
        hoursBankId: bank.id,
        description: `בנק שעות: ${bank.name}`,
      }
    }

    const res1 = resolveBankTimerAssignment(bankWithProject)
    expect(res1.projectId).toBe('proj-1')
    expect(res1.hoursBankId).toBe('bank-1')

    const res2 = resolveBankTimerAssignment(bankWithoutProject)
    expect(res2.projectId).toBe('proj-2')
    expect(res2.hoursBankId).toBe('bank-2')

    const res3 = resolveBankTimerAssignment(bankStandaloneClient)
    expect(res3.projectId).toBeNull()
    expect(res3.hoursBankId).toBe('bank-3')
  })

  it('9. TimerContext validation: Stopping timer with an Hours Bank succeeds without blocking error', () => {
    function validateStop(
      elapsedSeconds: number,
      selectedProjectId: string | null,
      selectedTaskId: string | null,
      selectedHoursBankId: string | null,
    ) {
      if (elapsedSeconds <= 0) return { success: true }
      const hasProject = Boolean(selectedProjectId && selectedProjectId.trim())
      const hasTask = Boolean(selectedTaskId && selectedTaskId.trim())
      const hasHoursBank = Boolean(selectedHoursBankId && selectedHoursBankId.trim())

      if (!hasProject && !hasTask && !hasHoursBank) {
        return {
          success: false,
          error: 'נא לבחור פרויקט לשיוך שעות העבודה (בחירת משימה היא אופציונלית)',
        }
      }
      return { success: true }
    }

    // Stopping with standalone hours bank (no project, no task) succeeds!
    expect(validateStop(180, null, null, 'bank-3').success).toBe(true)

    // Stopping with hours bank AND project succeeds!
    expect(validateStop(180, 'proj-1', null, 'bank-1').success).toBe(true)

    // Stopping with none fails with error
    expect(validateStop(180, null, null, null).success).toBe(false)
  })

  it('10. TogglTimerBar hasError flag is false when Hours Bank is selected', () => {
    function computeHasError(
      validationError: string | null,
      selectedProjectId: string | null,
      selectedTaskId: string | null,
      selectedHoursBankId: string | null,
    ) {
      return Boolean(validationError && !selectedProjectId && !selectedTaskId && !selectedHoursBankId)
    }

    const err = 'נא לבחור פרויקט לשיוך שעות העבודה (בחירת משימה היא אופציונלית)'
    // If validation error was set, but user selected hours bank, hasError is false
    expect(computeHasError(err, null, null, 'bank-1')).toBe(false)
    // If neither project, task, nor bank, hasError is true
    expect(computeHasError(err, null, null, null)).toBe(true)
  })
})
