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
      // General fixes
      replaceInFile(fullPath, [
        [/import\s+\{?[^}]*\}?\s+from\s+['"]firebase\/firestore['"]/g, ''],
        [/import\s+\{?[^}]*\}?\s+from\s+['"]firebase\/functions['"]/g, ''],
        [/import\s+\{?[^}]*\}?\s+from\s+['"]firebase\/storage['"]/g, ''],
        [/import\s+\{?[^}]*\}?\s+from\s+['"]firebase\/app['"]/g, ''],
        [/import\s+.*from\s+['"]\.\.?\/lib\/firebase['"]/g, ''],
        [/import\s+.*from\s+['"]\.\.?\/\.\.?\/lib\/firebase['"]/g, ''],
        [/deleteField\(\)/g, 'null'],
        [/serverTimestamp\(\)/g, 'new Date().toISOString()'],
      ]);
    }
  }
}

walk(dir);
