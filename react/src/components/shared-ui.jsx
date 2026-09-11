import React from 'react';
import { V, TX, TX2, BD, SUCCESS, WARNING, INFO, DANGER } from '../lib/constants.js';

/**
 * Shared UI kit for HR1's Employee Portal ESS modules.
 *
 * These components reuse HR1's existing design-system classes
 * (.panel, .page-header, .page-title, .badge-*, .btn, .data-table, .kpi-card)
 * so the imported modules look native to the Employee Portal instead of
 * introducing a second visual system.
 */

/* ---------------------------------- Card ---------------------------------- */
export function Card({ children, p = true, className = '', style }) {
    return (
        <div className={`panel ${className}`} style={{ padding: p ? undefined : 0, ...style }}>
            {children}
        </div>
    );
}

/* ------------------------------- PageHeader ------------------------------- */
export function PageHeader({ title, subtitle, children }) {
    return (
        <div className="page-header fade-in-up">
            <div>
                <h1 className="page-title">{title}</h1>
                {subtitle ? <p className="page-subtitle">{subtitle}</p> : null}
            </div>
            {children ? <div className="page-header-actions">{children}</div> : null}
        </div>
    );
}

/* ------------------------------- SectionHead ------------------------------ */
export function SectionHead({ title, subtitle }) {
    return (
        <div>
            <h3 style={{ fontSize: '1.05rem', fontWeight: 700, color: TX, margin: 0, letterSpacing: '-0.01em' }}>
                {title}
            </h3>
            {subtitle ? (
                <p style={{ fontSize: '.8rem', color: TX2, marginTop: '.15rem', marginBottom: 0 }}>{subtitle}</p>
            ) : null}
        </div>
    );
}

/* -------------------------------- Badge ----------------------------------- */
const VARIANT_MAP = {
    success: 'badge-success',
    warning: 'badge-warning',
    danger: 'badge-danger',
    info: 'badge-info',
    muted: 'badge-secondary',
    new: 'badge-new',
    purple: 'badge-primary',
    primary: 'badge-primary',
    secondary: 'badge-secondary',
};

export function StatusBadge({ label, variant = 'purple', style }) {
    return <span className={`badge ${VARIANT_MAP[variant] || 'badge-secondary'}`} style={style}>{label}</span>;
}

export function Badge({ label, variant = 'secondary', style }) {
    return <StatusBadge label={label} variant={variant} style={style} />;
}

/* -------------------------------- Counter --------------------------------- */
export function Counter({ value }) {
    return <span>{Number(value) || 0}</span>;
}

/* ----------------------------- Buttons ------------------------------------- */
export function GhostBtn({ children, icon: Icon, onClick, type = 'button', disabled, className = '', style }) {
    return (
        <button
            type={type}
            onClick={onClick}
            disabled={disabled}
            className={`btn btn-sm ${className}`}
            style={{
                background: 'transparent',
                border: `1px solid ${BD}`,
                color: V,
                whiteSpace: 'nowrap',
                ...style,
            }}
        >
            {Icon ? <Icon size={14} style={{ marginRight: 4 }} /> : null}
            {children}
        </button>
    );
}

function withIcon(Icon, children) {
    return Icon ? (
        <React.Fragment>
            <Icon size={14} style={{ marginRight: 4 }} />
            {children}
        </React.Fragment>
    ) : children;
}

export function PrimaryBtn({ children, icon: Icon, onClick, type = 'button', disabled, className = '', style }) {
    return (
        <button
            type={type}
            onClick={onClick}
            disabled={disabled}
            className={`btn btn-sm btn-primary ${className}`}
            style={{ whiteSpace: 'nowrap', ...style }}
        >
            {withIcon(Icon, children)}
        </button>
    );
}

export function SecondaryBtn({ children, icon: Icon, onClick, type = 'button', disabled, className = '', style }) {
    return (
        <button
            type={type}
            onClick={onClick}
            disabled={disabled}
            className={`btn btn-sm btn-outline ${className}`}
            style={{ whiteSpace: 'nowrap', ...style }}
        >
            {withIcon(Icon, children)}
        </button>
    );
}

