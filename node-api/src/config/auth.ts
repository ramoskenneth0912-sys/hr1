import Credentials from '@auth/express/providers/credentials';
import type { ExpressAuthConfig } from '@auth/express';
import { env } from './env.js';
import { verifyCredentials } from '../services/auth.service.js';

/**
 * Auth.js (`@auth/express`) foundation config.
 *
 * This is the Express-native build of the same engine behind NextAuth.js,
 * chosen because the HR1 frontend is a Vite SPA (no Next.js server exists
 * to host `next-auth` route handlers). It authenticates against the
 * EXISTING `users` table via a Credentials provider — no schema changes,
 * no password re-hashing, no database session table (JWT strategy only,
 * compatible with the Node API's read-only DB user).
 *
 * Identity fields embedded in the token/session mirror PHP's
 * `AuthController::userShape()`: id, username, email, role, employeeId,
 * isActive. RBAC/route protection is intentionally NOT wired to any
 * endpoint yet — this phase only prepares the foundation.
 */
declare module '@auth/express' {
  interface Session {
    user?: {
      id: string;
      username: string;
      email: string;
      role: 'hr' | 'manager' | 'employee' | 'applicant';
      employeeId: number | null;
      employeeNo: string | null;
      isActive: boolean;
    };
  }
}

declare module '@auth/core/jwt' {
  interface JWT {
    id?: string;
    username?: string;
    role?: 'hr' | 'manager' | 'employee' | 'applicant';
    employeeId?: number | null;
    employeeNo?: string | null;
    isActive?: boolean;
  }
}

export const authConfig: ExpressAuthConfig = {
  secret: env.auth.secret,
  trustHost: true,
  session: { strategy: 'jwt' },
  providers: [
    Credentials({
      name: 'Credentials',
      credentials: {
        credential: { label: 'Username or email' },
        password: { label: 'Password', type: 'password' },
      },
      async authorize(credentials) {
        const credential = typeof credentials?.credential === 'string' ? credentials.credential : '';
        const password = typeof credentials?.password === 'string' ? credentials.password : '';

        const user = await verifyCredentials(credential, password);
        if (!user) return null;

        return {
          id: user.id,
          name: user.username,
          email: user.email,
          username: user.username,
          role: user.role,
          employeeId: user.employeeId,
          employeeNo: user.employeeNo,
          isActive: user.isActive,
        };
      },
    }),
  ],
  callbacks: {
    async jwt({ token, user }) {
      if (user) {
        token.id = user.id;
        token.username = (user as { username?: string }).username;
        token.role = (user as { role?: 'hr' | 'manager' | 'employee' | 'applicant' }).role;
        token.employeeId = (user as { employeeId?: number | null }).employeeId ?? null;
        token.employeeNo = (user as { employeeNo?: string | null }).employeeNo ?? null;
        token.isActive = (user as { isActive?: boolean }).isActive ?? true;
      }
      return token;
    },
    async session({ session, token }) {
      if (token.id && token.username && token.role) {
        // `session.user`'s declared type is intersected with the database-
        // adapter `AdapterUser` shape by @auth/core's callback signature even
        // though this config has no adapter (JWT-only strategy) — the extra
        // adapter fields never exist at runtime, so the cast below is safe.
        session.user = {
          id: token.id,
          username: token.username,
          email: session.user?.email ?? '',
          role: token.role,
          employeeId: token.employeeId ?? null,
          employeeNo: token.employeeNo ?? null,
          isActive: token.isActive ?? true,
        } as unknown as NonNullable<typeof session.user>;
      }
      return session;
    },
  },
};
