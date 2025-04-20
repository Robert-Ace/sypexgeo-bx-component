<?php if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true) die();
$email = COption::GetOptionString("main", "email_from");
$arComponentParameters = [
    "GROUPS" => [],
    "PARAMETERS" => [
        "EMAIL" => [
            "PARENT" => "BASE",
            "NAME" => "Email для уведомлений об ошибках.",
            "TYPE" => "STRING",
            "MULTIPLE" => "N",
            "DEFAULT" => $email,
        ],
    ],
];
?>
