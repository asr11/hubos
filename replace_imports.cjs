const fs = require('fs');
const path = require('path');

function processFile(filePath) {
  let content = fs.readFileSync(filePath, 'utf-8');
  const original = content;

  // determine relative path from this file to src/lib/firebase-stubs
  const dir = path.dirname(filePath);
  const stubsPath = path.resolve('/home/asr11/ASHER/os.hub.co.il/src/lib/firebase-stubs');
  let relPath = path.relative(dir, stubsPath);
  if (!relPath.startsWith('.')) relPath = './' + relPath;

  // Replace from 'firebase/something'
  content = content.replace(/from\s+['"]firebase\/[^'"]+['"]/g, `from '${relPath}'`);
  
  // Replace from '../lib/firebase'
  content = content.replace(/from\s+['"](?:\.\.\/)+lib\/firebase['"]/g, `from '${relPath}'`);
  // also './lib/firebase' or similar
  content = content.replace(/from\s+['"]\.\/firebase['"]/g, `from '${relPath}'`);

  if (content !== original) {
    fs.writeFileSync(filePath, content);
    console.log('Fixed', filePath);
  }
}

function walk(dir) {
  for (const f of fs.readdirSync(dir)) {
    const full = path.join(dir, f);
    if (fs.statSync(full).isDirectory()) walk(full);
    else if (full.endsWith('.ts') || full.endsWith('.tsx')) processFile(full);
  }
}

walk('/home/asr11/ASHER/os.hub.co.il/src');
