import Extend from 'flarum/common/extenders';
import Model from 'flarum/common/Model';
import GoalReachedNotification from './components/GoalReachedNotification';

/** A goal in the store (the page reads goals through api.ts; the alert reads its own data). */
export class GoalModel extends Model {
  title = Model.attribute<string>('title');
}

/** /goals: the page and its forms are one chunk, fetched on first visit. */
const page = () => import('./goals').then(async (goals) => (await goals.loadCore(), { default: goals.GoalsPage }));

export default [
  new Extend.Routes().add('community-goals', '/goals', page),
  new Extend.Store().add('community-goals', GoalModel),
  new Extend.Notification().add('communityGoalReached', GoalReachedNotification),
];
