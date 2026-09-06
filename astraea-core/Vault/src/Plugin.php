<?php
// STATUS: DIAMANT VGT SUPREME
declare(strict_types=1);

namespace Astraea\Vault;

use Astraea\Vault\Admin\Admin;
use Astraea\Vault\Admin\AdminActions;
use Astraea\Vault\Backup\BackupService;
use Astraea\Vault\Backup\DatabaseExporter;
use Astraea\Vault\Backup\FileCollector;
use Astraea\Vault\Backup\VerifyService;
use Astraea\Vault\Crypto\CryptoService;
use Astraea\Vault\Crypto\KeyManager;
use Astraea\Vault\Crypto\ServiceKeyProvider;
use Astraea\Vault\Notification\Notifier;
use Astraea\Vault\Integration\AstraeaCore;
use Astraea\Vault\Recovery\FatalMonitor;
use Astraea\Vault\Recovery\RecoveryManager;
use Astraea\Vault\Repository\BackupRepository;
use Astraea\Vault\Repository\IncidentRepository;
use Astraea\Vault\Restore\DatabaseRestorer;
use Astraea\Vault\Restore\RestoreService;
use Astraea\Vault\Scheduler\Scheduler;
use Astraea\Vault\Security\PassphraseRateLimiter;
use Astraea\Vault\Storage\LocalStorage;
use Astraea\Vault\Update\UpdateGuard;

final class Plugin
{
    private static ?self $instance = null;
    private bool $booted = false;
    private bool $earlyBooted = false;

    private LocalStorage $storage;
    private CryptoService $crypto;
    private KeyManager $keys;
    private BackupRepository $backupRepository;
    private IncidentRepository $incidentRepository;
    private VerifyService $verifier;
    private BackupService $backups;
    private RestoreService $restore;
    private Notifier $notifier;
    private FatalMonitor $fatalMonitor;
    private RecoveryManager $recovery;

    private function __construct()
    {
        $this->storage = new LocalStorage();
        $this->crypto = new CryptoService();
        $serviceKeys = new ServiceKeyProvider($this->storage, $this->crypto);
        $this->keys = new KeyManager($this->crypto, $serviceKeys);
        $this->backupRepository = new BackupRepository();
        $this->incidentRepository = new IncidentRepository();
        $this->verifier = new VerifyService($this->storage, $this->crypto);
        $collector = new FileCollector($this->storage);
        $databaseExporter = new DatabaseExporter();
        $this->backups = new BackupService(
            $this->storage,
            $this->crypto,
            $this->keys,
            $this->backupRepository,
            $collector,
            $databaseExporter,
            $this->verifier
        );
        $databaseRestorer = new DatabaseRestorer();
        $this->restore = new RestoreService($this->storage, $this->crypto, $databaseRestorer);
        $this->notifier = new Notifier();
        $this->fatalMonitor = new FatalMonitor($this->incidentRepository);
        $this->recovery = new RecoveryManager(
            $this->keys,
            $this->crypto,
            $this->restore,
            $this->incidentRepository,
            $this->notifier
        );
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function bootEarlyRecovery(): void
    {
        if ($this->earlyBooted) { return; }
        $this->earlyBooted = true;
        try {
            $this->storage->initialize();
            $this->fatalMonitor->register();
            if ($this->keys->initialized()) {
                $this->recovery->maybeRecover();
            }
        } catch (\Throwable $e) {
            AstraeaCore::log('critical', 'Early recovery bootstrap failed.', ['exception' => get_class($e), 'message' => $e->getMessage()]);
        }
    }

    public function isEarlyRecoveryBooted(): bool
    {
        return $this->earlyBooted;
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    public function boot(): void
    {
        if ($this->booted) { return; }
        $this->booted = true;
        $this->bootEarlyRecovery();
        Installer::ensureCapabilities();

        $updateGuard = new UpdateGuard($this->backups, $this->keys, $this->crypto, $this->notifier);
        $updateGuard->register();

        $scheduler = new Scheduler($this->backups, $this->backupRepository, $this->storage, $this->keys, $this->crypto);
        $scheduler->register();

        if (is_admin()) {
            (new Admin($this->keys, $this->backupRepository, $this->incidentRepository, $this->notifier))->register();
            (new AdminActions($this->keys, $this->crypto, $this->backups, $this->verifier, $this->restore, $this->backupRepository, $this->storage, new PassphraseRateLimiter(), $this->notifier))->register();
        }

        add_action('plugins_loaded', static function(): void {
            if (function_exists('load_textdomain') && function_exists('determine_locale')) {
                $langDir = defined('ASTRAEA_VAULT_DIR') ? ASTRAEA_VAULT_DIR . '/languages' : '';
                $locale = determine_locale();
                $moFile = $langDir . '/astraea-vault-' . $locale . '.mo';
                if ($langDir !== '' && file_exists($moFile)) {
                    load_textdomain('astraea-vault', $moFile);
                }
            }
        });
        add_action('wp_loaded', [$this->notifier, 'flushDeferredMail'], PHP_INT_MAX);
    }

    public function services(): array
    {
        return [
            'storage' => $this->storage,
            'crypto' => $this->crypto,
            'keys' => $this->keys,
            'backups' => $this->backups,
            'verifier' => $this->verifier,
            'restore' => $this->restore,
            'backup_repository' => $this->backupRepository,
            'incident_repository' => $this->incidentRepository,
        ];
    }
}
