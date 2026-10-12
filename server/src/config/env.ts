// @ts-nocheck
import './loadEnv.js'

function readString(key: string, fallback = ''): string {
  const v = process.env[key]
  return typeof v === 'string' ? v.trim() : fallback
}

function readPort(key: string, fallback: number): number {
  const raw = readString(key)
  if (!raw) return fallback
  const n = Number.parseInt(raw, 10)
  return Number.isFinite(n) && n > 0 && n < 65536 ? n : fallback
}

function readOrigins(raw: string): string[] {
  if (!raw) return ['http://localhost:5173', 'http://127.0.0.1:5173']
  return raw
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean)
}

export const env = {
  nodeEnv: readString('NODE_ENV', 'development'),
  isDev: readString('NODE_ENV', 'development') !== 'production',
  host: readString('SERVER_HOST', '127.0.0.1'),
  port: readPort('SERVER_PORT', 8788),
  corsOrigins: readOrigins(readString('CORS_ORIGINS')),

  morning: {
    env: readString('MORNING_ENV', 'sandbox') as 'sandbox' | 'production',
    keyId: readString('MORNING_KEY_ID'),
    keySecret: readString('MORNING_KEY_SECRET'),
  },
  sync: {
    fromDate: readString('SYNC_FROM_DATE', '2026-03-01'),
    overlapDays: Math.max(1, readPort('SYNC_OVERLAP_DAYS', 7)),
  },
  /** מיילים שמורשים כמנהל כשקריאת Firestore users נכשלת (מופרד בפסיקים) */
  adminEmails: readString('ADMIN_EMAILS')
    .split(',')
    .map((s) => s.trim().toLowerCase())
    .filter(Boolean),
  /** מפתח API ל-/api/bot-data (בוטים/סוכנים חיצוניים - למשל ceo-digital-ai) */
  botApiKey: readString('BOT_API_KEY'),
  /**
   * תיקיית חשבוניות הספק. בפרודקשן זהו נפח קבוע של Docker — הקבצים חייבים
   * לשרוד בנייה מחדש של הקונטיינר.
   */
  financeDocsDir: readString('FINANCE_DOCS_DIR', './data/finance-documents'),
  /**
   * מפתחות + מצב סנכרון של חשבונית ירוקה על דיסק השרת.
   * כך סטטוס/חיבור לא תלויים במכסת Firestore Admin.
   */
  morningDataDir: readString('MORNING_DATA_DIR', './data/morning'),
} as const

/**
 * Resource API host — מסמכים / לקוחות / הוצאות.
 * החל מיוני 2026: רק api.greeninvoice.co.il (לא www.greeninvoice.co.il).
 * @see https://www.greeninvoice.co.il/help-center/api-updates-26/
 */
export const MORNING_BASE_URLS = {
  sandbox: 'https://sandbox.d.greeninvoice.co.il/api/v1',
  production: 'https://api.greeninvoice.co.il/api/v1',
} as const

/**
 * OAuth 2.0 IdP host — קבלת access token (נפרד מ-Resource API).
 * לפי דוקומנטציית Morning / מפתחים מעודכנת (API 2.0).
 */
export const MORNING_AUTH_URLS = {
  sandbox: 'https://api.sandbox.morning.dev/idp/v1/oauth/token',
  production: 'https://api.morning.co/idp/v1/oauth/token',
} as const

/** האם הוגדרו מפתחות Morning במשתני סביבה (fallback ל-Firestore) */
export function morningEnvConfigured(): boolean {
  return Boolean(env.morning.keyId && env.morning.keySecret)
}

/** @deprecated שם ישן — נשמר לתאימות עם health route */
export function morningConfigured(): boolean {
  return morningEnvConfigured()
}

export function injectCloudflareEnv(cfEnv: any) {
  const mutEnv = env as any;
  if (cfEnv.MORNING_ENV) mutEnv.morning.env = cfEnv.MORNING_ENV;
  if (cfEnv.MORNING_KEY_ID) mutEnv.morning.keyId = cfEnv.MORNING_KEY_ID;
  if (cfEnv.MORNING_KEY_SECRET) mutEnv.morning.keySecret = cfEnv.MORNING_KEY_SECRET;
  if (cfEnv.BOT_API_KEY) mutEnv.botApiKey = cfEnv.BOT_API_KEY;

  if (cfEnv.ADMIN_EMAILS) mutEnv.adminEmails = cfEnv.ADMIN_EMAILS.split(',').map((s: string) => s.trim().toLowerCase());
  if (cfEnv.CORS_ORIGINS) mutEnv.corsOrigins = cfEnv.CORS_ORIGINS.split(',').map((s: string) => s.trim());
}
