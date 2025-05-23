<?php if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();
/**
 * @var array $arResult
 * @var string $componentPath
 */

?>

<div class="search-ip">
    <form class="search-ip__form">
        <label for="search-ip-field-ip"></label>
        <div class="search-ip__form__error-message"></div>
        <div class="search-ip__form__group">
            <input type="text" name="ip" class="search-ip__form__input" id="search-ip-field-ip" value="" placeholder="Поиск по IP-адресу" maxlength="14" required />
            <button type="submit" class="search-ip__form__button" disabled>
                <svg class="search-ip__form__icon" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"
                     viewBox="0 0 512 512" xml:space="preserve">
                    <g>
                        <path d="M497.938,430.063l-126.914-126.91C389.287,272.988,400,237.762,400,200C400,89.719,310.281,0,200,0
                            C89.719,0,0,89.719,0,200c0,110.281,89.719,200,200,200c37.762,0,72.984-10.711,103.148-28.973l126.914,126.91
                            C439.438,507.313,451.719,512,464,512c12.281,0,24.563-4.688,33.938-14.063C516.688,479.195,516.688,448.805,497.938,430.063z
                             M64,200c0-74.992,61.016-136,136-136s136,61.008,136,136s-61.016,136-136,136S64,274.992,64,200z"/>
                    </g>
                </svg>
            </button>
        </div>
    </form>

    <div class="search-ip__result"></div>
</div>
