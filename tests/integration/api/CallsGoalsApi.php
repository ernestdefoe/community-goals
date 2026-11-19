<?php

namespace ErnestDefoe\CommunityGoals\Tests\integration\api;

/** The goals API from a test: JSON:API in and out, one goal flattened back to ['goal' => [...]]. */
trait CallsGoalsApi
{
    /** @return array{int, array<string, mixed>|null} */
    private function api(string $method, string $path, ?int $as, array $body = []): array
    {
        $resource = (bool) preg_match('#^(/\d+)?(\?.*)?$#', $path);
        $options = $as ? ['authenticatedAs' => $as] : [];
        if ($method !== 'GET') {
            $id = ltrim(strtok($path, '?') ?: '', '/');
            $options['json'] = $resource && $method !== 'DELETE'
                ? ['data' => ['type' => 'community-goals'] + ($id !== '' ? ['id' => $id] : []) + ['attributes' => $body]]
                : $body;
        }
        [$path, $query] = explode('?', $path, 2) + [1 => ''];
        $url = '/api/community-goals'.$path;
        // The harness doesn't read a query string from the URL.
        parse_str($query, $params);
        $response = $this->send($this->request($method, $url, $options)->withQueryParams($params));
        $json = json_decode((string) $response->getBody(), true);

        // The board and the form's options answer in meta: hand back just that.
        if (preg_match('#^/(board|options)$#', $path) && isset($json['meta'])) {
            $json = $json['meta'];
        }
        if ($resource && isset($json['data']['type'])) {
            $json = ['goal' => ['id' => (int) $json['data']['id']] + $json['data']['attributes'], 'document' => $json];
        }

        return [$response->getStatusCode(), $json];
    }
}
