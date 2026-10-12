import { Hono } from 'hono'
import { cors } from 'hono/cors'
import { buildApp } from './app.js'
import { initLocalDatabase, seedSuperAdmin, logSystemEvent, setD1Database } from './lib/db.js'
import { injectCloudflareEnv } from './config/env.js'
import type { Context, Next } from 'hono'

export interface ExecutionContext {
  waitUntil(promise: Promise<any>): void;
  passThroughOnException(): void;
  props: any;
}

export interface D1Database {
  prepare(query: string): any;
  dump(): Promise<ArrayBuffer>;
  batch(statements: any[]): Promise<any[]>;
  exec(query: string): Promise<any>;
}

type Bindings = {
  DB: D1Database
  JWT_SECRET?: string
}

let backendApp: Hono<any> | null = null;
let initialized = false

const workerRouter = new Hono<{ Bindings: Bindings }>()

workerRouter.use('/*', async (c: Context<{ Bindings: Bindings }>, next: Next) => {
  if (c.env) {
    injectCloudflareEnv(c.env)
  }
  if (c.env.DB) {
    setD1Database(c.env.DB)
  }

  if (!initialized && c.env.DB) {
    try {
      await initLocalDatabase()
      await seedSuperAdmin()
      await logSystemEvent('WORKER_START', 'CLOUDFLARE', 'Cloudflare Worker initialized with D1 database')
      initialized = true
    } catch (err) {
      console.error('[Worker Init] Failed:', err)
    }
  }
  
  await next()
})

import { jwt, sign } from 'hono/jwt'
import bcrypt from 'bcryptjs'

workerRouter.post('/api/auth/login', async (c: Context<{ Bindings: Bindings }>) => {
  const secret = c.env.JWT_SECRET;
  if (!secret) return c.json({ ok: false, error: 'server_misconfigured', message: 'JWT_SECRET missing' }, 500);

  try {
    const body = await c.req.json();
    const { email, password } = body as Record<string, string>;

    if (!email || !password) {
      return c.json({ ok: false, error: 'missing_credentials' }, 400);
    }

    const user = await c.env.DB.prepare('SELECT * FROM users WHERE email = ?').bind(email).first() as any;

    if (!user) {
      return c.json({ ok: false, error: 'invalid_credentials' }, 401);
    }

    const match = bcrypt.compareSync(password, user.password_hash);
    if (!match) {
      return c.json({ ok: false, error: 'invalid_credentials' }, 401);
    }

    const token = await sign({ uid: user.id, role: user.role, email: user.email, exp: Math.floor(Date.now() / 1000) + 60 * 60 * 24 * 7 }, secret, 'HS256');

    return c.json({
      ok: true,
      token,
      user: {
        id: user.id,
        email: user.email,
        role: user.role
      }
    }, 200);
  } catch(e: any) {
    return c.json({ ok: false, error: 'internal_error', message: e.message, trace: 'workerRouter.post' }, 500);
  }
})

workerRouter.use('/api/*', async (c: Context<{ Bindings: Bindings }>, next: Next) => {
  if (c.req.path.startsWith('/api/auth') || c.req.method === 'OPTIONS') {
    return next();
  }

  const secret = c.env.JWT_SECRET;
  if (!secret) return c.json({ ok: false, error: 'server_misconfigured', message: 'JWT_SECRET missing' }, 500);

  const jwtMiddleware = jwt({
    secret,
    alg: 'HS256'
  })

  try {
    return await jwtMiddleware(c, next)
  } catch(e) {
    return c.json({ ok: false, error: 'unauthorized' }, 401)
  }
})

workerRouter.use('/api/clients/*', cors({
  origin: '*',
  allowMethods: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
}))

workerRouter.get('/api/clients', async (c: Context<{ Bindings: Bindings }>) => {
  try {
    const { results } = await c.env.DB.prepare("SELECT id, data FROM generic_documents WHERE collection = 'clients'").all() as any;
    const { results: projectsResults } = await c.env.DB.prepare("SELECT data FROM generic_documents WHERE collection = 'projects'").all() as any;
    
    const projectCounts = new Map<string, number>();
    for (const row of projectsResults) {
      try {
        const p = JSON.parse(row.data);
        if (p.clientId) {
          projectCounts.set(String(p.clientId), (projectCounts.get(String(p.clientId)) || 0) + 1);
        }
      } catch (e) {}
    }

    const clients = results.map((row: { id: string; data: string }) => {
      try {
        const parsed = JSON.parse(row.data);
        return { id: row.id, ...parsed, projectCount: projectCounts.get(String(row.id)) || 0 };
      } catch (e) {
        return { id: row.id, projectCount: 0 };
      }
    });
    return c.json(clients, 200);
  } catch (err: any) {
    console.error("D1 Error:", err);
    return c.json({ error: err.message || 'Internal Server Error' }, 500);
  }
})

workerRouter.get('/api/projects', async (c: Context<{ Bindings: Bindings }>) => {
  try {
    const { results } = await c.env.DB.prepare('SELECT * FROM projects ORDER BY created_at DESC').all() as any;
    const mapped = results.map((r: any) => ({
      id: r.id,
      name: r.name,
      clientId: r.client_id,
      status: r.status,
      createdAt: r.created_at,
      created_at: r.created_at,
      tenantId: r.tenant_id
    }));
    return c.json(mapped, 200);
  } catch (err: any) {
    console.error("D1 Error:", err);
    return c.json({ error: err.message || 'Internal Server Error' }, 500);
  }
});

