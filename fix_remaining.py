import os, glob, re

def fix():
    files = glob.glob('server/src/**/*.ts', recursive=True)
    for f in files:
        with open(f, 'r') as fp: c = fp.read()
        
        c = re.sub(r"import\s+.*(?:FastifyInstance|FastifyRequest|FastifyReply).*from\s+'fastify';?", "", c)
        
        c = c.replace('.all<any>()', '.all()')
        c = c.replace('.first<any>()', '.first()')
        
        # fix _req, _reply -> c in app.get(..., async (_req, reply) => ...)
        c = re.sub(r'async\s*\(\s*[^,]+,\s*reply\s*\)\s*=>', r'async (c) =>', c)
        c = re.sub(r'async\s*\(\s*_req\s*,\s*_reply\s*\)\s*=>', r'async (c) =>', c)
        c = re.sub(r'async\s*\(\s*req\s*,\s*reply\s*\)\s*=>', r'async (c) =>', c)
        
        # missing request
        c = c.replace("request.params", "c.req.param()")
        
        with open(f, 'w') as fp: fp.write(c)

if __name__ == '__main__':
    fix()
