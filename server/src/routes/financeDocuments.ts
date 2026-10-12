// @ts-nocheck
import { readFile, mkdir, stat, writeFile } from 'node:fs/promises'
import path from 'node:path'
import type { Context, Hono } from 'hono'

import { env } from '../config/env.js'
import { requireAdmin } from '../middleware/requireAdmin.js'
import {
  ACCEPTED_EXTENSIONS,
  MAX_DOCUMENT_BYTES,
  UnsafePathError,
  buildStoredName,
  contentTypeFor,
  documentUrlFor,
  isAcceptedDocument,
  isSafeSegment,
  resolveDocumentPath,
} from '../lib/financeDocumentStore.js'

/**
 * חשבוניות הספק נשמרות על השרת ולא בשירות אחסון חיצוני.
 *
 * ההעלאה דורשת הרשאת מנהל. ההורדה פתוחה, אבל שם הקובץ אקראי ולכן הקישור
 * אינו ניתן לניחוש — כמו קישורי ההורדה של Firebase Storage שהוחלפו כאן.
 *
 * NOTE (Cloudflare Worker): there is no persistent local filesystem in the Worker runtime.
 * Storage failures are reported as 501 `storage_unavailable` JSON instead of crashing.
 */

function storageUnavailable(c: Context, e: unknown) {
  return c.json({
    ok: false,
    error: 'storage_unavailable',
    message: 'אחסון מסמכים מקומי אינו זמין בסביבת השרת הנוכחית (Cloudflare Worker ללא מערכת קבצים)',
    detail: e instanceof Error ? e.message : String(e),
  }, 501)
}

/** מיוצא בנפרד מהרישום כדי שאפשר יהיה לבדוק את ההעלאה בלי שכבת ההרשאות */
export async function handleDocumentUpload(c: Context) {
  const expenseId = c.req.param('expenseId') ?? ''
  if (!isSafeSegment(expenseId)) {
    return c.json({ ok: false, error: 'bad_expense_id', message: 'מזהה הוצאה לא חוקי' }, 400)
  }

  let file: unknown
  try {
    const body = await c.req.parseBody()
    file = body.file ?? Object.values(body).find((v) => v instanceof File)
  } catch {
    file = undefined
  }
  if (!(file instanceof File)) {
    return c.json({ ok: false, error: 'no_file', message: 'לא צורף קובץ' }, 400)
  }

  if (!isAcceptedDocument(file.name)) {
    return c.json({
      ok: false,
      error: 'unsupported_type',
      message: `סוג הקובץ אינו נתמך. אפשר להעלות ${ACCEPTED_EXTENSIONS.join(' · ')}`,
    }, 415)
  }

  if (file.size > MAX_DOCUMENT_BYTES) {
    return c.json({
      ok: false,
      error: 'too_large',
      message: `הקובץ גדול מ-${Math.round(MAX_DOCUMENT_BYTES / 1024 / 1024)}MB`,
    }, 413)
  }

  const buffer = new Uint8Array(await file.arrayBuffer())
  const storedName = buildStoredName(file.name)
  try {
    const target = resolveDocumentPath(env.financeDocsDir, expenseId, storedName)
    await mkdir(path.dirname(target), { recursive: true })
    await writeFile(target, buffer)
  } catch (e) {
    if (e instanceof UnsafePathError) {
      return c.json({ ok: false, error: 'bad_path', message: e.message }, 400)
    }
    return storageUnavailable(c, e)
  }

  return c.json({
    ok: true,
    url: documentUrlFor(expenseId, storedName),
    fileName: file.name,
    size: buffer.byteLength,
  })
}

export async function registerFinanceDocumentRoutes(app: Hono<any>) {
  app.post('/api/finance-documents/:expenseId', requireAdmin, handleDocumentUpload)

  app.get('/api/finance-documents/:expenseId/:fileName', async (c) => {
    const { expenseId, fileName } = c.req.param()

    let target: string
    try {
      target = resolveDocumentPath(env.financeDocsDir, expenseId, fileName)
    } catch (e) {
      if (e instanceof UnsafePathError) {
        return c.json({ ok: false, error: 'bad_path', message: e.message }, 400)
      }
      throw e
    }

    try {
      const info = await stat(target)
      if (!info.isFile()) throw new Error('not a file')
      const data = await readFile(target)
      return new Response(data, {
        headers: {
          'Content-Type': contentTypeFor(fileName),
          'Content-Length': String(info.size),
          // הקובץ אינו משתנה אחרי שנשמר, ולכן אפשר לשמור אותו במטמון
          'Cache-Control': 'private, max-age=31536000, immutable',
        },
      })
    } catch {
      return c.json({ ok: false, error: 'not_found', message: 'המסמך לא נמצא' }, 404)
    }
  })
}
