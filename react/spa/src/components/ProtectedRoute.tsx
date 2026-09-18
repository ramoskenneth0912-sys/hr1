import { useEffect, useState } from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { useAuth } from '@/auth/AuthContext';

export default function ProtectedRoute({ children }: { children: React.ReactNode }) {
  const { user, loading } = useAuth();
  const location = useLocation();
  const [showSpinner, setShowSpinner] = useState(false);

  // Only show the loading indicator after a short delay so authenticated
  // users see the app immediately on fast restores.
  useEffect(() => {
    const t = setTimeout(() => setShowSpinner(true), 400);
    return () => clearTimeout(t);
  }, []);

  if (loading) {
    return showSpinner ? (
      <div style={{ display: 'flex', minHeight: '100vh', alignItems: 'center', justifyContent: 'center' }}>
        <div
          className="animate-spin rounded-full border-b-2"
          style={{ width: 40, height: 40, borderColor: 'var(--purple)' }}
        />
      </div>
    ) : null;
  }

  if (!user) {
    return <Navigate to="/login" replace state={{ from: location.pathname }} />;
  }

  return <>{children}</>;
}