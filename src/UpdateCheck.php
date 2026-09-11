<?php

declare(strict_types=1);

final class UpdateCheck
{
    private const REPO = 'AnikethTS/RunnerDeck';

    /** @return array{current: string, latest: ?string, update_available: bool, error: ?string} */
    public static function check(): array
    {
        $current = Config::version();
        $result = Shell::exec([Config::ghBinary(), 'api', 'repos/' . self::REPO . '/releases/latest'], 10);
        if ($result['code'] !== 0) {
            return [
                'current' => $current,
                'latest' => null,
                'update_available' => false,
                'error' => trim($result['stderr'] ?: $result['stdout']) ?: 'gh api failed',
            ];
        }

        $data = json_decode($result['stdout'], true);
        $tag = is_array($data) ? (string) ($data['tag_name'] ?? '') : '';
        $latest = ltrim($tag, 'v');
        if ($latest === '') {
            return ['current' => $current, 'latest' => null, 'update_available' => false, 'error' => 'unexpected response from GitHub'];
        }

        return [
            'current' => $current,
            'latest' => $latest,
            'update_available' => version_compare($latest, $current, '>'),
            'error' => null,
        ];
    }
}
