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

$string['actions'] = 'Ações';
$string['actions_help'] = 'As ações são usadas para definir o que será executado quando as condições da regra forem atendidas';
$string['addactions'] = 'Adicionar ações';
$string['addconditions'] = 'Adicionar condições';
$string['after'] = 'Depois';
$string['allcourseactivitymodules'] = 'Todos os módulos de atividade do curso';
$string['availableplaceholders'] = 'Marcadores disponíveis';
$string['backtolistrules'] = 'Voltar à lista de regras';
$string['basedate'] = 'Data base';
$string['basedate_help'] = 'Escolha a data de referência para avaliar a inatividade:

* **Desde a data de inscrição**: Calcula a partir da inscrição do usuário.
* **Desde a data de início do curso**: Calcula a partir do início do curso.
* **A partir da ativação da regra**: Conta a partir da primeira ativação da regra. Pausar e reativar a regra não reinicia a contagem.';
$string['before'] = 'Antes';
$string['checklicensekey'] = 'Verificar chave de licença';
$string['complete_activity'] = 'Atividade concluída';
$string['complete_activity_condition_info'] = 'Esta condição verificará quais usuários concluíram o módulo de atividade selecionado.';
$string['complete_activity_description'] = 'Usuários que concluíram o módulo de atividade "{$a->moddescription}"';
$string['completiondate'] = 'Data de conclusão';
$string['componenttargetmissing'] = 'A atividade selecionada não está mais disponível neste curso, então este componente nunca poderá ter efeito.';
$string['conditions'] = 'Condições';
$string['conditions_help'] = 'As condições definem os critérios que devem ser atendidos para executar as ações da regra';
$string['copiedtoclipboard'] = 'Copiado para a área de transferência';
$string['copytoclipboard'] = 'Copiar para a área de transferência';
$string['course_inactivity'] = 'Inatividade no curso em intervalos de tempo';
$string['course_inactivity_custom_description'] = 'Usuários sem atividade no curso por intervalos de {$a->intervals} {$a->unit} a partir de {$a->basedate}';
$string['course_inactivity_info'] = 'Esta condição verificará quais usuários não tiveram atividade no curso dentro dos intervalos especificados.';
$string['course_inactivity_recurring_description'] = 'Usuários sem atividade no curso em intervalos recorrentes de {$a->intervals} {$a->unit} a partir de {$a->basedate}';
$string['course_inactivity_task'] = 'Tarefa de inatividade no curso';
$string['coursedynamicrules:createaction'] = 'Criar ações';
$string['coursedynamicrules:createcondition'] = 'Criar condições';
$string['coursedynamicrules:createrule'] = 'Criar regras';
$string['coursedynamicrules:deleteaction'] = 'Excluir ações';
$string['coursedynamicrules:deletecondition'] = 'Excluir condições';
$string['coursedynamicrules:deleterule'] = 'Excluir regras';
$string['coursedynamicrules:manageaction'] = 'Gerenciar ações';
$string['coursedynamicrules:managecondition'] = 'Gerenciar condições';
$string['coursedynamicrules:managerule'] = 'Gerenciar regras';
$string['coursedynamicrules:notification'] = 'Enviar notificação';
$string['coursedynamicrules:updateaction'] = 'Atualizar ações';
$string['coursedynamicrules:updatecondition'] = 'Atualizar condições';
$string['coursedynamicrules:updaterule'] = 'Atualizar regras';
$string['coursedynamicrules:viewaction'] = 'Ver ações';
$string['coursedynamicrules:viewcondition'] = 'Ver condições';
$string['coursedynamicrules:viewrule'] = 'Ver regras';
$string['courselink'] = 'Link do curso';
$string['coursemoduleelementnotfound'] = 'Elemento de seleção do módulo de atividade do curso não encontrado.';
$string['coursename'] = 'Nome do curso';
$string['coursestartdate'] = 'Data de início do curso';
$string['createaiactivity'] = 'Criar atividade de reforço com IA';
$string['createaiactivity_action_info'] = 'Esta ação solicitará ao serviço Datacurso AI a geração de uma atividade de reforço personalizada para os usuários que atenderem às condições da regra.';
$string['createaiactivity_beforemod'] = 'Colocar antes da atividade';
$string['createaiactivity_beforemod_help'] = 'Selecione a atividade que o novo recurso deve preceder ou mantenha a opção padrão para adicioná-lo ao final da seção.';
$string['createaiactivity_beforemod_none'] = 'Não posicionar antes de outra atividade';
$string['createaiactivity_description'] = 'Gerar uma atividade de reforço com IA na seção "{$a->section}" usando o prompt "{$a->prompt}"';
$string['createaiactivity_generateimages'] = 'Gerar imagens com IA';
$string['createaiactivity_generateimages_label'] = 'Permitir que a IA inclua imagens geradas quando suportado.';
$string['createaiactivity_placeholders_info'] = 'Marcadores disponíveis: <code>{$a->coursename}</code>, <code>{$a->courseurl}</code>, <code>{$a->fullname}</code>, <code>{$a->firstname}</code>, <code>{$a->lastname}</code>.';
$string['createaiactivity_prompt'] = 'Prompt de IA';
$string['createaiactivity_prompt_help'] = 'Escreva a instrução que será enviada ao serviço de IA. Você pode incluir marcadores que serão substituídos antes do envio.';
$string['createaiactivity_section'] = 'Seção do curso';
$string['createrule'] = 'Criar regra';
$string['customintervals'] = 'Intervalos personalizados';
$string['customintervals_help'] = 'Insira números separados por vírgulas representando períodos de inatividade (ex.: "7,14,30").';
$string['datacurso'] = 'Datacurso';
$string['date_from_course_start'] = 'Desde a data de início do curso';
$string['date_from_enrollment'] = 'Desde a data de inscrição';
$string['date_from_now'] = 'A partir da ativação da regra';
$string['days'] = 'Dias';
$string['deleteactioncheck'] = 'Tem certeza de que deseja excluir completamente esta ação?';
$string['deletecondition'] = 'Excluir condição';
$string['deleteconditioncheck'] = 'Tem certeza de que deseja excluir completamente esta condição?';
$string['deletedaction'] = 'Ação excluída <b>{$a}</b>';
$string['deletedcondition'] = 'Condição excluída <b>{$a}</b>';
$string['deletedrule'] = 'Regra excluída <b>{$a}</b>';
$string['deleterule'] = 'Excluir regra';
$string['deleterulecheck'] = 'Tem certeza de que deseja excluir completamente esta regra?';
$string['deletingcondition'] = 'Excluindo condição "{$a}"';
$string['deletingrule'] = 'Excluindo regra "{$a}"';
$string['description'] = 'Descrição';
$string['editactions'] = 'Editar ações';
$string['editconditions'] = 'Editar condições';
$string['editrule'] = 'Editar regra';
$string['enableactivity'] = 'Habilitar atividade';
$string['enableactivity_action_info'] = 'Esta ação habilitará os módulos de atividades selecionados para os usuários que atenderem aos critérios da regra.';
$string['enableactivity_alreadygated'] = 'Outra ação já abre "{$a}", nesta regra ou em outra. Duas ações não podem abrir a mesma atividade: o Moodle exige que ambas a tenham aberto para um estudante, então adicioná-la aqui a fecharia para os estudantes para quem a outra ação já a abriu. Escolha outra atividade. Liberá-la da outra ação só é possível enquanto a regra dessa ação nunca tiver sido ativada.';
$string['enableactivity_description'] = 'Habilitar atividades "{$a}"';
$string['enableactivity_noactivities'] = 'Habilitar atividades: nenhuma escolhida ainda';
$string['enablegradegreaterthanorequal_help'] = 'Habilitar nota maior ou igual a';
$string['enablegradelessthan'] = 'Habilitar nota menor que';
$string['enrollmentdate'] = 'Data de inscrição';
$string['errorgradeoutofrange'] = 'O valor deve estar entre {$a->min} e {$a->max}.';
$string['errormaxgradeexceeded'] = 'A nota não pode exceder a nota máxima da atividade.';
$string['errornegativegrade'] = 'A nota deve ser 0 ou maior.';
$string['errornocoursemodule'] = 'Você deve selecionar uma atividade deste curso.';
$string['expectedcompletiondate'] = 'Data de conclusão prevista';
$string['firstname'] = 'Nome do usuário';
$string['fullname'] = 'Nome completo do usuário';
$string['generalsettings'] = 'Configurações gerais';
$string['grade'] = 'Nota';
$string['grade_in_activity'] = 'Nota na atividade';
$string['grade_in_activity_condition_info'] = 'Esta condição verificará qual usuário obteve a nota especificada no módulo de atividade selecionado.';
$string['grade_in_activity_description'] = 'Para "{$a->moddescription}", devem ser obtidas as seguintes notas: {$a->gradestring}';
$string['gradegreaterthanorequal'] = 'deve ser &#x2265;';
$string['gradegreaterthanorequal_help'] = 'A condição é atendida se a nota do usuário for maior ou igual ao valor especificado.';
$string['gradegreaterthanorequalvalue'] = '&#x2265; {$a}';
$string['gradelessthan'] = 'deve ser <';
$string['gradelessthan_help'] = 'A condição é atendida se a nota do usuário for menor que o valor especificado.';
$string['gradelessthanvalue'] = '< {$a}';
$string['hours'] = 'Horas';
$string['intervaltype'] = 'Tipo de intervalo';
$string['intervaltype_help'] = 'Selecione como o intervalo será avaliado:\n\n* **Intervalos personalizados**: Para adicionar valores separados por vírgulas (ex.: 7,14,30) e avaliar a inatividade em momentos específicos.\n* **Intervalo recorrente**: Para avaliar a inatividade em intervalos recorrentes (ex.: a cada 7 dias).';
$string['intervalunit'] = 'Unidade de tempo';
$string['intervalunit_help'] = 'Selecione a unidade de tempo para os intervalos.';
$string['invalidbasedate'] = 'Tipo de data base inválido {$a}';
$string['invalidruleid'] = 'ID de regra inválido';
$string['lastname'] = 'Sobrenome do usuário';
$string['licensekey'] = 'Chave de licença';
$string['licensekey_desc'] = 'Chave de licença necessária para usar este plugin';
$string['licensekeycompany'] = 'Chave de licença para: {$a}';
$string['licensekeycompany_desc'] = 'Chave de licença necessária para usar este plugin para a empresa: {$a}';
$string['licensekeyinvalid'] = 'A chave de licença expirou ou é inválida. Acesse <a href="https://shop.datacurso.com/clientarea.php" target="_blank">Shop Datacurso</a> para renová-la ou comprar uma nova.';
$string['licensekeyvalid'] = 'A chave de licença é válida';
$string['messagebody'] = 'Corpo';
$string['messagebody_help'] = 'Os seguintes marcadores podem ser incluídos na mensagem:\n\n* Nome do curso {$a->coursename}\n* Nome completo do usuário {$a->fullname}\n* Nome do usuário {$a->firstname}\n* Sobrenome do usuário {$a->lastname}\n* Nome do módulo de atividade do curso {$a->modulename}\n* Nome da instância do módulo de atividade do curso {$a->moduleinstancename}';
$string['messageprovider:smart_rules_ai_notification'] = 'Notificação do Smart Rules AI';
$string['messagesubject'] = 'Assunto';
$string['minutes'] = 'Minutos';
$string['missing_plugins_warning'] = 'Melhore suas notificações! Nossos plugins <strong>Datacurso Message Hub</strong> permitem enviar notificações via WhatsApp e SMS usando provedores como Twilio.
<br>
<a href="https://shop.datacurso.com/clientarea.php" target="_blank">Clique aqui para comprá-los e habilitá-los agora!</a>';
$string['moduleinstancename'] = 'Nome da instância do módulo de atividade do curso';
$string['modulename'] = 'Nome do módulo de atividade do curso';
$string['months'] = 'Meses';
$string['mustselectonerole'] = 'Você deve selecionar pelo menos um papel.';
$string['name'] = 'Nome';
$string['no_complete_activity'] = 'Atividade não concluída';
$string['no_complete_activity_condition_info'] = 'Esta condição verificará qual usuário não concluiu o módulo de atividade selecionado após a data especificada.';
$string['no_complete_activity_description'] = 'Usuários que não concluíram o módulo de atividade "{$a->moddescription}" após {$a->expectedcompletiondate}';
$string['no_complete_activity_task'] = 'Tarefa de atividade não concluída';
$string['no_course_access'] = 'Sem acesso ao curso';
$string['no_course_access_condition_info'] = 'Esta condição verificará quais usuários não acessaram este curso dentro do período especificado.';
$string['no_course_access_description'] = 'Usuários que ficam mais de {$a->periodvalue} {$a->periodunit} sem acessar este curso.';
$string['no_course_access_task'] = 'Tarefa sem acesso ao curso';
$string['notification_action_info'] = 'Esta ação enviará uma notificação aos usuários que atenderem aos critérios da regra.';
$string['now'] = 'ativação da regra';
$string['passgrade'] = 'Conclusão da atividade com nota de aprovação';
$string['passgrade_condition_info'] = 'Esta condição verificará qual usuário concluiu o módulo de atividade selecionado com nota de aprovação.';
$string['passgrade_description'] = 'Usuários que concluíram o módulo de atividade "{$a}" com nota de aprovação';
$string['period'] = 'Período';
$string['period_help'] = 'O tempo mínimo que um usuário deve ficar sem acessar o curso.';
$string['plugin_disabled'] = 'Esta ação requer que o plugin <strong>{$a->pluginname}</strong> esteja habilitado. Acesse a página <a href="{$a->enableurl}" target="_blank">{$a->enableurl}</a>, pesquise por <strong>{$a->visiblename}</strong> e habilite-o.';
$string['plugin_missing'] = 'Esta ação requer que o plugin <strong>{$a->pluginname}</strong> esteja instalado e habilitado. Baixe-o em <a href="{$a->downloadurl}" target="_blank">{$a->downloadurl}</a> e instale-o.';
$string['pluginname'] = 'Smart Rules AI';
$string['pluginnotavailable'] = 'Este plugin não está disponível porque a licença do produto expirou ou é inválida. Acesse <a href="https://shop.datacurso.com/clientarea.php" target="_blank">Shop Datacurso</a> para renovar ou comprar uma nova licença.';
$string['provider_not_enabled_warning'] = 'Ative as notificações com o <strong>Datacurso Message Hub</strong> para que esta ação envie notificações via WhatsApp e SMS usando provedores como Twilio.
Você pode habilitar em <a href="{$a}" target="_blank">Configurações de notificação</a> pesquisando por <strong>Smart Rules AI notification</strong>.
<br>
<a href="https://docs.datacurso.com/index.php?title=Message_Hub" target="_blank">Consulte a documentação para mais informações.</a>';
$string['recurringinterval'] = 'Intervalo recorrente';
$string['recurringinterval_help'] = 'Insira um valor numérico que represente um intervalo recorrente de inatividade (ex.: "7" para a cada 7 dias de inatividade).';
$string['rolestonotify'] = 'Funções a notificar';
$string['rolestonotify_help'] = 'Selecione as funções que o usuário deve ter para receber a notificação. Selecione pelo menos uma.';
$string['ruleactive'] = 'Ativa';
$string['ruleactive_help'] = 'Ativar ou desativar a regra';
$string['ruleadd'] = 'Adicionar regra';
$string['ruleaddedsuccessfully'] = 'Regra adicionada com sucesso';
$string['ruleinactive'] = 'Inativa';
$string['rules'] = 'Regras';
$string['rules_help'] = 'As regras são usadas para definir um conjunto de condições e ações que serão executadas';
$string['ruleupdatedsuccessfully'] = 'Regra atualizada com sucesso';
$string['searchcourseactivitymodules'] = 'Pesquisar módulos de atividade do curso';
$string['selectanactivity'] = 'Selecionar uma atividade';
$string['sendnotification'] = 'Enviar notificação';
$string['sendnotification_description'] = 'Enviar notificação "{$a}" aos usuários';
$string['typemissing'] = 'Valor "type" ausente';
$string['weeks'] = 'Semanas';
