<?php

namespace Modules\OkayCMS\NovaposhtaCost;

use Okay\Modules\OkayCMS\NovaposhtaCost\Helpers\NPAdminIdentity;
use PHPUnit\Framework\TestCase;

/**
 * ajax/np/calc оголошений з to_front, а з order_id він рахує доставку за товарами
 * будь-якого замовлення. Так його кличе лише блок замовлення в адмінці, тож з
 * order_id маршрут відповідає тільки менеджеру.
 */
class OrderCalcAccessTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testSharedSessionWithoutManagerIsAnonymous(): void
    {
        $this->assertNull($this->identity(false)->login());
    }

    public function testSharedSessionWithManagerGivesLogin(): void
    {
        $_SESSION['admin'] = 'manager';

        $this->assertSame('manager', $this->identity(false)->login());
    }

    /** Рушій з окремою сесією адмінки: $_SESSION['admin'] вітрини не рахується. */
    public function testSeparateBackendSessionIgnoresFrontendKey(): void
    {
        $_SESSION['admin'] = 'manager';

        $this->assertNull($this->identity(true)->login());
    }

    public function testCalcRefusesOrderWithoutManager(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 4) . '/Okay/Modules/OkayCMS/NovaposhtaCost/Controllers/NovaposhtaCostController.php');

        $this->assertMatchesRegularExpression(
            '~if \(\$orderId\) \{\s*if \(\$adminIdentity->login\(\) === null\) \{\s*\$this->response->setStatusCode\(403\)~',
            $source
        );
    }

    private function identity(bool $separateBackendSession): NPAdminIdentity
    {
        return new class($separateBackendSession) extends NPAdminIdentity {
            public function __construct(private bool $separate)
            {
            }

            protected function hasSeparateBackendSession(): bool
            {
                return $this->separate;
            }
        };
    }
}
