# Delivery Zones on Map for WooCommerce — Free

Бесплатная версия плагина доставки WooCommerce по нарисованным зонам на карте.

## Free
- Яндекс.Карты и Яндекс Геокодер.
- До 3 зон доставки.
- Правила стоимости: сумма корзины от → стоимость доставки.
- Экспорт зон в JSON.
- Поддержка кастомных полей адреса checkout.
- Подсказка, пример корректного адреса и выпадающие Яндекс-подсказки в checkout.
- Ограничение поиска адресов рамками нарисованных зон доставки, чтобы короткие адреса не уводило в другой район.
- Базовое сообщение, если адрес находится вне зон доставки.

## Что вынесено из Free
Начиная с 1.4.1 рабочие Pro-обработчики физически вынесены из Free-ядра:
- Google Maps + Google Geocoding.
- Импорт зон из JSON.
- Диагностика адреса в админке.
- Неограниченное количество зон.
- Расширенный выбор провайдеров.

Free-ядро оставляет только безопасные хуки и UI-заглушки для Pro-дополнения.

## 1.4.11

- Усилена защита от повторной автоподстановки старого адреса после явной очистки поля: плагин теперь игнорирует программный возврат значения во время AJAX-пересчёта checkout.
- Если checkout всё же прислал флаг `ydzs_address_user_cleared` вместе со старым адресом, серверная часть считает адрес пустым и не рассчитывает доставку по зонам.
- После обновления checkout очистка выполняется в несколько проходов, чтобы кастомные шаблоны и сторонние скрипты не возвращали ранее выбранный адрес обратно в поле.

## 1.4.10

- Очистка адреса дополнительно фиксируется hidden-флагом `ydzs_address_user_cleared`, чтобы WooCommerce/кастомный checkout не подставлял старый сохранённый адрес обратно после AJAX-пересчёта.
- Подсказки адреса теперь запрашиваются не только при печати, но и при фокусе/клике по уже заполненному полю, а также после вставки адреса из буфера обмена.
- Для коротких адресов с сокращениями вроде `лесная ул 5` геокодер получает дополнительные варианты запроса: `лесная улица 5` и `лесная улица, 5`.
- Повторный клик по заполненному адресу заново открывает список подсказок, если варианты уже были найдены или запрос нужно повторить.

## 1.4.9

- Исправлена гонка AJAX-проверок адреса: после выбора подсказки сообщение сразу меняется на подтверждение адреса, без повторного выбора.
- Неподтверждённый вручную введённый адрес теперь не может оставить активной доставку по зонам за счёт старого адреса WooCommerce или кеша shipping rates.
- Hidden-поля подтверждённого адреса теперь корректно читаются из WooCommerce `post_data` при AJAX-пересчёте checkout.

## 1.4.8

- Очистка поля адреса теперь действительно очищает адрес: плагин больше не берёт старый `shipping_address_1` и не подставляет его обратно через секунду.
- Если адрес уже был заполнен при загрузке checkout, плагин автоматически запускает первичную проверку и после подтверждения адреса выбирает доставку по зонам, а не оставляет самовывоз по умолчанию.
- Неподтверждённый ручной адрес больше не синхронизируется в стандартные поля WooCommerce и не используется для расчёта доставки. Для коротких адресов вроде `лесная ул 5` доставка считается только после выбора точного варианта из подсказки.
- При подтверждённом адресе доставка по зонам автоматически выбирается после обновления checkout, если WooCommerce временно переключил метод на самовывоз.

## 1.4.7

- Подсказки адреса теперь фильтруются по номеру дома: если покупатель ввёл `Невельская улица 5`, в списке не показываются варианты без дома или с другим номером, например `СНТ ..., 1`.
- Для запросов без запятой перед номером дома плагин дополнительно отправляет в геокодер нормализованный вариант, например `Невельская улица, 5`.
- Серверный расчёт доставки тоже отбрасывает кандидаты без совпадающего номера дома, чтобы доставка не рассчиталась по центру улицы или по соседнему СНТ.
- Если нужный дом не найден в зонах доставки, покупатель видит понятное сообщение и должен уточнить адрес.

## 1.4.6

- Яндекс-подсказки на checkout заменены на собственный AJAX-список адресов под полем. Теперь список не зависит от виджета `ymaps.SuggestView` и лучше работает в кастомных шаблонах checkout.
- Ручной короткий адрес больше не подставляется автоматически в полный адрес из геокодера. Если покупатель ввёл `Невельская улица, 131`, плагин просит выбрать точный вариант из списка.
- Сообщения `not_found` и неоднозначного адреса стали мягче: без формулировок, которые могут восприниматься как просьба "перевести" адрес.
- Checkout по-прежнему использует защищённые координаты только после выбора точного адреса или после полного совпадения введённой строки с адресом геокодера.

## 1.4.5

- Добавлена живая AJAX-проверка выбранного адреса в checkout.
- Если адрес внутри зоны, покупатель видит подтверждение зоны доставки, а checkout получает защищённые координаты с серверным токеном.
- Если адрес вне зоны, helper сразу показывает понятное сообщение про самовывоз, не дожидаясь финальной отправки заказа.
- Если по короткому адресу найдено несколько вариантов внутри зон доставки, плагин не выбирает первый молча, а просит выбрать точный адрес из выпадающего списка.
- Выбранный адрес сохраняется в hidden-поля checkout и проверяется HMAC-токеном, чтобы расчёт не зависел от повторного угадывания строки адреса.

