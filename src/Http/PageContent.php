<?php

namespace ErnestDefoe\CommunityGoals\Http;

use Flarum\Frontend\Document;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Psr\Http\Message\ServerRequestInterface;

/** /goals as the server sends it: a title and a canonical URL; the page itself needs JavaScript. */
class PageContent
{
    public function __construct(
        private readonly UrlGenerator $url,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): Document
    {
        $document->title = (string) $this->translator->trans('ernestdefoe-community-goals.forum.page.title');
        $document->canonicalUrl = $this->url->to('forum')->route('community-goals');

        return $document;
    }
}
