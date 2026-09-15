import re

with open('src/hooks/useFinanceIncomes.tsx', 'r') as f:
    c = f.read()
    
c = c.replace("if (!res_upd.ok) throw new Error('API Error')", "")

with open('src/hooks/useFinanceIncomes.tsx', 'w') as f:
    f.write(c)

