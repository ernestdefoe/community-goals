<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

// Everyone can watch the forum's goals; only admins set them (admins have every permission).
return Migration::addPermissions([
    'communityGoals.view' => Group::GUEST_ID,
]);
