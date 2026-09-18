import express, { type Express } from 'express';
import cors from 'cors';
import { env } from './config/env.js';
import { buildRootRouter } from './routes/index.js';
import { notFoundHandler } from './middleware/notFound.js';
import { errorHandler } from './middleware/errorHandler.js';
import { requestLogger } from './middleware/requestLogger.js';

/** Assemble the Express application (kept separate from the HTTP listener for testability). */
export function createApp(): Express {
  const app = express();

  app.disable('x-powered-by');
  app.set('trust proxy', true);
  app.use(express.json({ limit: '2mb' }));
  app.use(express.urlencoded({ extended: true }));
  // `credentials: true` is required so the Auth.js session cookie (set via
  // `/api/v1/auth/*`) can be sent/received by the React SPA dev server,
  // which runs on a different origin/port.
  app.use(cors({ origin: env.corsOrigin, credentials: true }));
  app.use(requestLogger);

  app.use(buildRootRouter());

  app.use(notFoundHandler);
  app.use(errorHandler);

  return app;
}