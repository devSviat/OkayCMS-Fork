<?php


namespace Okay\Helpers;


use Okay\Core\EntityFactory;
use Okay\Core\FrontTranslations;
use Okay\Core\Modules\Extender\ExtenderFacade;
use Okay\Core\Request;
use Okay\Core\ServiceLocator;
use Okay\Core\Settings;
use Okay\Core\Validator;
use Okay\Entities\SubscribesEntity;
use Okay\Entities\UsersEntity;

class ValidateHelper
{

    private Validator $validator;
    private Settings $settings;
    private Request $request;
    private FrontTranslations $frontTranslations;
    private OrdersHelper $ordersHelper;

    public function __construct(
        Validator $validator,
        Settings $settings,
        Request $request,
        FrontTranslations $frontTranslations,
        OrdersHelper $ordersHelper
    ) {
        $this->validator = $validator;
        $this->settings = $settings;
        $this->request = $request;
        $this->frontTranslations = $frontTranslations;
        $this->ordersHelper = $ordersHelper;
    }

    public function getUserError($user, $currentUserId): ?string
    {
        $SL = ServiceLocator::getInstance();
        $entityFactory = $SL->getService(EntityFactory::class);
        /** @var UsersEntity $usersEntity */
        $usersEntity = $entityFactory->get(UsersEntity::class);

        $error = null;
        /*Валидация данных клиента*/
        if (($u = $usersEntity->get((string)$user->email)) && $u->id != $currentUserId) {
            $error = 'user_exists';
        } elseif (!$this->validator->isName($user->name, true) || !$this->validator->isName($user->last_name ?? '')) {
            $error = 'empty_name';
        } elseif (!$this->validator->isEmail($user->email, true)) {
            $error = 'empty_email';
        } elseif (!$this->validator->isPhone($user->phone)) {
            $error = 'empty_phone';
        }

        return ExtenderFacade::execute(__METHOD__, $error, func_get_args());
    }
    
    public function getUserRegisterError($user): ?string
    {
        $SL = ServiceLocator::getInstance();
        $entityFactory = $SL->getService(EntityFactory::class);
        /** @var UsersEntity $usersEntity */
        $usersEntity = $entityFactory->get(UsersEntity::class);

        $captchaCode =  $this->request->post('captcha_code', 'string');
        $error = null;
        $userExists = $usersEntity->count(['email'=>$user->email]);

        /*Валидация данных клиента*/
        if ($userExists) {
            $error = 'user_exists';
        } elseif (!$this->validator->isName($user->name, true) || !$this->validator->isName($user->last_name ?? '')) {
            $error = 'empty_name';
        } elseif (!$this->validator->isEmail($user->email, true)) {
            $error = 'empty_email';
        } elseif (!$this->validator->isPhone($user->phone)) {
            $error = 'empty_phone';
        } elseif (empty($user->password)) {
            $error = 'empty_password';
        } elseif ($this->settings->get('captcha_register') && !$this->validator->verifyCaptcha('captcha_register', $captchaCode)) {
            $error = 'captcha';
        }
        
        return ExtenderFacade::execute(__METHOD__, $error, func_get_args());
    }

    public function getUserLoginError($email, $password): ?string
    {
        $SL = ServiceLocator::getInstance();
        $entityFactory = $SL->getService(EntityFactory::class);
        /** @var UsersEntity $usersEntity */
        $usersEntity = $entityFactory->get(UsersEntity::class);
        
        $error = null;
        
        $userId = $usersEntity->checkPassword($email, $password);

        /*Валидация данных клиента*//*todo мож разделить проверку*/
        if (!$this->validator->isEmail($email, true) || empty($password) || !$userId) {
            $error = 'login_incorrect';
        } 

        return ExtenderFacade::execute(__METHOD__, $error, func_get_args());
    }
    
    public function getFeedbackValidateError($feedback): ?string
    {
        $captchaCode =  $this->request->post('captcha_code', 'string');
        
        $error = null;
        if (!$this->validator->isName($feedback->name, true)) {
            $error = 'empty_name';
        } elseif (!$this->validator->isEmail($feedback->email, true)) {
            $error = 'empty_email';
        } elseif (!$this->validator->isComment($feedback->message, true)) {
            $error = 'empty_text';
        } elseif ($this->settings->get('captcha_feedback') && !$this->validator->verifyCaptcha('captcha_feedback', $captchaCode)) {
            $error = 'captcha';
        }

        return ExtenderFacade::execute(__METHOD__, $error, func_get_args());
    }

    /**
     * Помилка оформлення з кошика.
     *
     * Ланки розширення бачать і змінюють лише помилку контактів (ім'я, email, телефон, коментар)
     * і можуть її зняти: модуль контактів робить email необов'язковим. Помилка
     * getCartSecurityError() (доставка, спосіб оплати, капча) рахується окремо й має перевагу,
     * тож ланка, що зняла помилку контактів, перевірки безпеки не вимикає.
     *
     * @param object $order результат CartRequest::postOrder()
     * @param object[] $cartDeliveries доставки, які кошик пропонує покупцеві: результат
     *        DeliveriesHelper::getCartDeliveriesList(). Без списку будь-яка доставка відхиляється.
     */
    public function getCartValidateError($order, array $cartDeliveries = []): ?string
    {
        $error = null;
        if (!$this->validator->isName($order->name, true) || !$this->validator->isName($order->last_name ?? '')) {
            $error = 'empty_name';
        } elseif (!$this->validator->isEmail($order->email, true)) {
            $error = 'empty_email';
        } elseif (!$this->validator->isPhone($order->phone)) {
            $error = 'empty_phone';
        } elseif (!$this->validator->isComment($order->comment)) {
            $error = 'empty_comment';
        }

        $contactError = ExtenderFacade::execute(__METHOD__, $error, func_get_args());

        return $this->getCartSecurityError($order, $cartDeliveries) ?? $contactError;
    }

