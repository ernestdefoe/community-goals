# Community Goals: working rules

- **Commit straight to `main` and push. Never open a pull request on this repo.** Commit subjects are conventional (`feat:`, `fix:`); the release drafter bumps from the subject.
- `js/dist` is committed and is what forums load. After any change under `js/src`, run `npm run build` in `js/` (from a fresh `npm ci`) and commit the bundle; CI fails if it is stale.
- Publishing a release is Ernest's click. Nothing is announced until he says so.

## Design rules that exist for a reason

- **Counting is a ledger, moved one scope at a time.** `Tally` is told WHERE something changed (a post, a discussion, a member) and makes `community_goal_entries` match what `Counting` says should be counted there; progress and `community_goal_contributors` move by exactly the rows written (affected-row counts, guarded UPDATEs). Never increment on an event without the ledger: duplicate events, hides, restores and approvals stay idempotent only because of it.
- **All goals at a scope in one pass, in a fixed number of queries**: one `lockForUpdate` on their rows (Recount takes the same lock), one ledger read, one `Counting::facts` query per family (posts / likes / discussions / members) sorted to each goal in PHP by `Counting::accepts` (same rules as `Counting::query`, which Recount runs as SQL: keep the two in step), batched writes in `adjust`. Measured: a reply costs ~+8 queries with 1 or 11 goals running. Only goals whose window contains NOW are synced (`OpenGoals`): an ended goal's number is final even before the tick closes it.
- **What counts = what a plain member can read** (`Counting::reader()`, a member with no extra groups, through Flarum's own visibility scopes) plus an explicit `hidden_at IS NULL`. Things are dated by when they were written (posts, discussions), given (likes) or joined (members). Self-likes never count.
- **Deleted/hidden things stop counting; restored things count again.** Discussion deletes are handled on `Deleting` (its posts go without events). Once reached, a goal stays reached (`hit_at` claimed once with `IS NULL`); milestones only ever go up.
- **Closed goals are frozen** (`closed_at`, set by `community-goals:tick` every five minutes after a last `Tally::check`). Reached or closed goals only change wording (`Goals::save`).
- **No query on a quiet forum.** `OpenGoals` caches the open goals (forget on save/close/delete via `Goals::changed`); no memo on the instance (queue workers live for hours). `Board` (index banner) is cached 60 s and forgotten on every count change; it rides in the forum payload.
- **Heavy counting only in `Recount`** (create/edit of metric, tag or window; the Recount button; `community-goals:recount`): two INSERT … SELECTs in a transaction.
- **Finishing a reached goal (`CompleteGoal`) is resumable**: a lease (`completing_at`, stale after `LEASE_MINUTES`, reclaimed by the tick), `_notified` and each reward saved to `reward_state` as done, `rewarded_at` set LAST. It is queued after the count commits and can never throw into the request that tipped it over.
- **A target already met is refused** on create/edit (`complete` pointer) unless the request says `complete: true` (the form asks): saving it would finish the goal for good.
- **Rewards** (`Rewards` + `Reward/*`) are set by ADMINS only (moderators with manage can't: they change site settings and hand out badges). They name other extensions' classes and are constructed only after `isEnabled`; timed ones (`until`) are taken back by the tick, and on delete, only if still ours (Wardrobe default unchanged; Festoon custom look id `goal-<id>`).
- **Notifications:** one blueprint for every helper (one sync); the SUBJECT IS THE GOAL (`Api\GoalResource`, no endpoints, + the `community-goals` store model), so alerts outlive the admin who set it. Never sync in chunks: `sync()` deletes anyone not in the list.
- **Closed forums stay closed**: every read needs `viewForum` too (`Settings::canView`); helpers are loaded `whereVisibleTo($actor)` and dropped from lists when hidden; a new-members goal names nobody without the member-list permission (`Present::canSeeHelpers`, also its announcement's {top}).
- Re-tagging re-syncs every metric (a move into a staff-only tag stops counting). Changing a TAG's permissions moves nothing by itself: Recount.
- Tag goals are 404 / left out for viewers who can't see the tag (`Present::visible`).
- 🚨 Raw SQL skips the table prefix: qualified columns go through `select()`, never `selectRaw`. MySQL refuses LIMIT inside an IN subquery: pluck ids first. Migrations never name an index or key (Laravel doesn't prefix explicit names).
- Frontend: forum.js carries only the route, nav link, banner/bar/celebrations and the notification; the page, top list and form are one lazy chunk (`goals.tsx`). Mithril has no function components; never mix keyed and unkeyed siblings (ItemList items have no keys).

## Testing

- `composer test:setup && composer test` (integration; set `DB_DRIVER=mysql DB_PREFIX=dev_ …` for prefixed MySQL), `composer analyse:phpstan`.
- `cd js && npm ci && npm run build && npm run format-check`.
- Local forum: ~/Herd/goalsdev (https://goalsdev.test, MySQL prefix `cg_`); screenshots: ~/github/scopes/community-goals-shots. The showcase seed is `~/github/showcase/seeds/98-community-goals.php`.
