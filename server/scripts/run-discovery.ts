import './src/config/loadEnv.js'
import { loadMorningCredentials } from './src/lib/morningCredentials.js'
import { MorningClient } from './src/lib/morningClient.js'
import fs from 'fs/promises'
import path from 'path'

async function run() {
  const creds = await loadMorningCredentials()
  if (!creds) throw new Error('Morning credentials missing')
  const client = new MorningClient(creds)
  
  const outDir = '/home/asr11/02_HUB_Dev/hub-os-1.0/discovery/morning'
  await fs.mkdir(outDir, { recursive: true })
  await fs.mkdir(path.join(outDir, 'raw'), { recursive: true })
  await fs.mkdir(path.join(outDir, 'schemas'), { recursive: true })
  
  console.log('Testing connection...')
  const conn = await client.verifyConnection()
  console.log('Connection:', conn)
  
  await fs.writeFile(path.join(outDir, 'raw', 'business.jsonl'), JSON.stringify(conn.business) + '\n')
  
  console.log('Done test.')
}
run().catch(console.error)
