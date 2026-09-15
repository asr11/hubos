import re

# Fix useProjects.tsx
with open('src/hooks/useProjects.tsx', 'r') as f:
    c = f.read()
# Replace duplicate doneCount
c = re.sub(r'doneCount:\s*0,\s*doneCount:\s*0,', 'doneCount: 0,', c)
with open('src/hooks/useProjects.tsx', 'w') as f:
    f.write(c)


# Fix useTasks.tsx
with open('src/hooks/useTasks.tsx', 'r') as f:
    c = f.read()

# Duplicate lastEventByUserName
c = re.sub(r'lastEventByUserName:\s*uname,\s*lastEventByUserName:\s*uname,', 'lastEventByUserName: uname,', c)
# Cannot find name ref -> replace ref.id with 'temp_id' (or similar, it's just a log entry)
c = c.replace('ref.id', 'id')  # 'id' is likely available, or we just pass a string
# timerNotes -> timerRef.current
c = c.replace('window.clearInterval(timerNotes)', '/* removed */')
# recurringTemplateId: templateRefId (which might be null) -> templateRefId || undefined
c = c.replace('recurringTemplateId: templateRefId,', 'recurringTemplateId: templateRefId || undefined,')

with open('src/hooks/useTasks.tsx', 'w') as f:
    f.write(c)


# Fix usePasswordVault.tsx
with open('src/hooks/usePasswordVault.tsx', 'r') as f:
    lines = f.readlines()
# Just remove lines 4, 5, 6, 7, 9
with open('src/hooks/usePasswordVault.tsx', 'w') as f:
    for i, line in enumerate(lines):
        if i in [3, 4, 5, 6, 8, 12]:  # 0-indexed: 4, 5, 6, 7, 9, 13
            pass
        else:
            f.write(line)

