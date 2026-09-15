import os
import re

def process_dir(d):
    for root, dirs, files in os.walk(d):
        for f in files:
            if f.endswith('.tsx') or f.endswith('.ts'):
                fpath = os.path.join(root, f)
                if 'hubFetch.ts' in fpath: continue
                with open(fpath, 'r') as file:
                    content = file.read()
                if re.search(r'\bfetch\(', content):
                    depth = fpath.count('/') - 2 # src/ is depth 0. e.g. src/hooks/a.ts (depth 1) -> ../lib/hubFetch
                    rel = '../' * depth + 'lib/hubFetch' if depth > 0 else './lib/hubFetch'
                    if 'src/lib/' in fpath:
                        rel = './hubFetch'
                    
                    if "hubFetch" not in content:
                        content = f"import {{ hubFetch }} from '{rel}'\n" + content
                    
                    content = re.sub(r'\bfetch\(', 'hubFetch(', content)
                    with open(fpath, 'w') as file:
                        file.write(content)

process_dir('src')
