<?php

namespace ErnestDefoe\CommunityGoals;

use Flarum\Extension\ExtensionManager;

/**
 * Which optional extensions are on, asked at run time. Their listeners are
 * registered through Extend\Conditional in extend.php; this answers the
 * questions a request asks: may a goal count likes or a tag's posts, may a
 * reward use Wardrobe, Festoon or badges. Every class that touches one of
 * them checks here first, before naming any of its classes.
 */
class Integrations
{
    public function __construct(private readonly ExtensionManager $extensions)
    {
    }

    public function tags(): bool
    {
        return $this->enabled('flarum-tags');
    }

    public function likes(): bool
    {
        return $this->enabled('flarum-likes');
    }

    public function enabled(string $id): bool
    {
        return $this->extensions->isEnabled($id);
    }
}
