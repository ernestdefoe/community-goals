import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';
import { type Goal, statusOf, t } from './common';

/*
 * Milestones and the finish, celebrated once per browser: we remember the
 * highest milestone each goal has shown here. A goal seen for the first time
 * is remembered quietly, unless it was just reached: everyone gets that party.
 * Pure CSS confetti, removed after it falls; skipped for readers who ask for
 * less motion (the message still shows).
 */

const KEY = 'communityGoals.seen';
const COLORS = ['#f43f5e', '#f59e0b', '#10b981', '#3b82f6', '#a855f7', '#ec4899', '#facc15'];

function seen(): Record<string, number> {
  try {
    return JSON.parse(localStorage.getItem(KEY) || '{}') || {};
  } catch {
    return {};
  }
}

function remember(map: Record<string, number>): void {
  try {
    localStorage.setItem(KEY, JSON.stringify(map));
  } catch {}
}

const calm = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

function layer(): HTMLElement {
  const el = document.createElement('div');
  el.className = 'GoalConfetti';
  el.setAttribute('aria-hidden', 'true');
  document.body.appendChild(el);
  return el;
}

/** A burst of paper from an element (a milestone mark). */
export function burst(from: Element | null, count = 36): void {
  if (calm()) return;
  // A hidden copy (the phone banner on a desktop) has no box: burst from the middle instead.
  const box = from && from.getClientRects().length ? from.getBoundingClientRect() : null;
  const x = box ? box.left + box.width / 2 : window.innerWidth / 2;
  const y = box ? box.top + box.height / 2 : window.innerHeight / 3;
  const el = layer();
  for (let i = 0; i < count; i++) {
    const piece = document.createElement('span');
    const angle = Math.random() * Math.PI * 2;
    const power = 60 + Math.random() * 140;
    piece.style.left = `${x}px`;
    piece.style.top = `${y}px`;
    piece.style.background = COLORS[i % COLORS.length];
    piece.style.setProperty('--dx', `${Math.cos(angle) * power}px`);
    piece.style.setProperty('--dy', `${Math.sin(angle) * power - 110}px`);
    piece.style.setProperty('--r', `${Math.random() * 720 - 360}deg`);
    piece.style.animationDelay = `${Math.random() * 120}ms`;
    el.appendChild(piece);
  }
  window.setTimeout(() => el.remove(), 2200);
}

/** Paper falling over the whole page: the goal is done. */
export function rain(count = 140): void {
  if (calm()) return;
  const el = layer();
  el.classList.add('GoalConfetti--rain');
  for (let i = 0; i < count; i++) {
    const piece = document.createElement('span');
    piece.style.left = `${Math.random() * 100}vw`;
    piece.style.background = COLORS[i % COLORS.length];
    piece.style.setProperty('--sway', `${Math.random() * 160 - 80}px`);
    piece.style.setProperty('--r', `${Math.random() * 1080 - 540}deg`);
    piece.style.animationDuration = `${2.2 + Math.random() * 1.8}s`;
    piece.style.animationDelay = `${Math.random() * 900}ms`;
    if (i % 3 === 0) piece.classList.add('is-round');
    el.appendChild(piece);
  }
  window.setTimeout(() => el.remove(), 5000);
}

/** Celebrate whatever these goals reached since this browser last looked. */
export function celebrate(goals: Goal[], root: Element | null): void {
  const map = seen();
  let changed = false;

  for (const goal of goals) {
    const key = String(goal.id);
    const before = map[key];
    const reachedLately = goal.hitAt && Date.now() - Date.parse(goal.hitAt) < 2 * 86400000;
    const step = goal.milestone;

    if (before === undefined && !(reachedLately && step >= 100)) {
      map[key] = step;
      changed = true;
      continue;
    }
    if ((before ?? 0) >= step) continue;

    map[key] = step;
    changed = true;

    if (step >= 100 && statusOf(goal) !== 'missed') {
      rain();
      app.alerts.show({ type: 'success' }, extractText(t('celebrate.reached', { title: goal.title })));
    } else if (step > 0) {
      burst(root?.querySelector(`[data-goal="${goal.id}"] .GoalBar-tick[data-step="${step}"]`) || null);
      app.alerts.show({ type: 'success' }, extractText(t('celebrate.milestone', { title: goal.title, percent: step })));
    }
    break; // One party at a time; the next page view has the rest.
  }

  if (changed) remember(map);
}
