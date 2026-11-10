<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * A goal: count one thing (metric, optionally within a tag) between
 * starts_at and ends_at until it reaches target. `progress` is the cached
 * count, moved by Tally as things happen, never recounted per request.
 * `milestone` is the highest of 25/50/75/100 reached; `hit_at` is set once,
 * the first time progress reaches the target, and rewards follow
 * (`rewards` = what to do, `reward_state` = what was done, so it can be
 * undone when a timed reward runs out). `closed_at` freezes the goal: after
 * it, nothing moves its numbers.
 */
return Migration::createTable('community_goals', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('user_id')->nullable();
    $table->string('title', 100);
    $table->text('description')->nullable();
    $table->string('metric', 20);
    $table->unsignedInteger('tag_id')->nullable();
    // What a goal counted by hand counts ("pages written"); counted goals name their own.
    $table->string('unit', 40)->nullable();
    $table->unsignedInteger('target');
    $table->unsignedInteger('progress')->default(0);
    $table->unsignedTinyInteger('milestone')->default(0);
    $table->string('reward', 200)->nullable();
    $table->json('rewards')->nullable();
    $table->json('reward_state')->nullable();
    $table->dateTime('starts_at');
    $table->dateTime('ends_at')->index();
    $table->dateTime('hit_at')->nullable();
    $table->dateTime('rewarded_at')->nullable();
    $table->dateTime('closed_at')->nullable()->index();
    $table->dateTime('counted_at')->nullable();
    $table->unsignedInteger('contributors_count')->default(0);
    $table->timestamps();

    $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
});
