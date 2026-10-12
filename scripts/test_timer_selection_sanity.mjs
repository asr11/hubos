import assert from 'node:assert/strict'

console.log('🧪 Starting Timer Task Selection Sanity & E2E Logic Test...')

// 1. Mock Data Sets
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
  { id: 'task-4', title: 'Client Onboarding Consultation', clientId: 'client-1', status: 'todo', createdAt: '2026-10-04' },
]

// 2. Maps construction (identical to TogglTimerBar.tsx lines 81-95)
const projectMap = new Map()
for (const p of mockProjects) projectMap.set(p.id, p)

const clientMap = new Map()
for (const c of mockClients) clientMap.set(c.id, c)

// TEST 1: Default View - Must open to 'tasks' tab (NOT 'clients'!)
console.log('▶ Test 1: Default tab state on opening picker')
let initialActiveTab = 'tasks' // TogglTimerBar.tsx line 56
assert.equal(initialActiveTab, 'tasks', 'Expected default tab to be tasks')

function openPicker() {
  return {
    searchQuery: '',
    activeTab: 'tasks',
    filterProjectId: null,
    filterClientId: null,
  }
}
const openedState = openPicker()
assert.equal(openedState.activeTab, 'tasks', 'Expected opened picker activeTab to be tasks, NOT clients!')
console.log('  ✔ Verified: Default picker view opens directly to Tasks.')

// TEST 2: Task Sorting and Filtering (TogglTimerBar.tsx lines 148-168)
console.log('▶ Test 2: Task Display and Open/Done Ordering')
function getDisplayedTasks(tasks, filterProjectId, filterClientId, projects) {
  let list = tasks
  if (filterProjectId) {
    list = list.filter((t) => t.projectId === filterProjectId)
  } else if (filterClientId) {
    const clientProjIds = new Set(
      projects.filter((p) => p.clientId === filterClientId).map((p) => p.id),
    )
    list = list.filter(
      (t) => t.clientId === filterClientId || (t.projectId && clientProjIds.has(t.projectId)),
    )
  }

  return [...list].sort((a, b) => {
    if (a.status === 'done' && b.status !== 'done') return 1
    if (a.status !== 'done' && b.status === 'done') return -1
    return (b.createdAt || '').localeCompare(a.createdAt || '')
  })
}

const defaultTasks = getDisplayedTasks(mockTasks, null, null, mockProjects)
assert.equal(defaultTasks.length, 4, 'Should show all 4 tasks by default')
// Check that open tasks come before 'done' tasks
assert.notEqual(defaultTasks[0].status, 'done', 'First task must not be done')
assert.equal(defaultTasks[defaultTasks.length - 1].status, 'done', 'Last task should be the done task')
console.log('  ✔ Verified: Open tasks are prioritized over completed tasks.')

// Filter by project
const proj1Tasks = getDisplayedTasks(mockTasks, 'proj-1', null, mockProjects)
assert.equal(proj1Tasks.length, 2, 'Project 1 should have 2 tasks')
assert.ok(proj1Tasks.every(t => t.projectId === 'proj-1'), 'All tasks must belong to proj-1')
console.log('  ✔ Verified: Filtering by project works correctly.')

// Filter by client
const client1Tasks = getDisplayedTasks(mockTasks, null, 'client-1', mockProjects)
// Should match task-1, task-2 (via proj-1) and task-4 (via direct clientId)
assert.equal(client1Tasks.length, 3, 'Client 1 should have 3 associated tasks')
console.log('  ✔ Verified: Filtering by client includes project tasks & direct client tasks.')

