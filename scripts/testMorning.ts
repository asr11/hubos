import '../server/src/config/loadEnv.js'
import { runMorningSync } from '../server/src/lib/morningSync.js'

async function main() {
  console.log('Starting full Morning sync from 2026-02-01...');
  const res = await runMorningSync({ fromDate: '2026-02-01' });
  console.log('Sync complete!');
  console.log(res);
}

main().catch(console.error);
