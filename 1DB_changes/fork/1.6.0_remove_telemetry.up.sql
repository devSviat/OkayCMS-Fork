-- Залишки каналів, якими ядро OkayCMS зверталося до серверів вендора.
--
-- Support (okay-cms.support): ключі каналу підтримки, баланс, доступи до сайту,
-- які менеджер передавав у тікетах. Код видалено, таблиця більше не читається.
--
-- gather_enabled вмикав GTM-контейнер вендора в адмінці.
-- email_for_module і modules_access_* — кеш і пошта для маркетплейсу та
-- перевірки ліцензій modules.okay-cms.com.

DROP TABLE IF EXISTS `__support_info`;

DELETE FROM `__settings` WHERE `param` IN (
    'gather_enabled',
    'email_for_module',
    'modules_access_expires',
    'modules_access_check_date'
);
