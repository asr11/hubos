// @ts-nocheck
import { scanNeglectedLeads, scanOverdueTasks, scanOutstandingBalances } from './scanners.js';
import { logSystemEvent } from '../lib/db.js';

let autonomyTimer: NodeJS.Timeout | null = null;

export function runScanners() {
  console.log('[AUTONOMY] Running anomaly scanners...');
  scanNeglectedLeads();
  scanOverdueTasks();
  // scanOutstandingBalances(); // Disabled by user
}

export function startAutonomyEngine(intervalMs: number = 5 * 60 * 1000) {
  if (autonomyTimer) {
    clearInterval(autonomyTimer);
  }
  
  console.log(`[AUTONOMY] Engine started. Scanning every ${intervalMs / 1000} seconds.`);
  logSystemEvent('AUTONOMY_START', 'SYSTEM', `Started autonomy engine with ${intervalMs}ms interval.`);

  // Run immediately on boot
  runScanners();
  
  // Then schedule
  autonomyTimer = setInterval(() => {
    runScanners();
  }, intervalMs);
}

export function stopAutonomyEngine() {
  if (autonomyTimer) {
    clearInterval(autonomyTimer);
    autonomyTimer = null;
    console.log('[AUTONOMY] Engine stopped.');
  }
}
