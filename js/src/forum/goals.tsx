import app from 'flarum/forum/app';

/*
 * Everything only /goals needs: the page, the top-contributors list and the
 * goal form. One chunk, fetched on first use; forum.js stays small.
 */

declare const flarum: any;

/** Core page parts, loaded through the registry before the page draws (they are lazy in Flarum 2). */
export let PageStructure: any;
export let IndexSidebar: any;

export async function loadCore(): Promise<void> {
  if (PageStructure) return;
  const [structure, sidebar] = await Promise.all([
    flarum.reg.asyncModuleImport('flarum/forum/components/PageStructure'),
    flarum.reg.asyncModuleImport('flarum/forum/components/IndexSidebar'),
  ]);
  PageStructure = structure.default || structure;
  IndexSidebar = sidebar.default || sidebar;
}

export function openGoalForm(attrs: Record<string, any>): void {
  app.modal.show(GoalModal, attrs);
}

import GoalModal from './components/GoalModal';
import GoalsPage from './components/GoalsPage';
export { GoalModal, GoalsPage };
