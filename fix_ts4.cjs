const fs = require('fs');

function replaceInFile(filePath, replacements) {
  let content = fs.readFileSync(filePath, 'utf-8');
  for (const [search, replace] of replacements) {
    content = content.replace(search, replace);
  }
  fs.writeFileSync(filePath, content);
}

replaceInFile('src/lib/firestoreCascades.ts', [
  [/export async function deleteActivitySubcollection\(_db: any, _path: string\) \{\}/g, 'export async function deleteActivitySubcollection(..._args: any[]) {}'],
  [/export async function deleteTaskNotesSubcollection\(_db: any, _path: string\) \{\}/g, 'export async function deleteTaskNotesSubcollection(..._args: any[]) {}']
]);

replaceInFile('src/lib/storageUpload.ts', [
  [/export async function uploadToStorage\(_path: string, _file: File\): Promise<string> \{ return "" \}/g, 'export async function uploadToStorage(..._args: any[]): Promise<string> { return "" }']
]);
