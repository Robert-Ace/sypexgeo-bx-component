<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Uri;
use Bitrix\Main\Web\Json;
use Bitrix\Highloadblock\HighloadBlockTable;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Mail\Event;
use Bitrix\Main\Config\Option;

class SypexgeoComponent extends CBitrixComponent implements Controllerable
{
    private const HL_MODULE_NAME = 'highloadblock';
    private const SERVICE_URL = 'https://api.sypexgeo.net/json/';
    private const METHOD = 'GET';
    private const API_GET_PARAM = 'ip';
    private string $hlName = 'IpInfo';
    private int $cacheTime = 36000000;
    private \Bitrix\Main\ORM\Entity $hlEntity;

    /**
     * Соотношение свойств HL блока к ключам данных ответа сервиса.
     * Используется для сохранения новых данных и для выборки из БД.
     * Ответ используемого сервиса имеет следующий формат:
     * {'ip': 123,'city': {'id': 321, 'name_ru': 'Саратов', 'name_en': 'Saratov'}}
     */
    private const FIELD_RELATIONS = [
        'UF_IP_ADDRESS' => 'ip',
        'UF_CITY_ID' => ['city' => 'id'],
        'UF_CITY_NAME_RU' => ['city' => 'name_ru'],
        'UF_CITY_NAME_EN' => ['city' => 'name_en'],
        'UF_REGION_ID' => ['region' => 'id'],
        'UF_REGION_NAME_RU' => ['region' => 'name_ru'],
        'UF_REGION_NAME_EN' => ['region' => 'name_en'],
        'UF_COUNTRY_ID' => ['country' => 'id'],
        'UF_COUNTRY_NAME_RU' => ['country' => 'name_ru'],
        'UF_COUNTRY_NAME_EN' => ['country' => 'name_en']
    ];

    private const SYSTEM_ERROR_CODE = '0';
    private const EMPTY_RESULT_ERROR_CODE = '1';
    private const INPUT_ERROR_CODE = '2';
    private const NETWORK_ERROR_CODE = '3';

    /**
     * Массив текста упрощённых сообщений по отношению к коду ошибки.
     * Используется для отдачи клиенту и отправки на почту в упрощённом виде
     */
    private const PUBLIC_ERROR_MESSAGES = [
        self::SYSTEM_ERROR_CODE => 'Ошибка системы.',
        self::EMPTY_RESULT_ERROR_CODE => 'Нет результатов.',
        self::INPUT_ERROR_CODE => 'Неверный формат.',
        self::NETWORK_ERROR_CODE => 'Ошибка сети.'
    ];

    /**
     * Адрес получателя писем об ошибках
     * Так же используется для установки глобальной константы, которую требует функция SendError()
     */
    private string $email = '';

    public function configureActions(): array
    {
        return [
            'check' => [
                'prefilters' => [
                    new \Bitrix\Main\Engine\ActionFilter\Csrf(),
                ]
            ]
        ];
    }

    /**
     * @throws LoaderException
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function checkAction($ip)
    {
//        throw new SystemException(
//            'Видимо, что-то случилось...',
//            self::EMPTY_RESULT_ERROR_CODE
//        );
        return $this->getResult($ip);
    }

    /**
     * @throws LoaderException
     * @throws SystemException
     */
    private function initialize():void
    {
        // если константа адреса получателя писем об ошибках не была установлена ранее
        if(!defined('ERROR_EMAIL')) {
            // установим из настроек сайта
            $this->email = Option::get("main", "email_from");
            // определяем необходимую константу адреса почты для отправки развёрнутой ошибки
            define('ERROR_EMAIL', $this->email);
        }
        // подключаем модуль HL и выбрасываем исключение если модуль не установлен
        if (!Loader::includeModule(self::HL_MODULE_NAME)) {
            // отправляем на почту оригинал ошибки
            $this->sendErrorMessage('Не удалось подключить модуль "' . self::HL_MODULE_NAME . '".');
            //выбрасываем клиенту изменённое сообщение
            throw new SystemException(
                self::replaceErrorMessageForClient(self::SYSTEM_ERROR_CODE),
                self::SYSTEM_ERROR_CODE
            );
        }
        // сохраняем в свойство сущность HL
        $this->hlEntity = HighloadBlockTable::compileEntity($this->hlName);
    }

    /**
     * @throws SystemException
     */
    private function getGeoIPData(string $userIP): array
    {
        // объект uri
        $uri = new Uri(self::SERVICE_URL . $userIP);
        // инициация клиента
        $client = new HttpClient();
        // выполнение запроса
        $isSuccess = $client->query(self::METHOD, $uri);
        // выбрасываем исключение в случае ошибки http-запроса
        if (!$isSuccess) {
            $arrHttpError = $client->getError();
            $message = '';
            foreach ($arrHttpError as $key => $value) {
                $message .= $key . ': ' . $value . "\n";
            }
            // отправляем на почту оригинал ошибки
            $this->sendErrorMessage($message);
            //выбрасываем клиенту изменённое сообщение
            throw new SystemException(
                self::replaceErrorMessageForClient(self::NETWORK_ERROR_CODE),
                self::NETWORK_ERROR_CODE
            );
        }
        // получаем ответ
        $response = $client->getResponse();
        if ($response) {
            $statusCode = $response->getStatusCode();
            $reasonPhrase = $response->getReasonPhrase();
            // выбрасываем исключение если код ответа сервера не 200
            if ($statusCode !== 200) {
                $message = $statusCode . ': ' . $reasonPhrase . "\n";
                // отправляем на почту оригинал ошибки
                $this->sendErrorMessage($message);
                //выбрасываем клиенту изменённое сообщение
                throw new SystemException(
                    self::replaceErrorMessageForClient(self::NETWORK_ERROR_CODE),
                    self::NETWORK_ERROR_CODE
                );
            }
        }
        // получение результата
        $strData = $client->getResult();
        // преобразование результата к массиву
        $arData = Json::decode($strData);
        // если нет данных о городе, выбрасываем исключение
        if (!isset($arData['country']) || empty($arData['country']['id'])) {
            // код ошибки зададим отличный от других ошибок для использования на стороне клиента, например
            // ибо это просто пустой результат как для 192.168.0.1
            throw new SystemException("Нет результатов.", self::EMPTY_RESULT_ERROR_CODE);
        }
        // если в ответе сервиса есть ошибка
        if ($arData['error']) {
            // отправляем на почту оригинал ошибки
            $this->sendErrorMessage($arData['error']);
            //выбрасываем клиенту изменённое сообщение
            throw new SystemException(
                self::replaceErrorMessageForClient(self::NETWORK_ERROR_CODE),
                self::NETWORK_ERROR_CODE
            );
        }
        return $arData;
    }

