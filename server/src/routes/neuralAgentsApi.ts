import type { FastifyInstance, FastifyRequest, FastifyReply } from 'fastify';
import { INITIAL_NODES, NeuralAgentEngine } from '../lib/neuralAgentEngine.js';
import { db } from '../lib/db.js';

export async function registerNeuralAgentsRoutes(app: FastifyInstance): Promise<void> {
  // Get all network nodes
  app.get('/api/neural-agents/nodes', async (_req: FastifyRequest, reply: FastifyReply) => {
    reply.send({
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
  app.get('/api/neural-agents/logs', async (_req: FastifyRequest, reply: FastifyReply) => {
    try {
      const logs = db.prepare(`
        SELECT id, event_type, target, details, timestamp 
        FROM system_logs 
        WHERE event_type LIKE 'neural_agent%' 
        ORDER BY id DESC LIMIT 100
      `).all();
      
      reply.send({
        ok: true,
        logs
      });
    } catch (error) {
      reply.code(500).send({
        ok: false,
        error: 'fetch_logs_failed',
        message: error instanceof Error ? error.message : 'שגיאה בשליפת לוגים'
      });
    }
  });

  // Run a new neural agent chain execution
  app.post('/api/neural-agents/chain/run', async (req: FastifyRequest, reply: FastifyReply) => {
    const body = (req.body ?? {}) as { task?: string };
    const task = body?.task?.trim() || 'עיבוד וסנכרון משימה שגרתית';

    try {
      const steps = await NeuralAgentEngine.executeChain(task);
      reply.send({
        ok: true,
        task,
        executedStepsCount: steps.length,
        steps
      });
    } catch (error) {
      reply.code(500).send({
        ok: false,
        error: 'neural_execution_failed',
        message: error instanceof Error ? error.message : 'שגיאה בהרצת שרשרת הסוכנים'
      });
    }
  });
}
