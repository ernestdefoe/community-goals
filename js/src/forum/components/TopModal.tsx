import app from 'flarum/forum/app';
import Modal, { type IInternalModalAttrs } from 'flarum/common/components/Modal';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Avatar from 'flarum/common/components/Avatar';
import Link from 'flarum/common/components/Link';
import username from 'flarum/common/helpers/username';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import { type Goal, type UserRow, call, number, pushUsers, t, unit } from '../common';

declare const m: Mithril.Static;

interface Attrs extends IInternalModalAttrs {
  goal: Goal;
}

/** A goal's ten biggest helpers, and where the viewer stands. */
export default class TopModal extends Modal<Attrs> {
  data: { goal: Goal; users: UserRow[] } | null = null;

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);
    call<{ goal: Goal; users: UserRow[] }>('GET', `/${this.attrs.goal.id}`).then((data) => {
      pushUsers(data.users);
      this.data = data;
      m.redraw();
    });
  }

  className() {
    return 'GoalTopModal Modal--small';
  }

  title() {
    return t('top_modal.title');
  }

  content() {
    const goal = this.data?.goal;
    if (!goal) return <div className="Modal-body">{<LoadingIndicator />}</div>;
    const me = app.session.user;

    return (
      <div className="Modal-body">
        <p className="GoalTopModal-goal">{goal.title}</p>
        {goal.top!.length ? (
          <ol className="GoalTopModal-list">
            {goal.top!.map(([id, count], i) => {
              const u = app.store.getById('users', String(id)) as any;
              return (
                <li className={classList('GoalTopModal-row', me && String(me.id()) === String(id) && 'is-me')}>
                  <span className="GoalTopModal-rank">{i < 3 ? ['🥇', '🥈', '🥉'][i] : i + 1}</span>
                  {u ? (
                    <Link className="GoalTopModal-who" href={app.route.user(u)}>
                      <Avatar user={u} /> {username(u)}
                    </Link>
                  ) : (
                    <span className="GoalTopModal-who">{username(null)}</span>
                  )}
                  <span className="GoalTopModal-count">
                    {number(count)} <small>{unit(goal, count)}</small>
                  </span>
                </li>
              );
            })}
          </ol>
        ) : (
          <p>{t('top_modal.empty')}</p>
        )}
        {me && goal.rank && goal.rank > 10 && (
          <p className="GoalTopModal-mine">
            {t('top_modal.you')}: {t('goal.rank', { rank: goal.rank })} · {number(goal.mine || 0)} {unit(goal, goal.mine || 0)}
          </p>
        )}
      </div>
    );
  }
}
