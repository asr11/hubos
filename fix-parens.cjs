const fs = require('fs');

function fixParen(file) {
  let text = fs.readFileSync(file, 'utf-8');
  text = text.replace(/=== undefined\s+return/g, '=== undefined) return');
  text = text.replace(/!== undefined\s+out/g, '!== undefined) out');
  text = text.replace(/=== undefined\s+continue/g, '=== undefined) continue');
  text = text.replace(/!== undefined\s+\{/g, '!== undefined) {');
  text = text.replace(/\|\s*undefined:\s*boolean\s*\{/g, '| undefined): boolean {');
  text = text.replace(/\|\s*undefined:\s*string\[\]\s*\{/g, '| undefined): string[] {');
  text = text.replace(/\|\s*undefined:\s*number\s*\|\s*null\s*\{/g, '| undefined): number | null {');
  text = text.replace(/\|\s*undefined:\s*string\s*\|\s*null\s*\{/g, '| undefined): string | null {');
  text = text.replace(/\|\s*undefined\s*=>\s*\{/g, '| undefined) => {');
  text = text.replace(/!== undefined\s+row/g, '!== undefined) row');
  text = text.replace(/null\s+as\s+any\.then/g, 'Promise.resolve(null as any).then');
  fs.writeFileSync(file, text);
}

const files = [
  'src/lib/stripUndefined.ts',
  'src/lib/supplierTypes.ts',
  'src/lib/salesAgent.ts',
  'src/pages/Calendar.tsx',
  'src/pages/EmployeePortal.tsx',
  'src/pages/Tasks.tsx',
  'src/pages/agent/AgentBusinessPage.tsx',
  'src/lib/morningSyncApply.ts',
  'src/pages/HourlyServiceShare.tsx'
];

files.forEach(f => {
  try {
    fixParen('/home/asr11/ASHER/os.hub.co.il/' + f);
  } catch(e) {}
});
