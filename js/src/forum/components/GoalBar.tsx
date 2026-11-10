import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Icon from 'flarum/common/components/Icon';
import classList from 'flarum/common/utils/classList';
import extractText from 'flarum/common/utils/extractText';
import { type Goal, percent, t } from '../common';

interface Attrs extends ComponentAttrs {
  goal: Goal;
  size?: 'sm' | 'lg';
}

/**
 * The progress bar: a striped fill that grows in when first drawn, marks at
 * 25, 50 and 75% that light up as they are passed, and a flag at the end.
 * Past 100% the fill stays full and the number keeps going.
 */
export default class GoalBar extends Component<Attrs> {
  view() {
    const goal = this.attrs.goal;
    const pct = percent(goal);
    const fill = Math.min(100, (goal.progress / Math.max(1, goal.target)) * 100);
    const reached = !!goal.hitAt;

    return (
      <div
        className={classList('GoalBar', `GoalBar--${this.attrs.size || 'lg'}`, reached && 'is-reached', !reached && pct >= 75 && 'is-close')}
        role="progressbar"
        aria-valuemin={0}
        aria-valuemax={goal.target}
        aria-valuenow={goal.progress}
        aria-valuetext={`${pct}%`}
      >
        <div className="GoalBar-track">{goal.progress > 0 && <div className="GoalBar-fill" style={{ width: `${fill}%` }} />}</div>
        {[25, 50, 75].map((step) => (
          <span
            className={classList('GoalBar-tick', fill >= step && 'is-passed')}
            data-step={step}
            style={{ left: `${step}%` }}
            title={extractText(t('goal.milestone', { percent: step }))}
          />
        ))}
        <span className={classList('GoalBar-flag', reached && 'is-passed')} data-step="100">
          <Icon name={reached ? 'fas fa-trophy' : 'fas fa-flag-checkered'} />
        </span>
      </div>
    );
  }
}
