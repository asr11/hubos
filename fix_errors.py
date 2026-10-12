import os, glob, re

def fix():
    files = glob.glob('server/src/**/*.ts', recursive=True)
    for f in files:
        with open(f, 'r') as fp: c = fp.read()
        
        c = c.replace("import type { FastifyInstance } from 'fastify'", "")
        c = c.replace("import type { FastifyReply, FastifyRequest } from 'fastify'", "")
        c = c.replace("import { FastifyInstance } from 'fastify'", "")
        
        # c.get('authUser') as any
        c = c.replace("c.get('authUser').", "(c.get('authUser') as any).")
        
        # request. -> c.req.
        # reply.status(500) -> return c.json({error: 'internal'}, 500)
        c = re.sub(r'reply\.status\((\d+)\)\.send\((.*?)\)', r'return c.json(\2, \1)', c)
        c = re.sub(r'reply\.status\((\d+)\)', r'return c.json({}, \1)', c)
        c = c.replace("reply.code(", "c.status(") # fallback
        c = c.replace("reply.send(", "return c.json(")
        
        c = c.replace("request.params", "c.req.param()")
        c = c.replace("request.body", "await c.req.json()")
        c = c.replace("request.query", "c.req.query()")
        
        # Parameter 'c' implicitly has an 'any' type
        # Just use ts-ignore or any
        c = re.sub(r'async\s*\(\s*c\s*\)\s*=>', r'async (c: any) =>', c)
        c = re.sub(r'async\s*\(\s*c,\s*next\s*\)\s*=>', r'async (c: any, next: any) =>', c)
        
        with open(f, 'w') as fp: fp.write(c)

if __name__ == '__main__':
    fix()
