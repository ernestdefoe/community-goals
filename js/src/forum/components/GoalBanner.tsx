import app from 'flarum/forum/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';

declare const m: Mithril.Static;
import GoalBar from './GoalBar';
import { celebrate } from '../celebrate';
import { type Goal, METRIC_ICONS, call, number, percent, setSkew, statusOf, t, timing, unit } from '../common';

interface Attrs extends ComponentAttrs {
  mode: 'hero' | 'sidebar';
  /** Sidebar mode on a phone, where the sidebar is a menu: the banner stands in. */
  phoneOnly?: boolean;
}

/** When the banner's goals were last fetched; the forum payload counts as the first. */
let fetchedAt = Date.now();

/**
 * Running goals on the discussion list: a banner over it (or a card in the
 * sidebar). Its goals come with the page (the forum payload). A page left
 * open for more than a minute asks again when the list is shown, and
 * anything reached since this browser last looked is celebrated.
 */
export default class GoalBanner extends Component<Attrs> {
  oncreate(vnode: Mithril.VnodeDOM<Attrs, this>) {
    super.oncreate(vnode);
    celebrate(goals(), this.element);

    if (Date.now() - fetchedAt > 60000) {
      fetchedAt = Date.now();
      call<{ goals: Goal[]; now: string }>('GET', '/board', undefined, true)
        .then((data) => {
          setSkew(data.now);
          app.forum.pushAttributes({ communityGoals: data.goals });
          m.redraw.sync();
          celebrate(data.goals, this.element);
        })
        .catch(() => {});
    }
  }

  view() {
    const list = goals();
    if (!list.length) return <div className="CommunityGoals CommunityGoals--empty" />;

    return (
      <div
        className={classList(
          'CommunityGoals',
          `CommunityGoals--${this.attrs.mode}`,
          this.attrs.phoneOnly && 'CommunityGoals--phoneOnly',
          list.length > 1 && 'CommunityGoals--many'
        )}
      >
        {this.cards(list)}
      </div>
    );
  }

  cards(list: Goal[]) {
    return (
      <div className="CommunityGoals-list">
        {list.map((goal) => {
          const status = statusOf(goal);
          const reached = !!goal.hitAt;
          return (
            <Link href={app.route('community-goals')} className={classList('GoalCard', `GoalCard--${status}`)} data-goal={goal.id}>
              <div className="GoalCard-head">
                <span className="GoalCard-icon" aria-hidden="true">
                  <Icon name={reached ? 'fas fa-trophy' : METRIC_ICONS[goal.metric]} />
                </span>
                <span className="GoalCard-title">{goal.title}</span>
                <span className="GoalCard-percent">{t('goal.percent', { percent: percent(goal) })}</span>
              </div>
              <GoalBar goal={goal} size={this.attrs.mode === 'sidebar' ? 'sm' : 'lg'} />
              <div className="GoalCard-meta">
                <span className="GoalCard-count">
                  <strong>{number(goal.progress)}</strong> {t('goal.of', { target: number(goal.target) })} {unit(goal, goal.target)}
                </span>
                <span className="GoalCard-time">{reached ? <strong className="GoalCard-done">{t('status.hit')}</strong> : timing(goal)}</span>
              </div>
              {goal.reward && (
                <div className={classList('GoalCard-reward', reached && 'is-unlocked')}>
                  <Icon name={reached ? 'fas fa-lock-open' : 'fas fa-gift'} />
                  <span>{goal.reward}</span>
                </div>
              )}
            </Link>
          );
        })}
      </div>
    );
  }
}

function goals(): Goal[] {
  return app.forum.attribute<Goal[]>('communityGoals') || [];
}
