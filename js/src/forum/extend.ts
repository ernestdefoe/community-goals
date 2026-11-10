import Extend from 'flarum/common/extenders';
import GoalReachedNotification from './components/GoalReachedNotification';

export default [new Extend.Notification().add('communityGoalReached', GoalReachedNotification)];
