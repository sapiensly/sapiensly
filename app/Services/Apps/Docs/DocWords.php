<?php

namespace App\Services\Apps\Docs;

use App\Support\Locale\SemanticLexicon;
use Illuminate\Support\Str;

/**
 * Everything the two generated documents say on their own behalf, per language.
 *
 * The app's own words — object names, field names, button labels — come from
 * the manifest and are already in the language it was written in. These are the
 * sentences around them: the headings, the step templates, the plain-language
 * name of a field type. They live here for the same reason
 * {@see SemanticLexicon} exists: one table per language beats a `$lang === 'es'`
 * ternary at every sentence, and adding a language is one block of entries.
 *
 * A key missing from a language falls back to English, so a partial translation
 * degrades to a readable sentence rather than to a key.
 */
class DocWords
{
    /**
     * @var array<string, array<string, string>>
     */
    private const WORDS = [
        'en' => [
            // Documents
            'manual_title' => 'User guide',
            'manual_subject' => 'How to use {app}',
            'tech_title' => 'Technical sheet',
            'tech_subject' => 'How {app} is built',
            'generated_note' => 'Written from the app itself, version {v}. It changes when the app changes.',

            // Manual — sections
            's_what' => 'What this app is for',
            's_screens' => 'The screens',
            's_tasks' => 'Doing each thing',
            's_data' => 'What it records',
            's_auto' => 'What happens on its own',
            's_who' => 'Who can do what',
            's_tips' => 'Worth knowing',

            // Manual — what
            'what_counts' => 'It records {kinds} kinds of thing and shows them across {pages} screens.',
            'what_holds' => 'What it keeps track of: {list}.',
            'what_home' => 'It opens on «{page}», which summarises the rest.',

            // Manual — screens
            'screen_path' => 'Reached at {path}',
            'screen_shows' => 'Shows {list}.',
            'screen_actions' => 'What you can do here:',
            'w_table' => 'a table of {n}',
            'w_board' => 'a board of {n} in columns by {f}',
            'w_calendar' => 'a calendar of {n} by {f}',
            'w_timeline' => 'a timeline of {n}',
            'w_charts' => '{c} charts',
            'w_chart' => 'a chart',
            'w_metrics' => '{c} headline figures',
            'w_metric' => 'a headline figure',
            'w_detail' => 'the full record of each {s}',
            'w_related' => 'the list of {n}',
            'w_form' => 'a form',
            'w_filters' => 'a filter bar',
            'w_text' => 'explanatory text',
            'w_other' => 'other elements',

            // Manual — tasks
            'task_add' => 'Add {s}',
            'step_open_page' => 'Open «{page}» from the menu.',
            'step_press' => 'Press «{button}».',
            'step_fill' => 'Fill in the form. Required: {list}.',
            'step_fill_none' => 'Fill in the form — no field is required.',
            'step_submit' => 'Finish with «{label}».',
            'task_edit' => 'To correct a record already saved, press «{label}» on its row.',
            'task_delete' => 'To remove a record, press «{label}» on its row.',
            'task_board' => 'You can also drag cards between columns to change {f} without opening the form.',
            'task_open' => 'Press «{label}» on a row to open the whole record.',
            'task_detail_children' => 'Its own page also lists {list}.',

            // Manual — data
            'data_intro' => 'Each {s} records:',
            'col_field' => 'Field',
            'col_holds' => 'Holds',
            'col_required' => 'Required',
            'yes' => 'Yes',
            'no' => 'No',

            // Manual — field types, in plain language
            't_string' => 'Short text',
            't_long_text' => 'A paragraph',
            't_rich_text' => 'Formatted text',
            't_email' => 'An email address',
            't_url' => 'A web address',
            't_phone' => 'A phone number',
            't_number' => 'A number',
            't_currency' => 'An amount in {c}',
            't_boolean' => 'Yes or no',
            't_date' => 'A date',
            't_datetime' => 'A date and time',
            't_date_range' => 'A range of dates',
            't_single_select' => 'One of: {list}',
            't_multi_select' => 'One or more of: {list}',
            't_relation_one' => 'Which {n} it belongs to',
            't_relation_many' => 'The {n} linked to it',
            't_formula' => 'Worked out on its own',
            't_lookup' => 'Taken from the linked {n}',
            't_rollup' => 'Worked out from its {n}',
            't_rollup_count' => 'How many {n} it has',
            't_rollup_sum' => 'The total of its {n}',
            't_rollup_sum_of' => 'The {f} of its {n}, added up',
            't_rating' => 'A rating from 1 to 5',
            't_slider' => 'A value on a slider',
            't_file' => 'A file',
            't_color' => 'A colour',
            't_unknown' => 'A value',

            // Manual — automations
            'auto_intro' => 'Some things the app does by itself:',
            'auto_line' => 'When {trigger}: {steps}.',
            'trg_created' => 'a {s} record is added',
            'trg_updated' => 'a {s} record is changed',
            'trg_deleted' => 'a {s} record is removed',
            'trg_schedule' => 'the schedule comes round',
            'trg_webhook' => 'another system calls in',
            'trg_manual' => 'someone runs it by hand',
            'trg_other' => 'it is triggered',

            // Manual — roles
            'who_intro' => 'The app has {n} roles. What each one may do with the records:',
            'col_role' => 'Role',
            'col_may' => 'May',
            'p_create' => 'add',
            'p_read' => 'see',
            'p_update' => 'change',
            'p_delete' => 'delete',
            'p_none' => 'nothing yet',
            'p_all' => 'everything',
            'who_default' => '«{role}» is what someone gets when nobody says otherwise.',

            // Manual — tips
            'tip_search' => 'Every table has a search box: type and it keeps only the matching rows.',
            'tip_sort' => 'Click a column heading to sort by it; click again to reverse it, a third time to leave it alone.',
            'tip_columns' => 'The «Columns» button hides, shows and reorders columns, and each person keeps their own arrangement.',
            'tip_required' => 'A required field left empty stops the form and says which one it was.',
            'tip_versions' => 'Nothing is lost when the app is changed: every version is kept and can be rolled back.',

            // Technical — sections
            's_identity' => 'Identity',
            's_model' => 'Data model',
            's_relations' => 'Relations',
            's_pages' => 'Pages and blocks',
            's_actions' => 'Actions',
            's_workflows' => 'Workflows',
            's_permissions' => 'Permissions',
            's_runtime' => 'How it runs',

            // Technical — identity
            'k_slug' => 'Slug',
            'k_id' => 'App id',
            'k_kind' => 'Kind',
            'k_version' => 'Version',
            'k_schema' => 'Manifest schema',
            'k_locale' => 'Locale',
            'k_currency' => 'Currency',
            'k_timezone' => 'Timezone',
            'k_counts' => 'Contents',
            'k_counts_v' => '{o} objects · {p} pages · {f} fields · {w} workflows',
            'k_theme' => 'Theme',
            'k_visibility' => 'Visibility',

            // Technical — model
            'col_name' => 'Name',
            'col_slug' => 'Slug',
            'col_type' => 'Type',
            'col_id' => 'Id',
            'col_detail' => 'Detail',
            'obj_display' => 'Records are labelled by «{f}».',
            'obj_fields' => '{n} fields.',

            // Technical — relations
            'rel_none' => 'This app has no relations between its objects.',
            'col_from' => 'From',
            'col_field2' => 'Field',
            'col_card' => 'Cardinality',
            'col_to' => 'To',
            'col_on_delete' => 'On delete',
            'rel_note' => 'A relation is stored as a pair of fields — the many side holds the parent id, the one side is its inverse and holds nothing. Both carry `inverse_field_id`, so either end finds the other.',

            // Technical — pages
            'page_path' => 'Path',
            'page_blocks' => '{n} blocks',

            // Technical — actions
            'act_none' => 'No block on this app triggers an action.',
            'col_where' => 'Where',
            'col_trigger' => 'On',
            'col_does' => 'Does',
            'trg_click' => 'click',
            'trg_submit' => 'submit',
            'act_note' => 'Values are resolved at run time: `{{form.x}}` is a field of the form being submitted, `{{params.x}}` a segment of the URL, `{{row.x}}` the row the action was pressed on.',

            // Technical — workflows
            'wf_none' => 'This app has no workflows.',
            'col_when' => 'When',
            'col_only_if' => 'Only if',
            'col_steps' => 'Steps',

            // Technical — permissions
            'perm_note' => 'An object with no policy is open within the app\'s visibility; a policy narrows it.',
            'col_object' => 'Object',

            // Technical — runtime
            'rt_url' => 'The app runs at {url}. Each page is that address plus the page path.',
            'rt_data' => 'Records live in the tenant schema behind row-level security: a query is scoped to the organisation (or the person) that owns it by Postgres itself, not by the app.',
            'rt_change' => 'The manifest is the app. It is changed with RFC 6902 patches against the JSON pointers below, each saved as a new version that can be rolled back.',
            'rt_pointers' => 'Useful pointers',
            'rt_read' => 'Read this app with `read_manifest`; change it with `propose_change`; check a draft first with `validate_manifest`.',
        ],

        'es' => [
            'manual_title' => 'Manual de uso',
            'manual_subject' => 'Cómo se usa {app}',
            'tech_title' => 'Ficha técnica',
            'tech_subject' => 'Cómo está construida {app}',
            'generated_note' => 'Escrito a partir de la app misma, versión {v}. Cambia cuando la app cambia.',

            's_what' => 'Para qué sirve esta app',
            's_screens' => 'Las pantallas',
            's_tasks' => 'Cómo hacer cada cosa',
            's_data' => 'Qué información guarda',
            's_auto' => 'Qué pasa solo',
            's_who' => 'Quién puede hacer qué',
            's_tips' => 'Vale la pena saber',

            'what_counts' => 'Guarda {kinds} tipos de información y los muestra en {pages} pantallas.',
            'what_holds' => 'Lleva el control de: {list}.',
            'what_home' => 'Abre en «{page}», que resume lo demás.',

            'screen_path' => 'Se llega en {path}',
            'screen_shows' => 'Muestra {list}.',
            'screen_actions' => 'Aquí puedes:',
            'w_table' => 'una tabla de {n}',
            'w_board' => 'un tablero de {n} en columnas por {f}',
            'w_calendar' => 'un calendario de {n} por {f}',
            'w_timeline' => 'una línea de tiempo de {n}',
            'w_charts' => '{c} gráficas',
            'w_chart' => 'una gráfica',
            'w_metrics' => '{c} indicadores',
            'w_metric' => 'un indicador',
            'w_detail' => 'la ficha completa de cada {s}',
            'w_related' => 'la lista de {n}',
            'w_form' => 'un formulario',
            'w_filters' => 'una barra de filtros',
            'w_text' => 'texto explicativo',
            'w_other' => 'otros elementos',

            'task_add' => 'Agregar {s}',
            'step_open_page' => 'Abre «{page}» en el menú.',
            'step_press' => 'Presiona «{button}».',
            'step_fill' => 'Llena el formulario. Obligatorios: {list}.',
            'step_fill_none' => 'Llena el formulario; ningún campo es obligatorio.',
            'step_submit' => 'Termina con «{label}».',
            'task_edit' => 'Para corregir un registro ya guardado, presiona «{label}» en su fila.',
            'task_delete' => 'Para quitar un registro, presiona «{label}» en su fila.',
            'task_board' => 'También puedes arrastrar las tarjetas entre columnas para cambiar {f} sin abrir el formulario.',
            'task_open' => 'Presiona «{label}» en una fila para abrir el registro completo.',
            'task_detail_children' => 'Su propia página también lista {list}.',

            'data_intro' => 'Cada {s} guarda:',
            'col_field' => 'Campo',
            'col_holds' => 'Qué guarda',
            'col_required' => 'Obligatorio',
            'yes' => 'Sí',
            'no' => 'No',

            't_string' => 'Texto corto',
            't_long_text' => 'Un párrafo',
            't_rich_text' => 'Texto con formato',
            't_email' => 'Un correo electrónico',
            't_url' => 'Una dirección web',
            't_phone' => 'Un teléfono',
            't_number' => 'Un número',
            't_currency' => 'Un importe en {c}',
            't_boolean' => 'Sí o no',
            't_date' => 'Una fecha',
            't_datetime' => 'Fecha y hora',
            't_date_range' => 'Un rango de fechas',
            't_single_select' => 'Una de: {list}',
            't_multi_select' => 'Una o varias de: {list}',
            't_relation_one' => 'A qué {n} pertenece',
            't_relation_many' => 'Sus registros ligados de {n}',
            't_formula' => 'Se calcula solo',
            't_lookup' => 'Se toma del registro ligado de {n}',
            't_rollup' => 'Se calcula a partir de sus {n}',
            't_rollup_count' => 'Cuántos registros de {n} tiene',
            't_rollup_sum' => 'La suma de sus {n}',
            't_rollup_sum_of' => 'La suma de {f} de sus {n}',
            't_rating' => 'Una calificación de 1 a 5',
            't_slider' => 'Un valor en una barra',
            't_file' => 'Un archivo',
            't_color' => 'Un color',
            't_unknown' => 'Un valor',

            'auto_intro' => 'Algunas cosas que la app hace sola:',
            'auto_line' => 'Cuando {trigger}: {steps}.',
            'trg_created' => 'se agrega un registro de {s}',
            'trg_updated' => 'se cambia un registro de {s}',
            'trg_deleted' => 'se elimina un registro de {s}',
            'trg_schedule' => 'llega la hora programada',
            'trg_webhook' => 'otro sistema llama',
            'trg_manual' => 'alguien la corre a mano',
            'trg_other' => 'se dispara',

            'who_intro' => 'La app tiene {n} roles. Lo que cada uno puede hacer con los registros:',
            'col_role' => 'Rol',
            'col_may' => 'Puede',
            'p_create' => 'agregar',
            'p_read' => 'ver',
            'p_update' => 'cambiar',
            'p_delete' => 'eliminar',
            'p_none' => 'nada todavía',
            'p_all' => 'todo',
            'who_default' => '«{role}» es lo que recibe alguien cuando nadie dice otra cosa.',

            'tip_search' => 'Toda tabla tiene un buscador: escribe y deja solo las filas que coinciden.',
            'tip_sort' => 'Haz clic en el encabezado de una columna para ordenar por ella; otra vez para invertir, una tercera para dejarla en paz.',
            'tip_columns' => 'El botón «Columnas» oculta, muestra y reordena columnas, y cada persona conserva su acomodo.',
            'tip_required' => 'Un campo obligatorio vacío detiene el formulario y dice cuál fue.',
            'tip_versions' => 'Nada se pierde cuando la app cambia: cada versión se guarda y se puede revertir.',

            's_identity' => 'Identidad',
            's_model' => 'Modelo de datos',
            's_relations' => 'Relaciones',
            's_pages' => 'Páginas y bloques',
            's_actions' => 'Acciones',
            's_workflows' => 'Automatizaciones',
            's_permissions' => 'Permisos',
            's_runtime' => 'Cómo corre',

            'k_slug' => 'Slug',
            'k_id' => 'Id de la app',
            'k_kind' => 'Tipo',
            'k_version' => 'Versión',
            'k_schema' => 'Esquema del manifiesto',
            'k_locale' => 'Idioma',
            'k_currency' => 'Moneda',
            'k_timezone' => 'Zona horaria',
            'k_counts' => 'Contenido',
            'k_counts_v' => '{o} objetos · {p} páginas · {f} campos · {w} automatizaciones',
            'k_theme' => 'Tema',
            'k_visibility' => 'Visibilidad',

            'col_name' => 'Nombre',
            'col_slug' => 'Slug',
            'col_type' => 'Tipo',
            'col_id' => 'Id',
            'col_detail' => 'Detalle',
            'obj_display' => 'Los registros se etiquetan con «{f}».',
            'obj_fields' => '{n} campos.',

            'rel_none' => 'Esta app no tiene relaciones entre sus objetos.',
            'col_from' => 'Desde',
            'col_field2' => 'Campo',
            'col_card' => 'Cardinalidad',
            'col_to' => 'Hacia',
            'col_on_delete' => 'Al eliminar',
            'rel_note' => 'Una relación se guarda como un par de campos: el lado «muchos» guarda el id del padre, el lado «uno» es su inverso y no guarda nada. Ambos llevan `inverse_field_id`, así que cualquier extremo encuentra al otro.',

            'page_path' => 'Ruta',
            'page_blocks' => '{n} bloques',

            'act_none' => 'Ningún bloque de esta app dispara una acción.',
            'col_where' => 'Dónde',
            'col_trigger' => 'Al',
            'col_does' => 'Hace',
            'trg_click' => 'presionar',
            'trg_submit' => 'enviar',
            'act_note' => 'Los valores se resuelven al correr: `{{form.x}}` es un campo del formulario que se envía, `{{params.x}}` un segmento de la URL, `{{row.x}}` la fila en la que se presionó.',

            'wf_none' => 'Esta app no tiene automatizaciones.',
            'col_when' => 'Cuándo',
            'col_only_if' => 'Solo si',
            'col_steps' => 'Pasos',

            'perm_note' => 'Un objeto sin política queda abierto dentro de la visibilidad de la app; una política lo restringe.',
            'col_object' => 'Objeto',

            'rt_url' => 'La app corre en {url}. Cada página es esa dirección más la ruta de la página.',
            'rt_data' => 'Los registros viven en el esquema tenant detrás de row-level security: una consulta queda acotada a la organización (o a la persona) dueña por Postgres mismo, no por la app.',
            'rt_change' => 'El manifiesto es la app. Se cambia con parches RFC 6902 contra los punteros JSON de abajo, y cada uno se guarda como una versión nueva que se puede revertir.',
            'rt_pointers' => 'Punteros útiles',
            'rt_read' => 'Lee esta app con `read_manifest`; cámbiala con `propose_change`; valida un borrador antes con `validate_manifest`.',
        ],

        'pt' => [
            'manual_title' => 'Manual de uso',
            'manual_subject' => 'Como usar {app}',
            'tech_title' => 'Ficha técnica',
            'tech_subject' => 'Como {app} foi construída',
            'generated_note' => 'Escrito a partir do próprio app, versão {v}. Muda quando o app muda.',

            's_what' => 'Para que serve este app',
            's_screens' => 'As telas',
            's_tasks' => 'Como fazer cada coisa',
            's_data' => 'Que informação guarda',
            's_auto' => 'O que acontece sozinho',
            's_who' => 'Quem pode fazer o quê',
            's_tips' => 'Vale saber',

            'what_counts' => 'Guarda {kinds} tipos de informação e os mostra em {pages} telas.',
            'what_holds' => 'Controla: {list}.',
            'what_home' => 'Abre em «{page}», que resume o resto.',

            'screen_path' => 'Chega-se em {path}',
            'screen_shows' => 'Mostra {list}.',
            'screen_actions' => 'Aqui você pode:',
            'w_table' => 'uma tabela de {n}',
            'w_board' => 'um quadro de {n} em colunas por {f}',
            'w_calendar' => 'um calendário de {n} por {f}',
            'w_timeline' => 'uma linha do tempo de {n}',
            'w_charts' => '{c} gráficos',
            'w_chart' => 'um gráfico',
            'w_metrics' => '{c} indicadores',
            'w_metric' => 'um indicador',
            'w_detail' => 'a ficha completa de cada {s}',
            'w_related' => 'a lista de {n}',
            'w_form' => 'um formulário',
            'w_filters' => 'uma barra de filtros',
            'w_text' => 'texto explicativo',
            'w_other' => 'outros elementos',

            'task_add' => 'Adicionar {s}',
            'step_open_page' => 'Abra «{page}» no menu.',
            'step_press' => 'Pressione «{button}».',
            'step_fill' => 'Preencha o formulário. Obrigatórios: {list}.',
            'step_fill_none' => 'Preencha o formulário; nenhum campo é obrigatório.',
            'step_submit' => 'Termine com «{label}».',
            'task_edit' => 'Para corrigir um registro já salvo, pressione «{label}» na linha dele.',
            'task_delete' => 'Para remover um registro, pressione «{label}» na linha dele.',
            'task_board' => 'Você também pode arrastar os cartões entre colunas para mudar {f} sem abrir o formulário.',
            'task_open' => 'Pressione «{label}» numa linha para abrir o registro completo.',
            'task_detail_children' => 'A sua própria página também lista {list}.',

            'data_intro' => 'Cada {s} guarda:',
            'col_field' => 'Campo',
            'col_holds' => 'O que guarda',
            'col_required' => 'Obrigatório',
            'yes' => 'Sim',
            'no' => 'Não',

            't_string' => 'Texto curto',
            't_long_text' => 'Um parágrafo',
            't_rich_text' => 'Texto formatado',
            't_email' => 'Um e-mail',
            't_url' => 'Um endereço web',
            't_phone' => 'Um telefone',
            't_number' => 'Um número',
            't_currency' => 'Um valor em {c}',
            't_boolean' => 'Sim ou não',
            't_date' => 'Uma data',
            't_datetime' => 'Data e hora',
            't_date_range' => 'Um intervalo de datas',
            't_single_select' => 'Uma de: {list}',
            't_multi_select' => 'Uma ou várias de: {list}',
            't_relation_one' => 'A qual {n} pertence',
            't_relation_many' => 'Seus registros ligados de {n}',
            't_formula' => 'Calcula-se sozinho',
            't_lookup' => 'Vem do registro ligado de {n}',
            't_rollup' => 'Calcula-se a partir dos seus registros de {n}',
            't_rollup_count' => 'Quantos registros de {n} tem',
            't_rollup_sum' => 'A soma dos seus registros de {n}',
            't_rollup_sum_of' => 'A soma de {f} nos seus registros de {n}',
            't_rating' => 'Uma nota de 1 a 5',
            't_slider' => 'Um valor numa barra',
            't_file' => 'Um arquivo',
            't_color' => 'Uma cor',
            't_unknown' => 'Um valor',

            'auto_intro' => 'Algumas coisas que o app faz sozinho:',
            'auto_line' => 'Quando {trigger}: {steps}.',
            'trg_created' => 'um registro de {s} é adicionado',
            'trg_updated' => 'um registro de {s} é alterado',
            'trg_deleted' => 'um registro de {s} é removido',
            'trg_schedule' => 'chega a hora programada',
            'trg_webhook' => 'outro sistema chama',
            'trg_manual' => 'alguém roda na mão',
            'trg_other' => 'é disparado',

            'who_intro' => 'O app tem {n} papéis. O que cada um pode fazer com os registros:',
            'col_role' => 'Papel',
            'col_may' => 'Pode',
            'p_create' => 'adicionar',
            'p_read' => 'ver',
            'p_update' => 'alterar',
            'p_delete' => 'excluir',
            'p_none' => 'nada ainda',
            'p_all' => 'tudo',
            'who_default' => '«{role}» é o que alguém recebe quando ninguém diz outra coisa.',

            'tip_search' => 'Toda tabela tem uma busca: digite e ficam só as linhas que combinam.',
            'tip_sort' => 'Clique no cabeçalho de uma coluna para ordenar por ela; de novo para inverter, uma terceira para deixar em paz.',
            'tip_columns' => 'O botão «Colunas» esconde, mostra e reordena colunas, e cada pessoa mantém o seu arranjo.',
            'tip_required' => 'Um campo obrigatório vazio trava o formulário e diz qual foi.',
            'tip_versions' => 'Nada se perde quando o app muda: cada versão é guardada e pode ser revertida.',

            's_identity' => 'Identidade',
            's_model' => 'Modelo de dados',
            's_relations' => 'Relações',
            's_pages' => 'Páginas e blocos',
            's_actions' => 'Ações',
            's_workflows' => 'Automações',
            's_permissions' => 'Permissões',
            's_runtime' => 'Como roda',

            'k_slug' => 'Slug',
            'k_id' => 'Id do app',
            'k_kind' => 'Tipo',
            'k_version' => 'Versão',
            'k_schema' => 'Esquema do manifesto',
            'k_locale' => 'Idioma',
            'k_currency' => 'Moeda',
            'k_timezone' => 'Fuso horário',
            'k_counts' => 'Conteúdo',
            'k_counts_v' => '{o} objetos · {p} páginas · {f} campos · {w} automações',
            'k_theme' => 'Tema',
            'k_visibility' => 'Visibilidade',

            'col_name' => 'Nome',
            'col_slug' => 'Slug',
            'col_type' => 'Tipo',
            'col_id' => 'Id',
            'col_detail' => 'Detalhe',
            'obj_display' => 'Os registros são rotulados por «{f}».',
            'obj_fields' => '{n} campos.',

            'rel_none' => 'Este app não tem relações entre os seus objetos.',
            'col_from' => 'De',
            'col_field2' => 'Campo',
            'col_card' => 'Cardinalidade',
            'col_to' => 'Para',
            'col_on_delete' => 'Ao excluir',
            'rel_note' => 'Uma relação é guardada como um par de campos: o lado «muitos» guarda o id do pai, o lado «um» é o seu inverso e não guarda nada. Ambos levam `inverse_field_id`, então qualquer ponta encontra a outra.',

            'page_path' => 'Rota',
            'page_blocks' => '{n} blocos',

            'act_none' => 'Nenhum bloco deste app dispara uma ação.',
            'col_where' => 'Onde',
            'col_trigger' => 'Ao',
            'col_does' => 'Faz',
            'trg_click' => 'pressionar',
            'trg_submit' => 'enviar',
            'act_note' => 'Os valores são resolvidos ao rodar: `{{form.x}}` é um campo do formulário enviado, `{{params.x}}` um trecho da URL, `{{row.x}}` a linha em que se pressionou.',

            'wf_none' => 'Este app não tem automações.',
            'col_when' => 'Quando',
            'col_only_if' => 'Só se',
            'col_steps' => 'Passos',

            'perm_note' => 'Um objeto sem política fica aberto dentro da visibilidade do app; uma política o restringe.',
            'col_object' => 'Objeto',

            'rt_url' => 'O app roda em {url}. Cada página é esse endereço mais a rota da página.',
            'rt_data' => 'Os registros vivem no esquema tenant atrás de row-level security: uma consulta é limitada à organização (ou à pessoa) dona pelo próprio Postgres, não pelo app.',
            'rt_change' => 'O manifesto é o app. Muda-se com patches RFC 6902 contra os ponteiros JSON abaixo, e cada um é guardado como uma versão nova que pode ser revertida.',
            'rt_pointers' => 'Ponteiros úteis',
            'rt_read' => 'Leia este app com `read_manifest`; mude com `propose_change`; valide um rascunho antes com `validate_manifest`.',
        ],

        'fr' => [
            'manual_title' => 'Manuel d’utilisation',
            'manual_subject' => 'Comment utiliser {app}',
            'tech_title' => 'Fiche technique',
            'tech_subject' => 'Comment {app} est construite',
            'generated_note' => 'Écrit à partir de l’application elle-même, version {v}. Il change quand elle change.',

            's_what' => 'À quoi sert cette application',
            's_screens' => 'Les écrans',
            's_tasks' => 'Comment faire chaque chose',
            's_data' => 'Ce qu’elle enregistre',
            's_auto' => 'Ce qui se fait tout seul',
            's_who' => 'Qui peut faire quoi',
            's_tips' => 'Bon à savoir',

            'what_counts' => 'Elle enregistre {kinds} types d’information et les montre sur {pages} écrans.',
            'what_holds' => 'Ce dont elle assure le suivi : {list}.',
            'what_home' => 'Elle ouvre sur «{page}», qui résume le reste.',

            'screen_path' => 'Accessible à {path}',
            'screen_shows' => 'Montre {list}.',
            'screen_actions' => 'Ici vous pouvez :',
            'w_table' => 'un tableau de {n}',
            'w_board' => 'un tableau de {n} en colonnes par {f}',
            'w_calendar' => 'un calendrier de {n} par {f}',
            'w_timeline' => 'une chronologie de {n}',
            'w_charts' => '{c} graphiques',
            'w_chart' => 'un graphique',
            'w_metrics' => '{c} indicateurs',
            'w_metric' => 'un indicateur',
            'w_detail' => 'la fiche complète de chaque {s}',
            'w_related' => 'la liste des {n}',
            'w_form' => 'un formulaire',
            'w_filters' => 'une barre de filtres',
            'w_text' => 'du texte explicatif',
            'w_other' => 'd’autres éléments',

            'task_add' => 'Ajouter {s}',
            'step_open_page' => 'Ouvrez «{page}» dans le menu.',
            'step_press' => 'Appuyez sur «{button}».',
            'step_fill' => 'Remplissez le formulaire. Obligatoires : {list}.',
            'step_fill_none' => 'Remplissez le formulaire ; aucun champ n’est obligatoire.',
            'step_submit' => 'Terminez par «{label}».',
            'task_edit' => 'Pour corriger un enregistrement déjà sauvegardé, appuyez sur «{label}» sur sa ligne.',
            'task_delete' => 'Pour retirer un enregistrement, appuyez sur «{label}» sur sa ligne.',
            'task_board' => 'Vous pouvez aussi glisser les cartes entre colonnes pour changer {f} sans ouvrir le formulaire.',
            'task_open' => 'Appuyez sur «{label}» sur une ligne pour ouvrir l’enregistrement complet.',
            'task_detail_children' => 'Sa propre page liste aussi {list}.',

            'data_intro' => 'Chaque {s} enregistre :',
            'col_field' => 'Champ',
            'col_holds' => 'Contient',
            'col_required' => 'Obligatoire',
            'yes' => 'Oui',
            'no' => 'Non',

            't_string' => 'Texte court',
            't_long_text' => 'Un paragraphe',
            't_rich_text' => 'Texte mis en forme',
            't_email' => 'Une adresse e-mail',
            't_url' => 'Une adresse web',
            't_phone' => 'Un téléphone',
            't_number' => 'Un nombre',
            't_currency' => 'Un montant en {c}',
            't_boolean' => 'Oui ou non',
            't_date' => 'Une date',
            't_datetime' => 'Une date et une heure',
            't_date_range' => 'Une plage de dates',
            't_single_select' => 'Une valeur parmi : {list}',
            't_multi_select' => 'Une ou plusieurs valeurs parmi : {list}',
            't_relation_one' => 'Appartient à un enregistrement de {n}',
            't_relation_many' => 'Ses enregistrements liés de {n}',
            't_formula' => 'Se calcule tout seul',
            't_lookup' => 'Repris de l’enregistrement lié de {n}',
            't_rollup' => 'Calculé à partir de ses {n}',
            't_rollup_count' => 'Combien de {n} il a',
            't_rollup_sum' => 'Le total de ses {n}',
            't_rollup_sum_of' => 'Le total de {f} de ses {n}',
            't_rating' => 'Une note de 1 à 5',
            't_slider' => 'Une valeur sur un curseur',
            't_file' => 'Un fichier',
            't_color' => 'Une couleur',
            't_unknown' => 'Une valeur',

            'auto_intro' => 'Quelques choses que l’application fait toute seule :',
            'auto_line' => 'Quand {trigger} : {steps}.',
            'trg_created' => 'un enregistrement de {s} est ajouté',
            'trg_updated' => 'un enregistrement de {s} est modifié',
            'trg_deleted' => 'un enregistrement de {s} est supprimé',
            'trg_schedule' => 'l’heure prévue arrive',
            'trg_webhook' => 'un autre système appelle',
            'trg_manual' => 'quelqu’un la lance à la main',
            'trg_other' => 'elle est déclenchée',

            'who_intro' => 'L’application a {n} rôles. Ce que chacun peut faire des enregistrements :',
            'col_role' => 'Rôle',
            'col_may' => 'Peut',
            'p_create' => 'ajouter',
            'p_read' => 'voir',
            'p_update' => 'modifier',
            'p_delete' => 'supprimer',
            'p_none' => 'rien encore',
            'p_all' => 'tout',
            'who_default' => '«{role}» est ce que reçoit quelqu’un quand personne n’en décide autrement.',

            'tip_search' => 'Chaque tableau a une recherche : tapez et il ne garde que les lignes qui correspondent.',
            'tip_sort' => 'Cliquez sur l’en-tête d’une colonne pour trier ; encore une fois pour inverser, une troisième pour la laisser tranquille.',
            'tip_columns' => 'Le bouton «Colonnes» masque, montre et réordonne les colonnes, et chacun garde sa disposition.',
            'tip_required' => 'Un champ obligatoire laissé vide arrête le formulaire et dit lequel.',
            'tip_versions' => 'Rien n’est perdu quand l’application change : chaque version est gardée et peut être annulée.',

            's_identity' => 'Identité',
            's_model' => 'Modèle de données',
            's_relations' => 'Relations',
            's_pages' => 'Pages et blocs',
            's_actions' => 'Actions',
            's_workflows' => 'Automatisations',
            's_permissions' => 'Permissions',
            's_runtime' => 'Comment elle tourne',

            'k_slug' => 'Slug',
            'k_id' => 'Id de l’application',
            'k_kind' => 'Type',
            'k_version' => 'Version',
            'k_schema' => 'Schéma du manifeste',
            'k_locale' => 'Langue',
            'k_currency' => 'Devise',
            'k_timezone' => 'Fuseau horaire',
            'k_counts' => 'Contenu',
            'k_counts_v' => '{o} objets · {p} pages · {f} champs · {w} automatisations',
            'k_theme' => 'Thème',
            'k_visibility' => 'Visibilité',

            'col_name' => 'Nom',
            'col_slug' => 'Slug',
            'col_type' => 'Type',
            'col_id' => 'Id',
            'col_detail' => 'Détail',
            'obj_display' => 'Les enregistrements sont étiquetés par «{f}».',
            'obj_fields' => '{n} champs.',

            'rel_none' => 'Cette application n’a aucune relation entre ses objets.',
            'col_from' => 'De',
            'col_field2' => 'Champ',
            'col_card' => 'Cardinalité',
            'col_to' => 'Vers',
            'col_on_delete' => 'À la suppression',
            'rel_note' => 'Une relation est stockée comme une paire de champs : le côté «plusieurs» garde l’id du parent, le côté «un» est son inverse et ne garde rien. Les deux portent `inverse_field_id`, donc chaque extrémité retrouve l’autre.',

            'page_path' => 'Chemin',
            'page_blocks' => '{n} blocs',

            'act_none' => 'Aucun bloc de cette application ne déclenche d’action.',
            'col_where' => 'Où',
            'col_trigger' => 'Au',
            'col_does' => 'Fait',
            'trg_click' => 'clic',
            'trg_submit' => 'envoi',
            'act_note' => 'Les valeurs sont résolues à l’exécution : `{{form.x}}` est un champ du formulaire envoyé, `{{params.x}}` un segment de l’URL, `{{row.x}}` la ligne sur laquelle on a appuyé.',

            'wf_none' => 'Cette application n’a aucune automatisation.',
            'col_when' => 'Quand',
            'col_only_if' => 'Seulement si',
            'col_steps' => 'Étapes',

            'perm_note' => 'Un objet sans politique reste ouvert dans la visibilité de l’application ; une politique le restreint.',
            'col_object' => 'Objet',

            'rt_url' => 'L’application tourne à {url}. Chaque page est cette adresse plus le chemin de la page.',
            'rt_data' => 'Les enregistrements vivent dans le schéma tenant derrière row-level security : une requête est limitée à l’organisation (ou à la personne) propriétaire par Postgres lui-même, pas par l’application.',
            'rt_change' => 'Le manifeste EST l’application. Il se modifie par des patchs RFC 6902 sur les pointeurs JSON ci-dessous, chacun enregistré comme une nouvelle version annulable.',
            'rt_pointers' => 'Pointeurs utiles',
            'rt_read' => 'Lisez cette application avec `read_manifest` ; modifiez-la avec `propose_change` ; validez un brouillon avec `validate_manifest`.',
        ],
    ];

