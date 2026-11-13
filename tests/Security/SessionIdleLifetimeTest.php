<?php

namespace Security;

use Okay\Core\Security\ManagerSessions;
use Okay\Core\Security\SessionNames;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Сесії вітрини й адмінки лежать в одній теці, а прибирання PHP видаляє в ній усе, старше за
 * session.gc_maxlifetime процесу, що прибирає. Із типовими 24 хвилинами на вітрині будь-який
 * відвідувач стирав сесію менеджера, неактивного пів години, і той входив знову з паролем і 2FA.
 */
class SessionIdleLifetimeTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testStorefrontCleanupKeepsIdleAdminSession(): void
    {
        $admin = $this->sessionsDir() . '/sess_' . str_repeat('a', 32);
        touch($admin, time() - 30 * 60);

        $this->storefrontCleanup();

        $this->assertFileExists($admin, 'пів години без запитів не має розлогінювати менеджера');
    }

    /** Контроль: прибирання працює — сесія, неактивна довше за межу реєстру, зникає. */
    #[RunInSeparateProcess]
    public function testSessionIdleLongerThanRegistryLimitIsRemoved(): void
    {
        $stale = $this->sessionsDir() . '/sess_' . str_repeat('b', 32);
        touch($stale, time() - ManagerSessions::IDLE_SECONDS - 60);

        $this->storefrontCleanup();

        $this->assertFileDoesNotExist($stale);
    }

    private function sessionsDir(): string
    {
        $dir = sys_get_temp_dir() . '/idle-' . uniqid('', true);
        mkdir($dir);
        ini_set('session.save_path', $dir);

        return $dir;
    }

    private function storefrontCleanup(): void
    {
        ini_set('session.gc_maxlifetime', '1440');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        SessionNames::startFrontend();
        session_gc();
    }
}
