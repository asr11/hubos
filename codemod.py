import os
import glob
import re

ROUTES_DIR = 'server/src/routes'
MIDDLEWARE_DIR = 'server/src/middleware'

def process_routes():
    files = glob.glob(os.path.join(ROUTES_DIR, '*.ts'))
    for filepath in files:
        with open(filepath, 'r', encoding='utf-8') as f:
            content = f.read()
        
        # Imports
        content = re.sub(r"import type { FastifyInstance } from 'fastify'", "import { Hono } from 'hono'", content)
        content = re.sub(r"import type { FastifyReply, FastifyRequest } from 'fastify'", "import { Context } from 'hono'", content)
        
        # export async function registerX(app: FastifyInstance)
        content = re.sub(r"export async function register(\w+)Routes\(app: FastifyInstance\)(?: *: *Promise<void>)? *{", r"export function register\1Routes(app: Hono<any>) {", content)
        content = re.sub(r"export function register(\w+)Routes\(app: FastifyInstance\)(?: *: *void)? *{", r"export function register\1Routes(app: Hono<any>) {", content)
        
        # Route signatures with requireAuth
        content = re.sub(
            r"app\.(get|post|put|patch|delete)\('([^']+)',\s*\{\s*preHandler:\s*([^\}]+)\s*\},\s*async\s*\(([^)]*)\)\s*=>\s*\{",
            r"app.\1('\2', \3, async (c) => {",
            content
        )
        
        # Route signatures without requireAuth
        content = re.sub(
            r"app\.(get|post|put|patch|delete)\('([^']+)',\s*async\s*\(([^)]*)\)\s*=>\s*\{",
            r"app.\1('\2', async (c) => {",
            content
        )
        
        # req.authUser -> c.get('authUser')
        content = re.sub(r"req\.authUser!?\.uid", "c.get('authUser').uid", content)
        content = re.sub(r"req\.authUser!?\.email", "c.get('authUser').email", content)
        content = re.sub(r"req\.authUser!?\.role", "c.get('authUser').role", content)
        content = re.sub(r"req\.authUser!?", "c.get('authUser')", content)
        
        # req.body -> await c.req.json()
        content = re.sub(r"req\.body\s+as\s+([a-zA-Z0-9_<>]+|any)", r"await c.req.json<any>() /* \1 */", content)
        content = re.sub(r"req\.body", r"await c.req.json()", content)
        
        # req.params -> c.req.param()
        content = re.sub(r"req\.params\s+as\s+any", r"c.req.param()", content)
        content = re.sub(r"req\.params\s+as\s+\{[^}]+\}", r"c.req.param()", content)
        content = re.sub(r"req\.params", r"c.req.param()", content)
        
        # req.query -> c.req.query()
        content = re.sub(r"req\.query\s+as\s+\{[^}]+\}", r"c.req.query()", content)
        content = re.sub(r"req\.query\s+as\s+any", r"c.req.query()", content)
        content = re.sub(r"req\.query", r"c.req.query()", content)
        
        # reply.code(x).send(y) -> return c.json(y, x)
        content = re.sub(r"reply\.code\((\d+)\)\.send\(([^)]+)\)", r"return c.json(\2, \1)", content)
        content = re.sub(r"reply\.send\(([^)]+)\)", r"return c.json(\1)", content)
        
        # Returns
        content = re.sub(r"^(\s*)return\s+(\{.*\}|\[.*\])\s*$", r"\1return c.json(\2)", content, flags=re.MULTILINE)
        content = re.sub(r"^(\s*)return\s+([a-zA-Z0-9_]+)\s*$", r"\1return c.json(\2)", content, flags=re.MULTILINE)
        
        content = re.sub(r"return\s+Response\.json\(([^,]+),\s*\{\s*headers:[^,]+,\s*status:\s*(\d+)\s*\}\)", r"return c.json(\1, \2)", content)
        content = re.sub(r"Response\.json", r"c.json", content)
        
        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(content)
        print(f"Processed {filepath}")

def process_middleware():
    filepath = os.path.join(MIDDLEWARE_DIR, 'requireAuth.ts')
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()
    
    content = content.replace("import type { FastifyReply, FastifyRequest } from 'fastify'", "import type { Context, Next } from 'hono'\nimport { env } from 'hono/adapter'")
    content = content.replace("export async function requireAuth(req: FastifyRequest, reply: FastifyReply): Promise<void> {", "export async function requireAuth(c: Context, next: Next): Promise<void> {")
    content = content.replace("function readBearerToken(req: FastifyRequest): string | null {", "function readBearerToken(c: Context): string | null {")
    content = content.replace("req.headers.authorization", "c.req.header('authorization')")
    content = content.replace("const token = readBearerToken(req)", "const token = readBearerToken(c)")
    content = content.replace("reply.code(401).send({", "return c.json({")
    content = content.replace("req.authUser =", "c.set('authUser',")
    content = content.replace("}\n  } catch {", "})\n    await next()\n  } catch {")
    
    with open(filepath, 'w', encoding='utf-8') as f:
        f.write(content)
    print(f"Processed {filepath}")

if __name__ == '__main__':
    process_routes()
    process_middleware()