## 1.4.4

- Добавлено ограничение Яндекс-подсказок и серверного геокодирования рамками нарисованных зон доставки.
- Поле «Регион для коротких адресов» переименовано в дополнительный текстовый контекст: основным уточнением теперь служат сами полигоны доставки, а не общий регион вроде «Ленинградская область».
- Для коротких адресов плагин сначала ищет варианты в технической рамке зон, а если подходящего варианта внутри зоны не нашлось — делает резервный обычный запрос и всё равно выбирает кандидат, попадающий в полигон.

## 1.4.3

- Добавлен выпадающий список адресов Яндекс.Карт в checkout.
- Добавлена настройка региона/контекста для коротких адресов, например «Лесная улица, 5».
- Серверный геокодер теперь запрашивает до 10 вариантов и выбирает вариант, который попадает в нарисованную зону доставки.
- Сообщение ошибки просит выбрать точный адрес из подсказки.

## 1.4.2

- Добавлены настройки подсказки для корректного ввода адреса.
- В checkout плагин автоматически добавляет helper-текст под найденное поле адреса.
- Для пустого placeholder добавляется пример адреса.
- Если адрес введён без номера дома, показывается мягкое предупреждение.
- Сообщения серверной валидации адреса стали понятнее для покупателя.

## 1.4.1

- Добавлена кнопка «Сохранить текущий вид карты» над картой зоны.
- Центр карты и масштаб сохраняются AJAX-запросом после подтверждения координат.
- Поле «Центр карты» дополнительно валидируется по диапазонам широты/долготы.



## 1.4.24 — English localization

English PHP and JavaScript translations are bundled. Russian remains available.
Existing plugin directory and main file are unchanged.


## 1.4.30 — provider frontend hooks

- Added backward-compatible filters for provider add-ons to enable the existing checkout address suggestion and validation UI.
- Default Yandex behavior is unchanged.

## 1.4.31

- Address suggestions now use a 600 ms debounce after the customer pauses typing (filterable with `ydzs_address_suggest_delay_ms`).
- Google Pro settings now support separate browser and server API keys with backward compatibility for the legacy shared key.
- Address suggestion and delivery-area restriction help text is provider-neutral for Yandex/Google.

## 1.4.32

- Frontend helper and suggestion strings are localized on the PHP side, which also works reliably for the inline checkout script.
- Keeps the 600 ms suggestion debounce introduced in 1.4.31.

## 1.4.33

- Added an opt-in **Checkout address fields** section. Existing installations remain unchanged until **Simplify delivery address** is enabled.
- Added a validated fixed delivery country and independent visibility controls for Address line 2, City, State/County and Postcode. Address line 1 remains the Delivery Zones source field.
- Yandex geocoding now exposes normalized address components; the shared component contract is also used by Pro providers.
- Confirmed exact addresses can autofill the visible standard WooCommerce address fields in both Classic Checkout and Checkout Block.
- Checkout Block synchronization uses the WooCommerce cart data store and Store API instead of relying on Classic Checkout AJAX.
- Fields manually edited after autofill become user-owned and are not overwritten by later address selections.
- Corrupt or unknown stored country codes fail safe: the country is not fixed or hidden.


## 1.4.34

- Added the missing English translations for the Checkout address fields settings introduced in 1.4.33.
- Improved spacing and grouping of the Checkout address fields admin controls without forced CSS overrides.
- Checkout field behavior, address validation and autofill logic are unchanged from 1.4.33.


## 1.4.35

- The fixed delivery country selector is now searchable in the Delivery Zones settings.
- Search uses the canonical WooCommerce country name and ISO country code only; no aliases or duplicate country names are added.
- The selector uses WooCommerce SelectWoo and keeps the existing saved country code and checkout behavior unchanged.


## 1.4.36

- Fixed the searchable **Fixed delivery country** selector on the Delivery Zones admin page by using WooCommerce's native enhanced-select initialization.
- The country list still contains one canonical WooCommerce name per country; no aliases or duplicate country entries are added.
- Delivery calculations, checkout field settings and saved country codes are unchanged.


## 1.4.38

- Исправлен расчёт доставки на кастомных Classic Checkout, которые используют подтверждённый адрес Delivery Zones, но скрывают стандартные поля региона/индекса WooCommerce.
- Требования `state` и `postcode` снимаются только для адреса со статусом `inside` и корректным серверным HMAC-токеном Delivery Zones.
- Неподтверждённый, очищенный, находящийся вне зон или подменённый адрес не меняет штатную готовность WooCommerce к расчёту доставки.
- Обычные checkout без подтверждённого Delivery Zones адреса работают без изменений.

## 1.4.37

- Grouped all **Simplify delivery address** options into one visual admin card so country settings are clearly part of the same feature.
- Child field/country controls are visually dimmed and made inert while Simplify is disabled, then re-enabled immediately when the master checkbox is turned on.
- Child values are preserved while the UI is inactive; checkout behavior and saved settings are unchanged.
