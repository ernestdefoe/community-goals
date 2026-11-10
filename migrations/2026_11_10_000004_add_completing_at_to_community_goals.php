<?php

use Flarum\Database\Migration;

/*
 * A lease on finishing a reached goal (notifications and rewards): taken by
 * CompleteGoal, released with rewarded_at when everything is done. A lease
 * older than CompleteGoal::LEASE_MINUTES belongs to a worker that died and
 * is taken over by the next try (the tick).
 */
return Migration::addColumns('community_goals', [
    'completing_at' => ['dateTime', 'nullable' => true],
]);
