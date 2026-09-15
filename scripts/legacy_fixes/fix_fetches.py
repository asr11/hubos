import os
import re

hooks_dir = 'src/hooks'
files_to_check = [os.path.join(hooks_dir, f) for f in os.listdir(hooks_dir) if f.endswith('.tsx') or f.endswith('.ts')]

# Also add Login and other potential components? Wait, Login handles its own fetch to /api/auth/login, we might not want to touch it because it doesn't need a token, but sending a token is harmless.
# Actually let's just replace all `fetch(` with `hubFetch(` in hooks and contexts.

for fpath in files_to_check + ['src/contexts/AuthContext.tsx', 'src/store/useStore.ts']:
    if not os.path.exists(fpath): continue
    with open(fpath, 'r') as f:
        content = f.read()
    
    if "fetch(" in content:
        # Add import if not there
        if "hubFetch" not in content:
            # Figure out import path depth
            depth = fpath.count('/') - 1
            rel_path = '../' * depth + 'lib/hubFetch'
            content = f"import {{ hubFetch }} from '{rel_path}'\n" + content
        
        # Replace
        content = re.sub(r'\bfetch\(', 'hubFetch(', content)
        
        with open(fpath, 'w') as f:
            f.write(content)

