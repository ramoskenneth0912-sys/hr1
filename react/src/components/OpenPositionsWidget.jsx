import React, { useEffect, useState } from 'react';
import { getOpenJobs } from '../lib/api.js';

const styles = {
    card: {
        background: '#ffffff',
        border: '1px solid #E0E4F0',
        borderRadius: '14px',
        padding: '1.25rem',
        boxShadow: '0 4px 16px rgba(43, 54, 116, 0.06)'
    },
    header: {
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'space-between',
        marginBottom: '.75rem'
    },
    title: {
        fontSize: '1rem',
        fontWeight: 700,
        color: '#2B3674',
        margin: 0
    },
    badge: {
        background: 'rgba(123, 44, 191, 0.12)',
        color: '#7B2CBF',
        fontWeight: 700,
        fontSize: '.75rem',
        padding: '.25rem .6rem',
        borderRadius: '999px'
    },
    list: {
        listStyle: 'none',
        margin: 0,
        padding: 0
    },
    item: {
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'space-between',
        gap: '.75rem',
        padding: '.55rem 0',
        borderTop: '1px solid #EEF1F8',
        fontSize: '.875rem',
        color: '#2B3674'
    },
    muted: {
        color: '#A3AED0',
        fontSize: '.75rem'
    }
};

export default function OpenPositionsWidget() {
    const [jobs, setJobs] = useState(null);
    const [error, setError] = useState('');

    useEffect(() => {
        let alive = true;
        getOpenJobs()
            .then((data) => { if (alive) setJobs(Array.isArray(data.data) ? data.data : data.jobs || []); })
            .catch((e) => { if (alive) setError(e.message); });
        return () => { alive = false; };
    }, []);

    if (error) {
        return (
            <div style={styles.card}>
                <h3 style={styles.title}>React · Open Positions</h3>
                <p style={{ ...styles.muted, color: '#B42318' }}>API error: {error}</p>
            </div>
        );
    }

    if (!jobs) {
        return (
            <div style={styles.card}>
                <h3 style={styles.title}>React · Open Positions</h3>
                <p style={styles.muted}>Loading from PHP API…</p>
            </div>
        );
    }

    return (
        <div style={styles.card}>
            <div style={styles.header}>
                <h3 style={styles.title}>React · Open Positions</h3>
                <span style={styles.badge}>{jobs.length} open</span>
            </div>
            {jobs.length === 0 ? (
                <p style={styles.muted}>No open positions right now.</p>
            ) : (
                <ul style={styles.list}>
                    {jobs.slice(0, 5).map((job) => (
                        <li key={job.id ?? job.job_code ?? job.title} style={styles.item}>
                            <span>{job.title ?? job.position ?? 'Untitled role'}</span>
                            <span style={styles.muted}>{job.location ?? job.department ?? ''}</span>
                        </li>
                    ))}
                </ul>
            )}
            <p style={{ ...styles.muted, marginTop: '.65rem' }}>
                Rendered by React · data via PHP REST API · MySQL untouched
            </p>
        </div>
    );
}
