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

$string['actions'] = 'Aktionen';
$string['actions_help'] = 'Aktionen definieren die Vorgänge, die ausgeführt werden, wenn die Bedingungen der Regel erfüllt sind';
$string['addactions'] = 'Aktionen hinzufügen';
$string['addconditions'] = 'Bedingungen hinzufügen';
$string['after'] = 'Nach';
$string['allcourseactivitymodules'] = 'Alle Kurs-Aktivitätsmodule';
$string['availableplaceholders'] = 'Verfügbare Platzhalter';
$string['backtolistrules'] = 'Zurück zur Regelnliste';
$string['basedate'] = 'Basisdatum';
$string['basedate_help'] = 'Wählen Sie das Referenzdatum zur Bewertung der Inaktivität:

* **Ab Einschreibedatum**: Berechnet ab dem Zeitpunkt der Einschreibung.
* **Ab Kursbeginn**: Berechnet ab dem Kursstartdatum.
* **Ab Aktivierung der Regel**: Zählt ab dem Zeitpunkt, an dem die Regel zum ersten Mal aktiviert wurde. Pausieren und erneutes Aktivieren setzen die Zählung nicht zurück.';
$string['before'] = 'Vor';
$string['checklicensekey'] = 'Lizenzschlüssel prüfen';
$string['complete_activity'] = 'Aktivität abgeschlossen';
$string['complete_activity_condition_info'] = 'Diese Bedingung prüft, welche Nutzer das ausgewählte Aktivitätsmodul abgeschlossen haben.';
$string['complete_activity_description'] = 'Nutzer, die das Aktivitätsmodul "{$a->moddescription}" abgeschlossen haben';
$string['completiondate'] = 'Abschlussdatum';
$string['componenttargetmissing'] = 'Die ausgewählte Aktivität ist in diesem Kurs nicht mehr verfügbar, daher kann diese Komponente niemals wirksam werden.';
$string['conditions'] = 'Bedingungen';
$string['conditions_help'] = 'Bedingungen definieren die Kriterien, die erfüllt sein müssen, damit die Regelaktionen ausgeführt werden';
$string['copiedtoclipboard'] = 'In die Zwischenablage kopiert';
$string['copytoclipboard'] = 'In die Zwischenablage kopieren';
$string['course_inactivity'] = 'Kursinaktivität in Zeitintervallen';
$string['course_inactivity_custom_description'] = 'Nutzer ohne Aktivität im Kurs über Intervalle von {$a->intervals} {$a->unit} ab {$a->basedate}';
$string['course_inactivity_info'] = 'Diese Bedingung prüft, welche Nutzer innerhalb der angegebenen Zeitintervalle keine Aktivität im Kurs hatten.';
$string['course_inactivity_recurring_description'] = 'Nutzer ohne Aktivität im Kurs in wiederkehrenden Intervallen von {$a->intervals} {$a->unit} ab {$a->basedate}';
$string['course_inactivity_task'] = 'Aufgabe Kursinaktivität';
$string['coursedynamicrules:createaction'] = 'Aktionen erstellen';
$string['coursedynamicrules:createcondition'] = 'Bedingungen erstellen';
$string['coursedynamicrules:createrule'] = 'Regeln erstellen';
$string['coursedynamicrules:deleteaction'] = 'Aktionen löschen';
$string['coursedynamicrules:deletecondition'] = 'Bedingungen löschen';
$string['coursedynamicrules:deleterule'] = 'Regeln löschen';
$string['coursedynamicrules:manageaction'] = 'Aktionen verwalten';
$string['coursedynamicrules:managecondition'] = 'Bedingungen verwalten';
$string['coursedynamicrules:managerule'] = 'Regeln verwalten';
$string['coursedynamicrules:notification'] = 'Benachrichtigung senden';
$string['coursedynamicrules:updateaction'] = 'Aktionen aktualisieren';
$string['coursedynamicrules:updatecondition'] = 'Bedingungen aktualisieren';
$string['coursedynamicrules:updaterule'] = 'Regeln aktualisieren';
$string['coursedynamicrules:viewaction'] = 'Aktionen anzeigen';
$string['coursedynamicrules:viewcondition'] = 'Bedingungen anzeigen';
$string['coursedynamicrules:viewrule'] = 'Regeln anzeigen';
$string['courselink'] = 'Kurslink';
$string['coursemoduleelementnotfound'] = 'Auswahlelement für Kurs-Aktivitätsmodul nicht gefunden.';
$string['coursename'] = 'Kursname';
$string['coursestartdate'] = 'Kursbeginn';
$string['createaiactivity'] = 'KI-Verstärkungsaktivität erstellen';
$string['createaiactivity_action_info'] = 'Diese Aktion fordert den Datacurso-AI-Dienst auf, eine personalisierte Verstärkungsaktivität für Nutzer zu generieren, die die Regelbedingungen erfüllen.';
$string['createaiactivity_beforemod'] = 'Vor Aktivität einfügen';
$string['createaiactivity_beforemod_help'] = 'Wählen Sie die Aktivität aus, vor der die neue Ressource eingefügt werden soll, oder lassen Sie die Standardoption, um sie am Ende des Abschnitts hinzuzufügen.';
$string['createaiactivity_beforemod_none'] = 'Nicht vor eine andere Aktivität positionieren';
$string['createaiactivity_description'] = 'Erzeuge eine KI-Verstärkungsaktivität im Abschnitt "{$a->section}" mit dem Prompt "{$a->prompt}"';
$string['createaiactivity_generateimages'] = 'Bilder mit KI generieren';
$string['createaiactivity_generateimages_label'] = 'Erlauben, dass die KI bei Unterstützung generierte Bilder einbezieht.';
$string['createaiactivity_placeholders_info'] = 'Verfügbare Platzhalter: <code>{$a->coursename}</code>, <code>{$a->courseurl}</code>, <code>{$a->fullname}</code>, <code>{$a->firstname}</code>, <code>{$a->lastname}</code>.';
$string['createaiactivity_prompt'] = 'KI-Prompt';
$string['createaiactivity_prompt_help'] = 'Geben Sie die Anweisung ein, die an den KI-Dienst gesendet wird. Sie können Platzhalter einfügen, die vor dem Senden ersetzt werden.';
$string['createaiactivity_section'] = 'Kursabschnitt';
$string['createrule'] = 'Regel erstellen';
$string['customintervals'] = 'Benutzerdefinierte Intervalle';
$string['customintervals_help'] = 'Geben Sie durch Komma getrennte Zahlen für Inaktivitätszeiträume ein (z. B. "7,14,30").';
$string['datacurso'] = 'Datacurso';
$string['date_from_course_start'] = 'Ab Kursbeginn';
$string['date_from_enrollment'] = 'Ab Einschreibedatum';
$string['date_from_now'] = 'Ab Aktivierung der Regel';
$string['days'] = 'Tage';
$string['deleteactioncheck'] = 'Sind Sie absolut sicher, dass Sie diese Aktion vollständig löschen möchten?';
$string['deletecondition'] = 'Bedingung löschen';
$string['deleteconditioncheck'] = 'Sind Sie absolut sicher, dass Sie diese Bedingung vollständig löschen möchten?';
$string['deletedaction'] = 'Aktion gelöscht <b>{$a}</b>';
$string['deletedcondition'] = 'Bedingung gelöscht <b>{$a}</b>';
$string['deletedrule'] = 'Regel gelöscht <b>{$a}</b>';
$string['deleterule'] = 'Regel löschen';
$string['deleterulecheck'] = 'Sind Sie absolut sicher, dass Sie diese Regel vollständig löschen möchten?';
$string['deletingcondition'] = 'Bedingung wird gelöscht "{$a}"';
$string['deletingrule'] = 'Regel wird gelöscht "{$a}"';
$string['description'] = 'Beschreibung';
$string['editactions'] = 'Aktionen bearbeiten';
$string['editconditions'] = 'Bedingungen bearbeiten';
$string['editrule'] = 'Regel bearbeiten';
$string['enableactivity'] = 'Aktivität aktivieren';
$string['enableactivity_action_info'] = 'Diese Aktion aktiviert ausgewählte Aktivitätsmodule für Nutzer, die die Regelkriterien erfüllen.';
$string['enableactivity_alreadygated'] = 'Eine andere Aktion gibt "{$a}" bereits frei, in dieser oder einer anderen Regel. Zwei Aktionen können dieselbe Aktivität nicht freigeben: Moodle verlangt, dass beide sie für einen Nutzer freigegeben haben, daher würde das Hinzufügen hier sie für die Nutzer der anderen Aktion schließen. Wählen Sie eine andere Aktivität. Aus der anderen Aktion lässt sie sich nur lösen, solange deren Regel nie aktiviert wurde.';
$string['enableactivity_description'] = 'Aktivitäten aktivieren "{$a}"';
$string['enableactivity_noactivities'] = 'Aktivitäten freigeben: noch keine ausgewählt';
$string['enablegradegreaterthanorequal_help'] = 'Note größer oder gleich aktivieren';
$string['enablegradelessthan'] = 'Note kleiner als aktivieren';
$string['enrollmentdate'] = 'Einschreibedatum';
$string['errorgradeoutofrange'] = 'Der Wert muss zwischen {$a->min} und {$a->max} liegen.';
$string['errormaxgradeexceeded'] = 'Die Note darf die Maximalpunktzahl der Aktivität nicht überschreiten.';
$string['errornegativegrade'] = 'Die Note muss 0 oder größer sein.';
$string['errornocoursemodule'] = 'Sie müssen eine Aktivität aus diesem Kurs auswählen.';
$string['expectedcompletiondate'] = 'Voraussichtliches Abschlussdatum';
$string['firstname'] = 'Vorname des Nutzers';
$string['fullname'] = 'Vollständiger Name des Nutzers';
$string['generalsettings'] = 'Allgemeine Einstellungen';
$string['grade'] = 'Note';
$string['grade_in_activity'] = 'Note in Aktivität';
$string['grade_in_activity_condition_info'] = 'Diese Bedingung prüft, welcher Nutzer die angegebene Note im ausgewählten Aktivitätsmodul erreicht hat.';
$string['grade_in_activity_description'] = 'Für "{$a->moddescription}" müssen folgende Noten erreicht werden: {$a->gradestring}';
$string['gradegreaterthanorequal'] = 'muss &#x2265; sein';
$string['gradegreaterthanorequal_help'] = 'Die Bedingung ist erfüllt, wenn die Note des Nutzers größer oder gleich dem angegebenen Wert ist.';
$string['gradegreaterthanorequalvalue'] = '&#x2265; {$a}';
$string['gradelessthan'] = 'muss < sein';
$string['gradelessthan_help'] = 'Die Bedingung ist erfüllt, wenn die Note des Nutzers kleiner als der angegebene Wert ist.';
$string['gradelessthanvalue'] = '< {$a}';
$string['hours'] = 'Stunden';
$string['intervaltype'] = 'Intervalltyp';
$string['intervaltype_help'] = 'Wählen Sie, wie das Intervall bewertet wird:

