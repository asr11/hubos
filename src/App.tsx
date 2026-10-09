import { lazy, Suspense } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AuthProvider } from './contexts/AuthContext'
import { FirestoreProviders } from './contexts/FirestoreProviders'
import { AgentPortalPreviewShell } from './components/AgentPortalPreviewShell'
import { AgentShell } from './components/AgentShell'
import { Layout } from './components/Layout'
import { RequireAuth } from './components/RequireAuth'
import { RequireAdmin } from './components/RequireAdmin'
import { StaffRoutesGuard } from './components/StaffRoutesGuard'
const AgentDashboard = lazy(() => import('./pages/AgentDashboard').then(m => ({ default: m.AgentDashboard })))
const AgentLeadsPage = lazy(() => import('./pages/agent/AgentLeadsPage').then(m => ({ default: m.AgentLeadsPage })))
const AgentBusinessPage = lazy(() => import('./pages/agent/AgentBusinessPage').then(m => ({ default: m.AgentBusinessPage })))
const AgentFinancePage = lazy(() => import('./pages/agent/AgentFinancePage').then(m => ({ default: m.AgentFinancePage })))
const AgentsAdmin = lazy(() => import('./pages/AgentsAdmin').then(m => ({ default: m.AgentsAdmin })))
const AgentsNeuralNetwork = lazy(() => import('./pages/AgentsNeuralNetwork').then(m => ({ default: m.AgentsNeuralNetwork })))
const Clients = lazy(() => import('./pages/Clients').then(m => ({ default: m.Clients })))
const Collections = lazy(() => import('./pages/Collections').then(m => ({ default: m.Collections })))
import { Dashboard } from './pages/Dashboard'
const Expenses = lazy(() => import('./pages/Expenses').then(m => ({ default: m.Expenses })))
const Finance = lazy(() => import('./pages/Finance').then(m => ({ default: m.Finance })))
const FinanceProjects = lazy(() => import('./pages/FinanceProjects').then(m => ({ default: m.FinanceProjects })))
const FinanceReconciliation = lazy(() => import('./pages/FinanceReconciliation').then(m => ({ default: m.FinanceReconciliation })))
const FinanceDocuments = lazy(() => import('./pages/FinanceDocuments').then(m => ({ default: m.FinanceDocuments })))
const FinanceCashflow = lazy(() => import('./pages/FinanceCashflow').then(m => ({ default: m.FinanceCashflow })))
const FinanceStandingOrders = lazy(() => import('./pages/FinanceStandingOrders').then(m => ({ default: m.FinanceStandingOrders })))
const FinanceRetainers = lazy(() => import('./pages/FinanceRetainers').then(m => ({ default: m.FinanceRetainers })))
const FinanceFixedExpenses = lazy(() => import('./pages/FinanceFixedExpenses').then(m => ({ default: m.FinanceFixedExpenses })))
const Income = lazy(() => import('./pages/Income').then(m => ({ default: m.Income })))
const Hosting = lazy(() => import('./pages/Hosting').then(m => ({ default: m.Hosting })))
const Campaigns = lazy(() => import('./pages/Campaigns').then(m => ({ default: m.Campaigns })))
const Leads = lazy(() => import('./pages/Leads').then(m => ({ default: m.Leads })))
import { Login } from './pages/Login'
const ProjectDetail = lazy(() => import('./pages/ProjectDetail').then(m => ({ default: m.ProjectDetail })))
const Projects = lazy(() => import('./pages/Projects').then(m => ({ default: m.Projects })))
const PromptLibrary = lazy(() => import('./pages/PromptLibrary').then(m => ({ default: m.PromptLibrary })))
const Suppliers = lazy(() => import('./pages/Suppliers').then(m => ({ default: m.Suppliers })))
const Calendar = lazy(() => import('./pages/Calendar').then(m => ({ default: m.Calendar })))
const MeetingSummaries = lazy(() => import('./pages/MeetingSummaries').then(m => ({ default: m.MeetingSummaries })))
const Tasks = lazy(() => import('./pages/Tasks').then(m => ({ default: m.Tasks })))
const Users = lazy(() => import('./pages/Users').then(m => ({ default: m.Users })))
const HourlyServices = lazy(() => import('./pages/HourlyServices').then(m => ({ default: m.HourlyServices })))
const HourlyServiceDetail = lazy(() => import('./pages/HourlyServiceDetail').then(m => ({ default: m.HourlyServiceDetail })))
const HourlyServiceShare = lazy(() => import('./pages/HourlyServiceShare').then(m => ({ default: m.HourlyServiceShare })))
const Attendance = lazy(() => import('./pages/Attendance').then(m => ({ default: m.Attendance })))
const Vault = lazy(() => import('./pages/Vault').then(m => ({ default: m.Vault })))
const DigitalCeo = lazy(() => import('./pages/DigitalCeo').then(m => ({ default: m.DigitalCeo })))

