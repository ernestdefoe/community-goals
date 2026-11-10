<?php

namespace ErnestDefoe\CommunityGoals\Reward;

use ErnestDefoe\CommunityGoals\Goal;
use ErnestDefoe\CommunityGoals\Present;
use Flarum\Api\JsonApi;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Http\UrlGenerator;
use Flarum\Tags\Tag;
use Laminas\Diactoros\ServerRequest;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Starts a discussion when the goal is reached, written by the admin who
 * set the goal, with the numbers and the top helpers. The admin can write
 * their own title and text ({title}, {target}, {progress}, {members},
 * {reward}, {top}, {link} are filled in).
 *
 * Started through the API like a member's own discussion, so every
 * extension's rules apply (Tags demands a tag: the form asks for one).
 */
class Announcement implements Reward
{
    public function __construct(
        private readonly JsonApi $api,
        private readonly ExtensionManager $extensions,
        private readonly TranslatorInterface $translator,
        private readonly UrlGenerator $url,
        private readonly Present $present,
    ) {
    }

    public function options(): mixed
    {
        if (! $this->extensions->isEnabled('flarum-tags')) {
            return ['tags' => null];
        }

        return ['tags' => Tag::query()->orderBy('position')->orderBy('name')->get(['id', 'name', 'parent_id'])
            ->map(fn (Tag $tag) => ['id' => $tag->id, 'title' => $tag->name, 'child' => (bool) $tag->parent_id])
            ->all()];
    }

    public function clean(array $config): ?array
    {
        $tag = (int) ($config['tag'] ?? 0);
        if ($this->extensions->isEnabled('flarum-tags') && ! Tag::query()->whereKey($tag)->exists()) {
            return null;
        }

        return [
            'tag' => $tag ?: null,
            'title' => mb_substr(trim((string) ($config['title'] ?? '')), 0, 80),
            'body' => mb_substr(trim((string) ($config['body'] ?? '')), 0, 2000),
        ];
    }

    public function apply(Goal $goal, array $config): array
    {
        $author = $goal->creator;
        // process() skips the endpoint's own permission check.
        if (! $author || ! $author->can('startDiscussion')) {
            return ['skipped' => 'no_author'];
        }

        $top = $this->present->top([$goal->id], 3)[$goal->id] ?? [];
        $names = collect($this->present->users(array_column($top, 0)))->keyBy('id');
        $topText = implode(', ', array_filter(array_map(fn ($row) => isset($names[$row[0]]) ? $names[$row[0]]['displayName'].' ('.$row[1].')' : null, $top)));

        $fill = [
            '{title}' => $goal->title,
            '{target}' => (string) $goal->target,
            '{progress}' => (string) $goal->progress,
            '{members}' => (string) $goal->contributors_count,
            '{reward}' => (string) $goal->reward,
            '{top}' => $topText,
            '{link}' => $this->url->to('forum')->route('community-goals'),
        ];

        $title = $config['title'] ?: $this->translator->trans('ernestdefoe-community-goals.lib.announce.title', ['title' => $goal->title]);
        $body = $config['body'] ?: implode("\n\n", array_filter([
            $this->translator->trans('ernestdefoe-community-goals.lib.announce.reached', ['progress' => $goal->progress, 'target' => $goal->target, 'members' => $goal->contributors_count]),
            $goal->reward ? $this->translator->trans('ernestdefoe-community-goals.lib.announce.reward', ['reward' => $goal->reward]) : null,
            $topText ? $this->translator->trans('ernestdefoe-community-goals.lib.announce.top', ['top' => $topText]) : null,
            $this->translator->trans('ernestdefoe-community-goals.lib.announce.link', ['link' => '{link}']),
        ]));

        $data = ['attributes' => [
            'title' => mb_substr(strtr($title, $fill), 0, 80),
            'content' => strtr($body, $fill),
        ]];
        if ($config['tag'] && $this->extensions->isEnabled('flarum-tags')) {
            $data['relationships']['tags']['data'] = [['type' => 'tags', 'id' => (string) $config['tag']]];
        }

        /** @var Discussion $discussion */
        $discussion = $this->api->forResource(DiscussionResource::class)
            ->forEndpoint('create')
            ->withRequest(new ServerRequest())
            ->process(['data' => $data], [], ['actor' => $author]);

        return ['discussion' => $discussion->id];
    }

    public function revert(Goal $goal, array $done): void
    {
        // Not timed: the discussion stays.
    }
}