    /**
     * Перевірки оформлення, яких ланки розширення не знімають: доставка з тих, що пропонує кошик,
     * спосіб оплати для цієї доставки, капча. Ланка може додати власну помилку, але помилку
     * ядра не прибере.
     *
     * Помилки доставки й способу йдуть текстом, а не кодом: теми знають лише свої коди, а невідому
     * помилку друкують як є. Без ключа в темі лишається код, бо порожня помилка означала б, що
     * перевірку пройдено.
     *
     * @param object[] $cartDeliveries див. getCartValidateError()
     */
    public function getCartSecurityError($order, array $cartDeliveries = []): ?string
    {
        $delivery = empty($order->delivery_id) ? null : self::offeredDelivery((int)$order->delivery_id, $cartDeliveries);

        $error = null;
        if (!empty($order->delivery_id) && $delivery === null) {
            $error = $this->frontTranslations->getTranslation('form_error_delivery') ?: 'delivery';
        } elseif (!empty($order->payment_method_id) && !$this->isPaymentMethodOffered($order, $delivery)) {
            $error = $this->frontTranslations->getTranslation('form_error_payment_method') ?: 'payment_method';
        } elseif ($this->settings->get('captcha_cart')
            && !$this->validator->verifyCaptcha('captcha_cart', $this->request->post('captcha_code', 'string'))
        ) {
            $error = 'captcha';
        }

        $extended = ExtenderFacade::execute(__METHOD__, $error, func_get_args());

        return $error ?? ($extended ?: null);
    }

    /**
     * Спосіб має бути і в списку, який кошик показав для цієї доставки, і повʼязаний із нею в базі:
     * правило сторінки замовлення (OrdersHelper::isPaymentMethodOffered()).
     */
    private function isPaymentMethodOffered(object $order, ?object $delivery): bool
    {
        $paymentMethodId = (int)$order->payment_method_id;
        $shown = array_map(intval(...), (array)($delivery->payment_methods_ids ?? []));

        return $delivery !== null
            && in_array($paymentMethodId, $shown, true)
            && $this->ordersHelper->isPaymentMethodOffered($order, $paymentMethodId);
    }

    /** Модулі можуть переставити ключі списку, тож збіг шукається за id доставки. */
    private static function offeredDelivery(int $deliveryId, array $cartDeliveries): ?object
    {
        foreach ($cartDeliveries as $delivery) {
            if (is_object($delivery) && (int)($delivery->id ?? 0) === $deliveryId) {
                return $delivery;
            }
        }

        return null;
    }

    public function getCallbackValidateError($callback): ?string
    {
        $captchaCode =  $this->request->post('captcha_code', 'string');
        
        $error = null;
        if (!$this->validator->isName($callback->name, true)) {
            $error = 'empty_name';
        } elseif (!$this->validator->isPhone($callback->phone, true)) {
            $error = 'empty_phone';
        } elseif (!$this->validator->isComment($callback->message)) {
            $error = 'empty_comment';
        } elseif ($this->settings->get('captcha_callback') && !$this->validator->verifyCaptcha('captcha_callback', $captchaCode)) {
            $error = 'captcha';
        }

        return ExtenderFacade::execute(__METHOD__, $error, func_get_args());
    }
    
    /**
     * @param object $comment
     * @param string|null $objectType 'product' вимагає оцінку: середній бал
     *        рахується з відгуків, тож відгук без зірок у нього не потрапляє
     *        і сторінка лишається без aggregateRating. У коментарях до статей
     *        оцінки немає взагалі.
     */
    public function getCommentValidateError($comment, $objectType = null): ?string
    {
        $captchaCode =  $this->request->post('captcha_code', 'string');

        $error = null;
        if (!$this->validator->isName($comment->name, true)) {
            $error = 'empty_name';
        } elseif ($objectType === 'product' && empty($comment->rating)) {
            $error = 'empty_rating';
        } elseif (!$this->validator->isComment($comment->text, true)) {
            $error = 'empty_comment';
        } elseif (!$this->validator->isEmail($comment->email)) {
            $error = 'empty_email';
        } elseif ($this->settings->get('captcha_comment') && !$this->validator->verifyCaptcha('captcha_comment', $captchaCode)) {
            $error = 'captcha';
        }

        return ExtenderFacade::execute(__METHOD__, $error, func_get_args());
    }
    
    public function getSubscribeValidateError($subscribe): ?string
    {
        $SL = ServiceLocator::getInstance();
        $entityFactory = $SL->getService(EntityFactory::class);
        /** @var SubscribesEntity $subscribesEntity */
        $subscribesEntity = $entityFactory->get(SubscribesEntity::class);
        
        $error = null;
        if (!$this->validator->isEmail($subscribe->email, true)) {
            $error = $this->frontTranslations->getTranslation('form_enter_email');
        } elseif ($subscribesEntity->count(['email' => $subscribe->email]) > 0) {
            $error = $this->frontTranslations->getTranslation('index_subscribe_already');
        }

        return ExtenderFacade::execute(__METHOD__, $error, func_get_args());
    }
}