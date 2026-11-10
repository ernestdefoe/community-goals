import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';

export type Metric = 'replies' | 'discussions' | 'likes' | 'members' | 'tag_posts' | 'custom';
export type Status = 'upcoming' | 'live' | 'hit' | 'done' | 'missed';

export interface TagRow {
  id: number;
  name: string;
  slug: string;
  color: string | null;
  icon: string | null;
}

export interface Goal {
  id: number;
  title: string;
  description: string | null;
  metric: Metric;
  tag: TagRow | null;
  unit: string | null;
  target: number;
  progress: number;
  milestone: number;
  status: Status;
  reward: string | null;
  startsAt: string;
  endsAt: string;
  hitAt: string | null;
  contributors: number;
  /** On /goals: [userId, count] of the top helpers, and the viewer's own count. */
  top?: [number, number][];
  mine?: number;
  rank?: number | null;
  /** Managers only. */
  rewards?: Record<string, any>;
  rewardState?: Record<string, any>;
  tagId?: number | null;
}

export interface UserRow {
  id: number;
  username: string;
  displayName: string;
  slug: string;
  avatarUrl: string | null;
}

export const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-community-goals.forum.${key}`, params);

export function call<T>(method: 'GET' | 'POST' | 'PATCH' | 'DELETE', path: string, body?: Record<string, any>, quiet = false): Promise<T> {
  return app.request<T>({
    method,
    url: app.forum.attribute<string>('apiUrl') + '/community-goals' + path,
    body,
    background: method === 'GET',
    errorHandler: (error: any) => {
      if (!quiet && error?.status !== 422) app.alerts.show({ type: 'error' }, extractText(app.translator.trans('core.lib.error.generic_message')));
      throw error;
    },
  });
}

/** People arrive as one compact list; the store lets avatar() and username() draw them the usual way. */
export function pushUsers(users: UserRow[]): void {
  for (const user of users) {
    app.store.pushObject({
      type: 'users',
      id: String(user.id),
      attributes: { username: user.username, displayName: user.displayName, slug: user.slug, avatarUrl: user.avatarUrl },
    } as any);
  }
}

export function number(value: number): string {
  try {
    return new Intl.NumberFormat(app.translator.getLocale() || undefined).format(value);
  } catch {
    return String(value);
  }
}

/** Whole percent, floored so 99.6% never reads as done. */
export function percent(goal: Goal): number {
  return goal.target ? Math.floor((goal.progress / goal.target) * 100) : 0;
}

/** "replies", "posts in Halloween", "pages written": what the number counts, for this many. */
export function unit(goal: Goal, count: number) {
  if (goal.metric === 'custom' && goal.unit) return goal.unit;
  return t(`metric.${goal.metric}`, { count, tag: goal.tag?.name || '' });
}

/** Server time minus ours, so countdowns agree with the server. */
let skew = 0;
export function setSkew(serverNow: string): void {
  const value = Date.parse(serverNow) - Date.now();
  if (Number.isFinite(value)) skew = value;
}
export function now(): number {
  return Date.now() + skew;
}

/** "2d 4h", "3h 05m", "12m". */
export function span(ms: number): string {
  const minutes = Math.max(1, Math.ceil(ms / 60000));
  const d = Math.floor(minutes / 1440);
  const h = Math.floor((minutes % 1440) / 60);
  const m = minutes % 60;
  if (d) return `${d}d ${h}h`;
  if (h) return `${h}h ${String(m).padStart(2, '0')}m`;
  return `${m}m`;
}

export function day(iso: string): string {
  try {
    return new Intl.DateTimeFormat(app.translator.getLocale() || undefined, { month: 'short', day: 'numeric', year: 'numeric' }).format(
      new Date(iso)
    );
  } catch {
    return new Date(iso).toLocaleDateString();
  }
}

/** The status as of now: the server's may be a minute old (the banner is cached). */
export function statusOf(goal: Goal): Status {
  const at = now();
  if (goal.hitAt) return at < Date.parse(goal.endsAt) ? 'hit' : 'done';
  if (at >= Date.parse(goal.endsAt)) return 'missed';
  return at < Date.parse(goal.startsAt) ? 'upcoming' : 'live';
}

/** The line under the bar: time left, when it starts, or when it ended. */
export function timing(goal: Goal) {
  const status = statusOf(goal);
  if (status === 'upcoming') return t('goal.starts', { time: span(Date.parse(goal.startsAt) - now()) });
  if (status === 'live' || status === 'hit') return t('goal.left', { time: span(Date.parse(goal.endsAt) - now()) });
  if (goal.hitAt) return t('goal.reached_on', { date: day(goal.hitAt) });
  return t('goal.ended', { date: day(goal.endsAt) });
}

export const METRIC_ICONS: Record<Metric, string> = {
  replies: 'fas fa-reply',
  discussions: 'fas fa-comments',
  likes: 'fas fa-heart',
  members: 'fas fa-user-plus',
  tag_posts: 'fas fa-tag',
  custom: 'fas fa-star',
};
