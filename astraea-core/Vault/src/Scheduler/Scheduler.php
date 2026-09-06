<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Vault\Scheduler;

use Astraea\Vault\Backup\BackupService;
use Astraea\Vault\Config;
use Astraea\Vault\Crypto\CryptoService;
use Astraea\Vault\Crypto\KeyManager;
use Astraea\Vault\Repository\BackupRepository;
use Astraea\Vault\Integration\AstraeaCore;
use Astraea\Vault\Storage\LocalStorage;

final class Scheduler
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly BackupRepository $repository,
        private readonly LocalStorage $storage,
        private readonly KeyManager $keys,
        private readonly CryptoService $crypto
    ) {}

    public function register(): void
    {
        add_filter('cron_schedules', [self::class, 'cronSchedules']);
        add_action(Config::CRON_HOOK, [$this, 'run']);
    }

    public static function cronSchedules(array $schedules): array
    {
        $schedules['astraea_weekly'] = ['interval' => WEEK_IN_SECONDS, 'display' => __('Once Weekly', 'astraea-vault')];
        return $schedules;
    }

    public static function reschedule(): void
    {
        wp_clear_scheduled_hook(Config::CRON_HOOK);
        $settings = Config::settings();
        $schedule = (string)($settings['schedule'] ?? 'daily');
        if ($schedule === 'off') { return; }
        $allowed = ['hourly', 'twicedaily', 'daily', 'astraea_weekly'];
        if (!in_array($schedule, $allowed, true)) { $schedule = 'daily'; }
        wp_schedule_event(time() + 300, $schedule, Config::CRON_HOOK);
    }

    public function run(): void
    {
        if (!$this->keys->initialized()) { return; }
        $settings = Config::settings();
        $type = in_array((string)$settings['scheduled_type'], ['full','database'], true) ? (string)$settings['scheduled_type'] : 'full';
        $master = '';
        try {
            $master = $this->keys->unlockWithServiceKey();
            $this->backups->create($type, $master, 'scheduled');
            $this->applyRetention((int)$settings['retention']);
        } catch (\Throwable $e) {
            AstraeaCore::log('error', 'Scheduled backup failed.', ['exception' => get_class($e)]);
        } finally {
            if ($master !== '') { $this->crypto->wipe($master); }
        }
    }

    private function applyRetention(int $keep): void
    {
        $keep = max(1, min(365, $keep));
        foreach ($this->repository->retentionCandidates($keep) as $id) {
            try {
                $this->storage->deleteBackup($id);
                $this->repository->delete($id);
            } catch (\Throwable $e) {
                AstraeaCore::log('warning', 'Retention cleanup failed.', ['exception' => get_class($e), 'backup_id' => $id]);
            }
        }
    }
}
