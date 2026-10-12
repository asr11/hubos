const fs = require('fs');
const path = require('path');

function replaceInFile(filePath, replacements) {
  let content = fs.readFileSync(filePath, 'utf-8');
  let original = content;
  for (const [search, replace] of replacements) {
    content = content.replace(search, replace);
  }
  if (content !== original) {
    fs.writeFileSync(filePath, content);
    console.log(`Updated ${filePath}`);
  }
}

const dir = '/home/asr11/ASHER/os.hub.co.il/src';

function walk(currentDir) {
  const files = fs.readdirSync(currentDir);
  for (const file of files) {
    const fullPath = path.join(currentDir, file);
    if (fs.statSync(fullPath).isDirectory()) {
      walk(fullPath);
    } else if (fullPath.endsWith('.ts') || fullPath.endsWith('.tsx')) {
      replaceInFile(fullPath, [
        [/err\s+false/g, 'false'],
        [/value\s+false/g, 'false'],
        [/ldr\s+false/g, 'false'],
        [/raw\s+false/g, 'false'],
        [/(?:undefined|null as any)\s*,\s*patch\s*,\s*\{\s*merge:\s*true\s*\}\s*\)/g, ''],
        [/(?:undefined|null as any)\s*,\s*docData\s*\)/g, ''],
        [/await\s+Promise\.resolve\(\{ docs: \[\] \} as any\)\)\)/g, 'await Promise.resolve({ docs: [] } as any)'],
        [/undefined\)/g, 'undefined'],
        [/null\s+as\s+any\)/g, 'null as any'],
        [/const ref = null as any\)/g, 'const ref = null as any;'],
        [/await Promise.resolve\(\{ docs: \[\] \} as any\)\)\)/g, 'await Promise.resolve({ docs: [] } as any)'],
        [/const anchor = null as any\)/g, 'const anchor = null as any;'],
      ]);
    }
  }
}

walk(dir);
