// Состояние компонента
const state = {
    form: {
        value: "",          // Значение поля для поиска
        valid: false,       // Правильно ли заполнено поле
        errors: [],         // Сообщения об ошибке ввода
    },
    result: {
        data: {},           // Объект результата
        errors: [],         // Сообщения об ошибке в работе
        status: ""          // Статус выполнения запроса
    }
};

const input = document.querySelector(".search-ip__form__input");
const submit = document.querySelector(".search-ip__form__button");
const errorMessage = document.querySelector(".search-ip__form__error-message");
const container = document.querySelector('.search-ip__result');

// Валидация значения поля ввода по регулярному выражению
const validate = (value) => {
    if (/^(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/.test(value)) {
        return { valid: true, errors: [] };
    }
    return { valid: false, errors: ["Неправильный формат"] };
};
const render = () => {
    const { value, valid, errors } = state.form;
    input.value = value;
    // Если не валидный ввод - disabled
    submit.disabled = !valid;
    // Скрываю кнопку поиска если значение в поле поиска невалидное
    submit.classList.toggle("search-ip__form__button--hidden", !valid);
    // Сокрытие кнопки поиска если значение не валидное
    input.classList.toggle("search-ip__form__input--error", !valid);
    input.classList.toggle("search-ip__form__input--bordered-right", !valid);
    // Наполнение элемента errorMessage сообщением о неправильном вводе
    errorMessage.textContent = errors.join(", ");
};

// Отправка ajax-запроса к контроллеру компонента с обработкой ответа
const getData = () => {
    BX.ajax.runComponentAction("ace:checker.sypexgeo", "check", {
        mode: "class",
        data: {
            "ip": state.form.value,
        },
    }).then(
        (response) => {
            // Отрисовка результата
            renderItems(response.data);
    },
        (response) => {
            // Отрисовка первой ошибки
            renderErrors(response.errors[0]['message'], response.errors[0]['code']);
    });
}

const renderItems = (data = {}) => {
    // Полностью очищаем контейнер результатов
    container.innerHTML = "";
    // Создаём обёртку для результата
    const wrapper =  document.createElement('div');
    wrapper.className = 'search-ip__result__items';
    // В цикле создаём элементы перебрав объект ответа
    for (const key in data) {

        const newItem = document.createElement('div');
        newItem.className = 'search-ip__result__item';

        const itemTitle = document.createElement('span');
        itemTitle.className = 'search-ip__result__item-title';
        itemTitle.textContent = key;

        const itemValue = document.createElement('span');
        itemValue.className = 'search-ip__result__item-value';
        itemValue.textContent = data[key];
        // Добавляем в блок элемента title и value
        newItem.append(itemTitle, itemValue);
        // Добавляем блок элемента в обёртку
        wrapper.append(newItem);
    }
    // Когда все данные обработаны - добавляем обёртку в контейнер
    container.append(wrapper);
}

const renderErrors = (message = '', code = 0) => {
    // Полностью очищаем контейнер результатов
    container.innerHTML = "";
    // Контейнер для ошибки
    const newError = document.createElement('div');
    newError.className = 'search-ip__result__error';
    // Изображение для ошибки
    const img = document.createElement('img');
    // Код ошибки подставляем в название файла изображения
    img.src = `/local/components/ace/checker.sypexgeo/templates/icons/error-${code}.svg`;
    img.className = 'search-ip__result__error-icon';
    // Добавление текста ошибки
    const text = document.createElement('span');
    text.className = 'search-ip__result__error-message';
    text.textContent = message;
    // Добавление изображения и сообщения об ошибке в обёртку ошибки
    newError.append(img, text);
    // Добавление Блока ошибки в контейнер результата
    container.append(newError);
}

// Основная работа после полной загрузки страницы
window.onload = () => {
    // Первоначальная отрисовка при загрузке страницы
    render();
    // При событии ввода в поле для поиска - изменяем состояние, Проверяем правильность и перерисовываем
    input.addEventListener("input", (e) => {
        state.form.value = e.target.value;
        Object.assign(
            state.form,
            validate(state.form.value),
        );
        // Последующие отрисовки при каждом событии ввода
        render();
    });
    // При клике на кнопку поиска
    submit.addEventListener("click", (e) => {
        // Отменяем обычное поведение кнопки формы
        e.preventDefault();
        // Отправляем запрос на бэк
        getData();
    });
};




