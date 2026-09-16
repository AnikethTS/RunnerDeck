<?php

declare(strict_types=1);

namespace RunnerDeck;

final class GithubAuthStatus
{
    public function __construct(
        public readonly bool $loggedIn,
        public readonly bool $orgAccessOk,
        public readonly string $message,
    ) {
    }

    public function toArray(): array
    {
        return [
            'logged_in' => $this->loggedIn,
            'org_access_ok' => $this->orgAccessOk,
            'message' => $this->message,
        ];
    }
}
