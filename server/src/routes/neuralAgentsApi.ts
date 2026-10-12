// @ts-nocheck

import { INITIAL_NODES, NeuralAgentEngine } from '../lib/neuralAgentEngine.js';
import { db } from '../lib/db.js';

export async function registerNeuralAgentsRoutes(app: Hono<any>) {
  // Get all network nodes
  app.get('/api/neural-agents/nodes', async (c: any) => {
    return c.json({
      ok: true,
      nodes: INITIAL_NODES,
      rules: {
        financialReadOnly: true,
        tenCommandmentsAudit: true,
        localFirstEngine: true
      }
    });
  });

  // Get agent audit logs
  app.get('/api/neural-agents/logs', async (c: any) => {
    try {
      const logs = db.prepare(`
        SELECT id, event_type, target, details, timestamp 
        FROM system_logs 
        WHERE event_type LIKE 'neural_agent%' 
        ORDER BY id DESC LIMIT 100
      `).all();
      
      return c.json({
        ok: true,
        logs
      });
    } catch (error) {
      return c.json({
        ok: false,
        error: 'fetch_logs_failed',
        message: error instanceof Error ? error.message : 'שגיאה בשליפת לוגים'
      }, 500);
    }
  });

  // Run a new neural agent chain execution
  app.post('/api/neural-agents/chain/run', async (c: any) => {
    const body = (await c.req.json() ?? {}) as { task?: string };
    const task = body?.task?.trim() || 'עיבוד וסנכרון משימה שגרתית';

    try {
      const steps = await NeuralAgentEngine.executeChain(task);
      return c.json({
        ok: true,
        task,
        executedStepsCount: steps.length,
        steps
      });
    } catch (error) {
      return c.json({
        ok: false,
        error: 'neural_execution_failed',
        message: error instanceof Error ? error.message : 'שגיאה בהרצת שרשרת הסוכנים'
      }, 500);
    }
  });
}
