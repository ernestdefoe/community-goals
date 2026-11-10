import app from 'flarum/forum/app';
import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Select from 'flarum/common/components/Select';
import Switch from 'flarum/common/components/Switch';
import Stream from 'flarum/common/utils/Stream';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { type Goal, type Metric, call, t } from '../common';

declare const m: Mithril.Static;

interface Choice {
  id: number | string;
  title: string;
  child?: boolean;
}

interface Options {
  likes: boolean;
  tags: Choice[] | null;
  /** Null for anyone but admins. */
  rewards: null | {
    theme?: Choice[];
    festoon?: Choice[];
    badge?: Choice[];
    announce?: { tags: Choice[] | null };
  };
}

interface Attrs extends IFormModalAttrs {
  goal?: Goal;
  onsave?: () => void;
}

/** "2026-02-08T18:30" for a datetime-local input, in the reader's zone. */
function local(date: Date): string {
  return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
}

const METRICS: Metric[] = ['replies', 'discussions', 'likes', 'members', 'tag_posts', 'custom'];

/**
 * Set a goal, or edit one. The rewards on offer depend on which extensions
 * the forum runs (asked from the server when the form opens). Once a goal
 * has been reached or is over, only its wording can change.
 */
export default class GoalModal extends FormModal<Attrs> {
  options: Options | null = null;

  name = Stream('');
  description = Stream('');
  metric = Stream<Metric>('replies');
  tagId = Stream('');
  unit = Stream('');
  progress = Stream('0');
  target = Stream('100');
  startsAt = Stream('');
  endsAt = Stream('');
  reward = Stream('');

