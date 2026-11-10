import Extend from 'flarum/common/extenders';
import Model from 'flarum/common/Model';
import GoalReachedNotification from './components/GoalReachedNotification';

/** A goal as a notification's subject: the alert reads everything else from its own data. */
export class GoalModel extends Model {
  title = Model.attribute<string>('title');
}

export default [new Extend.Store().add('community-goals', GoalModel), new Extend.Notification().add('communityGoalReached', GoalReachedNotification)];
