import { type FastifyInstance } from 'fastify'
import { db } from '../lib/db.js'
import crypto from 'node:crypto'

export async function registerAiLogsRoutes(app: FastifyInstance) {
  // Ingest logs
  app.post('/api/ai/logs', async (req, reply) => {
    try {
      const body = req.body as {
        agent_id: string
        prompt_tokens: number
        completion_tokens: number
        execution_time_ms: number
        estimated_cost_usd: number
        business_value_score: number
        context_summary: string
      }

      if (!body.agent_id) {
        return reply.code(400).send({ ok: false, message: 'agent_id is required' })
      }

      const id = crypto.randomUUID()
      const stmt = db.prepare(`
        INSERT INTO ai_agent_logs (id, agent_id, prompt_tokens, completion_tokens, execution_time_ms, estimated_cost_usd, business_value_score, context_summary)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
      `)
      
      stmt.run(
        id,
        body.agent_id,
        body.prompt_tokens || 0,
        body.completion_tokens || 0,
        body.execution_time_ms || 0,
        body.estimated_cost_usd || 0,
        body.business_value_score || 0,
        body.context_summary || ''
      )

      return { ok: true, id }
    } catch (err: any) {
      req.log.error(err)
      return reply.code(500).send({ ok: false, error: err.message })
    }
  })

  // Get aggregated report
  app.get('/api/ai/logs/report', async (req, reply) => {
    try {
      // Aggregate data
      const stats = db.prepare(`
        SELECT 
          SUM(business_value_score) as total_time_saved_hours,
          SUM(prompt_tokens + completion_tokens) as total_tokens,
          SUM(estimated_cost_usd) as total_cost_usd,
          COUNT(*) as total_actions
        FROM ai_agent_logs
      `).get() as { total_time_saved_hours: number, total_tokens: number, total_cost_usd: number, total_actions: number }

      // Get some recent logs for the table
      const logs = db.prepare(`
        SELECT * FROM ai_agent_logs ORDER BY timestamp DESC LIMIT 50
      `).all()

      return {
        ok: true,
        stats: {
          timeSavedHours: stats.total_time_saved_hours || 0,
          totalTokens: stats.total_tokens || 0,
          totalCostUsd: stats.total_cost_usd || 0,
          roiIndex: (stats.total_time_saved_hours || 0) * 150 - (stats.total_cost_usd || 0), // Assumes $150 per hour saved
          totalActions: stats.total_actions || 0
        },
        logs
      }
    } catch (err: any) {
      req.log.error(err)
      return reply.code(500).send({ ok: false, error: err.message })
    }
  })
}
