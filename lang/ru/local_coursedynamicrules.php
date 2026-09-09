<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Plugin strings are defined here.
 *
 * @package     local_coursedynamicrules
 * @category    string
 * @copyright   2025 Wilber Narvaez <https://datacurso.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['actions'] = 'Действия';
$string['actions_help'] = 'Действия используются для определения того, что будет выполняться при соблюдении условий правила';
$string['addactions'] = 'Добавить действия';
$string['addconditions'] = 'Добавить условия';
$string['after'] = 'После';
$string['allcourseactivitymodules'] = 'Все модули активности курса';
$string['availableplaceholders'] = 'Доступные плейсхолдеры';
$string['backtolistrules'] = 'Назад к списку правил';
$string['basedate'] = 'Базовая дата';
$string['basedate_help'] = 'Выберите опорную дату для оценки неактивности:

* **С даты зачисления**: Рассчитывается с момента зачисления пользователя.
* **С даты начала курса**: Рассчитывается с даты начала курса.
* **С момента активации правила**: Отсчитывается с момента первой активации правила. Приостановка и повторная активация правила не сбрасывают отсчёт.';
$string['before'] = 'До';
$string['checklicensekey'] = 'Проверить лицензионный ключ';
$string['complete_activity'] = 'Активность завершена';
$string['complete_activity_condition_info'] = 'Это условие проверит, какой пользователь завершил выбранный модуль активности.';
$string['complete_activity_description'] = 'Пользователи, завершившие модуль активности «{$a->moddescription}»';
$string['completiondate'] = 'Дата завершения';
$string['componenttargetmissing'] = 'Выбранная активность больше не доступна в этом курсе, поэтому этот компонент никогда не сработает.';
$string['conditions'] = 'Условия';
$string['conditions_help'] = 'Условия определяют критерии, которые должны быть выполнены для запуска действий правила';
$string['copiedtoclipboard'] = 'Скопировано в буфер обмена';
$string['copytoclipboard'] = 'Копировать в буфер обмена';
$string['course_inactivity'] = 'Неактивность в курсе по временным интервалам';
$string['course_inactivity_custom_description'] = 'Пользователи без активности в курсе в течение интервалов {$a->intervals} {$a->unit}, начиная с {$a->basedate}';
$string['course_inactivity_info'] = 'Это условие проверит пользователей, у которых не было активности в курсе в указанные временные интервалы.';
$string['course_inactivity_recurring_description'] = 'Пользователи без активности в курсе с повторяющимися интервалами {$a->intervals} {$a->unit}, начиная с {$a->basedate}';
$string['course_inactivity_task'] = 'Задача неактивности в курсе';
$string['coursedynamicrules:createaction'] = 'Создавать действия';
$string['coursedynamicrules:createcondition'] = 'Создавать условия';
$string['coursedynamicrules:createrule'] = 'Создавать правила';
$string['coursedynamicrules:deleteaction'] = 'Удалять действия';
$string['coursedynamicrules:deletecondition'] = 'Удалять условия';
$string['coursedynamicrules:deleterule'] = 'Удалять правила';
$string['coursedynamicrules:manageaction'] = 'Управлять действиями';
$string['coursedynamicrules:managecondition'] = 'Управлять условиями';
$string['coursedynamicrules:managerule'] = 'Управлять правилами';
$string['coursedynamicrules:notification'] = 'Отправить уведомление';
$string['coursedynamicrules:updateaction'] = 'Обновлять действия';
$string['coursedynamicrules:updatecondition'] = 'Обновлять условия';
$string['coursedynamicrules:updaterule'] = 'Обновлять правила';
$string['coursedynamicrules:viewaction'] = 'Просматривать действия';
$string['coursedynamicrules:viewcondition'] = 'Просматривать условия';
$string['coursedynamicrules:viewrule'] = 'Просматривать правила';
$string['courselink'] = 'Ссылка на курс';
$string['coursemoduleelementnotfound'] = 'Элемент выбора модуля активности курса не найден.';
$string['coursename'] = 'Название курса';
$string['coursestartdate'] = 'Дата начала курса';
$string['createaiactivity'] = 'Создать задание‑поддержку с ИИ';
$string['createaiactivity_action_info'] = 'Это действие запросит у сервиса Datacurso AI создание персонализированного задания‑поддержки для пользователей, соответствующих условиям правила.';
$string['createaiactivity_beforemod'] = 'Разместить перед активностью';
$string['createaiactivity_beforemod_help'] = 'Выберите активность, перед которой следует разместить новый ресурс, или оставьте значение по умолчанию, чтобы добавить его в конец раздела.';
$string['createaiactivity_beforemod_none'] = 'Не размещать перед другой активностью';
$string['createaiactivity_description'] = 'Сгенерировать задание‑поддержку с ИИ в разделе «{$a->section}» с использованием запроса «{$a->prompt}»';
$string['createaiactivity_generateimages'] = 'Генерировать изображения с помощью ИИ';
$string['createaiactivity_generateimages_label'] = 'Разрешить ИИ включать сгенерированные изображения, если поддерживается.';
$string['createaiactivity_placeholders_info'] = 'Доступные плейсхолдеры: <code>{$a->coursename}</code>, <code>{$a->courseurl}</code>, <code>{$a->fullname}</code>, <code>{$a->firstname}</code>, <code>{$a->lastname}</code>.';
$string['createaiactivity_prompt'] = 'Запрос ИИ';
$string['createaiactivity_prompt_help'] = 'Напишите инструкцию, которая будет отправлена в сервис ИИ. Можно включать плейсхолдеры, которые будут заменены перед отправкой.';
$string['createaiactivity_section'] = 'Раздел курса';
$string['createrule'] = 'Создать правило';
$string['customintervals'] = 'Пользовательские интервалы';
$string['customintervals_help'] = 'Введите числа, разделённые запятыми, обозначающие периоды неактивности (например, «7,14,30»).';
$string['datacurso'] = 'Datacurso';
$string['date_from_course_start'] = 'С даты начала курса';
$string['date_from_enrollment'] = 'С даты зачисления';
$string['date_from_now'] = 'С момента активации правила';
$string['days'] = 'Дни';
$string['deleteactioncheck'] = 'Вы действительно хотите полностью удалить это действие?';
$string['deletecondition'] = 'Удалить условие';
$string['deleteconditioncheck'] = 'Вы действительно хотите полностью удалить это условие?';
$string['deletedaction'] = 'Действие удалено <b>{$a}</b>';
$string['deletedcondition'] = 'Условие удалено <b>{$a}</b>';
$string['deletedrule'] = 'Правило удалено <b>{$a}</b>';
$string['deleterule'] = 'Удалить правило';
$string['deleterulecheck'] = 'Вы действительно хотите полностью удалить это правило?';
$string['deletingcondition'] = 'Удаление условия "{$a}"';
$string['deletingrule'] = 'Удаление правила "{$a}"';
$string['description'] = 'Описание';
$string['editactions'] = 'Редактировать действия';
$string['editconditions'] = 'Редактировать условия';
$string['editrule'] = 'Редактировать правило';
$string['enableactivity'] = 'Включить активность';
$string['enableactivity_action_info'] = 'Это действие включит выбранные модули активности для пользователей, которые соответствуют критериям правила.';
$string['enableactivity_alreadygated'] = 'Другое действие уже открывает «{$a}» — в этом правиле или в другом. Два действия не могут открывать одну и ту же активность: Moodle требует, чтобы оба открыли её для студента, поэтому добавление здесь закроет её для студентов, которым её открыло другое действие. Выберите другую активность. Освободить её от другого действия можно только пока правило того действия ни разу не активировалось.';
$string['enableactivity_description'] = 'Включить активности "{$a}"';
$string['enableactivity_noactivities'] = 'Открыть активности: пока ни одна не выбрана';
$string['enablegradegreaterthanorequal_help'] = 'Включить оценку больше либо равную';
$string['enablegradelessthan'] = 'Включить оценку меньше чем';
$string['enrollmentdate'] = 'Дата зачисления';
$string['errorgradeoutofrange'] = 'Значение должно быть между {$a->min} и {$a->max}.';
$string['errormaxgradeexceeded'] = 'Оценка не может превышать максимальную оценку для активности.';
$string['errornegativegrade'] = 'Оценка должна быть 0 или больше.';
$string['errornocoursemodule'] = 'Вы должны выбрать активность из этого курса.';
$string['expectedcompletiondate'] = 'Ожидаемая дата завершения';
$string['firstname'] = 'Имя пользователя';
$string['fullname'] = 'Полное имя пользователя';
$string['generalsettings'] = 'Общие настройки';
$string['grade'] = 'Оценка';
$string['grade_in_activity'] = 'Оценка в активности';
$string['grade_in_activity_condition_info'] = 'Это условие проверит, какой пользователь получил указанную оценку в выбранном модуле активности.';
$string['grade_in_activity_description'] = 'Для "{$a->moddescription}" необходимо получить следующие оценки: {$a->gradestring}';
$string['gradegreaterthanorequal'] = 'должна быть &#x2265;';
$string['gradegreaterthanorequal_help'] = 'Условие выполняется, если оценка пользователя больше либо равна указанному значению.';
$string['gradegreaterthanorequalvalue'] = '&#x2265; {$a}';
$string['gradelessthan'] = 'должна быть <';
$string['gradelessthan_help'] = 'Условие выполняется, если оценка пользователя меньше указанного значения.';
$string['gradelessthanvalue'] = '< {$a}';
$string['hours'] = 'Часы';
$string['intervaltype'] = 'Тип интервала';
$string['intervaltype_help'] = 'Выберите, как будет оцениваться интервал:

