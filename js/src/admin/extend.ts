import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';

declare const m: any;

const t = (key: string) => app.translator.trans(`ernestdefoe-community-goals.admin.${key}`, {}, true);
const setting = (key: string) => `ernestdefoe-community-goals.${key}`;

/*
 * Goals themselves are set on the forum's /goals page, where the admin sees
 * them as members do. Here: where they show, and who can see and set them.
 */
export default [
  new Extend.Admin()
    .setting(
      () => ({
        setting: setting('placement'),
        type: 'select',
        label: t('settings.placement'),
        options: { hero: t('settings.placement_hero'), sidebar: t('settings.placement_sidebar'), off: t('settings.placement_off') },
        default: 'hero',
      }),
      100
    )
    .setting(() => ({ setting: setting('nav'), type: 'boolean', label: t('settings.nav') }), 90)
    .customSetting(
      () =>
        m('.Form-group.CommunityGoalsAdmin-link', [
          m('p.helpText', t('settings.manage_link')),
          m('a.Button', { href: app.forum.attribute('baseUrl') + '/goals', target: '_blank', rel: 'noopener' }, t('settings.open_page')),
        ]),
      80
    )
    .permission(
      () => ({ permission: 'communityGoals.view', icon: 'fas fa-flag-checkered', label: t('permissions.view'), allowGuest: true }),
      'view',
      60
    )
    .permission(() => ({ permission: 'communityGoals.manage', icon: 'fas fa-flag-checkered', label: t('permissions.manage') }), 'moderate', 60),
];