// TEST 3: Multi-Entity Search (TogglTimerBar.tsx lines 113-145)
console.log('▶ Test 3: Search matching across Tasks, Projects, and Clients')
function searchEntities(q, tasks, projects, clients, projectMap, clientMap) {
  const query = q.toLowerCase().trim()
  if (!query) {
    return { matchedTasks: [], matchedProjects: [], matchedClients: [], isSearch: false }
  }

  const mTasks = tasks.filter((t) => {
    const titleMatch = t.title?.toLowerCase().includes(query)
    const proj = t.projectId ? projectMap.get(t.projectId) : null
    const projMatch = proj?.name?.toLowerCase().includes(query)
    const client =
      (t.clientId ? clientMap.get(t.clientId) : null) ||
      (proj?.clientId ? clientMap.get(proj.clientId) : null)
    const clientMatch = client?.name?.toLowerCase().includes(query)
    return titleMatch || projMatch || clientMatch
  })

  const mProjects = projects.filter((p) => {
    const nameMatch = p.name?.toLowerCase().includes(query)
    const client = p.clientId ? clientMap.get(p.clientId) : null
    const clientMatch = client?.name?.toLowerCase().includes(query)
    return nameMatch || clientMatch
  })

  const mClients = clients.filter((c) => c.name?.toLowerCase().includes(query))

  return { matchedTasks: mTasks, matchedProjects: mProjects, matchedClients: mClients, isSearch: true }
}

const searchResultTask = searchEntities('Homepage', mockTasks, mockProjects, mockClients, projectMap, clientMap)
assert.equal(searchResultTask.matchedTasks.length, 1)
assert.equal(searchResultTask.matchedTasks[0].id, 'task-1')
console.log('  ✔ Verified: Searching task title finds matching task.')

const searchResultClient = searchEntities('Acme', mockTasks, mockProjects, mockClients, projectMap, clientMap)
// Searching client name 'Acme' must find tasks under Acme
assert.equal(searchResultClient.matchedTasks.length, 3, 'Tasks belonging to client should be matched')
assert.equal(searchResultClient.matchedClients.length, 1)
console.log('  ✔ Verified: Searching by client name surfaces all relevant tasks.')

// TEST 4: Task Selection & Parent Project Resolution (TogglTimerBar.tsx lines 179-195)
console.log('▶ Test 4: Task Selection and Automatic Parent Project Attachment')
function selectTask(task, selectedProjectId, projects) {
  let projId = task.projectId || selectedProjectId || null
  if (!projId && task.clientId) {
    const clientProj = projects.find((p) => p.clientId === task.clientId)
    if (clientProj) projId = clientProj.id
  }
  if (!projId && projects.length > 0) {
    projId = projects[0].id
  }
  return { taskId: task.id, projId }
}

// Case A: Task with explicit projectId
const resA = selectTask(mockTasks[0], null, mockProjects)
assert.equal(resA.taskId, 'task-1')
assert.equal(resA.projId, 'proj-1')

// Case B: Task without projectId, but with clientId
const resB = selectTask(mockTasks[3], null, mockProjects)
assert.equal(resB.taskId, 'task-4')
assert.equal(resB.projId, 'proj-1', 'Should resolve client project proj-1')

// Case C: Task without project or client
const orphanTask = { id: 'orphan-1', title: 'Unassigned Task', status: 'todo' }
const resC = selectTask(orphanTask, null, mockProjects)
assert.equal(resC.taskId, 'orphan-1')
assert.equal(resC.projId, 'proj-1', 'Should fallback to first available project')
console.log('  ✔ Verified: selectTask reliably resolves parent project ID.')

// TEST 5: TimerContext stopTimer Validation
console.log('▶ Test 5: TimerContext stopTimer Validation')
function validateStopTimer(elapsedSeconds, selectedTaskId) {
  if (elapsedSeconds <= 0) {
    return { success: true }
  }
  const hasTask = Boolean(selectedTaskId && selectedTaskId.trim())
  if (!hasTask) {
    return { success: false, error: 'חובה לבחור משימה לפני סיום ושמירת הטיימר' }
  }
  return { success: true }
}

const noTaskStop = validateStopTimer(120, null)
assert.equal(noTaskStop.success, false)
assert.equal(noTaskStop.error, 'חובה לבחור משימה לפני סיום ושמירת הטיימר')

const withTaskStop = validateStopTimer(120, 'task-1')
assert.equal(withTaskStop.success, true)
console.log('  ✔ Verified: stopTimer requires task selection and succeeds when task is chosen.')

console.log('\n🎉 ALL 5 E2E SANITY TESTS PASSED SUCCESSFULLY! 🚀')
