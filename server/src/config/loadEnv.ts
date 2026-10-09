import dotenv from 'dotenv'
import { existsSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

let serverRoot = '';
let repoRoot = '';

try {
  if (import.meta.url) {
    serverRoot = path.dirname(path.dirname(path.dirname(fileURLToPath(import.meta.url))))
    repoRoot = path.dirname(serverRoot)
    
    /** טעינת env לפיתוח: server/.env.local → server/.env → שורש הפרויקט */
    const candidates = [
      path.join(serverRoot, '.env.local'),
      path.join(serverRoot, '.env'),
      path.join(repoRoot, '.env.local'),
      path.join(repoRoot, '.env'),
    ]
    
    let loaded = false
    for (const envPath of candidates) {
      if (existsSync(envPath)) {
        dotenv.config({ path: envPath, override: loaded })
        loaded = true
      }
    }
  }
} catch (err) {
  // Ignore in Cloudflare Worker environment
}

export { serverRoot, repoRoot }
