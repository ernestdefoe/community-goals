<?php

namespace ErnestDefoe\CommunityGoals;

final class Settings
{
    public const VIEW = 'communityGoals.view';
    public const MANAGE = 'communityGoals.manage';

    /** Where the index page shows running goals: hero (a banner over the list), sidebar (a card under the nav) or off. */
    public const PLACEMENT = 'ernestdefoe-community-goals.placement';
    public const NAV = 'ernestdefoe-community-goals.nav';
    public const PLACEMENTS = ['hero', 'sidebar', 'off'];
}
