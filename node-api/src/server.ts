import { createApp } from './app.js';
import { env } from './config/env.js';
import { closePool } from './config/database.js';

const app = createApp();

const server = app.listen(env.port, () => {
  console.log(`[hr1-node-api] listening on http://localhost:${env.port}${env.apiBase} (env=${env.nodeEnv})`);
});

/** Graceful shutdown: stop accepting connections, then close the DB pool. */
async function shutdown(signal: NodeJS.Signals): Promise<void> {
  console.log(`[hr1-node-api] received ${signal} — shutting down...`);
  server.close(async () => {
    try {
      await closePool();
    } finally {
      process.exit(0);
    }
  });
}

process.once('SIGINT', () => void shutdown('SIGINT'));
process.once('SIGTERM', () => void shutdown('SIGTERM'));

process.on('unhandledRejection', (reason) => {
  console.error('[hr1-node-api] unhandled rejection:', reason);
});