    /**
     * Выборка из HL блока ограничивается ключами массива сопоставлений FIELD_RELATIONS
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    private function getDataFromHL(string $queryIP): bool|array
    {
        $entityDataClass = $this->hlEntity->getDataClass();
        $query = $entityDataClass::query();
        // поля для выборки устанавливаем из ключей константы сопоставлений
        $fields = array_keys(self::FIELD_RELATIONS);
        $query->setSelect($fields)
            ->setFilter(['UF_IP_ADDRESS' => $queryIP])
            ->setCacheTtl($this->cacheTime);
        return $query->exec()->fetch();
    }

    /**
     * Запись уже подготовленных данных в HL блок
     * @throws ArgumentException
     * @throws SystemException
     */
    private function addDataToHL(array $arData): void
    {
        $entity = $this->hlEntity;
        $valuesCollection = $entity->createCollection();
        $collectionObj = $entity->createObject();
        foreach ($arData as $field => $value) {
            $collectionObj->set($field, $value);
        }
        $valuesCollection->add($collectionObj);
        $result = $valuesCollection->save(true);
        $result->isSuccess();
    }

    /**
     * Агрегация и подготовка данных ответа сервиса используя массив соотношений.
     * @param array $arApiData
     * @return array
     */
    private function prepareApiDataForHL(array $arApiData): array
    {
        $arResult = [];
        // Проходимся по каждому соотношению.
        foreach (self::FIELD_RELATIONS as $hlField => $serviceField) {
            // Если ключ для сервиса не массив - получим значение сразу по ключу.
            if(!is_array($serviceField)) {
                $arResult[$hlField] = (string) $arApiData[$serviceField];
            // Иначе получим доступ к значению перебрав составной ключ (он же массив).
            } else {
                foreach ($serviceField as $serviceParentField => $serviceChildField) {
                    $arResult[$hlField] =  (string) $arApiData[$serviceParentField][$serviceChildField];
                }
            }
        }
        return $arResult;
    }

    /**
     * Проверка клиентского ввода по регулярному выражению.
     * @throws SystemException
     */
    private function validateIP(string $queryIP): void
    {
        $pattern = "/^(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/";
        $isValid = preg_match($pattern, $queryIP) === 1;
        // Если каким-то образом не сработает валидация на фронте.
        if (!$isValid) {
            // Отправляем на почту оригинал ошибки.
            $this->sendErrorMessage("Введён неверный IP адрес: $queryIP");
            // Выбрасываем клиенту изменённое сообщение.
            throw new SystemException(
                self::replaceErrorMessageForClient(self::INPUT_ERROR_CODE),
                self::INPUT_ERROR_CODE);
        }
    }

    /**
     * Замена текста сообщения в зависимости от кода ошибки.
     * @param int $code
     * @return string
     */
    private static function replaceErrorMessageForClient(int $code): string
    {
        return self::PUBLIC_ERROR_MESSAGES[$code];
    }

    /**
     * Отправка письма с описанием ошибки.
     * @param string $message
     * @return void
     */
    private function sendErrorMessage(string $message): void
    {
        // Встроенная функция ядра. Шаблон не требуется, но необходима константа ERROR_EMAIL.
        SendError("[" . $message . "]\n\n");
        // Требуется почтовое событие ERROR_SYPEXGEO_COMPONENT и шаблон с полями #EMAIL_TO# #MESSAGE#.
        // Необходимо развернуть миграцию или создать самостоятельно.
        Event::send(
            [
                "EVENT_NAME" => "ERROR_SYPEXGEO_COMPONENT",
                "LID" => SITE_ID,
                "C_FIELDS" => [
                    "EMAIL_TO" => $this->email,
                    "MESSAGE" => $message
                ],
            ]
        );
    }

    /**
     * Получение результата.
     * @throws ObjectPropertyException
     * @throws LoaderException
     * @throws ArgumentException
     * @throws SystemException
     */
    private function getResult(string $strQuery): ?array
    {
        $arResult = null;
        // Аналог конструктора.
        $this->initialize();
        // Валидация клиентского запроса.
        $this->validateIP($strQuery);
        // Если есть запись в HL.
        $hlData = $this->getDataFromHL($strQuery);
        if ($hlData) {
            // Отдаем клиенту.
            $arResult = $hlData;
        } else {
            // Иначе идём в сервис.
            $arApiData = $this->getGeoIPData($strQuery);
            // Подготавливаем ответ для сохранения
            $newData = $this->prepareApiDataForHL($arApiData);
            // Сохраняем в HL.
            $this->addDataToHL($newData);
            // Отдаём клиенту.
            $arResult = $newData;
        }
        return $arResult;
    }

    public function executeComponent(): void
    {
        $this->includeComponentTemplate();
    }

}
