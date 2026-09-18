/*
 * Ambient types for the preserved HR1 island modules (react/src).
 *
 * These modules were written as plain JS (the island kit) and were never
 * type-checked before TypeScript existed in this repo. TS infers destructured
 * props on JS function params as REQUIRED, which produces false positives on
 * the SPA shell. These declarations give the SPA clean, optional prop types
 * while the legacy modules themselves are excluded via `// @ts-nocheck`.
 */
import type { CSSProperties, ComponentType, ReactNode } from 'react';

declare module '@hr1/components/shared-ui' {
  export function Card(props: { children?: ReactNode; p?: boolean; className?: string; style?: CSSProperties }): ReactNode;
  export function PageHeader(props: { title: ReactNode; subtitle?: ReactNode; children?: ReactNode }): ReactNode;
  export function SectionHead(props: { title: ReactNode; subtitle?: ReactNode }): ReactNode;
  export function StatusBadge(props: { label: ReactNode; variant?: string; style?: CSSProperties }): ReactNode;
  export function Badge(props: { label: ReactNode; variant?: string; style?: CSSProperties }): ReactNode;
  export function Counter(props: { value: ReactNode }): ReactNode;
  export function GhostBtn(props: {
    children?: ReactNode;
    icon?: ComponentType<{ size?: number; style?: CSSProperties; className?: string }>;
    onClick?: (...args: unknown[]) => void;
    type?: 'button' | 'submit' | 'reset';
    disabled?: boolean;
    className?: string;
    style?: CSSProperties;
  }): ReactNode;
  export function PrimaryBtn(props: {
    children?: ReactNode;
    icon?: ComponentType<{ size?: number; style?: CSSProperties; className?: string }>;
    onClick?: (...args: unknown[]) => void;
    type?: 'button' | 'submit' | 'reset';
    disabled?: boolean;
    className?: string;
    style?: CSSProperties;
  }): ReactNode;
  export function SecondaryBtn(props: {
    children?: ReactNode;
    icon?: ComponentType<{ size?: number; style?: CSSProperties; className?: string }>;
    onClick?: (...args: unknown[]) => void;
    type?: 'button' | 'submit' | 'reset';
    disabled?: boolean;
    className?: string;
    style?: CSSProperties;
  }): ReactNode;
  export function TableBase(props: { headers: string[]; children?: ReactNode }): ReactNode;
  export function TableRow(props: { children?: ReactNode; last?: boolean }): ReactNode;
  export function Td(props: { children?: ReactNode; style?: CSSProperties }): ReactNode;
  export function TdSub(props: { children?: ReactNode; style?: CSSProperties }): ReactNode;
  export function KpiCard(props: {
    label: ReactNode;
    value: ReactNode;
    sub?: ReactNode;
    icon?: ReactNode;
    colorClass?: string;
    bgClass?: string;
  }): ReactNode;
  export function ProgressBar(props: { value: ReactNode; color?: string; showLabel?: boolean; style?: CSSProperties }): ReactNode;
  export function GapBar(props: { current: ReactNode; required: ReactNode }): ReactNode;
  export function Modal(props: { open: boolean; onClose: () => void; title: ReactNode; children?: ReactNode }): ReactNode;
  export function Toast(props: {
    message: ReactNode;
    type?: 'success' | 'error' | 'warning' | 'info';
    onClose?: () => void;
  }): ReactNode;
  export function Pagination(props: { total: ReactNode }): ReactNode;
  export function Avatar(props: { initials?: string; color?: string; size?: 'sm' | 'md' | 'lg' }): ReactNode;
  export function ReadinessGauge(props: { value: ReactNode }): ReactNode;
}

declare module '@hr1/lib/constants' {
  export const V: string;
  export const TX: string;
  export const TX2: string;
  export const BD: string;
  export const SUCCESS: string;
  export const WARNING: string;
  export const INFO: string;
  export const DANGER: string;
  export const BASE_URL: string;
  const _default: {
    V: string;
    TX: string;
    TX2: string;
    BD: string;
    SUCCESS: string;
    WARNING: string;
    INFO: string;
    DANGER: string;
    BASE_URL: string;
  };
  export default _default;
}

declare module '@hr1/lib/essApi' {
  export function getEssList<T = unknown>(path: string): Promise<T[]>;
  export function getEssItem<T = unknown>(path: string): Promise<T | null>;
  export function essAction<T = unknown>(
    path: string,
    payload: unknown,
  ): Promise<{ success: boolean; data?: T; message?: string }>;
  export function essUpdate<T = unknown>(
    path: string,
    payload: unknown,
    method?: string,
  ): Promise<{ success: boolean; data?: T; message?: string }>;
  const _default: {
    getEssList: typeof getEssList;
    getEssItem: typeof getEssItem;
    essAction: typeof essAction;
    essUpdate: typeof essUpdate;
  };
  export default _default;
}