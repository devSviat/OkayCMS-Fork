<?php

namespace Admin\Helpers;

use Okay\Admin\Helpers\BackendOrderHistoryHelper;
use Okay\Admin\Requests\BackendOrdersRequest;
use Okay\Core\BackendTranslations;
use Okay\Core\EntityFactory;
use Okay\Core\QueryFactory;
use Okay\Core\Request;
use Okay\Core\ServiceLocator;
use Okay\Entities\ManagersEntity;
use Okay\Entities\OrderHistoryEntity;
use PHPUnit\Framework\TestCase;

/**
 * Історію показують картка замовлення (getHistory) і список (findOrdersHistory),
 * обидві без екранування в шаблоні. Прізвище покупця потрапляє в текст історії
 * як є, коли менеджер його змінює, тож чистити треба на обох шляхах.
 */
class OrderHistoryMarkupTest extends TestCase
{
    private const string STORED = 'Змінено прізвище з "<img src=x onerror=alert(1)>" на "<b onmouseover=alert(2)>І</b>"<br/>ok';
    private const string SHOWN = 'Змінено прізвище з "" на "<b>І</b>"<br>ok';

    public function testOrderCardShowsCleanMarkup(): void
    {
        $history = $this->helper()->getHistory(7);

        $this->assertSame(self::SHOWN, $history[0]->text);
    }

    public function testOrdersListShowsCleanMarkup(): void
    {
        $history = $this->helper()->findOrdersHistory([7]);

        $this->assertSame(self::SHOWN, $history[7][0]->text);
    }

    private function helper(): BackendOrderHistoryHelper
    {
        $historyEntity = $this->createStub(OrderHistoryEntity::class);
        $historyEntity->method('find')->willReturnCallback(fn () => [
            (object) ['id' => 1, 'order_id' => 7, 'manager_id' => null, 'new_status_id' => 0, 'text' => self::STORED],
        ]);

        try {
            $managers = ServiceLocator::getInstance()->getService(EntityFactory::class)->get(ManagersEntity::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('немає бази: ' . $e->getMessage());
        }

        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturnCallback(fn (string $class) => match ($class) {
            OrderHistoryEntity::class => $historyEntity,
            ManagersEntity::class => $managers,
        });

        $locator = ServiceLocator::getInstance();

        return new BackendOrderHistoryHelper(
            $factory,
            $locator->getService(BackendOrdersRequest::class),
            $locator->getService(Request::class),
            $locator->getService(BackendTranslations::class),
            $locator->getService(QueryFactory::class)
        );
    }
}
