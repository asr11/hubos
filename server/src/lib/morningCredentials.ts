// @ts-nocheck
import { env } from '../config/env.js'
import { db } from './db.js'
import {
  readLocalCredentials,
  readLocalSyncState,
  writeLocalCredentials,
  writeLocalSyncState,
} from './morningLocalStore.js'

export type MorningEnv = 'sandbox' | 'production'

export type MorningCredentials = {
  keyId: string
  keySecret: string
  env: MorningEnv
}

/** מסמך ישן ב-Firestore — רק כגיבוי לקריאה חד־פעמית / מיגרציה */
const SETTINGS_COLLECTION = 'integrations'
const SETTINGS_DOC = 'morning'

function normalizeEnv(raw: unknown): MorningEnv {
  return raw === 'sandbox' ? 'sandbox' : 'production'
}

/**
 * מקור המפתחות (בסדר עדיפות):
 * 1. משתני סביבה (MORNING_KEY_ID/SECRET)
 * 2. קובץ מקומי על השרת (נפח Docker) — בלי Firestore
 * 3. Firestore (גיבוי שקט אם המכסה מאפשרת; לא מפיל את הנתיב)
 */
export async function loadMorningCredentials(): Promise<MorningCredentials | null> {
  if (env.morning.keyId && env.morning.keySecret) {
    return {
      keyId: env.morning.keyId,
      keySecret: env.morning.keySecret,
      env: normalizeEnv(env.morning.env),
    }
  }

  const local = await readLocalCredentials()
  if (local) return local

  const fromFs = await readCredentialsFromFirestore()
  if (fromFs) {
    // מיגרציה שקטה לקובץ המקומי — הפעם הבאה בלי Firestore
    try {
      await writeLocalCredentials(fromFs)
    } catch {
      /* ignore */
    }
    return fromFs
  }

  return null
}

async function readCredentialsFromFirestore(): Promise<MorningCredentials | null> {
  try {
    const stmt = db.prepare('SELECT data FROM generic_documents WHERE collection = ? AND id = ?')
    const row = stmt.get(SETTINGS_COLLECTION, SETTINGS_DOC) as { data: string } | undefined
    if (!row) return null
    const data = JSON.parse(row.data)
    const keyId = String(data.keyId ?? '').trim()
    const keySecret = String(data.keySecret ?? '').trim()
    if (!keyId || !keySecret) return null
    return { keyId, keySecret, env: normalizeEnv(data.env) }
  } catch {
    return null
  }
}

/** שמירה לקובץ השרת — מקור האמת מעתה. Firestore הוא best-effort בלבד. */
export async function saveMorningCredentials(
  creds: MorningCredentials,
): Promise<void> {
  await writeLocalCredentials(creds)

  try {
    const getStmt = db.prepare('SELECT data FROM generic_documents WHERE collection = ? AND id = ?')
    const row = getStmt.get(SETTINGS_COLLECTION, SETTINGS_DOC) as { data: string } | undefined
    const existing = row ? JSON.parse(row.data) : {}
    
    const patch = {
      ...existing,
      keyId: creds.keyId,
      keySecret: creds.keySecret,
      env: creds.env,
      updatedAt: new Date().toISOString(),
    }
    const stmt = db.prepare(`
      INSERT INTO generic_documents (id, collection, data) 
      VALUES (?, ?, ?)
      ON CONFLICT(id) DO UPDATE SET data = excluded.data
    `)
    stmt.run(SETTINGS_DOC, SETTINGS_COLLECTION, JSON.stringify(patch))
  } catch {
    /* מכסת Firestore לא חוסמת חיבור חדש */
  }
}

/** האם המפתחות הגיעו ממשתני סביבה (לא ניתנים לעריכה מהאפליקציה) */
export function credentialsFromEnv(): boolean {
  return Boolean(env.morning.keyId && env.morning.keySecret)
}

/** קריאת מצב הסנכרון האחרון — קודם דיסק השרת */
export async function readSyncState(): Promise<Record<string, unknown> | null> {
  const local = await readLocalSyncState()
  if (local) return local

  try {
    const stmt = db.prepare('SELECT data FROM generic_documents WHERE collection = ? AND id = ?')
    const row = stmt.get(SETTINGS_COLLECTION, SETTINGS_DOC) as { data: string } | undefined
    if (!row) return null
    const data = JSON.parse(row.data)
    const state = (data.syncState as Record<string, unknown>) ?? null
    if (state) {
      try {
        await writeLocalSyncState(state)
      } catch {
        /* ignore */
      }
    }
    return state
  } catch {
    return null
  }
}

/** עדכון מצב הסנכרון האחרון — דיסק השרת הוא העוגן */
export async function writeSyncState(
  state: Record<string, unknown>,
): Promise<void> {
  await writeLocalSyncState(state)

  try {
    const getStmt = db.prepare('SELECT data FROM generic_documents WHERE collection = ? AND id = ?')
    const row = getStmt.get(SETTINGS_COLLECTION, SETTINGS_DOC) as { data: string } | undefined
    const existing = row ? JSON.parse(row.data) : {}
    
    const patch = {
      ...existing,
      syncState: state
    }
    
    const stmt = db.prepare(`
      INSERT INTO generic_documents (id, collection, data) 
      VALUES (?, ?, ?)
      ON CONFLICT(id) DO UPDATE SET data = excluded.data
    `)
    stmt.run(SETTINGS_DOC, SETTINGS_COLLECTION, JSON.stringify(patch))
  } catch {
    /* ignore */
  }
}
