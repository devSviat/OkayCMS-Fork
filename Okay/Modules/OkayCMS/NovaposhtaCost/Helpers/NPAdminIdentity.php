<?php

namespace Okay\Modules\OkayCMS\NovaposhtaCost\Helpers;

use Okay\Core\Security\SessionNames;

/**
 * Логін менеджера, залогіненого в адмінці, або null — для маршрутів з to_front,
 * які йдуть через вітрину повз авторизацію бекенду.
 *
 * На стоковій OkayCMS вітрина й адмінка ділять сесію, логін — у $_SESSION['admin'].
 * На форку в адмінки власна кука, і логін читає ядро (SessionNames).
 */
class NPAdminIdentity
{
    public function login(): ?string
    {
        if ($this->hasSeparateBackendSession()) {
            return SessionNames::adminLogin();
        }

        $login = $_SESSION['admin'] ?? null;

        return is_string($login) && $login !== '' ? $login : null;
    }

    /** Окремим методом, щоб обидві гілки перевірялись у тесті. */
    protected function hasSeparateBackendSession(): bool
    {
        return class_exists(SessionNames::class);
    }
}
