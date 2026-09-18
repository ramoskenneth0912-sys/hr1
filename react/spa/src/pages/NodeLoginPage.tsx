import { useEffect, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { Loader2, LogIn } from 'lucide-react';
import { useNodeAuth } from '@/hooks/useNodeAuth';

/**
 * Login page for the NEW Node.js/Express + Auth.js (@auth/express) session.
 *
 * This is deliberately a SEPARATE route (`/node-login`) from the existing
 * PHP-backed `/login` (`@/pages/LoginPage.tsx`), which continues to drive
 * the live dashboard/ESS routes untouched. It exists only to prove the new
 * Auth.js credentials flow works end-to-end before any production route is
 * cut over. Both logins authenticate against the SAME `users` table/password
 * hashes — this is not a second identity system, just a second front door
 * during the migration.
 */
export default function NodeLoginPage() {
  const { session, loading, error, signIn } = useNodeAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const from = (location.state as { from?: string } | null)?.from ?? '/node-dashboard';

  const [credential, setCredential] = useState('');
  const [password, setPassword] = useState('');
  const [formError, setFormError] = useState('');
  const [submitting, setSubmitting] = useState(false);

  // Already signed in (e.g. returning to /node-login with a live session) —
  // send the user straight to the test dashboard.
  useEffect(() => {
    if (!loading && session) {
      navigate(from, { replace: true });
    }
  }, [loading, session, from, navigate]);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!credential.trim() || !password) {
      setFormError('Username/email and password are required.');
      return;
    }
    setSubmitting(true);
    setFormError('');
    try {
      const result = await signIn(credential.trim(), password);
      if (result) {
        navigate(from, { replace: true });
      } else {
        setFormError('Invalid username/email or password.');
      }
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div
      style={{
        minHeight: '100vh',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        background: 'linear-gradient(160deg, #151521 0%, #2a1640 55%, #7B2CBF 130%)',
        padding: '1rem',
      }}
    >
      <div
        className="panel fade-in-up"
        style={{ width: '100%', maxWidth: 400, margin: 0, padding: '2.25rem 2rem' }}
      >
        <div style={{ textAlign: 'center', marginBottom: '1.75rem' }}>
          <div
            style={{
              width: 56,
              height: 56,
              borderRadius: 16,
              background: 'var(--purple)',
              color: '#fff',
              display: 'inline-flex',
              alignItems: 'center',
              justifyContent: 'center',
              fontWeight: 800,
              fontSize: '1.5rem',
              marginBottom: '1rem',
            }}
          >
            H
          </div>
          <h1 style={{ fontSize: '1.5rem', fontWeight: 800, color: 'var(--text-dark)', letterSpacing: '-0.02em', marginBottom: '.25rem' }}>
            Sign in to HR1
          </h1>
          <p style={{ fontSize: '.875rem', color: 'var(--muted)' }}>
            Node.js / Auth.js migration preview
          </p>
        </div>

        <form onSubmit={submit} noValidate>
          <div style={{ marginBottom: '1rem' }}>
            <label htmlFor="node-credential" style={{ display: 'block', fontSize: '.8125rem', fontWeight: 600, color: 'var(--text-dark)', marginBottom: '.4rem' }}>
              Username or email
            </label>
            <input
              id="node-credential"
              type="text"
              autoComplete="username"
              value={credential}
              onChange={(e) => setCredential(e.target.value)}
              placeholder="you@company.com"
              style={{ width: '100%', padding: '.6rem .8rem', borderRadius: 'var(--radius-sm)', border: '1px solid var(--border-strong)', fontSize: '.875rem', fontFamily: 'inherit', color: 'var(--text)' }}
            />
          </div>

          <div style={{ marginBottom: '1.25rem' }}>
            <label htmlFor="node-password" style={{ display: 'block', fontSize: '.8125rem', fontWeight: 600, color: 'var(--text-dark)', marginBottom: '.4rem' }}>
              Password
            </label>
            <input
              id="node-password"
              type="password"
              autoComplete="current-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder="••••••••"
              style={{ width: '100%', padding: '.6rem .8rem', borderRadius: 'var(--radius-sm)', border: '1px solid var(--border-strong)', fontSize: '.875rem', fontFamily: 'inherit', color: 'var(--text)' }}
            />
          </div>

          {formError || error ? (
            <div
              style={{
                marginBottom: '1rem',
                padding: '.65rem .85rem',
                borderRadius: 'var(--radius-sm)',
                background: 'var(--status-danger-bg)',
                border: '1px solid var(--status-danger-border)',
                color: 'var(--status-danger)',
                fontSize: '.8125rem',
              }}
            >
              {formError || error}
            </div>
          ) : null}

          <button type="submit" className="btn btn-primary" disabled={submitting} style={{ width: '100%' }}>
            {submitting ? <Loader2 size={15} className="animate-spin" /> : <LogIn size={15} />}
            {submitting ? 'Signing in…' : 'Sign in'}
          </button>
        </form>

        <p style={{ textAlign: 'center', marginTop: '1.5rem', fontSize: '.75rem', color: 'var(--muted)' }}>
          Migration preview only — authenticates against the same HR1 account
          system via the new Node.js/Express + Auth.js session. The production
          login remains at <code>/login</code>.
        </p>
      </div>
    </div>
  );
}
