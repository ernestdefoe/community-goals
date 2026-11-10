<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * How much each member added to a goal: the ledger summed per member, kept
 * in step with it by Tally so "top contributors" is one indexed read.
 */
return Migration::createTable('community_goal_contributors', function (Blueprint $table) {
    $table->unsignedInteger('goal_id');
    $table->unsignedInteger('user_id');
    $table->unsignedInteger('count')->default(0);

    $table->primary(['goal_id', 'user_id']);
    $table->index(['goal_id', 'count']);
    $table->index(['user_id']);

    $table->foreign('goal_id')->references('id')->on('community_goals')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