export default function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <Suspense fallback={<div className="flex h-screen items-center justify-center">טוען...</div>}>
        <Routes>
          <Route path="/login" element={<Login />} />
          <Route path="/share/hours/:token" element={<HourlyServiceShare />} />
          <Route element={<RequireAuth />}>
            <Route element={<FirestoreProviders />}>
              <Route path="agent" element={<AgentShell />}>
                <Route index element={<AgentDashboard />} />
                <Route path="leads" element={<AgentLeadsPage />} />
                <Route path="business" element={<AgentBusinessPage />} />
                <Route path="finance" element={<AgentFinancePage />} />
              </Route>
              <Route
                path="agents/preview/:agentUserId"
                element={
                  <RequireAdmin>
                    <AgentPortalPreviewShell />
                  </RequireAdmin>
                }
              >
                <Route index element={<AgentDashboard />} />
                <Route path="leads" element={<AgentLeadsPage />} />
                <Route path="business" element={<AgentBusinessPage />} />
                <Route path="finance" element={<AgentFinancePage />} />
              </Route>
              <Route element={<StaffRoutesGuard />}>
                <Route element={<Layout />}>
                  <Route index element={<Dashboard />} />
                  <Route path="clients" element={<Clients />} />
                  <Route path="collections" element={<Collections />} />
                  <Route
                    path="finance"
                    element={
                      <RequireAdmin>
                        <Finance />
                      </RequireAdmin>
                    }
                  />
                  <Route
                    path="finance/income"
                    element={
                      <RequireAdmin>
                        <Income />
                      </RequireAdmin>
                    }
                  />
                  <Route
                    path="finance/expenses"
                    element={
                      <RequireAdmin>
                        <Expenses />
                      </RequireAdmin>
                    }
                  />
                  <Route
                    path="finance/reconciliation"
                    element={
                      <RequireAdmin>
                        <FinanceReconciliation />
                      </RequireAdmin>
                    }
                  />
                  <Route
                    path="finance/documents"
                    element={
                      <RequireAdmin>
                        <FinanceDocuments />
                      </RequireAdmin>
                    }
                  />
                  <Route
                    path="finance/projects"
                    element={
                      <RequireAdmin>
                        <FinanceProjects />
                      </RequireAdmin>
                    }
                  />
                  <Route
                    path="finance/cashflow"
                    element={
                      <RequireAdmin>
                        <FinanceCashflow />
                      </RequireAdmin>
                    }
                  />
                  <Route
                    path="finance/fixed-expenses"
                    element={
                      <RequireAdmin>
                        <FinanceFixedExpenses />
                      </RequireAdmin>
                    }
                  />
                  <Route
                    path="finance/standing-orders"
                    element={
                      <RequireAdmin>
                        <FinanceStandingOrders />
                      </RequireAdmin>
                    }
                  />
                  <Route
                    path="finance/retainers"
                    element={
                      <RequireAdmin>
                        <FinanceRetainers />
                      </RequireAdmin>
                    }
                  />
                  <Route path="suppliers" element={<Suppliers />} />
                  <Route path="hosting" element={<Hosting />} />
                  <Route path="campaigns" element={<Campaigns />} />
                  <Route path="leads" element={<Leads />} />
                  <Route path="projects" element={<Projects />} />
                  <Route path="projects/:id" element={<ProjectDetail />} />
                  <Route path="tasks" element={<Tasks />} />
                  <Route path="hourly-services" element={<HourlyServices />} />
                  <Route
                    path="hourly-services/:id"
                    element={<HourlyServiceDetail />}
                  />
                  <Route path="calendar" element={<Calendar />} />
                  <Route
                    path="calendar/summaries"
                    element={<MeetingSummaries />}
                  />
                  <Route path="prompts" element={<PromptLibrary />} />
                  <Route
                    path="users"
                    element={
                      <RequireAdmin>
                        <Users />
                      </RequireAdmin>
                    }
                  />
                  <Route
                    path="agents"
                    element={
                      <RequireAdmin>
                        <AgentsAdmin />
                      </RequireAdmin>
                    }
                  />
                  <Route
                    path="agents/network"
                    element={
                      <RequireAdmin>
                        <AgentsNeuralNetwork />
                      </RequireAdmin>
                    }
                  />

                  <Route path="attendance" element={<Attendance />} />
                  <Route path="vault" element={<Vault />} />
                  <Route
                    path="ceo"
                    element={
                      <RequireAdmin>
                        <DigitalCeo />
                      </RequireAdmin>
                    }
                  />
                  <Route path="*" element={<Navigate to="/" replace />} />
                </Route>
              </Route>
            </Route>
          </Route>
        </Routes>
        </Suspense>
      </AuthProvider>
    </BrowserRouter>
  )
}
