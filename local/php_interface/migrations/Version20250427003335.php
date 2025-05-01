<?php

namespace Sprint\Migration;


class Version20250427003335 extends Version
{
    protected $author = "admin";

    protected $description = "Миграция почтового сообщения и шаблона письма уведомления об ошибке в компоненте";

    protected $moduleVersion = "5.0.0";

    /**
     * @throws Exceptions\HelperException
     * @return bool|void
     */
    public function up()
    {
        $helper = $this->getHelperManager();
        $helper->Event()->saveEventType('ERROR_SYPEXGEO_COMPONENT', array (
          'LID' => 'ru',
          'EVENT_TYPE' => 'email',
          'NAME' => 'Произошла ошибка в работе компонента aсe:checker.sypexgeo',
          'DESCRIPTION' => 'Шаблон для автоматической отправки электронного письма в случае ошибки компонента ace:checker.sypexgeo',
          'SORT' => '150',
        ));
        $helper->Event()->saveEventType('ERROR_SYPEXGEO_COMPONENT', array (
          'LID' => 'en',
          'EVENT_TYPE' => 'email',
          'NAME' => 'An error occurred in the ase component:checker.sypexgeo',
          'DESCRIPTION' => 'Template for automatically sending an email in case of an ace component error:checker.sypexgeo',
          'SORT' => '150',
        ));
        $helper->Event()->saveEventMessage('ERROR_SYPEXGEO_COMPONENT', array (
            'LID' => 's1',
            'ACTIVE' => 'Y',
            'EMAIL_FROM' => '#DEFAULT_EMAIL_FROM#',
            'SUBJECT' => 'Произошла ошибка в работе компонента',
            'EMAIL_TO' => '#EMAIL_TO#',
            'MESSAGE' => 'Информационное сообщение сайта "#SITE_NAME#"<br> На сервере: #SERVER_NAME# <br> В работе компонента aсe:checker.sypexgeo<br> Произошла следующая ошибка:<br> #MESSAGE#',
            'MESSAGE_PHP' => 'Информационное сообщение сайта "<?=$arParams["SITE_NAME"];?>"<br> На сервере: <?=$arParams["SERVER_NAME"];?> <br> В работе компонента aсe:checker.sypexgeo<br> Произошла следующая ошибка:<br> <?=$arParams["MESSAGE"];?>',
            'BODY_TYPE' => 'html',
        ));

    }
}
