// @ts-nocheck
import type { Context, Next } from 'hono'

export async function requireTenant(c: Context, next: Next) {
  let tenantId = c.req.header('x-tenant-id') || c.req.query('tenant_id')
  
  if (!tenantId) {
    const authUser = c.get('authUser')
    if (authUser && 'tenant_id' in authUser) {
      tenantId = (authUser as any).tenant_id
    }
  }

  // Default to HUB if not provided for now
  if (!tenantId) {
    tenantId = 'HUB'
  }

  c.set('tenantId', tenantId)
  await next()
}