* **Пользовательские интервалы**: чтобы добавить значения через запятую (например, 7,14,30) для оценки неактивности в конкретные моменты времени.
* **Повторяющийся интервал**: чтобы оценивать неактивность через повторяющиеся интервалы (например, каждые 7 дней).';
$string['intervalunit'] = 'Единица времени';
$string['intervalunit_help'] = 'Выберите единицу времени для интервалов.';
$string['invalidbasedate'] = 'Недопустимый тип базовой даты {$a}';
$string['invalidruleid'] = 'Недопустимый ID правила';
$string['lastname'] = 'Фамилия пользователя';
$string['licensekey'] = 'Лицензионный ключ';
$string['licensekey_desc'] = 'Лицензионный ключ, необходимый для использования этого плагина';
$string['licensekeycompany'] = 'Лицензионный ключ для: {$a}';
$string['licensekeycompany_desc'] = 'Лицензионный ключ, необходимый для использования этого плагина для компании: {$a}';
$string['licensekeyinvalid'] = 'Лицензионный ключ истёк или недействителен. Перейдите на <a href="https://shop.datacurso.com/clientarea.php" target="_blank">Shop Datacurso</a>, чтобы продлить или приобрести новый ключ.';
$string['licensekeyvalid'] = 'Лицензионный ключ действителен';
$string['messagebody'] = 'Текст';
$string['messagebody_help'] = 'В сообщение можно включить следующие плейсхолдеры:

