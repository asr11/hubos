import { useState, useEffect } from 'react'
import { PageHeader } from '../components/PageHeader'
import { Card } from '../components/Card'
import { api } from '../lib/api'

interface LogEntry {
  id: string
  agent_id: string
  timestamp: string
  prompt_tokens: number
  completion_tokens: number
  execution_time_ms: number
  estimated_cost_usd: number
  business_value_score: number
  context_summary: string
}

interface ReportStats {
  timeSavedHours: number
  totalTokens: number
  totalCostUsd: number
  roiIndex: number
  totalActions: number
}

export function AiEfficiencyDashboard() {
  const [stats, setStats] = useState<ReportStats | null>(null)
  const [logs, setLogs] = useState<LogEntry[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    fetchData()
  }, [])

  async function fetchData() {
    try {
      setLoading(true)
      const res = await api.get('/api/ai/logs/report')
      if (res.ok) {
        setStats(res.stats)
        setLogs(res.logs)
      }
    } catch (err) {
      console.error('Failed to load AI efficiency data:', err)
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="space-y-6">
      <PageHeader title='יעילות ו-ROI של המנכ"ל הדיגיטלי' />

      {loading ? (
        <div className="text-center py-20 text-slate-400">טוען נתונים...</div>
      ) : (
        <>
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <Card className="bg-slate-900/50 backdrop-blur-xl border border-slate-700 shadow-2xl">
              <div className="p-6">
                <h3 className="text-sm font-medium text-slate-400">חסכון בזמן אדם (שעות)</h3>
                <div className="mt-2 text-3xl font-bold text-emerald-400">
                  {stats?.timeSavedHours.toFixed(1)}h
                </div>
              </div>
            </Card>

            <Card className="bg-slate-900/50 backdrop-blur-xl border border-slate-700 shadow-2xl">
              <div className="p-6">
                <h3 className="text-sm font-medium text-slate-400">עלות תפעול AI (USD)</h3>
                <div className="mt-2 text-3xl font-bold text-red-400">
                  ${stats?.totalCostUsd.toFixed(2)}
                </div>
              </div>
            </Card>

            <Card className="bg-slate-900/50 backdrop-blur-xl border border-slate-700 shadow-2xl">
              <div className="p-6">
                <h3 className="text-sm font-medium text-slate-400">כמות פעולות / טוקנים</h3>
                <div className="mt-2 text-3xl font-bold text-blue-400">
                  {stats?.totalActions} / {(stats?.totalTokens || 0).toLocaleString()}
                </div>
              </div>
            </Card>

            <Card className="bg-slate-900/50 backdrop-blur-xl border border-slate-700 shadow-2xl">
              <div className="p-6">
                <h3 className="text-sm font-medium text-slate-400">מדד ROI נקי (הערכה)</h3>
                <div className="mt-2 text-3xl font-bold text-purple-400">
                  ${stats?.roiIndex.toFixed(2)}
                </div>
              </div>
            </Card>
          </div>

          <Card className="bg-slate-900/50 backdrop-blur-xl border border-slate-700 shadow-2xl overflow-hidden mt-8">
            <div className="p-6">
              <h3 className="text-lg font-bold text-slate-100 mb-4">לוגים אחרונים מהסוכנים</h3>
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-slate-800 text-sm text-right">
                  <thead>
                    <tr className="text-slate-400">
                      <th className="px-4 py-3 font-medium">זמן</th>
                      <th className="px-4 py-3 font-medium">סוכן</th>
                      <th className="px-4 py-3 font-medium">טוקנים</th>
                      <th className="px-4 py-3 font-medium">זמן ביצוע (ms)</th>
                      <th className="px-4 py-3 font-medium">עלות ($)</th>
                      <th className="px-4 py-3 font-medium">ערך עסקי</th>
                      <th className="px-4 py-3 font-medium">הקשר</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-800 text-slate-300">
                    {logs.map((log) => (
                      <tr key={log.id} className="hover:bg-slate-800/50 transition-colors">
                        <td className="px-4 py-3" dir="ltr">{new Date(log.timestamp).toLocaleString('he-IL')}</td>
                        <td className="px-4 py-3 font-medium text-emerald-400">{log.agent_id}</td>
                        <td className="px-4 py-3">{log.prompt_tokens + log.completion_tokens}</td>
                        <td className="px-4 py-3">{log.execution_time_ms}</td>
                        <td className="px-4 py-3">${log.estimated_cost_usd.toFixed(4)}</td>
                        <td className="px-4 py-3">{log.business_value_score}</td>
                        <td className="px-4 py-3 max-w-xs truncate" title={log.context_summary}>{log.context_summary}</td>
                      </tr>
                    ))}
                    {logs.length === 0 && (
                      <tr>
                        <td colSpan={7} className="px-4 py-8 text-center text-slate-500">
                          אין עדיין נתונים
                        </td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
            </div>
          </Card>
        </>
      )}
    </div>
  )
}
