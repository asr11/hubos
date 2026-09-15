import re

# Fix useFinanceCashflow.tsx
with open('src/hooks/useFinanceCashflow.tsx', 'r') as f:
    c = f.read()
# Remove unused readVatEstimateAmount
c = re.sub(r'function readVatEstimateAmount\(raw: unknown\): number \{.*?\n\}', '', c, flags=re.DOTALL)
# Fix updatedAt
c = c.replace('setState(prev => ({ ...prev, balance: Math.round(balance * 100) / 100, asOfDate: asOfDate.slice(0, 10) }))', 'setState(prev => prev ? { ...prev, balance: Math.round(balance * 100) / 100, asOfDate: asOfDate.slice(0, 10) } : null)')
with open('src/hooks/useFinanceCashflow.tsx', 'w') as f:
    f.write(c)


# Fix useFinanceImportRules.tsx
with open('src/hooks/useFinanceImportRules.tsx', 'r') as f:
    c = f.read()
c = c.replace("const RULES_COLLECTION = 'financeImportRules'", "")
with open('src/hooks/useFinanceImportRules.tsx', 'w') as f:
    f.write(c)


# Fix useFinanceIncomes.tsx
with open('src/hooks/useFinanceIncomes.tsx', 'r') as f:
    c = f.read()
c = c.replace("const res_upd = await fetch", "await fetch")
with open('src/hooks/useFinanceIncomes.tsx', 'w') as f:
    f.write(c)


# Fix useHosting.tsx
with open('src/hooks/useHosting.tsx', 'r') as f:
    lines = f.readlines()
# Remove lines 10 to 20, 22, 23 (firebase imports)
with open('src/hooks/useHosting.tsx', 'w') as f:
    for i, line in enumerate(lines):
        if 9 <= i <= 19 or i == 21 or i == 22:
            pass
        else:
            f.write(line)

            
# Fix useHourlyServices.tsx
with open('src/hooks/useHourlyServices.tsx', 'r') as f:
    c = f.read()
c = c.replace("let unsubServices = () => {}", "")
c = c.replace("let unsubEntries = () => {}", "")
with open('src/hooks/useHourlyServices.tsx', 'w') as f:
    f.write(c)


# Fix useTasks.tsx
with open('src/hooks/useTasks.tsx', 'r') as f:
    c = f.read()
c = c.replace("logActivity(id, uid, uname, 'created')", "logActivity('temp_id', uid, uname, 'created')")
c = c.replace("const profileReady =", "")
c = c.replace("const taskIds = new Set(taskList.map((t: Task) => t.id))", "")
with open('src/hooks/useTasks.tsx', 'w') as f:
    f.write(c)

    
# Fix Login.tsx
with open('src/pages/Login.tsx', 'r') as f:
    c = f.read()
c = c.replace("const LOGIN_LOGO_SRC = `${import.meta.env.BASE_URL}logo-hub-pos.png?v=3`", "")
with open('src/pages/Login.tsx', 'w') as f:
    f.write(c)

