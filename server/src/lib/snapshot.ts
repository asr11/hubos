// @ts-nocheck
import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'

let __dirname = ''
try {
  if (typeof import.meta !== 'undefined' && import.meta?.url) {
    __dirname = path.dirname(fileURLToPath(import.meta.url))
  }
} catch {
  // Workers environment
}

const DB_PATH = __dirname ? path.resolve(__dirname, '../../data/os_hub.db') : ''
const SNAPSHOTS_DIR = __dirname ? path.resolve(__dirname, '../../data/snapshots') : ''
const MAX_SNAPSHOTS = 20

/**
 * Creates a pre-change snapshot of the DB if local filesystem is available.
 * Call this BEFORE any write operation.
 */
export function preChangeSnapshot(reason: string): string {
  try {
    if (!SNAPSHOTS_DIR || !fs.existsSync || !fs.copyFileSync) {
      return ''
    }

    if (!fs.existsSync(SNAPSHOTS_DIR)) {
      fs.mkdirSync(SNAPSHOTS_DIR, { recursive: true })
    }

    if (!fs.existsSync(DB_PATH)) {
      return ''
    }

    const ts = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19)
    const safeName = reason.replace(/[^a-zA-Z0-9_-]/g, '_').slice(0, 30)
    const snapName = `${ts}_${safeName}.db`
    const snapPath = path.join(SNAPSHOTS_DIR, snapName)

    fs.copyFileSync(DB_PATH, snapPath)

    for (const ext of ['-wal', '-shm']) {
      const src = DB_PATH + ext
      if (fs.existsSync(src)) {
        fs.copyFileSync(src, snapPath + ext)
      }
    }

    const snaps = fs.readdirSync(SNAPSHOTS_DIR)
      .filter(f => f.endsWith('.db'))
      .sort()
      .reverse()

    for (const old of snaps.slice(MAX_SNAPSHOTS)) {
      for (const ext of ['', '-wal', '-shm']) {
        const p = path.join(SNAPSHOTS_DIR, old + ext)
        if (fs.existsSync(p)) fs.unlinkSync(p)
      }
    }

    return snapPath
  } catch {
    return ''
  }
}
