import './config/loadEnv.js';
import { buildApp } from './app.js';
import { env } from './config/env.js';
import { db } from './lib/db.js';
async function main() {
    const app = await buildApp();
    try {
        await app.listen({ host: env.host, port: env.port });
        app.log.info(`Hub POS server — http://${env.host}:${env.port}  (health: /api/health)`);
    }
    catch (err) {
        app.log.error(err);
        process.exit(1);
    }
    const shutdown = async (signal) => {
        app.log.info(`מקבל ${signal} — סגירה מסודרת של השרת ומסד הנתונים`);
        try {
            await app.close();
            db.close();
        }
        catch (e) {
            app.log.error(e);
        }
        process.exit(0);
    };
    process.on('SIGINT', () => void shutdown('SIGINT'));
    process.on('SIGTERM', () => void shutdown('SIGTERM'));
    process.on('unhandledRejection', (reason) => {
        app.log.error({ reason }, 'Unhandled Rejection detected');
    });
    process.on('uncaughtException', (err) => {
        app.log.error({ err }, 'Uncaught Exception detected');
    });
}
void main();
//# sourceMappingURL=index.js.map