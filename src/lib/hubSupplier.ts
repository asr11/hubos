import { hubFetch } from './hubFetch'

/** מזהה קבוע לספק ביצוע ברירת המחדל */
export const HUB_SUPPLIER_ID = 'supplier_hub_systems'

export const HUB_SUPPLIER_NAME = 'האב מערכות'

let ensureInFlight = false

/**
 * מבטיח שמסמך ברירת המחדל "האב מערכות" קיים — ללא מחיקת ספקים אחרים.
 */
export async function ensureHubSupplierExists(): Promise<void> {
  if (ensureInFlight) return
  ensureInFlight = true
  try {
    await hubFetch('/api/suppliers/' + HUB_SUPPLIER_ID, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        name: HUB_SUPPLIER_NAME,
        phone: '',
        email: 'admin@hub.co.il',
        createdAt: new Date().toISOString(),
      }),
    })
  } finally {
    ensureInFlight = false
  }
}
