import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import type ItemList from 'flarum/common/utils/ItemList';
import LinkButton from 'flarum/common/components/LinkButton';
import type Mithril from 'mithril';
import GoalBanner from './components/GoalBanner';
import { t } from './common';

export { default as extend } from './extend';

/*
 * forum.js is on every page, so it only carries what the discussion list
 * shows: the route, the sidebar link, the banner (or sidebar card) with its
 * bar and celebrations, and the notification. The Goals page and the forms
 * are one chunk (goals), fetched when first opened.
 */

const page = () => import('./goals').then(async (goals) => (await goals.loadCore(), { default: goals.GoalsPage }));

app.initializers.add('ernestdefoe/community-goals', () => {
  app.routes['community-goals'] = { path: '/goals', component: page };

  const placement = () => app.forum.attribute<string>('communityGoalsPlacement') || 'hero';

  // String form: these components live in lazy chunks, and extending an imported copy patches one core never renders.
  extend('flarum/forum/components/IndexSidebar', 'navItems', function (items: ItemList<Mithril.Children>) {
    if (!app.forum.attribute('communityGoalsCanView') || !app.forum.attribute('communityGoalsNav')) return;
    items.add('communityGoals', LinkButton.component({ href: app.route('community-goals'), icon: 'fas fa-flag-checkered' }, t('nav')), 78);
  });

  // The banner sits in the list's own column. In sidebar mode it still shows on phones, where the sidebar is a menu.
  extend('flarum/forum/components/IndexPage', 'contentItems', function (items: ItemList<Mithril.Children>) {
    if (placement() === 'off' || !(app.forum.attribute<any[]>('communityGoals') || []).length) return;
    items.add('communityGoals', <GoalBanner mode="hero" phoneOnly={placement() === 'sidebar'} />, 110);
  });

  extend('flarum/forum/components/IndexSidebar', 'items', function (items: ItemList<Mithril.Children>) {
    if (placement() !== 'sidebar' || !(app.forum.attribute<any[]>('communityGoals') || []).length) return;
    items.add('communityGoals', <GoalBanner mode="sidebar" />, -10);
  });

  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', function (items: ItemList<any>) {
    if (!app.forum.attribute('communityGoalsCanView')) return;
    items.add('communityGoalReached', { name: 'communityGoalReached', icon: 'fas fa-flag-checkered', label: t('notification.preference') });
  });
});