* **Benutzerdefinierte Intervalle**: Kommagetrennte Werte (z. B. 7,14,30), um Inaktivität zu bestimmten Zeitpunkten zu bewerten.
* **Wiederkehrendes Intervall**: Inaktivität in wiederkehrenden Intervallen (z. B. alle 7 Tage) bewerten.';
$string['intervalunit'] = 'Zeiteinheit';
$string['intervalunit_help'] = 'Wählen Sie die Zeiteinheit für die Intervalle.';
$string['invalidbasedate'] = 'Ungültiger Basisdatumstyp {$a}';
$string['invalidruleid'] = 'Ungültige Regel-ID';
$string['lastname'] = 'Nachname des Nutzers';
$string['licensekey'] = 'Lizenzschlüssel';
$string['licensekey_desc'] = 'Für die Nutzung dieses Plugins ist ein Lizenzschlüssel erforderlich';
$string['licensekeycompany'] = 'Lizenzschlüssel für: {$a}';
$string['licensekeycompany_desc'] = 'Lizenzschlüssel zur Nutzung dieses Plugins für das Unternehmen: {$a}';
$string['licensekeyinvalid'] = 'Der Lizenzschlüssel ist abgelaufen oder ungültig. Bitte gehen Sie zu <a href="https://shop.datacurso.com/clientarea.php" target="_blank">Shop Datacurso</a>, um ihn zu erneuern oder einen neuen zu erwerben.';
$string['licensekeyvalid'] = 'Lizenzschlüssel ist gültig';
$string['messagebody'] = 'Nachrichtentext';
$string['messagebody_help'] = 'Die folgenden Platzhalter können in der Nachricht verwendet werden:

