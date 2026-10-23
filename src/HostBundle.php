<?php

declare(strict_types=1);

namespace RunnerDeck;

final class HostBundle
{
    public const KIND = 'runnerdeck-host-bundle';
    public const VERSION = 1;
    public const MAX_BYTES = 2_097_152;

    /** @return array<string, mixed> */
    public static function export(bool $includeSecrets = false): array
    {
        $settings = SettingsPortable::export();
        $secrets = [];
        if ($includeSecrets) {
            $url = Config::crashWebhookUrl()
                ?? (string) (Settings::load()['RUNNERDECK_CRASH_WEBHOOK_URL'] ?? '');
            if ($url !== '') {
                $secrets['RUNNERDECK_CRASH_WEBHOOK_URL'] = $url;
            }
        }

        $bundle = [
            'kind' => self::KIND,
            'version' => self::VERSION,
            'exported_at' => gmdate('c'),
            'settings' => $settings,
            'host_mode' => [
                'maintenance' => HostMode::isMaintenance(),
                'reboot_ready' => HostMode::isRebootReady(),
            ],
            'crash_state' => CrashState::export(),
            'agent_roster' => AgentRoster::names(),
            'slots' => self::exportSlots(),
            'crash_events' => CrashHistory::exportEvents(),
        ];
        if ($secrets !== []) {
            $bundle['secrets'] = $secrets;
        }
        return $bundle;
    }

    public static function isBundle(array $decoded): bool
    {
        return ($decoded['kind'] ?? '') === self::KIND;
    }

    /**
     * @param array<mixed, mixed> $incoming
     * @return array{ok: bool, message: string}
     */
    public static function import(array $incoming): array
    {
        if (!self::isBundle($incoming)) {
            return ['ok' => false, 'message' => 'file is not a RunnerDeck host bundle'];
        }
        $version = (int) ($incoming['version'] ?? 0);
        if ($version !== self::VERSION) {
            return ['ok' => false, 'message' => 'unsupported host bundle version'];
        }

        $bits = [];
        $settings = is_array($incoming['settings'] ?? null) ? $incoming['settings'] : [];
        $secrets = is_array($incoming['secrets'] ?? null) ? $incoming['secrets'] : [];
        $webhook = $secrets['RUNNERDECK_CRASH_WEBHOOK_URL'] ?? null;
        if (is_string($webhook) && $webhook !== '') {
            $settings['RUNNERDECK_CRASH_WEBHOOK_URL'] = $webhook;
        }
        if ($settings !== []) {
            $saved = SettingsPortable::import($settings);
            if ($saved['ok']) {
                $bits[] = $saved['message'];
            }
        }

        if (isset($incoming['host_mode']) && is_array($incoming['host_mode'])) {
            HostMode::restore(
                !empty($incoming['host_mode']['maintenance']),
                !empty($incoming['host_mode']['reboot_ready'])
            );
            $bits[] = 'host mode';
        }

        if (isset($incoming['crash_state']) && is_array($incoming['crash_state'])) {
            CrashState::restore($incoming['crash_state']);
            $bits[] = 'crash flags';
        }

        if (isset($incoming['agent_roster']) && is_array($incoming['agent_roster'])) {
            $names = [];
            foreach ($incoming['agent_roster'] as $name) {
                if (is_string($name)) {
                    $names[] = $name;
                }
            }
            AgentRoster::replace($names);
            $bits[] = 'roster';
        }

        if (isset($incoming['slots']) && is_array($incoming['slots'])) {
            $slotCount = self::restoreSlots($incoming['slots']);
            $bits[] = $slotCount . ' slot(s)';
        }

        if (isset($incoming['crash_events']) && is_array($incoming['crash_events'])) {
            $eventRows = [];
            foreach ($incoming['crash_events'] as $row) {
                if (is_array($row)) {
                    $eventRows[] = $row;
                }
            }
            $nEvents = CrashHistory::replaceEvents($eventRows);
            $bits[] = $nEvents . ' crash event(s)';
        }

        if ($bits === []) {
            return ['ok' => false, 'message' => 'host bundle had no sections to apply'];
        }

        AppLog::info('host.bundle.import', 'imported host bundle (' . implode(', ', $bits) . ')');
        return ['ok' => true, 'message' => 'imported host bundle (' . implode(', ', $bits) . ')'];
    }

    /**
     * @return list<array{id: string, agent_name: string, ephemeral: bool, extra_labels: list<string>}>
     */
    private static function exportSlots(): array
    {
        $out = [];
        foreach (RunnerPool::discover(0) as $info) {
            $out[] = [
                'id' => $info->id,
                'agent_name' => $info->agentName,
                'ephemeral' => $info->ephemeral,
                'extra_labels' => $info->extraLabels,
            ];
        }
        return $out;
    }

    /** @param array<mixed, mixed> $slots */
    private static function restoreSlots(array $slots): int
    {
        $n = 0;
        foreach ($slots as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? '');
            if (!RunnerPool::isValidId($id)) {
                continue;
            }
            $dir = RunnerPool::dirFor($id);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                continue;
            }
            SlotFlags::setEphemeral($dir, !empty($row['ephemeral']));
            $labels = [];
            foreach (is_array($row['extra_labels'] ?? null) ? $row['extra_labels'] : [] as $label) {
                if (is_string($label) && $label !== '') {
                    $labels[] = $label;
                }
            }
            $parsed = Config::parseLabels(implode(',', $labels));
            SlotFlags::setExtraLabels($dir, $parsed ?? []);
            $n++;
        }
        return $n;
    }
}
