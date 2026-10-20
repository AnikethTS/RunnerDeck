<?php

declare(strict_types=1);

namespace RunnerDeck\Api;

use RunnerDeck\Api as JsonApi;
use RunnerDeck\Config;
use RunnerDeck\ProcessControl;

final class AddRunnerAction
{
    public static function handle(): never
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        $ephemeral = ($_POST['ephemeral'] ?? '') === '1';
        $extra = Config::parseLabels(trim((string) ($_POST['extra_labels'] ?? '')));
        if ($extra === null) {
            JsonApi::respond([
                'ok' => false,
                'message' => 'extra labels must be up to 12 comma-separated names '
                    . '(letters, digits, dot, underscore, hyphen)',
            ], 422);
        }
        JsonApi::respond(ProcessControl::addRunner($name !== '' ? $name : null, $ephemeral, $extra));
    }
}
