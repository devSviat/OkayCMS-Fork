<?php

namespace Admin\Controllers;

use Okay\Admin\Controllers\ModulesAdmin;
use Okay\Core\BackendPostRedirectGet;
use Okay\Core\Design;
use Okay\Core\Managers;
use Okay\Core\Modules\Installer;
use Okay\Core\Modules\Module;
use Okay\Core\Request;
use Okay\Core\Response;
use Okay\Entities\ManagersEntity;
use Okay\Entities\ModulesEntity;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * POST на сторінці модулів завжди закінчується редіректом, тож помилка
 * встановлення чи оновлення, покладена лише в Design, губилась. Вона мусить
 * лягти в сесію через BackendPostRedirectGet, звідки IndexAdmin віддасть її
 * наступному GET.
 */
class ModulesAdminFailureMessageTest extends TestCase
{
    public function testInstallFailureSurvivesRedirect(): void
    {
        $installer = $this->createStub(Installer::class);
        $installer->method('install')->willThrowException(new \RuntimeException('install broke'));

        $this->assertStoredErrorBeforeRedirect(['install_module' => 'Vendor/Name'], $installer, 'install broke');
    }

    public function testUpdateFailureSurvivesRedirect(): void
    {
        $installer = $this->createStub(Installer::class);
        $installer->method('update')->willThrowException(new \RuntimeException('update broke'));

        $this->assertStoredErrorBeforeRedirect(['check' => ['3'], 'action' => 'update'], $installer, 'update broke');
    }

    private function assertStoredErrorBeforeRedirect(array $post, Installer $installer, string $message): void
    {
        $postRedirectGet = (new ReflectionClass(SpyPostRedirectGet::class))->newInstanceWithoutConstructor();

        $controller = (new ReflectionClass(ModulesAdmin::class))->newInstanceWithoutConstructor();
        $values = [
            'request' => new ModulesAdminPostRequest($post),
            'response' => (new ReflectionClass(RedirectThrowingResponse::class))->newInstanceWithoutConstructor(),
            'design' => $this->createStub(Design::class),
            'postRedirectGet' => $postRedirectGet,
        ];
        foreach ($values as $name => $value) {
            (new ReflectionProperty(ModulesAdmin::class, $name))->setValue($controller, $value);
        }

        try {
            $controller->fetch(
                $this->createStub(ModulesEntity::class),
                $installer,
                $this->createStub(Module::class),
                $this->createStub(ManagersEntity::class),
                $this->createStub(Managers::class)
            );
            $this->fail('POST має закінчитись редіректом');
        } catch (RedirectIssued) {
        }

        $this->assertSame([$message], $postRedirectGet->storedErrors);
    }
}

/**
 * Справжній деструктор читає Request, якого тут немає.
 */
class SpyPostRedirectGet extends BackendPostRedirectGet
{
    public array $storedErrors = [];

    public function __destruct()
    {
    }

    public function storeMessageError($message)
    {
        $this->storedErrors[] = $message;
    }
}

class RedirectIssued extends \RuntimeException
{
}

/**
 * Справжній redirectTo() завершує процес через exit.
 */
class RedirectThrowingResponse extends Response
{
    public static function redirectTo(string $resource, int $responseCode = 302): void
    {
        throw new RedirectIssued($resource);
    }
}

/**
 * Request має власний метод method(), а PHPUnit 10+ відмовляється дублювати
 * класи з таким іменем методу, тож заглушка руками.
 */
class ModulesAdminPostRequest extends Request
{
    public function __construct(private array $postData)
    {
    }

    public function method($method = null)
    {
        return strtoupper((string)$method) === 'POST';
    }

    public function post($name = null, $type = null, $default = null)
    {
        return $this->postData[$name] ?? null;
    }

    public static function getCurrentUrl()
    {
        return '/backend/index.php?controller=ModulesAdmin';
    }
}