/* --------------------------------- Tables ---------------------------------- */
export function TableBase({ headers, children }) {
    return (
        <div className="table-wrap">
            <table className="data-table">
                <thead>
                    <tr>{headers.map((h) => <th key={h}>{h}</th>)}</tr>
                </thead>
                <tbody>{children}</tbody>
            </table>
        </div>
    );
}

export function TableRow({ children, last }) {
    return <tr style={last ? { borderBottom: 'none' } : undefined}>{children}</tr>;
}

export function Td({ children, style }) {
    return <td className="td-ess" style={style}>{children}</td>;
}

export function TdSub({ children }) {
    return <td className="td-ess" style={{ color: TX2, fontSize: '.8rem' }}>{children}</td>;
}

/* ------------------------------- KpiCard ----------------------------------- */
export function KpiCard({ label, value, sub, icon, colorClass, bgClass }) {
    return (
        <div className="kpi-card">
            <div className="kpi-top">
                <div>
                    <div className="kpi-value">{Number(value) || 0}</div>
                    <div className="kpi-label">{label}</div>
                </div>
                <div className={`kpi-icon ${colorClass || ''} ${bgClass || ''}`}>{icon}</div>
            </div>
            {sub ? <div style={{ fontSize: '.75rem', color: TX2 }}>{sub}</div> : null}
        </div>
    );
}

/* ------------------------------ ProgressBar -------------------------------- */
export function ProgressBar({ value, color = V, showLabel = true, style }) {
    const pct = Math.min(100, Math.max(0, Number(value) || 0));
    return (
        <div style={{ width: '100%' }}>
            {showLabel ? (
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
                    <span style={{ fontSize: '.8rem', color: TX2 }}>Progress</span>
                    <span style={{ fontSize: '.8rem', fontWeight: 600, color: pct >= 100 ? SUCCESS : V }}>{pct}%</span>
                </div>
            ) : null}
            <div style={{ height: 8, borderRadius: 999, background: BD, overflow: 'hidden', ...style }}>
                <div
                    style={{
                        height: '100%',
                        borderRadius: 999,
                        background: color,
                        width: `${pct}%`,
                        transition: 'width .5s ease',
                    }}
                />
            </div>
        </div>
    );
}

/* --------------------------------- GapBar ---------------------------------- */
export function GapBar({ current, required }) {
    const req = Number(required) || 1;
    const pct = Math.min(100, Math.max(0, (Number(current) / req) * 100));
    const met = Number(current) >= req;
    return (
        <div style={{ width: '100%' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
                <span style={{ fontSize: '.75rem', color: TX2 }}>Level {current} of {required} required</span>
                <span style={{ fontSize: '.75rem', fontWeight: 600, color: met ? SUCCESS : WARNING }}>{Math.round(pct)}%</span>
            </div>
            <div style={{ height: 8, borderRadius: 999, background: BD, overflow: 'hidden' }}>
                <div
                    style={{
                        height: '100%',
                        borderRadius: 999,
                        background: met ? SUCCESS : V,
                        width: `${pct}%`,
                        transition: 'width .5s ease',
                    }}
                />
            </div>
        </div>
    );
}

/* ---------------------------------- Modal ---------------------------------- */
export function Modal({ open, onClose, title, children }) {
    if (!open) return null;
    return (
        <div
            className="ess-modal-backdrop"
            onClick={onClose}
            style={{
                position: 'fixed',
                inset: 0,
                background: 'rgba(16,10,40,.55)',
                zIndex: 2000,
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                padding: '1rem',
            }}
        >
            <div
                className="ess-modal"
                onClick={(e) => e.stopPropagation()}
                style={{
                    background: '#fff',
                    borderRadius: 'var(--radius-sm)',
                    border: `1px solid ${BD}`,
                    boxShadow: '0 24px 60px -24px rgba(27,37,89,.45)',
                    width: '100%',
                    maxWidth: 560,
                    maxHeight: '86vh',
                    overflowY: 'auto',
                }}
            >
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '1.25rem 1.5rem', borderBottom: `1px solid ${BD}` }}>
                    <h3 style={{ margin: 0, fontSize: '1.05rem', fontWeight: 700, color: TX }}>{title}</h3>
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Close"
                        style={{ border: 'none', background: 'transparent', color: TX2, cursor: 'pointer', fontSize: '1.25rem', lineHeight: 1 }}
                    >
                        ×
                    </button>
                </div>
                <div style={{ padding: '1.5rem' }}>{children}</div>
            </div>
        </div>
    );
}

