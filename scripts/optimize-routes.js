const fs = require('fs');
const path = require('path');

const appPath = path.join(__dirname, '../src/App.tsx');
let content = fs.readFileSync(appPath, 'utf8');

// Add Suspense to react import
if (!content.includes('Suspense')) {
  content = "import { lazy, Suspense } from 'react'\n" + content;
}

// Regex to find all page imports: import { Something } from './pages/Something'
const importRegex = /import\s*{\s*([A-Za-z0-9_]+)\s*}\s*from\s*'(\.\/pages\/[^']+)'/g;

content = content.replace(importRegex, (match, componentName, importPath) => {
  return `const ${componentName} = lazy(() => import('${importPath}').then(m => ({ default: m.${componentName} })))`;
});

// Wrap <Routes> with <Suspense>
if (!content.includes('<Suspense')) {
  content = content.replace('<Routes>', '<Suspense fallback={<div className="flex h-screen items-center justify-center">טוען...</div>}>\n        <Routes>');
  content = content.replace('</Routes>', '</Routes>\n        </Suspense>');
}

fs.writeFileSync(appPath, content);
console.log('App.tsx optimized with lazy loading!');
