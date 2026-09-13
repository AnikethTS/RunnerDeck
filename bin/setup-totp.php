#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$color = function_exists('stream_isatty') && stream_isatty(STDOUT);

function paint(string $code, string $text, bool $color): string
{
    return $color ? "\033[{$code}m{$text}\033[0m" : $text;
}

echo "RunnerDeck login setup\n\n";

if (Config::authEnabled()) {
    echo paint('33', 'A login secret is already configured.', $color) . " Continuing will replace it —\n";
    echo "codes from the old secret will stop working immediately.\n\n";
}

$secret = Totp::generateSecret();
$groups = implode(' ', str_split($secret, 4));

echo "Add this key to your authenticator app (Google Authenticator, Authy,\n";
echo "1Password, etc.) using manual/text entry — type: Time-based, digits: 6:\n\n";
echo '  ' . paint('1', $groups, $color) . "\n\n";
echo "Or paste this URI if your app supports it:\n\n";
echo '  otpauth://totp/RunnerDeck?secret=' . $secret . '&issuer=RunnerDeck' . "\n\n";

echo 'Enter the 6-digit code your app shows now, to confirm it works: ';
$code = trim((string) fgets(STDIN));

if (!Totp::verify($secret, $code)) {
    echo "\n" . paint('31', '[FAIL]', $color) . " That code doesn't match — nothing was saved. Try again.\n";
    exit(1);
}

Auth::saveTotpSecret($secret);

echo "\n" . paint('32', '[ OK ]', $color) . " Saved. RunnerDeck will now ask for a code at login.\n";
