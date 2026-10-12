// @ts-nocheck
import { Hono } from 'hono'
import { cors } from 'hono/cors'
import { env } from './config/env.js'
import { initLocalDatabase, logSystemEvent } from './lib/db.js'
import { startAutonomyEngine } from './jobs/index.js'

import { registerFinanceDocumentRoutes } from './routes/financeDocuments.js'
import { registerHealthRoutes } from './routes/health.js'
import { registerApiRoutes } from './routes/api.js'
import { registerMorningRoutes } from './routes/morning.js'
import { registerBotApiRoutes } from './routes/botApi.js'
import { registerFinanceAgentRoutes } from './routes/financeAgent.js'
import { registerNeuralAgentsRoutes } from './routes/neuralAgentsApi.js'
import { registerAuthRoutes } from './routes/auth.js'
import { registerUsersRoutes } from './routes/users.js'
import { registerCrudRoutes } from './routes/crud.js'
import { registerAiLogsRoutes } from './routes/ai-logs.js'

export async function buildApp() {
  initLocalDatabase()
  logSystemEvent('SYSTEM_START', 'SERVER', 'SQLite local database initialized successfully')
  
  if (env.isDev && process.env.DISABLE_AUTONOMY !== 'true') {
    startAutonomyEngine(5 * 60 * 1000); // 5 minutes
  }

  const app = new Hono<{ Bindings: any }>()

  app.use('/*', cors({
    origin: env.corsOrigins,
    credentials: true,
    allowMethods: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
  }))

  await registerHealthRoutes(app)
  await registerApiRoutes(app)
  await registerMorningRoutes(app)
  await registerBotApiRoutes(app)
  await registerFinanceAgentRoutes(app)
  await registerFinanceDocumentRoutes(app)
  await registerNeuralAgentsRoutes(app)
  // await registerAuthRoutes(app)
  await registerUsersRoutes(app)
  await registerCrudRoutes(app)
  await registerAiLogsRoutes(app)

  app.notFound((c) => {
    return c.json({ ok: false, error: 'not_found', message: 'נתיב לא קיים' }, 404)
  })

  app.onError((err, c) => {
    console.error(err)
    const status = (err as any).statusCode || 500
    const message = err instanceof Error ? err.message : 'שגיאה פנימית'
    return c.json({
      ok: false,
      error: status >= 500 ? 'internal' : ((err as any).code ?? 'bad_request'),
      message: status < 500 || env.isDev ? message : 'שגיאה פנימית',
    }, status)
  })

  return app
}
