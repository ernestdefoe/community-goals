<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * The ledger: one row per thing a goal counted (a post, a discussion, a
 * like, a member), keyed by what it is (subject_id: a post, discussion or
 * user id, depending on the metric) and who gets the credit. The unique key
 * is what makes counting idempotent: the same post counted twice is one row,
 * and removing it again removes exactly what was added. discussion_id lets a
 * whole discussion be re-checked at once (hidden, restored, re-tagged).
 */
return Migration::createTable('community_goal_entries', function (Blueprint $table) {
    $table->unsignedInteger('goal_id');
    $table->unsignedInteger('subject_id');
    $table->unsignedInteger('user_id');
    $table->unsignedInteger('discussion_id')->nullable();

    $table->primary(['goal_id', 'subject_id', 'user_id']);
    $table->index(['goal_id', 'discussion_id']);
    $table->index(['user_id']);

    $table->foreign('goal_id')->references('id')->on('community_goals')->cascadeOnDelete();
});
