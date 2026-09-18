import { Link } from 'react-router-dom';
import { Compass } from 'lucide-react';

export default function NotFoundPage() {
  return (
    <div style={{ minHeight: '60vh', display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', textAlign: 'center' }}>
      <Compass size={40} style={{ color: 'var(--muted)', marginBottom: '1rem' }} />
      <h1 style={{ fontSize: '1.5rem', fontWeight: 800, color: 'var(--text-dark)', letterSpacing: '-0.02em' }}>Page not found</h1>
      <p style={{ fontSize: '.875rem', color: 'var(--muted)', marginBottom: '1.5rem' }}>
        The page you're looking for doesn't exist in the HR1 portal.
      </p>
      <Link to="/" className="btn btn-primary">Back to Dashboard</Link>
    </div>
  );
}