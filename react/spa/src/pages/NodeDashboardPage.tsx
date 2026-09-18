import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { LogOut } from 'lucide-react';
import { useNodeAuth } from '@/hooks/useNodeAuth';

/**
 * Migration-verification page for the NEW Node.js/Express + Auth.js session.
 * Proves the session survives a refresh and that sign-out correctly clears
 * it — nothing here migrates real HR1 dashboard functionality.
 */
export default function NodeDashboardPage() {
  const { session, signOut } = useNodeAuth();
  const navigate = useNavigate();
  const [signingOut, setSigningOut] = useState(false);

  const handleSignOut = async () => {
    setSigningOut(true);
    try {
      await signOut();
      navigate('/node-login', { replace: true });
    } finally {
      setSigningOut(false);
    }
  };

  return (
    <div style={{ minHeight: '100vh', background: 'var(--bg)', padding: '2rem 1rem' }}>
      <div className="panel" style={{ maxWidth: 560, margin: '0 auto', padding: '2rem' }}>
        <h1 style={{ fontSize: '1.375rem', fontWeight: 800, color: 'var(--text-dark)', marginBottom: '.25rem' }}>
          Node/Auth.js Session — Migration Preview
        </h1>
        <p style={{ fontSize: '.8125rem', color: 'var(--muted)', marginBottom: '1.5rem' }}>
          This page only verifies the new Node.js/Express + Auth.js session. It
          does not represent the real HR1 dashboard, which remains PHP-backed
          at <code>/</code>.
        </p>

        {session ? (
          <>
            <dl style={{ display: 'grid', gridTemplateColumns: '140px 1fr', rowGap: '.6rem', columnGap: '.75rem', fontSize: '.875rem', marginBottom: '1.5rem' }}>
              <dt style={{ color: 'var(--muted)' }}>User ID</dt>
              <dd style={{ color: 'var(--text-dark)', fontWeight: 600 }}>{session.user.id}</dd>

              <dt style={{ color: 'var(--muted)' }}>Username</dt>
              <dd style={{ color: 'var(--text-dark)', fontWeight: 600 }}>{session.user.username}</dd>

              <dt style={{ color: 'var(--muted)' }}>Email</dt>
              <dd style={{ color: 'var(--text-dark)', fontWeight: 600 }}>{session.user.email || '—'}</dd>

              <dt style={{ color: 'var(--muted)' }}>Role</dt>
              <dd style={{ color: 'var(--text-dark)', fontWeight: 600 }}>{session.user.role}</dd>

              <dt style={{ color: 'var(--muted)' }}>Employee ID</dt>
              <dd style={{ color: 'var(--text-dark)', fontWeight: 600 }}>{session.user.employeeNo ?? '—'}</dd>

              <dt style={{ color: 'var(--muted)' }}>Active</dt>
              <dd style={{ color: 'var(--text-dark)', fontWeight: 600 }}>{session.user.isActive ? 'Yes' : 'No'}</dd>

              <dt style={{ color: 'var(--muted)' }}>Session expires</dt>
              <dd style={{ color: 'var(--text-dark)', fontWeight: 600 }}>{session.expires}</dd>
            </dl>

            <button type="button" className="btn btn-outline" onClick={handleSignOut} disabled={signingOut}>
              <LogOut size={15} />
              {signingOut ? 'Signing out…' : 'Sign out'}
            </button>
          </>
        ) : (
          <p style={{ fontSize: '.875rem', color: 'var(--muted)' }}>No active session.</p>
        )}
      </div>
    </div>
  );
}