workerRouter.get('/api/suppliers', async (c: Context<{ Bindings: Bindings }>) => {
  try {
    const { results } = await c.env.DB.prepare('SELECT * FROM suppliers ORDER BY created_at DESC').all() as any;
    const mapped = results.map((r: any) => ({
      id: r.id,
      name: r.name,
      contactInfo: r.contact_info,
      createdAt: r.created_at,
      created_at: r.created_at
    }));
    return c.json(mapped, 200);
  } catch (err: any) {
    console.error("D1 Error:", err);
    return c.json({ error: err.message || 'Internal Server Error' }, 500);
  }
});

workerRouter.get('/api/tasks', async (c: Context<{ Bindings: Bindings }>) => {
  try {
    const { results } = await c.env.DB.prepare('SELECT * FROM tasks ORDER BY created_at DESC').all() as any;
    const mapped = results.map((r: any) => {
      let st = r.status;
      if (st === 'לביצוע') st = 'todo';
      if (st === 'בתהליך') st = 'in_progress';
      if (st === 'הושלם') st = 'done';
      return {
        id: r.id,
        title: r.title,
        description: r.description,
        status: st,
        projectId: r.project_id,
        assignee: r.assignee,
        assigneeId: r.assignee,
        dueDate: r.due_at || null,
        createdAt: r.created_at,
        created_at: r.created_at
      };
    });
    return c.json(mapped, 200);
  } catch (err: any) {
    console.error("D1 Error:", err);
    return c.json({ error: err.message || 'Internal Server Error' }, 500);
  }
});

workerRouter.get('/api/leads', async (c: Context<{ Bindings: Bindings }>) => {
  try {
    const { results } = await c.env.DB.prepare('SELECT * FROM leads ORDER BY created_at DESC').all() as any;
    const mapped = results.map((r: any) => ({
      id: r.id,
      name: r.name,
      email: r.email,
      phone: r.phone,
      status: r.status,
      createdAt: r.created_at,
      created_at: r.created_at,
      tenantId: r.tenant_id
    }));
    return c.json(mapped, 200);
  } catch (err: any) {
    console.error("D1 Error:", err);
    return c.json({ error: err.message || 'Internal Server Error' }, 500);
  }
});

workerRouter.get('/api/campaigns', async (c: Context<{ Bindings: Bindings }>) => {
  try {
    const { results } = await c.env.DB.prepare('SELECT * FROM campaigns ORDER BY created_at DESC').all() as any;
    const mapped = results.map((r: any) => ({
      id: r.id,
      name: r.name,
      status: r.status,
      budget: r.budget,
      spent: r.spent,
      createdAt: r.created_at,
      created_at: r.created_at,
      tenantId: r.tenant_id
    }));
    return c.json(mapped, 200);
  } catch (err: any) {
    console.error("D1 Error:", err);
    return c.json({ error: err.message || 'Internal Server Error' }, 500);
  }
});

workerRouter.all('/api/*', async (c: Context<{ Bindings: Bindings }>) => {
  if (!backendApp) {
    backendApp = await buildApp()
  }
  return backendApp.fetch(c.req.raw, c.env, c.executionCtx)
})

workerRouter.all('/health/*', async (c: Context<{ Bindings: Bindings }>) => {
  if (!backendApp) {
    backendApp = await buildApp()
  }
  return backendApp.fetch(c.req.raw, c.env, c.executionCtx)
})

workerRouter.all('*', async (c: Context<{ Bindings: Bindings }>) => {
  const request = c.req.raw
  const url = new URL(request.url)

  // Frontend UI & static assets proxied from Cloudflare Pages
  const pagesUrl = new URL(request.url)
  pagesUrl.hostname = 'os-hub.pages.dev'
  pagesUrl.protocol = 'https:'

  try {
    const pagesReq = new Request(pagesUrl.toString(), {
      method: request.method,
      headers: request.headers,
      body: ['GET', 'HEAD'].includes(request.method) ? undefined : await c.req.text(),
      redirect: 'follow',
    })

    const pagesRes = await fetch(pagesReq)
    if (pagesRes.status === 404 && !url.pathname.includes('.')) {
      // SPA Fallback for client-side routing
      const fallbackUrl = new URL('https://os-hub.pages.dev/')
      return fetch(new Request(fallbackUrl.toString(), {
        method: request.method,
        headers: request.headers,
      }))
    }

    return pagesRes
  } catch (err) {
    console.error('[Pages Proxy Error]', err)
    return new Response('Error loading frontend', { status: 502 })
  }
})

export default {
  async fetch(request: Request, env: Bindings, ctx: ExecutionContext): Promise<Response> {
    return workerRouter.fetch(request, env, ctx)
  },

  async scheduled(event: any, env: Bindings, _ctx: ExecutionContext) {
    if (env.DB) {
      setD1Database(env.DB)
    }
    console.log('[Worker Cron] Scheduled event trigger:', event?.cron)
    await logSystemEvent('CRON_TRIGGER', 'SCHEDULER', `Cloudflare cron executed: ${event?.cron}`)
  }
}