* Название курса {$a->coursename}
* Полное имя пользователя {$a->fullname}
* Имя пользователя {$a->firstname}
* Фамилия пользователя {$a->lastname}
* Название модуля активности курса {$a->modulename}
* Название экземпляра модуля активности курса {$a->moduleinstancename}';
$string['messageprovider:smart_rules_ai_notification'] = 'Уведомление Smart Rules AI';
$string['messagesubject'] = 'Тема';
$string['minutes'] = 'Минуты';
$string['missing_plugins_warning'] = '🔔 Улучшите ваши уведомления! Наши плагины <strong>Datacurso Message Hub</strong> позволяют отправлять уведомления через WhatsApp и SMS с использованием провайдеров, таких как Twilio.
<br>
<a href="https://shop.datacurso.com/clientarea.php" target="_blank">Нажмите здесь, чтобы приобрести и включить их сейчас!</a>';
$string['moduleinstancename'] = 'Название экземпляра модуля активности курса';
$string['modulename'] = 'Название модуля активности курса';
$string['months'] = 'Месяцы';
$string['mustselectonerole'] = 'Вы должны выбрать как минимум одну роль.';
$string['name'] = 'Название';
$string['no_complete_activity'] = 'Активность не завершена';
$string['no_complete_activity_condition_info'] = 'Это условие проверит, какой пользователь не завершил выбранный модуль активности после указанной даты.';
$string['no_complete_activity_description'] = 'Пользователи, которые не завершили модуль активности «{$a->moddescription}» после {$a->expectedcompletiondate}';
$string['no_complete_activity_task'] = 'Задача незавершенной активности';
$string['no_course_access'] = 'Нет доступа к курсу';
$string['no_course_access_condition_info'] = 'Это условие проверит, какие пользователи не заходили на этот курс в течение указанного периода.';
$string['no_course_access_description'] = 'Пользователи, которые более чем {$a->periodvalue} {$a->periodunit} не заходят на этот курс.';
$string['no_course_access_task'] = 'Задача отсутствия доступа к курсу';
$string['notification_action_info'] = 'Это действие отправит уведомление пользователям, соответствующим критериям правила.';
$string['now'] = 'Момента активации правила';
$string['passgrade'] = 'Завершение активности с проходной оценкой';
$string['passgrade_condition_info'] = 'Это условие проверит, какой пользователь завершил выбранный модуль активности с проходной оценкой.';
$string['passgrade_description'] = 'Пользователи, завершившие модуль активности «{$a}» с проходной оценкой';
$string['period'] = 'Период';
$string['period_help'] = 'Минимальное время, в течение которого пользователь не должен заходить на курс.';
$string['plugin_disabled'] = 'Для этого действия требуется, чтобы плагин <strong>{$a->pluginname}</strong> был включен. Перейдите на страницу <a href="{$a->enableurl}" target="_blank">{$a->enableurl}</a>, найдите <strong>{$a->visiblename}</strong> и включите его.';
$string['plugin_missing'] = 'Для этого действия требуется, чтобы плагин <strong>{$a->pluginname}</strong> был установлен и включен. Загрузите его с <a href="{$a->downloadurl}" target="_blank">{$a->downloadurl}</a> и установите.';
$string['pluginname'] = 'Smart Rules AI';
$string['pluginnotavailable'] = 'Этот плагин недоступен, поскольку срок действия лицензии истек или она недействительна. Перейдите на <a href="https://shop.datacurso.com/clientarea.php" target="_blank">Shop Datacurso</a>, чтобы продлить или приобрести новую лицензию.';
$string['provider_not_enabled_warning'] = 'Включите уведомления с помощью <strong>Datacurso Message Hub</strong>, чтобы это действие могло отправлять уведомления через WhatsApp и SMS с использованием провайдеров вроде Twilio.
Вы можете включить это в <a href="{$a}" target="_blank">Настройках уведомлений</a>, найдя <strong>Smart Rules AI notification</strong>.
<br>
<a href="https://docs.datacurso.com/index.php?title=Message_Hub" target="_blank">См. документацию для получения дополнительной информации.</a>';
$string['recurringinterval'] = 'Повторяющийся интервал';
$string['recurringinterval_help'] = 'Введите числовое значение, представляющее повторяющийся интервал неактивности (например, «7» для каждых 7 дней неактивности).';
$string['rolestonotify'] = 'Роли для уведомления';
$string['rolestonotify_help'] = 'Выберите роли, которые должен иметь пользователь, чтобы получить уведомление. Необходимо выбрать как минимум одну.';
$string['ruleactive'] = 'Активно';
$string['ruleactive_help'] = 'Включить или отключить правило';
$string['ruleadd'] = 'Добавить правило';
$string['ruleaddedsuccessfully'] = 'Правило успешно добавлено';
$string['ruleinactive'] = 'Неактивно';
$string['rules'] = 'Правила';
$string['rules_help'] = 'Правила используются для определения набора условий и действий, которые будут выполняться';
$string['ruleupdatedsuccessfully'] = 'Правило успешно обновлено';
$string['searchcourseactivitymodules'] = 'Поиск модулей активности курса';
$string['selectanactivity'] = 'Выберите активность';
$string['sendnotification'] = 'Отправить уведомление';
$string['sendnotification_description'] = 'Отправить уведомление «{$a}» пользователям';
$string['typemissing'] = 'Отсутствует значение «type»';
$string['weeks'] = 'Недели';
