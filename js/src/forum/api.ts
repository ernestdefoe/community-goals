import app from 'flarum/forum/app';
import { type Goal, call } from './common';

/*
 * Goals through the JSON:API, for the lazy chunk only (the page, the top
 * list and the form); forum.js never needs them.
 */

/** History comes this many at a time (GoalResource::PER_PAGE). */
export const PER_PAGE = 10;

/** The tag is only a relationship while Tags is on; asking for it otherwise is a 400. */
const include = () => ('flarum-tags' in flarum.extensions ? 'helpers,tag' : 'helpers');

/** Goals from the JSON:API in the shape the components use; their helpers and tags go into the store. */
function fromDocument(doc: any): Goal[] {
  app.store.pushPayload(doc);
  return (Array.isArray(doc.data) ? doc.data : [doc.data]).map((row: any) => {
    const tag: any = row.relationships?.tag?.data && app.store.getById('tags', row.relationships.tag.data.id);
    return {
      ...row.attributes,
      id: Number(row.id),
      tag: tag ? { id: Number(tag.id()), name: tag.name(), slug: tag.slug(), color: tag.color(), icon: tag.icon() } : null,
    };
  });
}

/** A page of goals: `open` (running or coming up) or the history, newest first. */
export function goalPage(
  when: 'open' | 'past',
  offset: number,
  limit: number,
  quiet = false
): Promise<{ goals: Goal[]; more: boolean; now: string }> {
  const params = new URLSearchParams({ include: include(), 'page[offset]': String(offset), 'page[limit]': String(limit), 'page[total]': '0' });
  if (when === 'open') params.set('filter[when]', 'open');
  return call<any>('GET', '?' + params, undefined, quiet).then((doc) => ({ goals: fromDocument(doc), more: !!doc.links?.next, now: doc.meta?.now }));
}

/** One goal: its ten biggest helpers, the viewer's rank, and for managers the form's fields. */
export function oneGoal(id: number): Promise<Goal> {
  return call<any>('GET', `/${id}?include=${include()}`).then((doc) => fromDocument(doc)[0]);
}

/** Create (no id) or edit a goal. */
export function saveGoal(id: number | null, attributes: Record<string, any>): Promise<unknown> {
  return call(id ? 'PATCH' : 'POST', id ? `/${id}` : '', { data: { type: 'community-goals', ...(id ? { id: String(id) } : {}), attributes } });
}