/* ---------------------------------- Toast ---------------------------------- */
const TOAST_COLORS = { success: SUCCESS, error: DANGER, warning: WARNING, info: V };

export function Toast({ message, type = 'info', onClose }) {
    const color = TOAST_COLORS[type] || V;
    return (
        <div
            className="ess-toast"
            style={{
                position: 'fixed',
                top: 20,
                right: 20,
                zIndex: 2400,
                background: '#fff',
                border: `1px solid ${color}`,
                borderLeft: `4px solid ${color}`,
                borderRadius: 'var(--radius-sm)',
                boxShadow: '0 12px 30px -12px rgba(27,37,89,.35)',
                padding: '.85rem 1rem .85rem 1.25rem',
                display: 'flex',
                alignItems: 'center',
                gap: '.75rem',
                maxWidth: 380,
            }}
        >
            <span style={{ color, fontWeight: 700, fontSize: '.85rem', flex: 1 }}>{message}</span>
            {onClose ? (
                <button
                    type="button"
                    onClick={onClose}
                    aria-label="Dismiss"
                    style={{ border: 'none', background: 'transparent', color: TX2, cursor: 'pointer', fontSize: '1.1rem', lineHeight: 1 }}
                >
                    ×
                </button>
            ) : null}
        </div>
    );
}

/* ------------------------------- Pagination -------------------------------- */
export function Pagination({ total }) {
    const count = Number(total) || 0;
    return (
        <div
            className="ess-pagination"
            style={{
                display: 'flex',
                justifyContent: 'flex-end',
                alignItems: 'center',
                gap: '.5rem',
                padding: '1rem 1.5rem',
                color: TX2,
                fontSize: '.8rem',
            }}
        >
            <span>{count} record{count === 1 ? '' : 's'}</span>
        </div>
    );
}

/* --------------------------------- Avatar ---------------------------------- */
export function Avatar({ initials, color = V, size = 'md' }) {
    const px = size === 'lg' ? 56 : size === 'sm' ? 32 : 40;
    return (
        <span
            style={{
                width: px,
                height: px,
                borderRadius: '50%',
                background: color,
                color: '#fff',
                display: 'inline-flex',
                alignItems: 'center',
                justifyContent: 'center',
                fontWeight: 700,
                fontSize: size === 'lg' ? 20 : 14,
                flexShrink: 0,
            }}
        >
            {String(initials || '?').charAt(0).toUpperCase()}
        </span>
    );
}

/* ----------------------------- ReadinessGauge ------------------------------ */
export function ReadinessGauge({ value }) {
    const v = Math.min(100, Math.max(0, Number(value) || 0));
    const r = 34;
    const c = 2 * Math.PI * r;
    return (
        <div style={{ position: 'relative', width: 96, height: 96, flexShrink: 0 }}>
            <svg viewBox="0 0 80 80" width="96" height="96">
                <circle cx="40" cy="40" r={r} fill="none" stroke="rgba(255,255,255,.25)" strokeWidth="8" />
                <circle
                    cx="40"
                    cy="40"
                    r={r}
                    fill="none"
                    stroke="#fff"
                    strokeWidth="8"
                    strokeLinecap="round"
                    strokeDasharray={`${(v / 100) * c} ${c}`}
                    transform="rotate(-90 40 40)"
                />
            </svg>
            <div style={{ position: 'absolute', inset: 0, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center' }}>
                <span style={{ color: '#fff', fontWeight: 800, fontSize: '1.2rem', lineHeight: 1 }}>{v}%</span>
                <span style={{ color: 'rgba(255,255,255,.75)', fontSize: '.6rem', marginTop: 2 }}>Ready</span>
            </div>
        </div>
    );
}

export const sharedUi = { Card, PageHeader, SectionHead, StatusBadge, Badge, Counter, GhostBtn, PrimaryBtn, SecondaryBtn, TableBase, TableRow, Td, TdSub, KpiCard, ProgressBar, GapBar, Modal, Toast, Pagination, Avatar, ReadinessGauge };