import re

with open('server/src/routes/crud.ts', 'r') as f:
    crud = f.read()

if 'requireAuth' not in crud:
    crud = "import { requireAuth } from '../middleware/requireAuth.js'\n" + crud
    crud = crud.replace("app.get('/api/:collection', async", "app.get('/api/:collection', { preHandler: requireAuth }, async")
    crud = crud.replace("app.get('/api/:collection/:id', async", "app.get('/api/:collection/:id', { preHandler: requireAuth }, async")
    crud = crud.replace("app.post('/api/:collection', async", "app.post('/api/:collection', { preHandler: requireAuth }, async")
    crud = crud.replace("app.put('/api/:collection/:id', async", "app.put('/api/:collection/:id', { preHandler: requireAuth }, async")
    crud = crud.replace("app.patch('/api/:collection/:id', async", "app.patch('/api/:collection/:id', { preHandler: requireAuth }, async")
    crud = crud.replace("app.delete('/api/:collection/:id', async", "app.delete('/api/:collection/:id', { preHandler: requireAuth }, async")

with open('server/src/routes/crud.ts', 'w') as f:
    f.write(crud)

with open('server/src/routes/api.ts', 'r') as f:
    api_ts = f.read()

# Remove the entities loop
api_ts = re.sub(r"const entities = \['projects'.*?\}\)\n  \}\n", "", api_ts, flags=re.DOTALL)

with open('server/src/routes/api.ts', 'w') as f:
    f.write(api_ts)

