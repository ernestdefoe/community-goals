import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Dropdown from 'flarum/common/components/Dropdown';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import Avatar from 'flarum/common/components/Avatar';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import username from 'flarum/common/helpers/username';
import classList from 'flarum/common/utils/classList';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import GoalBar from './GoalBar';
import TopModal from './TopModal';
import { celebrate } from '../celebrate';
import { type Goal, type UserRow, METRIC_ICONS, call, number, percent, pushUsers, setSkew, statusOf, t, timing, unit } from '../common';
import { IndexSidebar, PageStructure, openGoalForm } from '../goals';

declare const m: Mithril.Static;

interface List {
  open: Goal[];
  past: Goal[];
  more: boolean;
  users: UserRow[];
  canManage: boolean;
  now: string;
}

const MEDALS = ['🥇', '🥈', '🥉'];

function user(id: number): any {
  return app.store.getById('users', String(id));
}

/**
 * /goals: what the forum is working toward now (big cards, with the top
 * helpers and the viewer's own part), what is coming up, and every past
 * goal, reached or missed. Refreshed every minute while it is open and seen.
 */
export default class GoalsPage extends Page {
  data: List | null = null;
  error = false;
  loadingMore = false;
  page = 1;
  private timer: number | undefined;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    app.history.push('community-goals', extractText(t('page.title')));
    app.setTitle(extractText(t('page.title')));
    app.setTitleCount(0);
    this.load();
    this.timer = window.setInterval(() => !document.hidden && this.load(), 60000);
  }

  onremove(vnode: Mithril.VnodeDOM) {
    super.onremove(vnode);
    clearInterval(this.timer);
  }

  load() {
    call<List>('GET', '', undefined, !!this.data)
      .then((data) => {
        pushUsers(data.users);
        setSkew(data.now);
        // Pages of history already shown stay; the first page is fetched again.
        if (this.data && this.page > 1) data.past = [...data.past, ...this.data.past.slice(data.past.length)];
        this.data = data;
        this.error = false;
        m.redraw.sync();
        celebrate(data.open, this.element);
      })
      .catch(() => {
        this.error = !this.data;
        m.redraw();
      });
  }

  more() {
    const data = this.data!;
    this.loadingMore = true;
    call<List>('GET', '?page=' + (this.page + 1))
      .then((next) => {
        pushUsers(next.users);
        data.past.push(...next.past);
        data.more = next.more;
        this.page++;
      })
      .catch(() => {})
      .finally(() => {
        this.loadingMore = false;
        m.redraw();
      });
  }

  view() {
    return (
      <PageStructure className="CommunityGoalsPage" sidebar={() => <IndexSidebar />}>
        {this.body()}
      </PageStructure>
    );
  }

  body(): Mithril.Children {
    const data = this.data;
    const header = (
      <header className="CommunityGoalsPage-header">
        <div className="CommunityGoalsPage-heading">
          <h2 className="CommunityGoalsPage-title">
            <Icon name="fas fa-flag-checkered" /> {t('page.title')}
          </h2>
          <p className="CommunityGoalsPage-intro">{t('page.intro')}</p>
        </div>
        {data?.canManage && (
          <Button className="Button Button--primary" icon="fas fa-plus" onclick={() => openGoalForm({ onsave: () => this.load() })}>
            {t('page.new')}
          </Button>
        )}
      </header>
    );

    if (!data) {
      return [header, this.error ? <p className="CommunityGoalsPage-empty">{t('page.error')}</p> : <LoadingIndicator />];
    }

    const running = data.open.filter((goal) => statusOf(goal) !== 'upcoming');
    const coming = data.open.filter((goal) => statusOf(goal) === 'upcoming');

    return [
      header,
      !data.open.length && (
        <div className="CommunityGoalsPage-empty">
          <Icon name="fas fa-mountain-sun" />
          <p>{data.canManage ? t('page.none_manage') : t('page.none')}</p>
        </div>
      ),
      running.length > 0 && (
        <section className="CommunityGoalsPage-section">
          <h3 className="CommunityGoalsPage-sectionTitle">{t('page.running')}</h3>
          {running.map((goal) => this.panel(goal))}
        </section>
      ),
      coming.length > 0 && (
        <section className="CommunityGoalsPage-section">
          <h3 className="CommunityGoalsPage-sectionTitle">{t('page.coming_up')}</h3>
          {coming.map((goal) => this.panel(goal))}
        </section>
      ),
      <section className="CommunityGoalsPage-section">
        <h3 className="CommunityGoalsPage-sectionTitle">{t('page.history')}</h3>
        {data.past.length ? (
          <ul className="GoalHistory">{data.past.map((goal) => this.row(goal))}</ul>
        ) : (
          <p className="CommunityGoalsPage-note">{t('page.no_history')}</p>
        )}
        {data.more && (
          <div className="CommunityGoalsPage-more">
            <Button className="Button" loading={this.loadingMore} onclick={() => this.more()}>
              {t('page.load_more')}
            </Button>
          </div>
        )}
      </section>,
    ];
  }

  panel(goal: Goal): Mithril.Children {
    const status = statusOf(goal);
    const reached = !!goal.hitAt;

    return (
      <article className={classList('GoalPanel', `GoalPanel--${status}`)} data-goal={goal.id}>
        <div className="GoalPanel-top">
          <span className="GoalPanel-icon" aria-hidden="true">
            <Icon name={reached ? 'fas fa-trophy' : METRIC_ICONS[goal.metric]} />
          </span>
          <div className="GoalPanel-heading">
            <h4 className="GoalPanel-title">{goal.title}</h4>
            <div className="GoalPanel-sub">
              <span className={classList('GoalStatus', `GoalStatus--${status}`)}>{t(`status.${status}`)}</span>
              {goal.tag && this.tag(goal)}
              <span className="GoalPanel-time">
                <Icon name="far fa-clock" /> {timing(goal)}
              </span>
            </div>
          </div>
          {this.controls(goal)}
        </div>

        {goal.description && <p className="GoalPanel-description">{goal.description}</p>}

        <div className="GoalPanel-numbers">
          <span className="GoalPanel-progress">{number(goal.progress)}</span>
          <span className="GoalPanel-target">
            {t('goal.of', { target: number(goal.target) })} {unit(goal, goal.target)}
          </span>
          <span className="GoalPanel-percent">{t('goal.percent', { percent: percent(goal) })}</span>
        </div>
        <GoalBar goal={goal} />
        <div className="GoalPanel-steps" aria-hidden="true">
          {[25, 50, 75, 100].map((step) => (
            <span className={classList(goal.milestone >= step && 'is-passed')} style={{ left: `${step}%` }}>
              {step}%
            </span>
          ))}
        </div>

        {goal.reward && (
          <div className={classList('GoalPanel-reward', reached && 'is-unlocked')}>
            <Icon name={reached ? 'fas fa-lock-open' : 'fas fa-gift'} />
            <span className="GoalPanel-rewardLabel">{reached ? t('goal.reward_unlocked') : t('goal.reward')}</span>
            <span className="GoalPanel-rewardText">{goal.reward}</span>
          </div>
        )}

        <div className="GoalPanel-foot">
          <div className="GoalPanel-helpers">
            <div className="GoalPanel-faces">
              {(goal.top || []).map(([id, count], i) => (
                <span className="GoalPanel-face" title={`${extractText(username(user(id) || null))}: ${number(count)}`}>
                  <Avatar user={user(id) || null} />
                  {i < 3 && <span className="GoalPanel-medal">{MEDALS[i]}</span>}
                </span>
              ))}
            </div>
            <span className="GoalPanel-helperCount">
              {t(reached && status === 'done' ? 'goal.helped' : 'goal.helpers', { count: goal.contributors })}
            </span>
            {(goal.top || []).length > 0 && (
              <Button className="Button Button--link GoalPanel-all" onclick={() => app.modal.show(TopModal, { goal })}>
                {t('goal.see_all')}
              </Button>
            )}
          </div>
          {app.session.user && goal.metric !== 'custom' && status !== 'upcoming' && (
            <div className={classList('GoalPanel-mine', goal.mine && 'is-helping')}>
              {goal.mine ? (
                <>
                  <Icon name="fas fa-hand-holding-heart" /> {t('goal.mine', { count: goal.mine })} {unit(goal, goal.mine!)}
                </>
              ) : (
                t('goal.mine_none')
              )}
            </div>
          )}
        </div>

        <p className="GoalPanel-counts">
          <Icon name="fas fa-circle-info" /> {t(`goal.counts.${goal.metric}`, { tag: goal.tag?.name || '' })}{' '}
          {goal.metric !== 'custom' && goal.metric !== 'members' && t('goal.hidden_note')}
        </p>
      </article>
    );
  }

  row(goal: Goal): Mithril.Children {
    const reached = !!goal.hitAt;
    const fill = Math.min(100, (goal.progress / Math.max(1, goal.target)) * 100);

    return (
      <li className={classList('GoalHistory-row', reached ? 'is-reached' : 'is-missed')}>
        <span className="GoalHistory-badge" aria-hidden="true">
          <Icon name={reached ? 'fas fa-trophy' : 'fas fa-hourglass-end'} />
        </span>
        <div className="GoalHistory-main">
          <span className="GoalHistory-title">{goal.title}</span>
          <span className="GoalHistory-meta">
            <span className={classList('GoalStatus', reached ? 'GoalStatus--done' : 'GoalStatus--missed')}>
              {t(reached ? 'status.done' : 'status.missed')}
            </span>
            {timing(goal)} · {t('goal.helped', { count: goal.contributors })}
          </span>
          {goal.reward && (
            <span className="GoalHistory-reward">
              <Icon name={reached ? 'fas fa-lock-open' : 'fas fa-gift'} /> {goal.reward}
            </span>
          )}
        </div>
        <div className="GoalHistory-result">
          <span className="GoalHistory-numbers">
            <strong>{number(goal.progress)}</strong> / {number(goal.target)} <small>{unit(goal, goal.target)}</small>
          </span>
          <span className="GoalHistory-bar">
            <span style={{ width: `${fill}%` }} />
          </span>
          <span className="GoalHistory-faces">
            {(goal.top || []).map(([id]) => (
              <Avatar user={user(id) || null} className="Avatar--small" />
            ))}
          </span>
        </div>
        {this.controls(goal)}
      </li>
    );
  }

  tag(goal: Goal): Mithril.Children {
    const tag = goal.tag!;
    return (
      <Link className="GoalTag" href={app.route('tag', { tags: tag.slug })} style={tag.color ? { '--tag-bg': tag.color } : undefined}>
        {tag.icon && <Icon name={tag.icon} />} {tag.name}
      </Link>
    );
  }

  controls(goal: Goal): Mithril.Children {
    if (!this.data?.canManage) return null;
    const open = statusOf(goal) !== 'done' && statusOf(goal) !== 'missed';

    return (
      <Dropdown
        className="GoalControls"
        buttonClassName="Button Button--icon Button--flat"
        icon="fas fa-ellipsis-h"
        label={extractText(t('manage.edit'))}
        accessibleToggleLabel={extractText(t('manage.edit'))}
      >
        <Button icon="fas fa-pencil-alt" onclick={() => this.edit(goal)}>
          {t('manage.edit')}
        </Button>
        {open && goal.metric !== 'custom' && (
          <Button icon="fas fa-rotate" onclick={() => this.recount(goal)}>
            {t('manage.recount')}
          </Button>
        )}
        {open && goal.metric === 'custom' && (
          <Button icon="fas fa-plus" onclick={() => this.addProgress(goal)}>
            {t('manage.add_progress')}
          </Button>
        )}
        <Button icon="fas fa-trash-alt" className="Button GoalControls-delete" onclick={() => this.remove(goal)}>
          {t('manage.delete')}
        </Button>
      </Dropdown>
    );
  }

  edit(goal: Goal) {
    // The full goal (with its rewards) is only sent to managers, on its own endpoint.
    call<{ goal: Goal }>('GET', `/${goal.id}`).then((data) => openGoalForm({ goal: data.goal, onsave: () => this.load() }));
  }

  recount(goal: Goal) {
    call('POST', `/${goal.id}/recount`).then(() => {
      app.alerts.show({ type: 'success' }, extractText(t('manage.recount_done')));
      window.setTimeout(() => this.load(), 2500);
    });
  }

  addProgress(goal: Goal) {
    const value = window.prompt(extractText(t('manage.add_progress_prompt')), '1');
    const add = parseInt(value || '', 10);
    if (!add) return;
    call('POST', `/${goal.id}/progress`, { add }).then(() => this.load());
  }

  remove(goal: Goal) {
    if (!window.confirm(extractText(t('manage.delete_confirm', { title: goal.title })))) return;
    call('DELETE', `/${goal.id}`).then(() => this.load());
  }
}