  theme = Stream('');
  themeDays = Stream('7');
  festoon = Stream('');
  festoonDays = Stream('3');
  badge = Stream('');
  badgeMin = Stream('1');
  announce = Stream(false);
  announceTag = Stream('');
  announceTitle = Stream('');
  announceBody = Stream('');

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);
    const goal = this.attrs.goal;

    if (goal) {
      const rewards = goal.rewards || {};
      this.name(goal.title);
      this.description(goal.description || '');
      this.metric(goal.metric);
      this.tagId(goal.tagId ? String(goal.tagId) : '');
      this.unit(goal.unit || '');
      this.target(String(goal.target));
      this.startsAt(local(new Date(goal.startsAt)));
      this.endsAt(local(new Date(goal.endsAt)));
      this.reward(goal.reward || '');
      this.theme(rewards.theme?.id || '');
      this.themeDays(String(rewards.theme?.days ?? 7));
      this.festoon(rewards.festoon?.look || '');
      this.festoonDays(String(rewards.festoon?.days ?? 3));
      this.badge(rewards.badge ? String(rewards.badge.id) : '');
      this.badgeMin(String(rewards.badge?.min ?? 1));
      this.announce(!!rewards.announce);
      this.announceTag(rewards.announce?.tag ? String(rewards.announce.tag) : '');
      this.announceTitle(rewards.announce?.title || '');
      this.announceBody(rewards.announce?.body || '');
    } else {
      this.preset('7');
    }

    call<Options>('GET', '/options').then((options) => {
      this.options = options;
      m.redraw();
    });
  }

  get frozen(): boolean {
    const goal = this.attrs.goal;
    return !!goal && (!!goal.hitAt || goal.status === 'done' || goal.status === 'missed');
  }

  className() {
    return 'GoalModal Modal--medium';
  }

  title() {
    return this.attrs.goal ? t('form.edit_title') : t('form.title');
  }

  content() {
    if (!this.options) return <div className="Modal-body">{<LoadingIndicator />}</div>;
    const frozen = this.frozen;
    const metrics = METRICS.filter((metric) => (metric !== 'likes' || this.options!.likes) && (metric !== 'tag_posts' || this.options!.tags));

    return (
      <div className="Modal-body">
        <div className="Form">
          {frozen && <p className="GoalModal-frozen">{t('form.frozen')}</p>}
          <div className="Form-group">
            <label for="cg-name">{t('form.name')}</label>
            <input
              id="cg-name"
              className="FormControl"
              maxlength={100}
              bidi={this.name}
              required
              placeholder={extractText(t('form.name_placeholder'))}
            />
          </div>
          <div className="Form-group">
            <label for="cg-description">{t('form.description')}</label>
            <textarea
              id="cg-description"
              className="FormControl"
              rows={2}
              maxlength={1000}
              bidi={this.description}
              placeholder={extractText(t('form.description_placeholder'))}
            />
          </div>

          <div className="GoalModal-row">
            <div className="Form-group">
              <label>{t('form.metric')}</label>
              <Select
                value={this.metric()}
                disabled={frozen}
                options={Object.fromEntries(metrics.map((metric) => [metric, extractText(t(`form.metric_${metric}`))]))}
                onchange={(value: Metric) => this.metric(value)}
              />
            </div>
            <div className="Form-group">
              <label for="cg-target">{t('form.target')}</label>
              <input id="cg-target" className="FormControl" type="number" min={1} max={10000000} bidi={this.target} disabled={frozen} required />
            </div>
          </div>

          {this.metric() === 'tag_posts' && (
            <div className="Form-group">
              <label>{t('form.tag')}</label>
              {this.choose(this.tagId, this.options.tags || [], extractText(t('form.tag_choose')), frozen)}
            </div>
          )}
          {this.metric() === 'custom' && (
            <div className="GoalModal-row">
              <div className="Form-group">
                <label for="cg-unit">{t('form.unit')}</label>
                <input id="cg-unit" className="FormControl" maxlength={40} bidi={this.unit} placeholder={extractText(t('form.unit_placeholder'))} />
              </div>
              {!this.attrs.goal && (
                <div className="Form-group">
                  <label for="cg-progress">{t('form.start_progress')}</label>
                  <input id="cg-progress" className="FormControl" type="number" min={0} bidi={this.progress} />
                </div>
              )}
            </div>
          )}

          <div className="GoalModal-row">
            <div className="Form-group">
              <label for="cg-starts">{t('form.starts')}</label>
              <input id="cg-starts" className="FormControl" type="datetime-local" bidi={this.startsAt} disabled={frozen} required />
            </div>
            <div className="Form-group">
              <label for="cg-ends">{t('form.ends')}</label>
              <input id="cg-ends" className="FormControl" type="datetime-local" bidi={this.endsAt} min={this.startsAt()} disabled={frozen} required />
            </div>
          </div>
          {!frozen && (
            <div className="GoalModal-presets">
              <span className="helpText">{t('form.presets')}</span>
              {['week', '7', 'month'].map((key) => (
                <Button className="Button Button--text GoalModal-preset" type="button" onclick={() => this.preset(key)}>
                  {t(`form.preset_${key}`)}
                </Button>
              ))}
            </div>
          )}

          <div className="Form-group">
            <label for="cg-reward">{t('form.reward')}</label>
            <input
              id="cg-reward"
              className="FormControl"
              maxlength={200}
              bidi={this.reward}
              placeholder={extractText(t('form.reward_placeholder'))}
            />
          </div>

          {!frozen && (this.options.rewards ? this.rewards() : <p className="helpText GoalModal-adminOnly">{t('form.rewards_admin')}</p>)}

          <div className="Form-group">
            <Button className="Button Button--primary Button--block" type="submit" loading={this.loading} disabled={!this.name().trim()}>
              {this.attrs.goal ? t('form.save') : t('form.create')}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  rewards(): Mithril.Children {
    const offer = this.options!.rewards!;
    const days = (max: number, forever: boolean) => ({
      ...(forever ? { 0: extractText(t('form.days_forever')) } : {}),
      ...Object.fromEntries([1, 2, 3, 5, 7, 10, 14, 30, 60].filter((n) => n <= max).map((n) => [n, extractText(t('form.days_n', { count: n }))])),
    });

    return (
      <fieldset className="GoalModal-rewards">
        <legend>{t('form.rewards')}</legend>
        <p className="helpText">{t('form.rewards_help')}</p>

        {offer.theme && (
          <div className="GoalModal-reward">
            <label>{t('form.theme')}</label>
            <div className="GoalModal-pair">
              {this.choose(this.theme, offer.theme, extractText(t('form.theme_none')))}
              {this.theme() && <Select value={this.themeDays()} options={days(60, true)} onchange={this.themeDays} />}
            </div>
          </div>
        )}

        {offer.festoon && (
          <div className="GoalModal-reward">
            <label>{t('form.festoon')}</label>
            <div className="GoalModal-pair">
              {this.choose(this.festoon, offer.festoon, extractText(t('form.festoon_none')))}
              {this.festoon() && <Select value={this.festoonDays()} options={days(14, false)} onchange={this.festoonDays} />}
            </div>
          </div>
        )}

        {offer.badge && (
          <div className="GoalModal-reward">
            <label>{t('form.badge')}</label>
            <div className="GoalModal-pair">
              {this.choose(this.badge, offer.badge, extractText(t('form.badge_none')))}
              {this.badge() && (
                <label className="GoalModal-min">
                  {t('form.badge_min')}
                  <input className="FormControl" type="number" min={1} bidi={this.badgeMin} />
                </label>
              )}
            </div>
          </div>
        )}

        {offer.announce && (
          <div className="GoalModal-reward">
            <Switch state={this.announce()} onchange={(value: boolean) => this.announce(value)}>
              {t('form.announce')}
            </Switch>
            {this.announce() && (
              <div className="GoalModal-announce">
                {offer.announce.tags && (
                  <label>
                    {t('form.announce_tag')}
                    {this.choose(this.announceTag, offer.announce.tags, extractText(t('form.tag_choose')))}
                  </label>
                )}
                <label>
                  {t('form.announce_title')}
                  <input
                    className="FormControl"
                    maxlength={80}
                    bidi={this.announceTitle}
                    placeholder={extractText(t('form.announce_title_placeholder', { title: this.name() || '…' }))}
                  />
                </label>
                <label>
                  {t('form.announce_body')}
                  <textarea className="FormControl" rows={3} maxlength={2000} bidi={this.announceBody} />
                </label>
                <p className="helpText">{t('form.announce_body_help')}</p>
              </div>
            )}
          </div>
        )}

        {!offer.theme && !offer.festoon && !offer.badge && <p className="helpText">{t('form.rewards_none')}</p>}
      </fieldset>
    );
  }

  choose(stream: Stream<string>, choices: Choice[], none: string, disabled = false): Mithril.Children {
    return (
      <Select
        value={stream()}
        disabled={disabled}
        options={{ '': none, ...Object.fromEntries(choices.map((c) => [String(c.id), (c.child ? '— ' : '') + c.title])) }}
        onchange={stream}
      />
    );
  }

  /** Fill the window: this week (to Sunday night), the next 7 days, or this month. */
  preset(key: string) {
    const start = new Date();
    start.setSeconds(0, 0);
    const end = new Date(start);
    if (key === 'week') {
      end.setDate(end.getDate() + ((7 - end.getDay()) % 7));
      end.setHours(23, 59, 0, 0);
    } else if (key === 'month') {
      end.setMonth(end.getMonth() + 1, 0);
      end.setHours(23, 59, 0, 0);
    } else {
      end.setDate(end.getDate() + 7);
    }
    this.startsAt(local(start));
    this.endsAt(local(end));
  }

  data(): Record<string, any> {
    const iso = (value: string) => (value ? new Date(value).toISOString() : null);
    const body: Record<string, any> = {
      title: this.name(),
      description: this.description(),
      reward: this.reward(),
      unit: this.unit(),
    };
    if (this.frozen) return body;

    Object.assign(body, {
      metric: this.metric(),
      tagId: this.tagId() ? Number(this.tagId()) : null,
      target: Number(this.target()),
      startsAt: iso(this.startsAt()),
      endsAt: iso(this.endsAt()),
      progress: Number(this.progress()) || 0,
      rewards: {
        ...(this.theme() ? { theme: { id: this.theme(), days: Number(this.themeDays()) } } : {}),
        ...(this.festoon() ? { festoon: { look: this.festoon(), days: Number(this.festoonDays()) } } : {}),
        ...(this.badge() ? { badge: { id: Number(this.badge()), min: Number(this.badgeMin()) || 1 } } : {}),
        ...(this.announce()
          ? { announce: { tag: this.announceTag() ? Number(this.announceTag()) : null, title: this.announceTitle(), body: this.announceBody() } }
          : {}),
      },
    });

    return body;
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    this.save(false);
  }

  /**
   * The server refuses a target that is already met (the goal would be
   * reached, for good, the moment it's saved) unless asked on purpose: say
   * why and ask, then send it again with `complete`.
   */
  save(complete: boolean) {
    this.loading = true;
    const goal = this.attrs.goal;
    const body = this.data();
    if (complete) body.complete = true;

    call(goal ? 'PATCH' : 'POST', goal ? `/${goal.id}` : '', body)
      .then(() => {
        this.hide();
        this.attrs.onsave?.();
      })
      .catch((error) => {
        this.loading = false;
        const problem = error?.response?.errors?.[0];
        if (!complete && problem?.source?.pointer === '/data/attributes/complete') {
          m.redraw();
          if (window.confirm(extractText(t('form.complete_confirm', { reason: problem.detail })))) this.save(true);
          return;
        }
        this.onerror(error);
        m.redraw();
      });
  }
}
