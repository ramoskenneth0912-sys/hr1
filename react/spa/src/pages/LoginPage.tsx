import { useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { Loader2, LogIn } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';

export default function LoginPage() {
  const { login } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const from = (location.state as { from?: string } | null)?.from ?? '/';

  const [credential, setCredential] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!credential.trim() || !password) {
      setError('Username/email and password are required.');
      return;
    }
    setSubmitting(true);
    setError('');
    try {
      await login(credential.trim(), password);
      navigate(from, { replace: true });
    } catch (err) {
      setError((err as Error).message || 'Unable to sign in. Please try again.');
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
          <p style={{ fontSize: '.875rem', color: 'var(--muted)' }}>Employee Portal</p>
        </div>

        <form onSubmit={submit} noValidate>
          <div style={{ marginBottom: '1rem' }}>
            <label htmlFor="credential" style={{ display: 'block', fontSize: '.8125rem', fontWeight: 600, color: 'var(--text-dark)', marginBottom: '.4rem' }}>
              Username or email
            </label>
            <input
              id="credential"
              type="text"
              autoComplete="username"
              value={credential}
              onChange={(e) => setCredential(e.target.value)}
              placeholder="you@company.com"
              style={{ width: '100%', padding: '.6rem .8rem', borderRadius: 'var(--radius-sm)', border: '1px solid var(--border-strong)', fontSize: '.875rem', fontFamily: 'inherit', color: 'var(--text)' }}
            />
          </div>

          <div style={{ marginBottom: '1.25rem' }}>
            <label htmlFor="password" style={{ display: 'block', fontSize: '.8125rem', fontWeight: 600, color: 'var(--text-dark)', marginBottom: '.4rem' }}>
              Password
            </label>
            <input
              id="password"
              type="password"
              autoComplete="current-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder="••••••••"
              style={{ width: '100%', padding: '.6rem .8rem', borderRadius: 'var(--radius-sm)', border: '1px solid var(--border-strong)', fontSize: '.875rem', fontFamily: 'inherit', color: 'var(--text)' }}
            />
          </div>

          {error ? (
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
              {error}
            </div>
          ) : null}

          <button type="submit" className="btn btn-primary" disabled={submitting} style={{ width: '100%' }}>
            {submitting ? <Loader2 size={15} className="animate-spin" /> : <LogIn size={15} />}
            {submitting ? 'Signing in…' : 'Sign in'}
          </button>
        </form>

        <p style={{ textAlign: 'center', marginTop: '1.5rem', fontSize: '.75rem', color: 'var(--muted)' }}>
          Uses the existing HR1 account system — session &amp; bearer authentication.
        </p>
      </div>
    </div>
  );
}