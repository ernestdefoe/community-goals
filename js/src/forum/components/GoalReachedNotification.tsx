import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';
import type Mithril from 'mithril';
import { t } from '../common';

/** To everyone who helped: the goal was reached. */
export default class GoalReachedNotification extends Notification {
  icon() {
    return 'fas fa-flag-checkered';
  }

  href() {
    return app.route('community-goals');
  }

  content(): Mithril.Children {
    const data: any = (this.attrs.notification as any).content() || {};
    return t('notification.reached', { title: data.title });
  }

  excerpt(): Mithril.Children {
    const data: any = (this.attrs.notification as any).content() || {};
    return data.reward || null;
  }
}
