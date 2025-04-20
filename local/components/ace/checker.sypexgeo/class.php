<?php
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Uri;
use \Bitrix\Main\Web\Json;
use Bitrix\Highloadblock\HighloadBlockTable;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

class GeoIPComponent extends CBitrixComponent
{
    private const HL_MODULE_NAME = 'highloadblock';
    private const SERVICE_URL = 'https://api.sypexgeo.net/json/';
    private const METHOD = 'GET';
    private const API_GET_PARAM = 'ip';
    private string $hlName = 'IpInfo';
    private int $cacheTime = 36000000;
    private \Bitrix\Main\ORM\Entity $hlEntity;
    // соотношение свойств HL к ключам данных сервиса
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
    private const SYSTEM_ERROR_CODE = 0;
    private const EMPTY_RESULT_ERROR_CODE = 1;
    private const INPUT_ERROR_CODE = 2;
    private const NETWORK_ERROR_CODE = 3;
    private const PUBLIC_ERROR_MESSAGES = [
        self::SYSTEM_ERROR_CODE => 'Ошибка системы.',
        self::EMPTY_RESULT_ERROR_CODE => 'Нет результатов.',
        self::INPUT_ERROR_CODE => 'Неверный формат.',
        self::NETWORK_ERROR_CODE => 'Ошибка сети.'
    ];

    /**
     * @throws \Bitrix\Main\LoaderException
     * @throws \Bitrix\Main\SystemException
     */
    private function initialize():void
    {
        // определяем необходимую константу адреса почты
        define('ERROR_EMAIL', $this->arParams['EMAIL']);
        // подключаем модуль HL и выбрасываем исключение если модуль не установлен
        if (!Loader::includeModule(self::HL_MODULE_NAME)) {
            throw new \Bitrix\Main\SystemException(
                'Не удалось подключить модуль "' . self::HL_MODULE_NAME . '".',
                self::SYSTEM_ERROR_CODE
            );
        }
        // сохраняем в свойство сущность HL
        $this->hlEntity = HighloadBlockTable::compileEntity($this->hlName);
    }

    /**
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\SystemException
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
            throw new \Bitrix\Main\SystemException($message, self::NETWORK_ERROR_CODE);
        }
        // получаем ответ
        $response = $client->getResponse();
        if ($response) {
            $statusCode = $response->getStatusCode();
            $reasonPhrase = $response->getReasonPhrase();
            // выбрасываем исключение если код ответа сервера не 200
            if ($statusCode !== 200) {
                $message = $statusCode . ': ' . $reasonPhrase . "\n";
                throw new \Bitrix\Main\SystemException($message, self::NETWORK_ERROR_CODE);
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
            throw new \Bitrix\Main\SystemException("Нет результатов.", self::EMPTY_RESULT_ERROR_CODE);
        }
        // если в ответе сервиса есть ошибка, выбросим исключение
        if($arData['error']) {
            throw new \Bitrix\Main\SystemException($arData['error'], self::NETWORK_ERROR_CODE);
        }
        return $arData;
    }

    /**
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    private function getDataFromHL(string $queryIP): bool|array
    {
        $entityDataClass = $this->hlEntity->getDataClass();
        $query = $entityDataClass::query();
        // поля для выборки устанавливаем из ключей константы сопоставлений
        $query->setSelect(array_keys(self::FIELD_RELATIONS))
            ->setFilter(['UF_IP_ADDRESS' => $queryIP])
            ->setCacheTtl($this->cacheTime);
        return $query->exec()->fetch();
    }

    /**
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\SystemException
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

    private function prepareApiDataForHL(array $arApiData): array
    {
        // агрегация ответа сервиса по необходимым свойствам
        $arResult = [];
        foreach (self::FIELD_RELATIONS as $key => $value) {
            if(!is_array($value)) {
                $arResult[$key] = (string) $arApiData[$value];
            } else {
                foreach ($value as $k => $v) {
                    $arResult[$key] =  (string) $arApiData[$k][$v];
                }
            }
        }
        return $arResult;
    }

    private static function sendErrorMessage(string $message): void
    {
        // отправка письма на email инструментами Битрикс
        SendError("[" . $message . "]\n\n");
    }

    /**
     * @throws \Bitrix\Main\SystemException
     */
    private function validateIP(string $queryIP): void
    {
        // проверка клиентского параметра по регулярному выражению
        $pattern = "/^(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/";
        $isValid = preg_match($pattern, $queryIP) === 1;
        // выбрасываю исключение на случай если каким-то образом не сработает валидация на фронте
        if (!$isValid) {
            throw new \Bitrix\Main\SystemException('Введён неверный IP адрес', self::INPUT_ERROR_CODE);
        }
    }
    private function prepareErrorForClient(int $code): array
    {
        // Установим текст сообщения для клиента. В зависимости от кода
        return ['CODE' => $code, 'MESSAGE' => self::PUBLIC_ERROR_MESSAGES[$code]];
    }

    private function getResult(): array
    {
        // основная работа компонента
        $arResult = [];
        try {
            // аналог конструктора
            $this->initialize();
            // текущий контекст запроса
            $request = Application::getInstance()->getContext()->getRequest();
            // получаем клиентский запрос из get
            $strQuery = $request->getQuery(self::API_GET_PARAM);
            // если был передан параметр запроса
            if($strQuery) {
                // валидация клиентского запроса
                $this->validateIP($strQuery);
                // если есть запись в HL
                $hlData = $this->getDataFromHL($strQuery);
                if ($hlData) {
                    // отдаем клиенту
                    $arResult['ITEMS'] = $hlData;
                } else {
                    // иначе идём в сервис
                    $arApiData = $this->getGeoIPData($strQuery);
                    // подготавливаем ответ для сохранения
                    $newData = $this->prepareApiDataForHL($arApiData);
                    // сохраняем в HL
                    $this->addDataToHL($newData);
                    // отдаём клиенту
                    $arResult['ITEMS'] = $newData;
                }
            }
            // отлавливаем сначала LoaderException по приоритету
        } catch (\Bitrix\Main\LoaderException | \Bitrix\Main\SystemException $e) {
            // отдаём ошибку клиенту если код нас устраивает
            $arResult['ERROR'] = $this->prepareErrorForClient($e->getCode());
            // отправляем сообщение об ошибке на почту
            self::sendErrorMessage($e->getMessage());
        }
        return $arResult;
    }

    public function onPrepareComponentParams($arParams): array
    {
        return $arParams;
    }

    public function executeComponent(): void
    {
        // собственно, исполнение компонента
        $this->arResult = $this->getResult();
        $this->includeComponentTemplate();
    }

}
