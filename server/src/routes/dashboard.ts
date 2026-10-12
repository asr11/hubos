// @ts-nocheck
import type { Hono } from 'hono'
import { requireAuth } from '../middleware/requireAuth.js'
import { requireTenant } from '../middleware/tenant.js'
import { db } from '../lib/db.js'

export function registerDashboardRoutes(app: Hono<any>): void {
  app.get('/api/dashboard', requireAuth, requireTenant, async (c: any) => {
    const tenantId = (c as any).get('tenantId') || 'HUB'
    
    // Money Collected
    const invoicesPaid = await db.prepare(
      "SELECT SUM(amount) as total FROM invoices WHERE tenant_id = ? AND status = 'paid'"
    ).bind(tenantId).first<{total: number | null}>()

    // Pending Money (invoices pending/unpaid)
    const invoicesPending = await db.prepare(
      "SELECT SUM(amount) as total FROM invoices WHERE tenant_id = ? AND status IN ('pending', 'unpaid')"
    ).bind(tenantId).first<{total: number | null}>()

    const openQuotes = await db.prepare(
      "SELECT SUM(total_amount) as total FROM quotes WHERE tenant_id = ? AND status IN ('draft', 'sent')"
    ).bind(tenantId).first<{total: number | null}>()

    const collected = (invoicesPaid?.total || 0)
    const pending = (invoicesPending?.total || 0) + (openQuotes?.total || 0)

    return c.json({
      ok: true,
      data: {
        moneyCollected: collected,
        moneyPending: pending,
        sovereigntyStatus: 'Active/Green'
      }
    })
  })
}