    private function __construct(private readonly string $lang) {}

    public static function for(?string $locale): self
    {
        return new self(SemanticLexicon::resolve($locale));
    }

    public function lang(): string
    {
        return $this->lang;
    }

    /**
     * One phrase, with its `{placeholders}` filled.
     *
     * @param  array<string, string|int>  $replace
     */
    public function get(string $key, array $replace = []): string
    {
        $phrase = self::WORDS[$this->lang][$key] ?? self::WORDS['en'][$key] ?? $key;

        foreach ($replace as $token => $value) {
            $phrase = str_replace('{'.$token.'}', (string) $value, $phrase);
        }

        return $phrase;
    }

    /**
     * "A, B and C" in the app's language.
     *
     * @param  list<string>  $items
     */
    public function list(array $items): string
    {
        $items = array_values(array_filter($items, fn (string $i): bool => trim($i) !== ''));

        if (count($items) < 2) {
            return $items[0] ?? '';
        }

        $and = ['en' => 'and', 'es' => 'y', 'pt' => 'e', 'fr' => 'et'][$this->lang] ?? 'and';
        $last = (string) end($items);

        // "Contratos e Incidencias", never "y Incidencias": Spanish swaps the
        // conjunction before an i- sound, and these lists are built from object
        // names, so it comes up as soon as an app has an Incidencias.
        if ($this->lang === 'es' && preg_match('/^(i|hi)(?!e)/iu', $this->fold($last)) === 1) {
            $and = 'e';
        }

        return implode(', ', array_slice($items, 0, -1)).' '.$and.' '.$last;
    }

    /** Accent-folded, so "Índices" is recognised as starting with an i. */
    private function fold(string $text): string
    {
        return (string) (Str::ascii($text) ?: $text);
    }

    /**
     * The keys a language is missing against the English table.
     *
     * Asked this way rather than by comparing VALUES: half the technical
     * headings are the same word in four languages ("Slug", "Id", and every one
     * of Relations/Actions/Permissions/Version/Type in French), so a
     * value-difference check reports translations that are correct and quietly
     * accepts a missing key that fell back.
     *
     * @return list<string>
     */
    public static function missingKeys(string $lang): array
    {
        $table = self::WORDS[$lang] ?? [];

        return array_values(array_diff(self::keys(), array_keys($table)));
    }

    /**
     * Every key the English table defines — what a test asserts the other
     * languages against, so a half-translated document is caught here rather
     * than found as an English heading inside an otherwise Spanish manual.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::WORDS['en']);
    }

    /**
     * @return list<string>
     */
    public static function languages(): array
    {
        return array_keys(self::WORDS);
    }
}