* Kursname {$a->coursename}
* Vollständiger Nutzername {$a->fullname}
* Nutzer-Vorname {$a->firstname}
* Nutzer-Nachname {$a->lastname}
* Name des Kurs-Aktivitätsmoduls {$a->modulename}
* Name der Instanz des Kurs-Aktivitätsmoduls {$a->moduleinstancename}';
$string['messageprovider:smart_rules_ai_notification'] = 'Smart Rules AI Benachrichtigung';
$string['messagesubject'] = 'Betreff';
$string['minutes'] = 'Minuten';
$string['missing_plugins_warning'] = '🔔 Verbessern Sie Ihre Benachrichtigungen! Unsere <strong>Datacurso Message Hub</strong>-Plugins ermöglichen das Senden von Benachrichtigungen über WhatsApp und SMS mit Anbietern wie Twilio.
<br>
<a href="https://shop.datacurso.com/clientarea.php" target="_blank">Klicken Sie hier, um sie jetzt zu kaufen und zu aktivieren!</a>';
$string['moduleinstancename'] = 'Name der Instanz des Kurs-Aktivitätsmoduls';
$string['modulename'] = 'Name des Kurs-Aktivitätsmoduls';
$string['months'] = 'Monate';
$string['mustselectonerole'] = 'Sie müssen mindestens eine Rolle auswählen.';
$string['name'] = 'Name';
$string['no_complete_activity'] = 'Aktivität nicht abgeschlossen';
$string['no_complete_activity_condition_info'] = 'Diese Bedingung prüft, welcher Nutzer das ausgewählte Aktivitätsmodul nach dem angegebenen Datum nicht abgeschlossen hat.';
$string['no_complete_activity_description'] = 'Nutzer, die das Aktivitätsmodul "{$a->moddescription}" nach {$a->expectedcompletiondate} nicht abgeschlossen haben';
$string['no_complete_activity_task'] = 'Aufgabe Nicht abgeschlossene Aktivität';
$string['no_course_access'] = 'Kein Kurszugriff';
$string['no_course_access_condition_info'] = 'Diese Bedingung prüft, welche Nutzer innerhalb des angegebenen Zeitraums nicht auf diesen Kurs zugegriffen haben.';
$string['no_course_access_description'] = 'Nutzer, die mehr als {$a->periodvalue} {$a->periodunit} benötigen, ohne auf diesen Kurs zuzugreifen.';
$string['no_course_access_task'] = 'Aufgabe Kein Kurszugriff';
$string['notification_action_info'] = 'Diese Aktion sendet eine Benachrichtigung an Nutzer, die die Regelkriterien erfüllen.';
$string['now'] = 'Der Aktivierung der Regel';
$string['passgrade'] = 'Aktivitätsabschluss mit Bestehensnote';
$string['passgrade_condition_info'] = 'Diese Bedingung prüft, welcher Nutzer das ausgewählte Aktivitätsmodul mit Bestehensnote abgeschlossen hat.';
$string['passgrade_description'] = 'Nutzer, die das Aktivitätsmodul "{$a}" mit Bestehensnote abgeschlossen haben';
$string['period'] = 'Zeitraum';
$string['period_help'] = 'Die Mindestzeit, die ein Nutzer ohne Zugriff auf den Kurs verbringen muss.';
$string['plugin_disabled'] = 'Für diese Aktion muss das Plugin <strong>{$a->pluginname}</strong> aktiviert sein. Gehen Sie zur Seite <a href="{$a->enableurl}" target="_blank">{$a->enableurl}</a>, suchen Sie <strong>{$a->visiblename}</strong> und aktivieren Sie es.';
$string['plugin_missing'] = 'Für diese Aktion muss das Plugin <strong>{$a->pluginname}</strong> installiert und aktiviert sein. Laden Sie es von <a href="{$a->downloadurl}" target="_blank">{$a->downloadurl}</a> herunter und installieren Sie es.';
$string['pluginname'] = 'Smart Rules AI';
$string['pluginnotavailable'] = 'Dieses Plugin ist nicht verfügbar, da die Produktlizenz abgelaufen oder ungültig ist. Bitte gehen Sie zu <a href="https://shop.datacurso.com/clientarea.php" target="_blank">Shop Datacurso</a>, um sie zu erneuern oder eine neue zu erwerben.';
$string['provider_not_enabled_warning'] = 'Aktivieren Sie Benachrichtigungen mit <strong>Datacurso Message Hub</strong>, damit diese Aktion Benachrichtigungen über WhatsApp und SMS mit Anbietern wie Twilio sendet.
Sie können dies unter <a href="{$a}" target="_blank">Benachrichtigungseinstellungen</a> aktivieren, indem Sie nach <strong>Smart Rules AI notification</strong> suchen.
<br>
<a href="https://docs.datacurso.com/index.php?title=Message_Hub" target="_blank">Weitere Informationen finden Sie in der Dokumentation.</a>';
$string['recurringinterval'] = 'Wiederkehrendes Intervall';
$string['recurringinterval_help'] = 'Geben Sie einen numerischen Wert für ein wiederkehrendes Inaktivitätsintervall ein (z. B. "7" für alle 7 Tage Inaktivität).';
$string['rolestonotify'] = 'Zu benachrichtigende Rollen';
$string['rolestonotify_help'] = 'Wählen Sie die Rollen aus, die der Nutzer haben muss, um die Benachrichtigung zu erhalten. Wählen Sie mindestens eine aus.';
$string['ruleactive'] = 'Aktiv';
$string['ruleactive_help'] = 'Regel aktivieren oder deaktivieren';
$string['ruleadd'] = 'Regel hinzufügen';
$string['ruleaddedsuccessfully'] = 'Regel erfolgreich hinzugefügt';
$string['ruleinactive'] = 'Inaktiv';
$string['rules'] = 'Regeln';
$string['rules_help'] = 'Regeln definieren eine Reihe von Bedingungen und Aktionen, die ausgeführt werden';
$string['ruleupdatedsuccessfully'] = 'Regel erfolgreich aktualisiert';
$string['searchcourseactivitymodules'] = 'Kurs-Aktivitätsmodule suchen';
$string['selectanactivity'] = 'Aktivität auswählen';
$string['sendnotification'] = 'Benachrichtigung senden';
$string['sendnotification_description'] = 'Benachrichtigung "{$a}" an Nutzer senden';
$string['typemissing'] = 'Fehlender Wert "type"';
$string['weeks'] = 'Wochen';
