<?php

/**
 * Questplugin for RPG pls read readme and Wiki! 
 * https://github.com/katjalennartz/questsystem/wiki
 * https://github.com/katjalennartz/questsystem/blob/main/README.md
 *
 * @author risuena
 * @version 1.0
 * @copyright risuena 2025
 * 
 */
// enable for Debugging:
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', true);



// Disallow direct access to this file for security reasons
if (!defined("IN_MYBB")) {
  die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}
function questsystem_info()
{
  global $lang, $db, $plugins_cache, $mybb;
  $lang->load("questsystem");
  return array(
    "name" => $lang->questsystem_name,
    "description" => $lang->questsystem_description,
    "author" => $lang->questsystem_author,
    "authorsite" => $lang->questsystem_web,
    "version" => "1.0",
    "compatability" => "18*"
  );
}

function questsystem_is_installed()
{
  global $db;
  if ($db->table_exists("questsystem_type")) {
    return true;
  }
  return false;
}

function questsystem_install()
{
  global $db, $cache;
  // RPG Stuff Modul muss vorhanden sein
  if (!file_exists(MYBB_ADMIN_DIR . "/modules/rpgstuff/module_meta.php")) {
    flash_message("Das ACP Modul <a href=\"https://github.com/little-evil-genius/rpgstuff_modul\" target=\"_blank\">\"RPG Stuff\"</a> muss vorhanden sein!", 'error');
    admin_redirect('index.php?module=config-plugins');
  }

  questsystem_uninstall();

  questsystem_add_db();

  questsystem_add_settings();

  questsystem_add_templates();

  $db->insert_query('tasks', array(
    'title' => 'Task für Quests',
    'description' => 'Abgelaufene Quests finden und Punkte abziehen',
    'file' => 'questsystem',
    'minute' => '0',
    'hour' => '01',
    'day' => '*',
    'month' => '*',
    'weekday' => '*',
    'nextrun' => TIME_NOW,
    'lastrun' => 0,
    'enabled' => 1,
    'logging' => 1,
    'locked' => 0,
  ));

  $cache->update_tasks();


  $css = questsystem_stylesheet();

  require_once MYBB_ADMIN_DIR . "inc/functions_themes.php";

  $sid = $db->insert_query("themestylesheets", $css);
  $db->update_query("themestylesheets", array("cachefile" => "css.php?stylesheet=" . $sid), "sid = '" . $sid . "'", 1);

  $tids = $db->simple_select("themes", "tid");
  while ($theme = $db->fetch_array($tids)) {
    update_theme_stylesheet_list($theme['tid']);
  }
}

function questsystem_uninstall()
{
  global $db, $cache;
  // Einstellungen entfernen
  $db->delete_query("settings", "name LIKE 'questsystem_%'");
  $db->delete_query('settinggroups', "name = 'questsystem'");
  //templates noch entfernen
  rebuild_settings();

  if ($db->table_exists("questsystem_type")) {
    $db->drop_table("questsystem_type");
  }
  if ($db->table_exists("questsystem_quest")) {
    $db->drop_table("questsystem_quest");
  }
  if ($db->table_exists("questsystem_quest_user")) {
    $db->drop_table("questsystem_quest_user");
  }
  if ($db->table_exists("questsystem_points")) {
    $db->drop_table("questsystem_points");
  }

  $db->delete_query("templates", "title LIKE 'questsystem%'");
  $db->delete_query("templategroups", "prefix = 'questsystem'");

  require_once MYBB_ADMIN_DIR . "inc/functions_themes.php";
  $db->delete_query("themestylesheets", "name = 'questsystem.css'");
  $query = $db->simple_select("themes", "tid");
  while ($theme = $db->fetch_array($query)) {
    update_theme_stylesheet_list($theme['tid']);
  }

  $db->delete_query('tasks', "file='questsystem'");
  $cache->update_tasks();
}

function questsystem_activate()
{
  global $db, $mybb, $cache;
  //add your variables to templates
  include MYBB_ROOT . "/inc/adminfunctions_templates.php";
  find_replace_templatesets("index", "#" . preg_quote('{$header}') . "#i", '{$header}{$questsystem_index_mod}');
  find_replace_templatesets("postbit", "#" . preg_quote('{$post[\'button_edit\']}') . "#i", '{$post[\'questbutton\']}{$post[\'button_edit\']}');
  find_replace_templatesets("member_profile", "#" . preg_quote('{$awaybit}') . "#i", '{$awaybit}{$questsystem_member}');

  //Default Berechtigungen für Admins
  change_admin_permission('rpgstuff', 'questsystem', 1);

  if (function_exists('myalerts_is_activated') && myalerts_is_activated()) {

    $alertTypeManager = MybbStuff_MyAlerts_AlertTypeManager::getInstance();

    if (!$alertTypeManager) {
      $alertTypeManager = MybbStuff_MyAlerts_AlertTypeManager::createInstance($db, $cache);
    }

    //alert: Questvorschlag wurde akzeptiert
    $alertTypeQuestAccepted = new MybbStuff_MyAlerts_Entity_AlertType();
    $alertTypeQuestAccepted->setCanBeUserDisabled(true);
    $alertTypeQuestAccepted->setCode("questsystem_QuestAccepted");
    $alertTypeQuestAccepted->setEnabled(true);
    $alertTypeManager->add($alertTypeQuestAccepted);

    //alert: eingereichtes Quest wurde abgelehnt
    $alertTypeQuestAccept = new MybbStuff_MyAlerts_Entity_AlertType();
    $alertTypeQuestAccept->setCanBeUserDisabled(true);
    $alertTypeQuestAccept->setCode("questsystem_QuestAccept");
    $alertTypeQuestAccept->setEnabled(true);
    $alertTypeManager->add($alertTypeQuestAccept);

    //alert: eingereichtes Quest wurde abgelehnt
    $alertTypeQuestDeny = new MybbStuff_MyAlerts_Entity_AlertType();
    $alertTypeQuestDeny->setCanBeUserDisabled(true);
    $alertTypeQuestDeny->setCode("questsystem_QuestDeny");
    $alertTypeQuestDeny->setEnabled(true);
    $alertTypeManager->add($alertTypeQuestDeny);


    //alert: User wurde ein Quest zugeteilt
    $alertTypeQuestgiveUserw = new MybbStuff_MyAlerts_Entity_AlertType();
    $alertTypeQuestgiveUserw->setCanBeUserDisabled(true);
    $alertTypeQuestgiveUserw->setCode("questsystem_giveUser");
    $alertTypeQuestgiveUserw->setEnabled(true);
    $alertTypeManager->add($alertTypeQuestgiveUserw);

    //alert: User wurde als Partner eingetragen
    $alertTypeQuestPartner = new MybbStuff_MyAlerts_Entity_AlertType();
    $alertTypeQuestPartner->setCanBeUserDisabled(true);
    $alertTypeQuestPartner->setCode("questsystem_QuestPartner");
    $alertTypeQuestPartner->setEnabled(true);
    $alertTypeManager->add($alertTypeQuestPartner);
  }
  $cache->update_usergroups();
}

function questsystem_deactivate()
{
  global $db, $mybb, $cache;
  //remove alerts
  if (class_exists('MybbStuff_MyAlerts_AlertTypeManager')) {
    $alertTypeManager = MybbStuff_MyAlerts_AlertTypeManager::getInstance();

    if (!$alertTypeManager) {
      $alertTypeManager = MybbStuff_MyAlerts_AlertTypeManager::createInstance($db, $cache);
    }
    $alertTypeManager->deleteByCode('questsystem_QuestDeny');
    $alertTypeManager->deleteByCode('questsystem_QuestAccept');
    $alertTypeManager->deleteByCode('questsystem_giveUser');
    $alertTypeManager->deleteByCode('questsystem_QuestPartner');
    $alertTypeManager->deleteByCode('questsystem_QuestAccepted');
  }

  //remove templates
  include MYBB_ROOT . "/inc/adminfunctions_templates.php";
  find_replace_templatesets("index", "#" . preg_quote('{$questsystem_index_mod}') . "#i", '');
  find_replace_templatesets("postbit", "#" . preg_quote('{$post[\'questbutton\']}') . "#i", '');
  find_replace_templatesets("member_profile", "#" . preg_quote('{$questsystem_member}') . "#i", '');
}

/**
 * action handler fürs ACP -> Konfiguration
 */
$plugins->add_hook("admin_rpgstuff_action_handler", "questsystem_admin_rpgstuff_action_handler");
// $plugins->add_hook("admin_config_action_handler", "questsystem_admin_config_action_handler");
function questsystem_admin_rpgstuff_action_handler(&$actions)
{
  $actions['questsystem'] = array('active' => 'questsystem', 'file' => 'questsystem');
}

/**
 * Berechtigungen im ACP
 */
$plugins->add_hook("admin_rpgstuff_permissions", "questsystem_admin_rpgstuff_permissions");
// $plugins->add_hook("admin_config_permissions", "questsystem_admin_config_permissions");
function questsystem_admin_rpgstuff_permissions(&$admin_permissions)
{
  global $lang;
  $lang->load("questsystem");

  $admin_permissions['questsystem'] = $lang->questsystem_adminsetting;

  return $admin_permissions;
}

/**
 * Menü im ACP einfügen
 */
$plugins->add_hook("admin_rpgstuff_menu", "questsystem_admin_rpgstuff_menu");
function questsystem_admin_rpgstuff_menu(&$sub_menu)
{
  global $mybb, $lang;
  $lang->load("questsystem");

  $sub_menu[] = [
    "id" => "questsystem",
    "title" => $lang->questsystem_name,
    "link" => "index.php?module=rpgstuff-questsystem"
  ];
}

/**
 * Verwaltung der Quests im ACP
 * (Anlegen/Löschen von Aufgabentypen etc)
 * Viiiiiiiel Zeug ;) 
 */
$plugins->add_hook("admin_load", "questsystem_admin_load");
function questsystem_admin_load()
{
  global $mybb, $db, $lang, $page, $run_module, $action_file;
  $lang->load("questsystem");

  if ($page->active_action != 'questsystem') {
    return false;
  }
  // Übersicht 
  if ($run_module == 'rpgstuff' && $action_file == 'questsystem') {

    // Allgemein - Welche Questsysteme gibt es
    if ($mybb->input['action'] == "" || !isset($mybb->input['action'])) {
      $page->add_breadcrumb_item($lang->questsystem_name);
      $page->output_header($lang->questsystem_name);

      // submenü erstellen
      $sub_tabs = questsystem_do_submenu();
      $page->output_nav_tabs($sub_tabs, 'questsystem');
      // fehleranzeige
      if (isset($errors)) {
        $page->output_inline_error($errors);
      }

      //Hier erstellen wir jetzt unsere ganzen Felder
      //erst brauchen wir ein Formular
      $form = new Form("index.php?module=rpgstuff-questsystem", "post");
      $form_container = new FormContainer($lang->questsystem_manage_overview);
      $form_container->output_row_header($lang->questsystem_manage_overview);
      $form_container->output_row_header("<div style=\"text-align: center;\">{$lang->questsystem_manage_options}</div>");

      //Alle Einträge aus Questsystem bekommen um sie anzuzeigen, nach Namen sortiert
      $get_questtype = $db->simple_select("questsystem_type", "*", "", ["order_by" => 'name']);
      //alle durchgehen und Spalte erstellen
      while ($questtype = $db->fetch_array($get_questtype)) {
        $form_container->output_cell('<strong>' . htmlspecialchars_uni($questtype['name']) . '</strong>');
        //menü für löschen & editieren
        $popup = new PopupMenu("questsystem_{$questtype['id']}", "verwalten");
        $popup->add_item(
          "edit",
          "index.php?module=rpgstuff-questsystem&amp;action=questsystem_edit&amp;id={$questtype['id']}"
        );
        if ($questtype['active'] == 1) {
          $popup->add_item(
            "deaktivieren",
            "index.php?module=rpgstuff-questsystem&amp;action=questsystem_deactivate&amp;id={$questtype['id']}"
              . "&amp;my_post_key={$mybb->post_code}"
          );
        } else {
          $popup->add_item(
            "aktivieren",
            "index.php?module=rpgstuff-questsystem&amp;action=questsystem_activate&amp;id={$questtype['id']}"
              . "&amp;my_post_key={$mybb->post_code}"
          );
        }
        $popup->add_item(
          "delete",
          "index.php?module=rpgstuff-questsystem&amp;action=questsystem_delete&amp;id={$questtype['id']}"
            . "&amp;my_post_key={$mybb->post_code}"
        );

        $form_container->output_cell($popup->fetch(), array("class" => "align_center"));
        $form_container->construct_row();
      }

      $form_container->end();
      $form->end();
      $page->output_footer();
      die();
    }

    // Questtyp erstellen
    if ($mybb->get_input('action') == "questsystem_questtype_add") {
      //Das ganze einmal abspeichern und in der Datenbank eintragen
      if ($mybb->request_method == "post") {
        //als erstes fangen wir Fehler und leere Eingaben ab
        if (empty($mybb->get_input('name'))) {
          $errors[] = $lang->questsystem_cqt_error_name;
        }
        if (empty($mybb->get_input('name_db'))) {
          $errors[] = $lang->questsystem_cqt_error_name_db;
        }
        if (empty($mybb->get_input('descr'))) {
          $errors[] = $lang->questsystem_cqt_error_typdescr;
        }
        if (empty($mybb->get_input('groupselect'))) {
          $errors[] = $lang->questsystem_cqt_error_groupselect;
        }
        if (empty($mybb->get_input('enddays'))) {
          $end = "0";
        } else {
          $end =  $mybb->get_input('enddays');
        }
        if (empty($mybb->get_input('points_add'))) {
          $points_add = "0";
        } else {
          $points_add =  $mybb->get_input('points_add');
        }
        if (empty($mybb->get_input('points_minus'))) {
          $points_minus = "0";
        } else {
          $points_minus =  $mybb->get_input('points_minus');
        }
        if (empty($mybb->get_input('group_profilefield'))) {
          $mybb->input['group_profilefield'] = "0";
        } else {
          $mybb->input['group_profilefield'] = $mybb->get_input('group_profilefield');
        }
        if (empty($mybb->get_input('group_profilefield_type'))) {
          $mybb->input['group_profilefield_type'] = "0";
        } else {
          $mybb->input['group_profilefield_type'] = $mybb->get_input('group_profilefield_type');
        }
        if (empty($mybb->get_input('delete'))) {
          $mybb->input['delete'] = "0";
        }
        if (empty($mybb->get_input('finish'))) {
          $errors[] = $lang->questsystem_manage_cqt_form_finish_err;
        }
        if ($mybb->get_input('groupselect') == "custom") {
          if ($mybb->get_input('groupselect_sel') == "") {
            $errors[] = $lang->questsystem_cqt_error_groupselect;
          }
        }
        if (empty($errors)) {
          if ($mybb->get_input('groupselect') == "custom") {
            // var_dump($mybb->input['groupselect_sel']);
            $grpstring = implode(",", $mybb->get_input('groupselect_sel'));
          }
          if ($mybb->get_input('groupselect') == "all") {
            $grpstring = "-1";
          }
          if ($mybb->get_input('groupselect') == "none") {
            $grpstring = "";
          }

          //einfügen
          $insert = [
            "name" => $db->escape_string($mybb->get_input('name')),
            "type" => $db->escape_string($mybb->get_input('name_db')),
            "typedescr" => $db->escape_string($mybb->get_input('descr')),
            "groups_allowed" => $grpstring,
            // "groups_questdepend" => implode(",", $mybb->input['users']),
            "enddays" => $end,
            "points_minus" => $points_minus,
            "points_add" => $points_add,
            "admin_assignment" => $mybb->get_input('admin_assignment'),
            "repeat" => $mybb->get_input('repeat'),
            "delete" => $mybb->get_input('delete'),
            "unique" => $mybb->get_input('unique'),
            "group_str" => $db->escape_string($mybb->get_input('group_profilefield')),
            "group_fid" => $db->escape_string($mybb->get_input('group_profilefield_type')),
            "groupquest" => $mybb->get_input('grouptask'),
            "finish_typ" => $db->escape_string($mybb->get_input('finish')),
            "user_add" => $mybb->get_input('user_add'),
          ];
          $db->insert_query("questsystem_type", $insert);
          $mybb->input['module'] = "questsystem";
          $mybb->input['action'] = "Erfolgreich gespeichert";
          // log_admin_action("users: " . htmlspecialchars_uni(implode(",", $mybb->input['users'])) . " Questsystem:" . htmlspecialchars_uni(implode(",", $mybb->input['awards'])));
          flash_message("Erfolgreich gespeichert", 'success');
          admin_redirect("index.php?module=rpgstuff-questsystem");
          die(); //evt. wieder rauswerfen
        }
      }
      //Formularanzeige
      $page->add_breadcrumb_item($lang->questsystem_manage_createquesttype);
      $page->output_header($lang->questsystem_name);
      $sub_tabs = questsystem_do_submenu();
      $page->output_nav_tabs($sub_tabs, 'questsystem_questtype_add');

      if (isset($errors)) {
        $page->output_inline_error($errors);
      }

      //Erst einmal das Formular erstellen
      $form = new Form("index.php?module=rpgstuff-questsystem&amp;action=questsystem_questtype_add", "post", "", 1);
      $form_container = new FormContainer("Questtyp erstellen");
      $form_container->output_row(
        $lang->questsystem_manage_cqt_formname, //Name 
        $lang->questsystem_manage_cqt_formname_descr,
        $form->generate_text_box('name', $mybb->input['name'])
      );

      //maschinennamen
      $form_container->output_row(
        $lang->questsystem_manage_cqt_formname_db, //Name 
        $lang->questsystem_manage_cqt_formname_db_descr,
        $form->generate_text_box('name_db', $mybb->input['name_db'])
      );

      //Beschreibung des Typs
      $form_container->output_row(
        $lang->questsystem_manage_cqt_typdescr, //Name 
        $lang->questsystem_manage_cqt_typdescr_descr,
        $form->generate_text_area('descr', $mybb->input['descr'])
      );

      //nur bestimmte Gruppen?  (allgemein)
      print_selection_javascript();
      $selected_values = "";
      $select_code = "
        <dl style=\"margin-top: 0; margin-bottom: 0; width: 100%\">
          <dt><label style=\"display: block;\"><input type=\"radio\" name=\"groupselect\" value=\"all\" class=\"groupselect_forums_groups_check\" onclick=\"checkAction('groupselect');\" style=\"vertical-align: middle;\" /> <strong>{$lang->all_groups}</strong></label></dt>
          <dt><label style=\"display: block;\">
          <input type=\"radio\" name=\"groupselect\" value=\"custom\"  class=\"groupselect_forums_groups_check\" onclick=\"checkAction('groupselect');\" style=\"vertical-align: middle;\" /> <strong>{$lang->select_groups}</strong></label></dt>
          <dd style=\"margin-top: 4px;\" id=\"groupselect_forums_groups_custom\" class=\"groupselect_forums_groups\">
            <table cellpadding=\"4\">
              <tr>
                <td colspan=\"2\">" .
        $form->generate_group_select(
          'groupselect_sel[]',
          $selected_values,
          array('id' => 'groupselect_sel', 'multiple' => true, 'size' => 5)
        ) . "</td>
              </tr>
            </table>
          </dd>
          <dt><label style=\"display: block;\"><input type=\"radio\" name=\"groupselect\" value=\"none\"  class=\"groupselect_forums_groups_check\" onclick=\"checkAction('groupselect');\" style=\"vertical-align: middle;\" /> <strong>{$lang->none}</strong></label></dt>
        </dl>
        <script type=\"text/javascript\">
          checkAction('groupselect');
        </script>";
      $form_container->output_row($lang->questsystem_manage_cqt_group, $lang->questsystem_manage_cqt_group_descr, $select_code, '', array(), array('id' => 'row_groupselect'));

      //Nur für beestimmte Gruppen/Profilfeld
      $form_container->output_row(
        $lang->questsystem_manage_cqt_groupprofielfield, //Name 
        $lang->questsystem_manage_cqt_groupprofielfield_descr,
        $form->generate_text_box('group_profilefield', $mybb->input['group_profilefield'])
      );
      //profilfeld oder type
      $form_container->output_row(
        $lang->questsystem_manage_cqt_groupprofielfield_type, //Name 
        $lang->questsystem_manage_cqt_groupprofielfield_type_descr,
        $form->generate_text_box('group_profilefield_type', $mybb->input['group_profilefield_type'])
      );
      //gibt es ein ablaufdatum? & wieviele tage hat man zeit?
      $form_container->output_row(
        $lang->questsystem_manage_cqt_formenddate, //Name 
        $lang->questsystem_manage_cqt_formenddate_descr,
        $form->generate_numeric_field('enddays', $mybb->input['enddays'], array('id' => 'disporder', 'min' => 0))
      );
      //Punkte hinzufügen -> wenn ja wieviele
      $form_container->output_row(
        $lang->questsystem_manage_cqt_addpoints, //Name 
        $lang->questsystem_manage_cqt_addpoints_descr,
        $form->generate_numeric_field('points_add', $mybb->input['points_add'], array('id' => 'disporder', 'min' => 0))
      );
      //Punkte abziehen -> wenn ja wieviele
      $form_container->output_row(
        $lang->questsystem_manage_cqt_subpoints, //Name 
        $lang->questsystem_manage_cqt_subpoints_descr,
        $form->generate_numeric_field('points_minus', $mybb->input['points_minus'], array('id' => 'disporder', 'min' => 0))
      );
      //Wird zufällig ein Quest des Typs ausgewählt? 
      $form_container->output_row(
        $lang->questsystem_manage_cqt_formadmin_assignment, //Name 
        $lang->questsystem_manage_cqt_formadmin_assignment_descr,
        $form->generate_yes_no_radio('admin_assignment', 0)
      );
      //Wird zufällig ein Quest des Typs ausgewählt? 
      $form_container->output_row(
        $lang->questsystem_manage_cqt_formadmin_delete, //Name 
        $lang->questsystem_manage_cqt_formadmin_delete_descr,
        $form->generate_yes_no_radio('delete', 0)
      );
      //Darf ein Quest des Typs vom gleichen User mehrfach gezogen werden (nach Beendigung)? 
      $form_container->output_row(
        $lang->questsystem_manage_cqt_formrepeat, //Name 
        $lang->questsystem_manage_cqt_formrepeat_descr,
        $form->generate_yes_no_radio('repeat', 1)
      );

      //darf das quest nur von einem user gleichzeitig bearbeitet werden
      $form_container->output_row(
        $lang->questsystem_manage_cqt_formunique, //Name 
        $lang->questsystem_manage_cqt_formunique_descr,
        $form->generate_yes_no_radio('unique', 1)
      );

      //darf es in diesem Questtypen allgemein Gruppenquests geben
      $form_container->output_row(
        $lang->questsystem_manage_cqt_grouptask, //Name 
        $lang->questsystem_manage_cqt_grouptask_descr,
        $form->generate_yes_no_radio('grouptask', $mybb->input['grouptask'], 1)
      );

      //Darf pro quest bestimmt werden welche gruppen (d.h. Questtyp kann einzel und gruppenquests haben)
      $form_container->output_row(
        $lang->questsystem_manage_cqt_form_useradd, //Name 
        $lang->questsystem_manage_cqt_form_useradd_descr,
        $form->generate_yes_no_radio('user_add', 1)
      );

      //einreichen über post // thread (quasi kleine aufgabe(nur post) oder szenenrelevante aufgabe)
      $quest_finish_options = array(
        $form->generate_radio_button("finish", "post", $lang->questsystem_manage_cqt_form_finish_post, array("checked" => 1)),
        $form->generate_radio_button("finish", "szene", $lang->questsystem_manage_cqt_form_finish_szene, array("checked" => 0)),
      );


      $form_container->output_row($lang->questsystem_manage_cqt_form_finish_descr, "", implode("<br />", $quest_finish_options));

      $form_container->end();
      $buttons[] = $form->generate_submit_button($lang->questsystem_manage_cqt_form_create);
      $form->output_submit_wrapper($buttons);
      $form->end();
      $page->output_footer();

      die();
    }

    // Ein Quest hinzufügen
    if ($mybb->get_input('action') == "questsystem_quest_add") {
      if ($mybb->request_method == "post") {
        if (empty($mybb->get_input('qname'))) {
          $errors[] = $lang->questsystem_manage_cqt_questname_error;
        }
        if (empty($mybb->input['types'])) {
          $errors[] = $lang->questsystem_manage_cqt_questtypes_error;
        }
        if ($mybb->input['types'][0] == '') {
          $errors[] = $lang->questsystem_manage_cqt_questtypes_error;
        }
        if (empty($mybb->input['qdescr'])) {
          $errors[] = $lang->questsystem_manage_cqt_qdescr_error;
        }
        $groupflag = $db->fetch_field($db->simple_select("questsystem_type", "groupquest", "id = '{$mybb->input['types'][0]}'"), "groupquest");
        if ($mybb->get_input('group') == 1 && $groupflag == 0) {
          $errors[] = "Dieser Typ erlaubt keine Gruppenquests";
        }
        if (empty($errors)) {
          $insert = [
            "name" => $db->escape_string($mybb->get_input('qname')),
            "type" => $mybb->input['types'][0],
            "questdescr" => $db->escape_string($mybb->get_input('qdescr')),
            "groupquest" => $mybb->get_input('group'),
            "admincheck" => 1,
          ];
          $db->insert_query("questsystem_quest", $insert);
          $mybb->input['module'] = "questsystem";
          $mybb->input['action'] = "Erfolgreich gespeichert";
          flash_message("Erfolgreich gespeichert", 'success');
          admin_redirect("index.php?module=rpgstuff-questsystem");
          die();
        }
      }

      //Formular um ein Questhinzuzufügen
      $page->add_breadcrumb_item($lang->questsystem_manage_createquest);
      $page->output_header($lang->questsystem_name);
      $sub_tabs = questsystem_do_submenu();
      $page->output_nav_tabs($sub_tabs, 'questsystem_quest_add');

      if (isset($errors)) {
        $page->output_inline_error($errors);
      }
      $form = new Form("index.php?module=rpgstuff-questsystem&amp;action=questsystem_quest_add", "post", "", 1);
      $form_container = new FormContainer("Quest erstellen");
      $form_container->output_row(
        $lang->questsystem_manage_cqt_questname, //Name 
        $lang->questsystem_manage_cqt_questname_descr,
        $form->generate_text_box('qname', $mybb->input['qname'])
      );
      $questtype = $db->simple_select("questsystem_type", "id,name", "", array("order" => "name"));
      while ($result = $db->fetch_array($questtype)) {
        $id = $result['id'];
        $alltypes[$id] = $result['name'];
      }

      //Reihe für Die User
      $form_container->output_row(
        $lang->questsystem_manage_cqt_questtypes, //name
        $lang->questsystem_manage_cqt_questtypes_descr,
        $form->generate_select_box(
          'types[]',
          $alltypes,
          '',
          array('id' => 'id', 'size' => 5, 'multiple' => null),
        ),
        'questtype'
      );
      //name des Quests
      $form_container->output_row(
        $lang->questsystem_manage_cqt_questdescr,
        $lang->questsystem_manage_cqt_questdescr_descr,
        $form->generate_text_area('qdescr', $mybb->input['qdescr'])
      );

      //Darf pro quest bestimmt werden welche gruppen (d.h. Questtyp kann einzel und gruppenquests haben)
      $form_container->output_row(
        $lang->questsystem_manage_cqt_groupquest, //Name 
        $lang->questsystem_manage_cqt_groupquest_descr,
        $form->generate_yes_no_radio('group', 0)
      );

      $form_container->end();
      $buttons[] = $form->generate_submit_button($lang->questsystem_manage_cqt_form_create);
      $form->output_submit_wrapper($buttons);
      $form->end();
      $page->output_footer();
      die();
    }

    // Management der Quests 
    // Übersicht, welche Quests gibt es, welche User bearbeiten sie gerade
    // Zuteilung von Quests an User die warten
    if ($mybb->get_input('action') == "questsystem_quest_manage") {
      $page->add_breadcrumb_item("Quests verwalten");
      $page->output_header($lang->questsystem_name);
      $sub_tabs = questsystem_do_submenu();
      $page->output_nav_tabs($sub_tabs, 'questsystem_quest_manage');

      if (isset($errors)) {
        $page->output_inline_error($errors);
      }

      //Alle Questtypen bekommen 
      $get_questtypes = $db->simple_select("questsystem_type", "*");
      //Unser form erstellen
      $form = new Form("index.php?module=rpgstuff-questsystem&amp;action=questsystem_quest_manage&amp;manage=quests", "post");
      $form_container = new FormContainer("Übersicht Quests der User nach Questtyp");

      //Die einzelnen Questtypen durchgehen
      while ($questtype = $db->fetch_array($get_questtypes)) {
        $form_container->construct_row();
        // Die Quests zum Typen bekommen
        $get_quests = $db->simple_select("questsystem_quest", "*", "type = {$questtype['id']}");
        $questding = "";
        // Wieviele Tage Zeit hat man
        $daystoend = $db->fetch_field($db->simple_select("questsystem_type ", "*", "id = {$questtype['id']}"), "enddays");
        // Anzeige der Tage 
        if ($daystoend == "" || $daystoend == "0") {
          $daystoend = 0;
          $daystoendstr = "Kein Ablaufdatum ";
        } else {
          $daystoendstr = "{$daystoend} Tage(e) ";
        }
        // quests dieses Questtyps durchgehen
        while ($quest = $db->fetch_array($get_quests)) {
          // ist es ein Gruppenquest oder Einzelquest
          if ($quest['groupquest'] == 1) {
            $group = "- Gruppenquest";
          } else {
            $group = "- Einzelquest";
          }
          $usersstr = "";

          //Die User bekommen, die das Quest bearbeiten
          $get_users = $db->simple_select("questsystem_quest_user", "*, DATE_FORMAT(startdate, '%d.%m.%Y') as startdate, DATE_FORMAT((startdate + INTERVAL + {$daystoend} DAY), '%d.%m.%Y') as enddate ", "qid = {$quest['id']}");
          // die User durchgehen
          while ($user = $db->fetch_array($get_users)) {
            // Infos zur uid bekommen
            $userinfo = get_user($user['uid']);
            // Von Wann bis wann wird das Quest bearbeitet 
            if ($user['done'] == 1) {
              $status_start = "<s>";
              $status_end = "</s> erledigt";
            } else {
              $status_start = "";
              $status_end = "";
            }
            $end = " ({$user['startdate']} - {$user['enddate']}), ";
            if ($daystoend == 0) {
              $end = " (kein Ablaufdatum) ";
            }
            if ($quest['groupquest'] == 1) {
              $grpstr = "," . $user['groups_uids'] . ",";
              // ,17,23,
              $grpstr = str_replace("," . $user['uid'] . ",", "", $grpstr);
              $grpstr = trim(str_replace(",", "", $grpstr));
              $partnerinfo = get_user($grpstr);
              $partner = " mit {$partnerinfo['username']}";
            } else {
              $partner = "";
            }
            //Info zu dem User zusammenbauen
            $usersstr .= "<span style=\"background-color: #8080806e; display: inline-block; padding:2px 4px; margin-left:5px;\">" . $status_start . build_profile_link($userinfo['username'], $userinfo['uid']) . $partner . $end . $status_end . "</span>";
          }

          //Popup menü bauen. 
          $popup = new PopupMenu("questsystem_{$quest['id']}", "verwalten");
          if ($questtype['admin_assignment'] == 1) {
            $popup->add_item(
              "User zuteilen",
              "index.php?module=rpgstuff-questsystem&amp;action=questsystem_quest_add_user&amp;id={$quest['id']}"
            );
          }
          $popup->add_item(
            "delete",
            "index.php?module=rpgstuff-questsystem&amp;action=questsystem_delete_quest&amp;id={$quest['id']}"
              . "&amp;my_post_key={$mybb->post_code}"
          );
          $popup->add_item(
            "edit",
            "index.php?module=rpgstuff-questsystem&amp;action=questsystem_edit_quest&amp;id={$quest['id']}"
              . "&amp;my_post_key={$mybb->post_code}"
          );
          //Muss das Quest noch freigeschaltet werden? Dann entsprechend button anzeigen
          if ($quest['admincheck'] == 0) {
            $popup->add_item(
              "freischalten",
              "index.php?module=rpgstuff-questsystem&amp;action=questsystem_activate_quest&amp;id={$quest['id']}"
                . "&amp;my_post_key={$mybb->post_code}"
            );
          } else {
            // Hier kann es auch wieder zurückgenommen werden.
            $popup->add_item(
              "zurücknehmen",
              "index.php?module=rpgstuff-questsystem&amp;action=questsystem_deactivate_quest&amp;id={$quest['id']}"
                . "&amp;my_post_key={$mybb->post_code}"
            );
          }
          // Zeige Info wenn das Quest noch freigeschaltet werden muss
          if ($quest['admincheck'] == 0) {
            $userinfo = get_user($quest['uid']);
            $from = build_profile_link($userinfo['username'], $quest['uid']);
            $activated = "<br/><span style=\"color: red;\"><b>Noch nicht freigeschaltet!</b> Eingereicht von {$from}</span> <br/>";
          } else {
            $activated = "";
          }
          // Baue Anzeige des Quests
          $questding .= '
        <div style="margin: 10px; padding:5px; border-left:10px solid grey;">
                <b>Name:</b> ' . htmlspecialchars_uni($quest['name']) .
            $activated .
            '<span style="padding-left:10px"><i>' . $group . '</i></span>' .
            '<div style="margin: 5px; margin-left:10px; max-height:50px; overflow: auto">' . htmlspecialchars_uni($quest['questdescr']) .
            '</div>' .
            '<b>User:</b><br/> ' .
            $usersstr . '<br/>                  
                <br />' . $popup->fetch()
            . '</div>';
        }

        $await_usersstr = "";
        //Diesem Questtyp müssen User zugeteilt werden.
        if ($questtype['admin_assignment'] == 1) {
          $admin = "Admin muss Quests zuteilen <br>";
          // User die warten
          $awaiting_user_get = $db->simple_select("questsystem_quest_user", "*", "qtid={$questtype['id']} AND qid = 0");
          $await_usersstr = "<b>wartende User:</b> ";
          // durchgehen und anzeige bauen
          while ($awaiting_user = $db->fetch_array($awaiting_user_get)) {
            $await_uinfo = get_user($awaiting_user['uid']);

            if ($awaiting_user['groups_uids'] != "" && $awaiting_user['groups_uids'] != "0" && !empty($awaiting_user['groups_uids'])) {
              $grpstr = "," . $awaiting_user['groups_uids'] . ",";
              // ,17,23,
              $grpstr = str_replace("," . $awaiting_user['uid'] . ",", "", $grpstr);
              $grpstr = trim(str_replace(",", "", $grpstr));
              $partnerinfo = get_user($grpstr);
              $partner = " mit {$partnerinfo['username']}";
            } else {
              $partner = "";
            }

            $await_usersstr .= "<span style=\"background-color: #8080806e; display: inline-block; padding:2px 4px; margin-left:5px;\">" . build_profile_link($await_uinfo['username'], $await_uinfo['uid']) . $partner . "</span> ";
          }
        } else {
          $admin = "";
        }
        if ($questtype['active'] == 0) {
          $form_container->output_cell('
          <s><strong>' . htmlspecialchars_uni($questtype['name']) . '</strong></s> - <i>deaktiviert</i>
          ');
        } else {
          //Anzeige für Quest bauen
          $form_container->output_cell('<strong>' . htmlspecialchars_uni($questtype['name']) . '</strong> <br>
      <b>Zeit:</b> ' . $daystoendstr . ' - 
      <b>Punkte:</b> ' . $questtype['points_add'] . ' - 
      <b>Punktabzug:</b> ' . $questtype['points_minus'] . '  - 
      <b>Einreichung:</b> ' . $questtype['finish_typ'] . '   <br/>
      ' . $admin . '
      <div style="margin-left:10px; min-height:50px; max-height:200px; overflow: auto;">
        ' . $questding . '
      </div><br/>
      ' . $await_usersstr);
        }

        $form_container->construct_row();
      }
      $form_container->end();
      $form->end();
      $page->output_footer();
      die();
    }

    if ($mybb->get_input('action') == "questsystem_edit_quest") {

      if ($mybb->request_method == "post") {
        $questid = $mybb->get_input('id');
        $groupflag = $db->fetch_field($db->simple_select("questsystem_type", "groupquest", "id = {$mybb->input['types'][0]}"), "groupquest");
        if (empty($mybb->get_input('qname'))) {
          $errors[] = $lang->questsystem_manage_cqt_questname_error;
        }
        if (empty($mybb->input['types'])) {
          $errors[] = $lang->questsystem_manage_cqt_questtypes_error;
        }
        if (empty($mybb->input['qdescr'])) {
          $errors[] = $lang->questsystem_manage_cqt_qdescr_error;
        }
        if ($mybb->get_input('group') == 1 && $groupflag == 0) {
          $errors[] = "Dieser Typ erlaubt keine Gruppenquests";
        }
        if (empty($errors)) {
          $update = [
            "name" => $db->escape_string($mybb->get_input('qname')),
            "type" => $mybb->input['types'][0],
            "questdescr" => $db->escape_string($mybb->get_input('qdescr')),
            "groupquest" => $mybb->get_input('group'),
            "admincheck" => 1,
          ];
          $db->update_query("questsystem_quest", $update, "id = '{$questid}'");
          $mybb->input['module'] = "questsystem";
          $mybb->input['action'] = "Erfolgreich gespeichert";
          flash_message("Erfolgreich gespeichert", 'success');
          admin_redirect("index.php?module=rpgstuff-questsystem&action=questsystem_quest_manage");
          die();
        }
      }

      //Formular um ein Questhinzuzufügen
      $page->add_breadcrumb_item($lang->questsystem_manage_createquest);
      $page->output_header($lang->questsystem_name);
      $sub_tabs = questsystem_do_submenu();
      $page->output_nav_tabs($sub_tabs, 'questsystem_quest_add');

      if (isset($errors)) {
        $page->output_inline_error($errors);
      }
      $id = $mybb->get_input('id', MyBB::INPUT_INT);
      $questdata = $db->simple_select("questsystem_quest", "*", "id={$id}");
      $edit = $db->fetch_array($questdata);

      $form = new Form("index.php?module=rpgstuff-questsystem&amp;action=questsystem_edit_quest", "post", "", 1);
      echo $form->generate_hidden_field('id', $id);
      $form_container = new FormContainer("Quest editieren");
      $form_container->output_row(
        $lang->questsystem_manage_cqt_questname, //Name 
        $lang->questsystem_manage_cqt_questname_descr,
        $form->generate_text_box('qname', htmlspecialchars_uni($edit['name']))
      );
      $questtype = $db->simple_select("questsystem_type", "id,name", "", array("order" => "name"));
      while ($result = $db->fetch_array($questtype)) {
        $id = $result['id'];
        $alltypes[$id] = $result['name'];
      }

      //Reihe für Die User
      $form_container->output_row(
        $lang->questsystem_manage_cqt_questtypes, //name
        $lang->questsystem_manage_cqt_questtypes_descr,
        $form->generate_select_box(
          'types[]',
          $alltypes,
          $edit['type'],
          array('id' => 'id', 'multiple' => null, 'size' => 5)
        ),
        'questtype'
      );
      //name des Quests
      $form_container->output_row(
        $lang->questsystem_manage_cqt_questdescr,
        $lang->questsystem_manage_cqt_questdescr_descr,
        $form->generate_text_area('qdescr', $edit['questdescr'])
      );

      //Darf pro quest bestimmt werden welche gruppen (d.h. Questtyp kann einzel und gruppenquests haben)
      $form_container->output_row(
        $lang->questsystem_manage_cqt_groupquest, //Name 
        $lang->questsystem_manage_cqt_groupquest_descr,
        $form->generate_yes_no_radio('group', $edit['groupquest'])
      );

      $form_container->end();
      $buttons[] = $form->generate_submit_button($lang->questsystem_manage_cqt_form_save);
      $form->output_submit_wrapper($buttons);
      $form->end();
      $page->output_footer();
      die();
    }

    //Questtyp editieren
    if ($mybb->get_input('action') == "questsystem_edit") {
      //edit questtype
      if ($mybb->request_method == "post") {
        //als erstes fangen wir Fehler und leere Eingaben ab
        if (empty($mybb->input['name'])) {
          $errors[] = $lang->questsystem_cqt_error_name;
        }
        if (empty($mybb->input['name_db'])) {
          $errors[] = $lang->questsystem_cqt_error_name_db;
        }
        if (empty($mybb->input['descr'])) {
          $errors[] = $lang->questsystem_cqt_error_typdescr;
        }
        if (empty($mybb->input['groupselect'])) {
          $errors[] = $lang->questsystem_cqt_error_groupselect;
        }
        if ($mybb->get_input('groupselect') == "custom") {
          if ($mybb->get_input('groupselect_sel') == "") {
            $errors[] = $lang->questsystem_cqt_error_groupselect;
          }
        }
        if (empty($mybb->input['enddays'])) {
          $end = "0";
        } else {
          $end =  $mybb->input['enddays'];
        }
        if (empty($mybb->input['points_add'])) {
          $points_add = "0";
        } else {
          $points_add =  $mybb->input['points_add'];
        }
        if (empty($mybb->input['points_minus'])) {
          $points_minus = "0";
        } else {
          $points_minus =  $mybb->input['points_minus'];
        }
        if (empty($mybb->input['delete'])) {
          $delete = "0";
        }
        if (empty($mybb->input['group_profilefield'])) {
          $mybb->input['group_profilefield'] = "0";
        } else {
          $mybb->input['group_profilefield'] = $mybb->get_input('group_profilefield');
        }
        if (empty($mybb->input['group_profilefield_type'])) {
          $mybb->input['group_profilefield_type'] = "0";
        } else {
          $mybb->input['group_profilefield_type'] = $mybb->input['group_profilefield_type'];
        }
        if (empty($mybb->input['finish'])) {
          $errors[] = $lang->questsystem_manage_cqt_form_finish_err;
        }
        if (empty($errors)) {
          if ($mybb->input['groupselect'] == "custom") {
            // var_dump($mybb->input['groupselect_sel']);
            $grpstring = implode(",", $mybb->input['groupselect_sel']);
          }
          if ($mybb->input['groupselect'] == "all") {
            $grpstring = "-1";
          }
          if ($mybb->input['groupselect'] == "none") {
            $grpstring = "";
          }
          $questid = $mybb->input['qid'];

          //update
          $update = [
            "name" => $db->escape_string($mybb->input['name']),
            "type" => $db->escape_string($mybb->input['name_db']),
            "typedescr" => $db->escape_string($mybb->input['descr']),
            "groups_allowed" => $grpstring,
            // "groups_questdepend" => implode(",", $mybb->input['users']),
            "enddays" => $end,
            "points_minus" => $points_minus,
            "points_add" => $points_add,
            "admin_assignment" => $mybb->get_input('admin_assignment'),
            "repeat" => $mybb->get_input('repeat'),
            "unique" => $mybb->get_input('unique'),
            "delete" => $mybb->get_input('delete'),
            "group_str" => $db->escape_string($mybb->input['group_profilefield']),
            "group_fid" => $db->escape_string($mybb->input['group_profilefield_type']),
            "groupquest" => $mybb->get_input('grouptask'),
            "finish_typ" => $db->escape_string($mybb->get_input('finish')),
            "user_add" => $mybb->get_input('user_add'),
          ];
          $db->update_query("questsystem_type", $update, "id = {$questid}");
          $mybb->input['module'] = "questsystem";
          $mybb->input['action'] = "Erfolgreich gespeichert";
          flash_message("Erfolgreich gespeichert", 'success');
          admin_redirect("index.php?module=rpgstuff-questsystem");
          die(); //evt. wieder rauswerfen
        }
      }
      //Formularanzeige
      $page->add_breadcrumb_item($lang->questsystem_manage_createquesttype);
      $page->output_header($lang->questsystem_name);
      $sub_tabs = questsystem_do_submenu();
      $page->output_nav_tabs($sub_tabs, 'questsystem_questtype_add');


      if (isset($errors)) {
        $page->output_inline_error($errors);
      }
      $id = $mybb->get_input('id', MyBB::INPUT_INT);
      $questtypedata = $db->simple_select("questsystem_type", "*", "id={$id}");
      $edit = $db->fetch_array($questtypedata);

      $form = new Form("index.php?module=rpgstuff-questsystem&amp;action=questsystem_edit&amp;id={$id}", "post", "", 1);
      $form_container = new FormContainer("Questtyp erstellen");
      echo $form->generate_hidden_field('qid', $id);
      $form_container->output_row(
        $lang->questsystem_manage_cqt_formname, //Name 
        $lang->questsystem_manage_cqt_formname_descr,
        $form->generate_text_box('name', htmlspecialchars_uni($edit['name']))
      );

      //maschinennamen
      $form_container->output_row(
        $lang->questsystem_manage_cqt_formname_db, //Name 
        $lang->questsystem_manage_cqt_formname_db_descr,
        $form->generate_text_box('name_db', htmlspecialchars_uni($edit['type']))
      );

      //Beschreibung des Typs
      $form_container->output_row(
        $lang->questsystem_manage_cqt_typdescr, //Name 
        $lang->questsystem_manage_cqt_typdescr_descr,
        $form->generate_text_area('descr', $edit['typedescr'])
      );

      //nur bestimmte Gruppen?  (allgemein)
      print_selection_javascript();
      if ($edit['groups_allowed'] == "-1") {
        $sel_all = "CHECKED";
      } else if ($edit['groups_allowed'] == "") {
        $sel_none = "CHECKED";
      } else {
        $sel_gr = "CHECKED";
        $selected_values = explode(",", $edit['groups_allowed']);
        // $sel = ""; 
      }
      // var_dump($selected_values);
      $select_code = "
    <dl style=\"margin-top: 0; margin-bottom: 0; width: 100%\">
      <dt><label style=\"display: block;\"><input type=\"radio\" name=\"groupselect\" value=\"all\" $sel_all class=\"groupselect_forums_groups_check\" onclick=\"checkAction('groupselect');\" style=\"vertical-align: middle;\" /> <strong>{$lang->all_groups}</strong></label></dt>
      <dt><label style=\"display: block;\">
      <input type=\"radio\" name=\"groupselect\" value=\"custom\"  class=\"groupselect_forums_groups_check\" $sel_gr onclick=\"checkAction('groupselect');\" style=\"vertical-align: middle;\" /> <strong>{$lang->select_groups}</strong></label></dt>
      <dd style=\"margin-top: 4px;\" id=\"groupselect_forums_groups_custom\" class=\"groupselect_forums_groups\">
        <table cellpadding=\"4\">
          <tr>
            
            <td colspan=\"2\">" .
        $form->generate_group_select(
          'groupselect_sel[]',
          $selected_values,
          array('id' => 'groupselect_sel', 'multiple' => true, 'size' => 5)
        ) . "</td>
          </tr>
        </table>
      </dd>
      <dt><label style=\"display: block;\"><input type=\"radio\" name=\"groupselect\" value=\"none\"  $sel_none class=\"groupselect_forums_groups_check\" onclick=\"checkAction('groupselect');\" style=\"vertical-align: middle;\" /> <strong>{$lang->none}</strong></label></dt>
    </dl>
    <script type=\"text/javascript\">
      checkAction('groupselect');
    </script>";
      $form_container->output_row($lang->questsystem_manage_cqt_group, $lang->questsystem_manage_cqt_group_descr, $select_code, '', array(), array('id' => 'row_groupselect'));

      //Nur für beestimmte Gruppen/Profilfeld
      $form_container->output_row(
        $lang->questsystem_manage_cqt_groupprofielfield, //Name 
        $lang->questsystem_manage_cqt_groupprofielfield_descr,
        $form->generate_text_box('group_profilefield', htmlspecialchars_uni($edit['group_str']))
      );
      //profilfeld oder type
      $form_container->output_row(
        $lang->questsystem_manage_cqt_groupprofielfield_type, //Name 
        $lang->questsystem_manage_cqt_groupprofielfield_type_descr,
        $form->generate_text_box('group_profilefield_type', htmlspecialchars_uni($edit['group_fid']))
      );

      //gibt es ein ablaufdatum? & wieviele tage hat man zeit?
      $form_container->output_row(
        $lang->questsystem_manage_cqt_formenddate, //Name 
        $lang->questsystem_manage_cqt_formenddate_descr,
        $form->generate_numeric_field('enddays', $edit['enddays'], array('id' => 'disporder', 'min' => 0))
      );
      //Punkte hinzufügen -> wenn ja wieviele
      $form_container->output_row(
        $lang->questsystem_manage_cqt_addpoints, //Name 
        $lang->questsystem_manage_cqt_addpoints_descr,
        $form->generate_numeric_field('points_add', $edit['points_add'], array('id' => 'disporder', 'min' => 0))
      );
      //Punkte abziehen -> wenn ja wieviele
      $form_container->output_row(
        $lang->questsystem_manage_cqt_subpoints, //Name 
        $lang->questsystem_manage_cqt_subpoints_descr,
        $form->generate_numeric_field('points_minus', $edit['points_minus'], array('id' => 'disporder', 'min' => 0))
      );
      //Wird zufällig ein Quest des Typs ausgewählt? 
      $form_container->output_row(
        $lang->questsystem_manage_cqt_formadmin_assignment, //Name 
        $lang->questsystem_manage_cqt_formadmin_assignment_descr,
        $form->generate_yes_no_radio('admin_assignment', $edit['admin_assignment'])
      );
      //Wird zufällig ein Quest des Typs ausgewählt? 
      $form_container->output_row(
        $lang->questsystem_manage_cqt_formadmin_delete, //Name 
        $lang->questsystem_manage_cqt_formadmin_delete_descr,
        $form->generate_yes_no_radio('delete', $edit['delete'])
      );
      //Darf ein Quest des Typs vom gleichen User mehrfach gezogen werden (nach Beendigung)? 
      $form_container->output_row(
        $lang->questsystem_manage_cqt_formrepeat, //Name 
        $lang->questsystem_manage_cqt_formrepeat_descr,
        $form->generate_yes_no_radio('repeat', $edit['repeat'])
      );

      //darf das quest nur von einem user gleichzeitig bearbeitet werden
      $form_container->output_row(
        $lang->questsystem_manage_cqt_formunique, //Name 
        $lang->questsystem_manage_cqt_formunique_descr,
        $form->generate_yes_no_radio('unique', $edit['unique'])
      );

      //darf es in diesem Questtypen allgemein Gruppenquests geben
      $form_container->output_row(
        $lang->questsystem_manage_cqt_grouptask, //Name 
        $lang->questsystem_manage_cqt_grouptask_descr,
        $form->generate_yes_no_radio('grouptask', $edit['groupquest'])
      );

      //Darf pro quest bestimmt werden welche gruppen (d.h. Questtyp kann einzel und gruppenquests haben)
      $form_container->output_row(
        $lang->questsystem_manage_cqt_form_useradd, //Name 
        $lang->questsystem_manage_cqt_form_useradd_descr,
        $form->generate_yes_no_radio('user_add', $edit['user_add'])
      );

      //einreichen über post // thread (quasi kleine aufgabe(nur post) oder szenenrelevante aufgabe)
      if ($edit['finish_typ'] == 'post') {
        $selp = 1;
        $selt = 0;
      } else {
        $selp = 0;
        $selt = 1;
      }
      $quest_finish_options = array(
        $form->generate_radio_button("finish", "post", $lang->questsystem_manage_cqt_form_finish_post, array("checked" =>  $selp)),
        $form->generate_radio_button("finish", "szene", $lang->questsystem_manage_cqt_form_finish_szene, array("checked" =>  $selt)),
      );


      $form_container->output_row($lang->questsystem_manage_cqt_form_finish_descr, "", implode("<br />", $quest_finish_options));

      $form_container->end();
      $buttons[] = $form->generate_submit_button($lang->questsystem_manage_cqt_form_edit);
      $form->output_submit_wrapper($buttons);
      $form->end();
      $page->output_footer();

      die();
    }

    //Das Quest muss noch freigeschaltet werden. 
    if ($mybb->get_input('action') == "questsystem_activate_quest") {
      $id = $mybb->get_input('id', MyBB::INPUT_INT);

      if (empty($id)) {
        flash_message($lang->questsystem_manage_cqt_admincheck_error, 'error');
        admin_redirect("index.php?module=rpgstuff-questsystem");
      }

      if (isset($mybb->input['no']) && $mybb->input['no']) {
        admin_redirect("index.php?module=rpgstuff-questsystem");
      }
      if (!verify_post_check($mybb->input['my_post_key'])) {
        flash_message($lang->questsystem_manage_cqt_delete_error_auth, 'error');
        admin_redirect("index.php?module=rpgstuff-questsystem");
      } else {
        if ($mybb->request_method == "post") {
          $questname = $db->fetch_field($db->simple_select("questsystem_quest", "*", "id='{$id}'"), "name");
          $uid = $db->fetch_field($db->simple_select("questsystem_quest", "*", "id='{$id}'"), "uid");

          //dazugehörige Quests löschen
          $update = [
            "admincheck" => 1,
          ];

          $db->update_query("questsystem_quest", $update, "id='{$id}'");

          //dazugehörige Quests der User löschen
          $insert = array(
            "uid" => $uid,
            "points" => $mybb->settings['questsystem_points'],
            "reason" => $lang->questsystem_sendQuestPoints,
            "date" => date("Y-m-d"),
          );
          $db->insert_query("questsystem_points", $insert);

          if (class_exists('MybbStuff_MyAlerts_AlertTypeManager')) {
            $alertType = MybbStuff_MyAlerts_AlertTypeManager::getInstance()->getByCode('questsystem_QuestAccepted');
            if ($alertType != NULL && $alertType->getEnabled()) {
              //constructor for MyAlert gets first argument, $user (not sure), second: type  and third the objectId 
              $alert = new MybbStuff_MyAlerts_Entity_Alert((int)$uid, $alertType, (int)$id);
              //some extra details
              $alert->setExtraDetails([
                'name' => $questname,
              ]);
              //add the alert
              MybbStuff_MyAlerts_AlertManager::getInstance()->addAlert($alert);
            }
          }

          $mybb->input['module'] = "questsystem";
          $mybb->input['action'] = $lang->questsystem_manage_cqt_admincheck;
          log_admin_action(htmlspecialchars_uni($questname));
          flash_message($lang->questsystem_manage_cqt_admincheck_success, 'success');
          admin_redirect("index.php?module=rpgstuff-questsystem&action=questsystem_quest_manage");
        } else {
          $page->output_confirm_action(
            "index.php?module=rpgstuff-questsystem&amp;action=questsystem_activate_quest&amp;id={$id}",
            $lang->questsystem_manage_cqt_admincheck
          );
        }
      }
      die();
    }
    //Das Quest wieder zurücknehmen
    if ($mybb->input['action'] == "questsystem_deactivate_quest") {
      $id = $mybb->get_input('id', MyBB::INPUT_INT);

      if (empty($id)) {
        flash_message($lang->questsystem_manage_cqt_admincheck_error, 'error');
        admin_redirect("index.php?module=rpgstuff-questsystem&action=questsystem_quest_manage");
      }

      if (isset($mybb->input['no']) && $mybb->input['no']) {
        admin_redirect("index.php?module=rpgstuff-questsystem&action=questsystem_quest_manage");
      }
      if (!verify_post_check($mybb->input['my_post_key'])) {
        flash_message($lang->questsystem_manage_cqt_delete_error_auth, 'error');
        admin_redirect("index.php?module=rpgstuff-questsystem");
      } else {
        if ($mybb->request_method == "post") {
          $questname = $db->fetch_field($db->simple_select("questsystem_quest", "*", "id='{$id}'"), "name");
          //dazugehörige Quests löschen
          $update = [
            "admincheck" => 0,
          ];

          $db->update_query("questsystem_quest", $update, "id='{$id}'");
          //dazugehörige Quests der User löschen

          $mybb->input['module'] = "questsystem";
          $mybb->input['action'] = $lang->questsystem_manage_cqt_admincheck_back;
          log_admin_action(htmlspecialchars_uni($questname));
          flash_message($lang->questsystem_manage_cqt_admincheck_success, 'success');
          admin_redirect("index.php?module=rpgstuff-questsystem");
        } else {
          $page->output_confirm_action(
            "index.php?module=rpgstuff-questsystem&amp;action=questsystem_deactivate_quest&amp;id={$id}",
            $lang->questsystem_manage_cqt_admincheck_back
          );
        }
      }
      die();
    }

    //Einen Questtypen deaktivieren
    if ($mybb->get_input('action') == "questsystem_deactivate") {
      $id = $mybb->get_input('id', MyBB::INPUT_INT);

      if (empty($id)) {
        flash_message($lang->questsystem_manage_cqt_deactivate_error, 'error');
        admin_redirect("index.php?module=rpgstuff-questsystem");
      }

      if (isset($mybb->input['no']) && $mybb->input['no']) {
        admin_redirect("index.php?module=rpgstuff-questsystem");
      }
      if (!verify_post_check($mybb->input['my_post_key'])) {
        flash_message($lang->questsystem_manage_cqt_delete_error_auth, 'error');
        admin_redirect("index.php?module=rpgstuff-questsystem");
      } else {
        if ($mybb->request_method == "post") {
          $typename = $db->fetch_field($db->simple_select("questsystem_type", "*", "id='{$id}'"), "name");
          //dazugehörige Quests löschen
          $update = [
            "active" => 0,
          ];

          $db->update_query("questsystem_type", $update, "id='{$id}'");
          //dazugehörige Quests der User löschen

          $mybb->input['module'] = "questsystem";
          $mybb->input['action'] = $lang->questsystem_manage_cqt_delete_tit;
          log_admin_action(htmlspecialchars_uni($typename));
          flash_message($lang->questsystem_manage_cqt_activate_success, 'success');
          admin_redirect("index.php?module=rpgstuff-questsystem");
        } else {
          $page->output_confirm_action(
            "index.php?module=rpgstuff-questsystem&amp;action=questsystem_deactivate&amp;id={$id}",
            $lang->questsystem_manage_cqt_deactivate_msg
          );
        }
      }
      die();
    }

    if ($mybb->get_input('action') == "questsystem_activate") {
      $id = $mybb->get_input('id', MyBB::INPUT_INT);

      if (empty($id)) {
        flash_message($lang->questsystem_manage_cqt_activate_error, 'error');
        admin_redirect("index.php?module=rpgstuff-questsystem");
      }

      if (isset($mybb->input['no']) && $mybb->input['no']) {
        admin_redirect("index.php?module=rpgstuff-questsystem");
      }
      if (!verify_post_check($mybb->input['my_post_key'])) {
        flash_message($lang->questsystem_manage_cqt_delete_error_auth, 'error');
        admin_redirect("index.php?module=rpgstuff-questsystem");
      } else {

        if ($mybb->request_method == "post") {
          $typename = $db->fetch_field($db->simple_select("questsystem_type", "*", "id='{$id}'"), "name");
          //dazugehörige Quests löschen
          $update = [
            "active" => 1,
          ];

          $db->update_query("questsystem_type", $update, "id='{$id}'");
          //dazugehörige Quests der User löschen

          $mybb->input['module'] = "questsystem";
          $mybb->input['action'] = $lang->questsystem_manage_cqt_delete_tit;
          log_admin_action(htmlspecialchars_uni($typename));
          flash_message($lang->questsystem_manage_cqt_activate_success, 'success');
          admin_redirect("index.php?module=rpgstuff-questsystem");
        } else {
          $page->output_confirm_action(
            "index.php?module=rpgstuff-questsystem&amp;action=questsystem_activate&amp;id={$id}",
            $lang->questsystem_manage_cqt_activate_msg
          );
        }
      }
      die();
    }

    //Einen Questtypen löschen
    if ($mybb->get_input('action') == "questsystem_delete") {
      $id = $mybb->get_input('id', MyBB::INPUT_INT);
      $get_type = $db->simple_select("questsystem_type", "*", "id={$id}");
      $del_type = $db->fetch_array($get_type);

      if (empty($id)) {
        flash_message($lang->questsystem_manage_cqt_delete_error, 'error');
        admin_redirect("index.php?module=rpgstuff-questsystem");
      }

      if (isset($mybb->input['no']) && $mybb->input['no']) {
        admin_redirect("index.php?module=rpgstuff-questsystem");
      }

      if (!verify_post_check($mybb->input['my_post_key'])) {
        flash_message($lang->questsystem_manage_cqt_delete_error_auth, 'error');
        admin_redirect("index.php?module=rpgstuff-questsystem");
      } else {
        if ($mybb->request_method == "post") {
          // $typename = $db->fetch_field($db->simple_select("questsystem_quest", "*", "type='{$id}'"), "type");
          //dazugehörige Quests löschen
          $db->delete_query("questsystem_quest", "type='{$id}'");
          //dazugehörige Quests der User löschen
          $db->delete_query("questsystem_quest_user", "qtid='{$id}'");
          $db->delete_query("questsystem_type", "id='{$id}'");
          $mybb->input['module'] = "questsystem";
          $mybb->input['action'] = $lang->questsystem_manage_cqt_delete_tit;
          log_admin_action(htmlspecialchars_uni($del_type['name']));
          flash_message($lang->questsystem_manage_cqt_delete_success, 'success');
          admin_redirect("index.php?module=rpgstuff-questsystem");
        } else {
          $page->output_confirm_action(
            "index.php?module=rpgstuff-questsystem&amp;action=questsystem_delete&amp;id={$id}",
            $lang->questsystem_manage_cqt_delete_msg
          );
        }
      }
      die();
    }
    // Ein Quest löschen
    if ($mybb->get_input('action') == "questsystem_delete_quest") {
      $id = $mybb->get_input('id', MyBB::INPUT_INT);
      $get_quest = $db->simple_select("questsystem_quest", "*", "id={$id}");
      $del_quest = $db->fetch_array($get_quest);
      if (empty($id)) {
        flash_message($lang->questsystem_manage_cqt_delete_error, 'error');
        admin_redirect("index.php?module=rpgstuff-questsystem");
      }

      if (isset($mybb->input['no']) && $mybb->input['no']) {
        admin_redirect("index.php?module=rpgstuff-questsystem");
      }

      if (!verify_post_check($mybb->input['my_post_key'])) {
        flash_message($lang->questsystem_manage_cqt_delete_error_auth, 'error');
        admin_redirect("index.php?module=rpgstuff-questsystem");
      } else {
        if ($mybb->request_method == "post") {
          // $typename = $db->fetch_field($db->simple_select("questsystem_quest", "*", "type='{$id}'"), "type");
          //dazugehörige Quests löschen
          $db->delete_query("questsystem_quest", "id='{$id}'");

          //dazugehörige Quests der User löschen
          $db->delete_query("questsystem_quest_user", "qid='{$id}' ");

          $mybb->input['module'] = "questsystem";

          $mybb->input['action'] = $lang->questsystem_manage_cqt_delete_tit;
          log_admin_action(htmlspecialchars_uni($del_quest['name']) . "(id: {$id})");
          flash_message($lang->questsystem_manage_cqt_delete_success, 'success');
          admin_redirect("index.php?module=rpgstuff-questsystem&action=questsystem_quest_manage");
        } else {
          $page->output_confirm_action(
            "index.php?module=rpgstuff-questsystem&amp;action=questsystem_delete_quest&amp;id={$id}",
            $lang->questsystem_manage_cqt_deletequest_msg
          );
        }
      }
      die();
    }

    //Einem (oder mehreren) Usern ein Quest zuteilen
    if ($mybb->get_input('action') == "questsystem_quest_add_user") {
      if ($mybb->request_method == "post") {
        $questid = $mybb->get_input('id', MyBB::INPUT_INT);
        $questinfo = $db->fetch_array($db->simple_select("questsystem_quest", "*", "id = {$questid}"));
        //als erstes fangen wir Fehler und leere Eingaben ab
        if (empty($mybb->input['waiting'])) {
          $errors[] = $lang->questsystem_error_adduser_user;
        }

        if (empty($errors)) {
          $questype = $db->fetch_field($db->simple_select("questsystem_quest", "type", "id = {$questid}"), "type");
          $arr_uids_str = $mybb->get_input('waiting');
          $arr_uids = array();
          $arr_uids = explode(",", $arr_uids_str);


          $today = date("Y-m-d h:i", time());
          foreach ($arr_uids as $uid) {
            if ($questinfo['groupquest'] == 1) {
              $update = [
                "qid" => $questid,
                "uid" => $uid,
                "startdate" => $today,
              ];
            } else {
              $update = [
                "qid" => $questid,
                "startdate" => $today,
                "uid" => $uid,
              ];
            }
            $db->update_query("questsystem_quest_user", $update, "qtid = {$questype} and uid = {$uid}");

            if (class_exists('MybbStuff_MyAlerts_AlertTypeManager')) {
              $alertType = MybbStuff_MyAlerts_AlertTypeManager::getInstance()->getByCode('questsystem_giveUser');
              //Not null, the user wants an alert and the user is not on his own page.
              if ($alertType != NULL && $alertType->getEnabled()) {
                //constructor for MyAlert gets first argument, $user (not sure), second: type  and third the objectId 
                $alert = new MybbStuff_MyAlerts_Entity_Alert((int)$uid, $alertType);
                //some extra details
                $alert->setExtraDetails([
                  'qid' => $questid,
                  // 'fromuser' => $uid
                ]);
                //add the alert
                MybbStuff_MyAlerts_AlertManager::getInstance()->addAlert($alert);
              }
            }
          }
          flash_message("Erfolgreich gespeichert", 'success');
          admin_redirect("index.php?module=rpgstuff-questsystem&action=questsystem_quest_manage");
          die();
        }
      }
      //Formularanzeige
      $page->add_breadcrumb_item($lang->questsystem_manage_createquesttype);
      $page->output_header($lang->questsystem_name);
      $sub_tabs = questsystem_do_submenu();
      $page->output_nav_tabs($sub_tabs);

      if (isset($errors)) {
        $page->output_inline_error($errors);
      }

      //ID des Quests welchem ein user zugeteilt werden soll
      $id = $mybb->get_input('id', MyBB::INPUT_INT);
      if (!empty($id)) {
        //infos des Quests bekommen
        $questdata = $db->simple_select("questsystem_quest", "*", "id={$id}");
        $questinfos = $db->fetch_array($questdata);
        //infos zum dazugehörigen Typen
        $questtypeinfo = $db->fetch_array($db->simple_select("questsystem_type", "*", "id={$questinfos['type']}"));

        $form = new Form("index.php?module=rpgstuff-questsystem&amp;action=questsystem_quest_add_user&amp;id={$id}", "post", "", 1);
        $form_container = new FormContainer("Quest einem User zuteilen");
        echo $form->generate_hidden_field('qid', $id);
        echo $form->generate_hidden_field('qtid', $questtypeinfo['id']);

        //wartende User des Questtypen bekommen
        $awaiting_user = array();
        $awaiting_justsingle = array();
        $userdata = $db->simple_select("questsystem_quest_user", "*", "qid=0 AND qtid = {$questtypeinfo['id']}");

        while ($result = $db->fetch_array($userdata)) {
          if ($result['groups_uids'] != '') {
            $partnerstring = $result['groups_uids'];
            $uids = explode(",", $result['groups_uids']);
            $selectstr = "";
            foreach ($uids as $partner) {
              $userinfo = get_user($partner);
              $selectstr .= $userinfo['username'] . " - ";
            }
            $selectstr = substr($selectstr, 0, -2);
            $awaiting_user[$partnerstring] = $selectstr;
          } else {
            $uid = $result['uid'];
            $userinfo = get_user($uid);
            $selectstr = $userinfo['username'];
            $awaiting_user[$uid] = $selectstr;
            $awaiting_justsingle[$uid] = $selectstr;
          }
        }
        //sortieren
        asort($awaiting_user);
        //leere stellen rauswerfen
        array_filter($awaiting_user);
        //Gruppenquest, ja oder nein? 
        if ($questinfos['groupquest'] == 1) {
          $form_container->output_row(
            $questinfos['name'], //Name 
            "<i>" . $questinfos['questdescr'] . "</i>",
            "<p style=\"padding-left:20px;\"><b>wartende User des Questtyps({$questtypeinfo['name']})</b><br/>" .
              $form->generate_select_box(
                'waiting',
                $awaiting_user,
                '',
                array('id' => 'user')
              ) . "</p>"
          );
        } else {
          //Kein Gruppenquest nur ein User kann ausgewählt werden
          $form_container->output_row(
            $questinfos['name'], //Name 
            "<i>" . $questinfos['questdescr'] . "</i>",
            "<p style=\"padding-left:20px;\"><b>wartende User des Questtyps({$questtypeinfo['name']})</b><br/>" .
              $form->generate_select_box(
                'waiting',
                $awaiting_justsingle,
                '',
                array('id' => 'user')
              ) . "</p>"
          );
        }
        $form_container->end();
        $buttons[] = $form->generate_submit_button($lang->questsystem_manage_cqt_form_save);
        $form->output_submit_wrapper($buttons);
        $form->end();
        $page->output_footer();
      } else {
        echo
        "<a href=\"index.php?module=rpgstuff-questsystem&action=questsystem_quest_manage\"></b>Erst Quest wählen, dem ein User zugeteilt werden soll</b></a>
      ";
      }
      die();
    }
  }
}

/**
 * Hilfsfunktion um das Submenü im ACP zu erstellen.
 */
function questsystem_do_submenu()
{
  global $lang;
  $lang->load("questsystem");
  //Übersicht
  $sub_tabs['questsystem'] = [
    "title" => $lang->questsystem_overview,
    "link" => "index.php?module=rpgstuff-questsystem",
    "description" => "Eine Übersicht aller Questtypen"
  ];

  //Questtyp anlegen
  $sub_tabs['questsystem_questtype_add'] = [
    "title" => $lang->questsystem_manage_createquesttype,
    "link" => "index.php?module=rpgstuff-questsystem&amp;action=questsystem_questtype_add",
    "description" => "Einen Questtyp anlegen."
  ];

  //Quest anlegen
  $sub_tabs['questsystem_quest_add'] = [
    "title" => $lang->questsystem_manage_addquest,
    "link" => "index.php?module=rpgstuff-questsystem&amp;action=questsystem_quest_add",
    "description" => "Ein Quest anlegen"
  ];

  //Übersicht welches Mitglied/hat welche Aufgabe
  $sub_tabs['questsystem_quest_manage'] = [
    "title" => $lang->questsystem_management,
    "link" => "index.php?module=rpgstuff-questsystem&amp;action=questsystem_quest_manage",
    "description" => "Welches Mitglied hat gerade welches Quest - Übersicht und Verwaltung"
  ];
  return $sub_tabs;
}


/**
 * Questsystem Punkteanzeige im Profil
 * Zeige alle Quests
 * Quests nehmen
 */
$plugins->add_hook("member_profile_end", "questsystem_member_profile");
function questsystem_member_profile()
{
  global $memprofile, $lang, $db, $mybb, $templates, $questsystem_member_bit, $questsystem_member;
  $lang->load('questsystem');
  $questsystem_member_bit = "";
  $questsystem_member = "";
  //punkte im ucp löschen
  if ($mybb->get_input('deleteentry') != "" && $mybb->usergroup['canmodcp'] == 1) {
    $id = $mybb->get_input('deleteentry');
    $db->delete_query("questsystem_points", "id = '{$id}'");
  }

  if ($mybb->settings['questsystem_points_generell']) {
    $uid = $memprofile['uid'];

    $points_sum = $db->fetch_field($db->write_query("SELECT sum(points) as total, uid FROM `" . TABLE_PREFIX . "questsystem_points` WHERE uid = '{$memprofile['uid']}' GROUP BY uid "), "total");

    $get_all_points = $db->simple_select("questsystem_points", "*", "uid = {$uid}", array("order_by" => "date", "order_dir" => "desc"));

    if ($points_sum == "") {
      $points_sum = "0 Punkte";
    }

    while ($userpoints = $db->fetch_array($get_all_points)) {
      if ($userpoints['points'] == "1" || $userpoints['points'] == "-1") {
        $punkte = $userpoints['points'] . " " . $lang->questsystem_point;
      } else {
        $punkte = $userpoints['points'] . " " . $lang->questsystem_points;
      }
      if ($userpoints['points'] == 0 || $userpoints['points'] == "0") {
        $punkte = "0 " . $lang->questsystem_points;
      }

      $reason = $userpoints['reason'];

      $date = date("d.m.Y", strtotime($userpoints['date']));

      if ($mybb->usergroup['canmodcp'] == 1) {
        $date .= " <a href=\"member.php?action=profile&uid={$uid}&deleteentry={$userpoints['id']}\" onclick=\"return confirm('Möchtest du den Punkteeintrag wirklich löschen?');\" style=\"font-size: 0.7em;\">[x]</a>";
      }

      eval("\$questsystem_member_bit = \"" . $templates->get("questsystem_member_bit") . "\";");
    }
    eval("\$questsystem_member = \"" . $templates->get("questsystem_member") . "\";");
  }
}

/**
 * Questsystem Hauptanzeige
 * Zeige alle Quests
 * Quests nehmen
 */

$plugins->add_hook("misc_start", "questsystem_show");
function questsystem_show()
{
  global $db, $mybb, $templates, $header, $footer, $theme, $headerinclude, $lang, $takequest, $questsystem_nav, $questsystem_nav_points;

  //Ein Quest wird im Post als fertig markiert:
  if ($mybb->input['action'] == "questsystem_submitquest") {
    // $qid = $fetch_field($db->simple_select("questsystem_quest", "id", ),"id");
    $questdata = $db->fetch_array($db->simple_select("questsystem_quest_user", "*", "id = {$mybb->input['questid']}"));
    $pid = $mybb->input['pid'];
    $tid = $mybb->input['tid'];
    $thisuser = $mybb->user['uid'];
    if ($questdata['groups_uids'] != 0) {
      $user_arr = explode(",", $questdata['groups_uids']);
    } else {
      $user_arr[0] = $thisuser;
    }
    foreach ($user_arr as $uid) {
      $update = [
        "done" => 2,
        "pid" => $pid,
        "tid" => $tid
      ];
      // echo $uid ."and". $questdata['qid']; 
      $db->update_query("questsystem_quest_user", $update, "qid = {$questdata['qid']} and  uid = '{$thisuser}' ");
    }
    redirect("showthread.php?tid={$tid}&pid={$pid}#pid{$pid}");
    die();
  }

  if (!$mybb->get_input('action') == "questsystem") return;

  if ($mybb->get_input('action') == "questsystem") {
    $formgroup = "";
    $lang->load('questsystem');
    add_breadcrumb($lang->questsystem_name, "misc.php?action=misc.php?action=questsystem");
    questsystem_navigation();

    $thisuser = $mybb->user['uid'];
    if ($mybb->user['uid'] == '0') {
      error_no_permission();
    }

    $questsystem_misc_main = "";
    $takequest = "";

    //Typen bekommen
    $get_types = $db->simple_select("questsystem_type", "*", "active = 1");
    while ($type = $db->fetch_array($get_types)) {
      //Darf der User diesen Questtypen sehen/nutzen
      $isAllowed = questsystem_isAllowed($type, $thisuser);
      if ($isAllowed) {
        // welchen typ haben wir
        $quest_type = $type['name'];

        if ($type['enddays'] != 0) {
          $daysend = $lang->sprintf($lang->questsystem_timeperiode_days, $type['enddays']);
        } else {
          $daysend = $lang->questsystem_timeperiode_none;
        }

        if ($type['admin_assignment'] == 1) {
          $admin = $lang->questsystem_admin_allocation;

          if ($type['groupquest'] == 1) {
            eval("\$formgroup = \"" . $templates->get("questsystem_form_grouprequest") . "\";");
          } else {
            $formgroup = "";
          }
          eval("\$takequest = \"" . $templates->get("questsystem_form_takequest") . "\";");
        } else {
          $admin = $lang->questsystem_admin_allocation_none;
          if ($type['groupquest'] == 1) {
            eval("\$formgroup = \"" . $templates->get("questsystem_form_grouprequest") . "\";");
          } else {
            $formgroup = "";
          }
          eval("\$takequest = \"" . $templates->get("questsystem_form_takequest_random") . "\";");
        }
        if ($type['points_add'] != 0) {
          $pointsadd = $lang->sprintf($lang->questsystem_points_for_quest, $type['points_add']);
        } else {
          $pointsadd = "";
        }
        if ($type['points_minus'] != 0) {
          $pointsminus = $lang->sprintf($lang->questsystem_points_for_quest_minus, $type['points_minus']);
        } else {
          $pointsminus = "";
        }
        if ($type['finish_typ'] == "post") {
          $success = $lang->questsystem_end_post;
        } else {
          $success = $lang->questsystem_end_scene;
        }

        if ($mybb->input['take_waiting']) {

          $qtid = $mybb->get_input('id');
          $typeinfos =  $db->fetch_array($db->write_query("SELECT * FROM " . TABLE_PREFIX . "questsystem_type WHERE id = {$qtid}"));
          //Gruppenquest - auch für partner eintragen
          $groupstring = "";
          if ($mybb->input['partners'] != "") {
            $partneruid = get_user_by_username($mybb->input['partners']);
            $groupstring = $mybb->user['uid'] . "," . $partneruid['uid'];
            $insert = [
              "qid" => 0,
              "qtid" => $qtid,
              "uid" => $partneruid['uid'],
              "groups_uids" => $groupstring,
            ];
            $db->insert_query("questsystem_quest_user", $insert);

            //alert an partner
            if (class_exists('MybbStuff_MyAlerts_AlertTypeManager')) {
              $alertType = MybbStuff_MyAlerts_AlertTypeManager::getInstance()->getByCode('questsystem_QuestPartner');
              //testen ob es den alertTyp gibt und ob der user einen Alert bekommen möchte
              if ($alertType != NULL && $alertType->getEnabled()) {
                //constructor: first: an welchen user , second: type  and third the objectId 
                $alert = new MybbStuff_MyAlerts_Entity_Alert((int)$partneruid['uid'], $alertType);
                //some extra details
                $alert->setExtraDetails([]);
                //add the alert
                MybbStuff_MyAlerts_AlertManager::getInstance()->addAlert($alert);
              }
            }
          }

          // Ziehen eines Quests, welches zugeteilt werden muss -> User in die Warteschlange packen
          $insert = [
            "qid" => 0,
            "qtid" => $qtid,
            "uid" => $mybb->user['uid'],
            "groups_uids" => $groupstring,
          ];
          $db->insert_query("questsystem_quest_user", $insert);

          redirect('misc.php?action=questsystem_progress');
        }

        if ($mybb->get_input('take_random')) {
          // Ziehen eines Quests, welches zufällig zugeordnet wird

          $qtid = $mybb->get_input('questid');

          $typeinfos =  $db->fetch_array($db->write_query("SELECT * FROM " . TABLE_PREFIX . "questsystem_type WHERE id = {$qtid}"));

          //Darf das Quest mehrfach erledigt werden? 
          if ($typeinfos['unique'] == 0) {
            $in_progress = " AND in_progress = 0 ";
          } else {
            $in_progress = "";
          }

          //Darf ein User ein Quest mehrfach erledigen?
          if ($typeinfos['repeat'] == 1) {
            $repeat = " AND concat(',',uids,',') not LIKE '%,{$mybb->user['uid']},%' ";
          } else {
            $repeat = "";
          }
          //zufälliges Quest holen
          if ($mybb->input['partners'] == "" || empty($mybb->input['partners'])) {
            //KEIN GRUPPENQUEST

            $randquest = $db->fetch_array($db->write_query("SELECT * FROM " . TABLE_PREFIX . "questsystem_quest WHERE type = '{$qtid}' " . $in_progress . $repeat . " AND  id NOT IN (SELECT QID as ID FROM " . TABLE_PREFIX . "questsystem_quest_user WHERE uid = '{$mybb->user['uid']}}' AND done = 0) AND groupquest = 0 AND admincheck = 1 ORDER BY RAND() LIMIT 1 "));

            if (empty($randquest)) {
              error("Bei diesem Questtyp gibt es zur Zeit keine Quests und du kannst keins ziehen.", "Keine Quests.");
            } else {
              $groupstring = "";
              //Quest für user speichern
              $insert = [
                "qid" => $randquest['id'],
                "qtid" => $qtid,
                "uid" => $mybb->user['uid'],
                "groups_uids" => $groupstring,
              ];
              $db->insert_query("questsystem_quest_user", $insert);

              //Quest auf in progress setzen
              $update_quest = [
                "in_progress" => 1,
              ];
              $db->update_query("questsystem_quest", $update_quest, "id='{$randquest['id']}'");
            }
          } else {
            //GRUPPENQUEST
            $randquest = $db->fetch_array($db->write_query("SELECT * FROM " . TABLE_PREFIX . "questsystem_quest WHERE type = '{$qtid}' " . $in_progress . $repeat . " AND id NOT IN (SELECT QID as ID FROM " . TABLE_PREFIX . "questsystem_quest_user WHERE uid = '{$mybb->user['uid']}' AND done = 0) AND groupquest = 1 AND admincheck = 1 ORDER BY RAND() LIMIT 1 "));
            if (empty($randquest)) {
              error("Bei diesem Questtyp gibt es zur Zeit keine Quests und du kannst keins ziehen.", "Keine Quests.");
            } else {
              $groupstring = "";
              //Quest für user speichern

              //Quest auf in progress setzen
              $update_quest = [
                "in_progress" => 1,
              ];
              $db->update_query("questsystem_quest", $update_quest, "id='{$randquest['id']}'");

              //Beim Partner auch speichern 
              $userdata = array_filter(explode(",", $mybb->input['partners']));
              $partner = get_user_by_username($userdata[0]);

              $groupstring = $mybb->user['uid'] . "," . $partner['uid'];
              $insert = [
                "qid" => $randquest['id'],
                "qtid" => $qtid,
                "uid" => $partner['uid'],
                "groups_uids" => $groupstring,
              ];
              $db->insert_query("questsystem_quest_user", $insert);

              //alert an partner
              if (class_exists('MybbStuff_MyAlerts_AlertTypeManager')) {
                $alertType = MybbStuff_MyAlerts_AlertTypeManager::getInstance()->getByCode('questsystem_QuestPartner');
                //testen ob es den alertTyp gibt und ob der user einen Alert bekommen möchte
                if ($alertType != NULL && $alertType->getEnabled()) {
                  //constructor: first: an welchen user , second: type  and third the objectId 
                  $alert = new MybbStuff_MyAlerts_Entity_Alert((int)$partner['uid'], $alertType);
                  //some extra details
                  $alert->setExtraDetails([]);
                  //add the alert
                  MybbStuff_MyAlerts_AlertManager::getInstance()->addAlert($alert);
                }
              }

              $insert = [
                "qid" => $randquest['id'],
                "qtid" => $qtid,
                "uid" => $mybb->user['uid'],
                "groups_uids" => $groupstring,
              ];
              $db->insert_query("questsystem_quest_user", $insert);
            }
          }
          redirect('misc.php?action=questsystem_progress');
        }
        eval("\$questsystem_misc_questtypbit .= \"" . $templates->get("questsystem_misc_questtypbit") . "\";");
      }
    }
    eval("\$questsystem_misc_main = \"" . $templates->get("questsystem_misc_main") . "\";");
    output_page($questsystem_misc_main);
    die();
  }
}

$plugins->add_hook("misc_start", "questsystem_show_progress");
function questsystem_show_progress()
{
  global $db, $mybb, $templates, $header, $footer, $theme, $headerinclude, $lang, $takequest, $questsystem_nav, $questsystem_nav_points;

  if (!$mybb->get_input('action') == "questsystem_progress") return;

  if ($mybb->get_input('action') == "questsystem_progress") {
    if ($mybb->get_input('delete') != "") {
      //TODO Das der partner löschen
      //questid bekommen
      $id = $mybb->get_input('delete');
      $questid = $mybb->get_input('questid');
      $db->delete_query("questsystem_quest_user", "id = {$id}");
      //Quest progress zurücksetzen
      $update_quest = [
        "in_progress" => 0,
      ];
      $db->update_query("questsystem_quest", $update_quest, "id='{$questid}'");
    }

    $questsystem_misc_progress = "";
    $lang->load('questsystem');
    add_breadcrumb($lang->questsystem_name . " - Aktuelle Quests", "misc.php?action=misc.php?action=questsystem_progress");
    questsystem_navigation();

    $charas = questsystem_get_allchars($mybb->user['uid']);
    $cnt = 0;
    foreach ($charas as $uid => $username) {
      $questflag = false;
      $progress_quests = $db->simple_select("questsystem_quest_user", "*", "done != 1 AND uid = {$uid}");
      $cnt = 0;
      while ($quest = $db->fetch_array($progress_quests)) {
        $cnt++;
        $questflag = true;
        // var_dump($quest);
        $type =  $db->fetch_array($db->write_query("SELECT * FROM " . TABLE_PREFIX . "questsystem_type WHERE id = {$quest['qtid']}"));
        $questdata = $db->fetch_array($db->write_query("SELECT * FROM " . TABLE_PREFIX . "questsystem_quest WHERE id = {$quest['qid']}"));
        $quest_type = $type['name'];

        //Noch kein Quest zugeteilt, wartet auf admin zuteilung
        if ($quest['qid'] == 0) {
          $delete_link = "";
          $daysend  = "";
          $waiting = $lang->questsystem_waiting;
          $success = "";
          $submitted = "";
          $partner_str = "";
          $partner_arr = array();
          if ($quest['groups_uids'] != 0  || $quest['groups_uids'] != "") {
            $partner_arr = explode(",", $quest['groups_uids']);
            foreach ($partner_arr as $p_uid) {
              if ($p_uid != $uid) {
                $partner = get_user($p_uid);
                $partner_str .= "<span class=\"quest-partnerlink\"> " . build_profile_link($partner['username'], $p_uid) . " </span>";
              }
            }
            $group = "<span><b>Gruppenquest mit:</b> " . $partner_str . "</span>";
          } else {
            $group = "";
          }
        } else {
          $waiting = "";
          if ($type['enddays'] == NULL) {
            $type['enddays'] = 0;
          }
          $enddate = $db->fetch_field($db->simple_select("questsystem_quest_user", "*, date_format((startdate + INTERVAL + {$type['enddays']} DAY), '%d.%m.%Y') as enddate", "id = {$quest['id']}"), "enddate");
          $progress_state = 0;
          // $progress_state = $db->fetch_field($db->simple_select("questsystem_quest_user", "done", "id = {$quest['id']}"), "done");
          $type['typedescr'] = htmlspecialchars_uni($type['typedescr']);
          if ($type['enddays'] != 0) {
            $daysend = $lang->sprintf($lang->questsystem_daysuntil, $enddate);
          } else {
            $daysend = $lang->questsystem_daysuntil_none;
          }
          if ($type['delete'] == "1") {
            $delete_link = "<a href=\"misc.php?action=questsystem_progress&delete={$quest['id']}&questid={$quest['qid']}\" onclick=\"return confirm('Willst du das Quest wirklich zurückgeben?')\">Quest zurückgeben</a>";
          } else {
            $delete_link = "";
          }
          if ($type['finish_typ'] == "post") {
            $success = $lang->questsystem_end_post;
          } else {
            $success = $lang->questsystem_end_scene;
          }
          if ($type['points_add'] != 0) {
            $pointsadd = $lang->sprintf($lang->questsystem_points_for_quest, $type['points_add']);
          } else {
            $pointsadd = "";
          }
          if ($type['points_minus'] != 0) {
            $pointsminus = $lang->sprintf($lang->questsystem_points_for_quest_minus, $type['points_minus']);
          } else {
            $pointsminus = "";
          }
          $partner_arr = array();
          if ($questdata['groupquest'] == 1) {
            $partner_str = "";
            if ($quest['groups_uids'] != 0 || !empty($quest['groups_uids']) || $quest['groups_uids'] != "") {
              $partner_arr = explode(",", $quest['groups_uids']);
              foreach ($partner_arr as $p_uid) {
                if ($p_uid != $uid) {
                  $partner = get_user($p_uid);
                  $partner_str .= "<span class=\"quest-partnerlink\">" . build_profile_link($partner['username'], $p_uid) . " </span>";
                } else {
                  $partner_str = "";
                }
              }
            }

            $group = "<span><b>Gruppenquest mit:</b> " . $partner_str . "</span>";
          } else {
            $group = "";
          }
          if ($quest['done'] == 2) {
            $submitted = $lang->questsystem_submitted;
          } else {
            $submitted = "";
          }
        }

        if ($cnt == 1) {
          $username_tit = "<h2 class=\"questsystem-username bl-heading2\">{$username}</h2>";
        } else {
          $username_tit = "";
        }
        if ($questflag) {
          eval("\$questsystem_misc_quests_progress .= \"" . $templates->get("questsystem_misc_quests_progress") . "\";");
        }
      }
    }
    eval("\$questsystem_misc_progress = \"" . $templates->get("questsystem_misc_progress") . "\";");
    output_page($questsystem_misc_progress);
    die();
  }
}

$plugins->add_hook("misc_start", "questsystem_show_done");
function questsystem_show_done()
{
  global $db, $mybb, $templates, $header, $footer, $theme, $headerinclude, $lang, $takequest, $questsystem_nav, $questsystem_nav_points;

  if (!$mybb->get_input('action') == "questsystem_done") return;

  if ($mybb->input['action'] == "questsystem_done") {
    $quest = false;
    $questsystem_misc_done = "";
    $lang->load('questsystem');
    add_breadcrumb($lang->questsystem_name . " - Erledigte Quests", "misc.php?action=misc.php?action=^");
    questsystem_navigation();

    $charas = questsystem_get_allchars($mybb->user['uid']);
    $cnt = 0;
    foreach ($charas as $uid => $username) {

      $quests_done = $db->simple_select("questsystem_quest_user", "*", "done = 1 AND uid = {$uid}");
      $cnt = 0;
      while ($quest = $db->fetch_array($quests_done)) {
        $questflag = false;
        $cnt++;
        // var_dump($quest);
        $type =  $db->fetch_array($db->write_query("SELECT * FROM " . TABLE_PREFIX . "questsystem_type WHERE id = {$quest['qtid']}"));
        $questdata = $db->fetch_array($db->write_query("SELECT * FROM " . TABLE_PREFIX . "questsystem_quest WHERE id = {$quest['qid']}"));
        $quest_type = $type['name'];

        $waiting = "";

        $enddatestr = $db->fetch_field($db->simple_select("questsystem_quest_user", "*, date_format((startdate + INTERVAL + {$type['enddays']} DAY), '%d.%m.%Y') as enddate", "id = {$quest['id']}"), "enddate");

        $enddate = new DateTime($enddatestr);
        $today = new DateTime();
        //Test if enddate > als heute und tid leer -> dann nicht beendet
        if (($quest['pid'] == "0" && $quest['tid'] == "0")) {
          $questflag = true;
          //Quest wäre abgelaufen
          //endday nicht größer, dann alles gut und quest erledigt
          $success = "<span>Quest ist abgelaufen am <b>{$enddatestr}</b></span>";

          if ($type['points_minus'] != 0) {
            $pointsminus = $lang->sprintf($lang->questsystem_points_for_quest_minus, $type['points_minus']);
            $pointsadd = "";
          } else {
            $pointsminus = "";
            $pointsadd = "";
          }

          if ($questdata['groupquest'] == 1) {
            $getpartners = $db->fetch_array($db->write_query("SELECT * FROM " . TABLE_PREFIX . "questsystem_quest_user WHERE uid = {$uid} AND qid = {$quest['qid']}"));
            $partner_arr = explode(",", $getpartners['groups_uids']);
            $partner_str = "";
            foreach ($partner_arr as $p_uid) {
              if ($p_uid != $uid) {
                $partner = get_user($p_uid);
                $partner_str .= "<span class=\"quest-partnerlink\">" . build_profile_link($partner['username'], $p_uid) . " </span>";
              } else {
              }
            }
            $group = "<span><b>Gruppenquest mit:</b> " . $partner_str . "</span>";
          } else {
            $group = "";
          }
          if ($cnt == 1) {
            $username_tit = "<h2>{$username}</h2>";
          } else {
            $username_tit = "";
          }
          if ($questflag) {
            eval("\$questsystem_misc_quests_expired .= \"" . $templates->get("questsystem_misc_quests_done") . "\";");
          }
        } else {
          $questflag = true;
          //endday nicht größer, dann alles gut und quest erledigt
          if ($type['finish_typ'] == "post") {
            $success = "<span><a href=\"showthread.php?tid={$quest['tid']}&pid={$quest['pid']}#pid{$quest['pid']}\">Zum Post</a></span>";
          } else {
            $success = "<span><a href=\"showthread.php?tid={$quest['tid']}\">Zur Szene</a></span>";
          }
          if ($type['points_add'] != 0) {
            $pointsadd = "<span><b>Punkte:</b> {$type['points_add']} Punkte für ein Quest.</span>";
            $pointsminus = "";
          } else {
            $pointsadd = "";
            $pointsminus = "";
          }

          if ($questdata['groupquest'] == 1) {
            $getpartners = $db->fetch_array($db->write_query("SELECT * FROM " . TABLE_PREFIX . "questsystem_quest_user WHERE uid = {$uid} AND qid = {$quest['qid']}"));
            $partner_arr = explode(",", $getpartners['groups_uids']);
            $partner_str = "";
            foreach ($partner_arr as $p_uid) {
              if ($p_uid != $uid) {
                $partner = get_user($p_uid);
                $partner_str .= "<span class=\"quest-partnerlink\">" . build_profile_link($partner['username'], $p_uid) . " </span>";
              } else {
              }
            }
            $group = "<span><b>Gruppenquest mit:</b> " . $partner_str . "</span>";
          } else {
            $group = "";
          }
          if ($cnt == 1) {
            $username_tit = "<h2>{$username}</h2>";
          } else {
            $username_tit = "";
          }
          if ($questflag) {
            eval("\$questsystem_misc_quests_done .= \"" . $templates->get("questsystem_misc_quests_done") . "\";");
          }
        }
      }
    }
    eval("\$questsystem_misc_done = \"" . $templates->get("questsystem_misc_done") . "\";");
    output_page($questsystem_misc_done);
    die();
  }
}

$plugins->add_hook("misc_start", "questsystem_show_submit");
function questsystem_show_submit()
{
  global $db, $mybb, $templates, $header, $footer, $theme, $headerinclude, $lang, $takequest, $questsystem_nav, $questsystem_nav_points;

  if (!$mybb->get_input('action') == "questsystem_submit") return;

  if ($mybb->input['action'] == "questsystem_submit") {
    $lang->load('questsystem');
    add_breadcrumb($lang->questsystem_name . " - Questeinreichen", "misc.php?action=misc.php?action=questsystem_submit");
    questsystem_navigation();
    $questsystem_misc_submit = "";


    $get_questtyp = $db->simple_select("questsystem_type", "*", "user_add = 1 ");
    $cnt = 0;
    $select_typ_group = "<select name=\"questtyp\" size=\"3\" required>";
    while ($questtyp = $db->fetch_array($get_questtyp)) {
      if ($questtyp['groupquest'] == 1) {
        $select_typ_group .= "<option value=\"{$questtyp['id']},0\">{$questtyp['name']}</option>";
        $select_typ_group .= "<option value=\"{$questtyp['id']},1\">{$questtyp['name']} - Gruppenquest</option>";
      } else {
        $select_typ_group .= "<option value=\"{$questtyp['id']},0\">{$questtyp['name']}</option>";
      }
    }
    $select_typ_group .= "</select>";

    if ($mybb->input['submit_quest']) {
      if ($mybb->input['as_uid'] == 0) {
        $from =  $mybb->user['uid'];
      } else {
        $from =  $mybb->user['as_uid'];
      }

      $setting_type = explode(",", $mybb->input['questtyp']);
      $insert = [
        "type" => $setting_type['0'],
        "groupquest" => $setting_type['1'],
        "name" => $db->escape_string($mybb->input['quest_name']),
        "uid" => $from,
        "questdescr" => $db->escape_string($mybb->input['quest_dscr']),
        "admincheck" => 0,
      ];
      $db->insert_query("questsystem_quest", $insert);
      redirect('misc.php?action=questsystem_submit');
    }
    eval("\$questsystem_misc_submit = \"" . $templates->get("questsystem_misc_submit") . "\";");
    output_page($questsystem_misc_submit);
    die();
  }
}

$plugins->add_hook("misc_start", "questsystem_show_overview");
function questsystem_show_overview()
{
  global $db, $mybb, $templates, $header, $footer, $theme, $headerinclude, $lang, $takequest, $questsystem_nav, $questsystem_nav_points, $questsystem_misc_overviewquests, $questsystem_overview_points;


  if (!$mybb->get_input('action') == "questsystem_points") return;

  if ($mybb->input['action'] == "questsystem_points") {
    if ($mybb->settings['questsystem_overview_overall']) {
      $lang->load('questsystem');
      add_breadcrumb($lang->questsystem_name . " - Übersicht", "misc.php?action=misc.php?action=questsystem_submit");
      questsystem_navigation();
      $questsystem_misc_overview = "";
      $questsystem_misc_overviewquests = "";
      $questsystem_misc_overviewpoints = "";
      // questsystem_overview_quest
      if ($mybb->settings['questsystem_overview_quest']) {
        //Alle User bekommen, die ein Quest machen
        $query_questuser = $db->write_query("SELECT uid FROM `" . TABLE_PREFIX . "questsystem_points` GROUP by uid");
        while ($questuser = $db->fetch_array($query_questuser)) {
          //pro user die quests sammeln
          $quests = "";
          $questdescr = "";
          $questname = "";
          $userinfo = get_user($questuser['uid']);
          $username = build_profile_link($userinfo['username'],  $userinfo['uid']);;
          $quests_user = $db->simple_select("questsystem_quest_user", "*", "uid = '{$questuser['uid']}'", array("order_by" => "done"));
          while ($questuser = $db->fetch_array($quests_user)) {

            $questname = $db->fetch_field($db->write_query("SELECT name FROM `" . TABLE_PREFIX . "questsystem_quest` WHERE id = '{$questuser['qid']}'"), "name");
            $questdescr = $db->fetch_field($db->write_query("SELECT questdescr FROM `" . TABLE_PREFIX . "questsystem_quest` WHERE id = '{$questuser['qid']}'"), "questdescr");

            if ($questuser['done'] == 0) {
              $status = " - in progress";
            } else {
              $status = " - done";
            }

            $quests .= "<div class=\"questsystem-overview__status\">{$questname}{$status}
          <div =\"questsystem-overview__questdescr\">{$questdescr}</div>
          </div>";
          }
          eval("\$questsystem_misc_overviewquestsbit .= \"" . $templates->get("questsystem_misc_overviewquestsbit") . "\";");
        }
        eval("\$questsystem_misc_overviewquests = \"" . $templates->get("questsystem_misc_overviewquests") . "\";");
      }

      // questsystem_overview_points
      if ($mybb->settings['questsystem_overview_points']) {
        //Alle User bekommen, die ein Quest machen
        $query_questuser = $db->write_query("SELECT uid FROM `" . TABLE_PREFIX . "questsystem_points` GROUP by uid");
        while ($questuser = $db->fetch_array($query_questuser)) {
          //pro user die quests sammeln
          $points_reasons = "";
          $userinfo = get_user($questuser['uid']);
          $username = build_profile_link($userinfo['username'],  $userinfo['uid']);;
          $quests_points = $db->simple_select("questsystem_points", "*", "uid = '{$questuser['uid']}'");

          $quests_points_sum = $db->fetch_field($db->write_query("SELECT sum(points) as summe FROM " . TABLE_PREFIX . "questsystem_points  WHERE uid = '{$questuser['uid']}' GROUP BY uid"), "summe");

          while ($questpoint = $db->fetch_array($quests_points)) {
            if ($questpoint['objectid'] != "" && $questpoint['objectid'] != 0) {
              $questname = $db->fetch_field(
                $db->write_query("SELECT name FROM `" . TABLE_PREFIX . "questsystem_quest` WHERE id = '{$questpoint['objectid']}'"),
                "name"
              ) . " - ";
            } else {
              $questname = "";
            }
            $points_reasons .= "<div class=\"\">{$questname}{$questpoint['points']} {$questpoint['reason']}</div>";
          }
          $points_reasons .= "";

          eval("\$questsystem_misc_overviewpoints_bit .= \"" . $templates->get("questsystem_misc_overviewpoints_bit") . "\";");
        }
        eval("\$questsystem_misc_overviewpoints = \"" . $templates->get("questsystem_misc_overviewquests") . "\";");



        eval("\$questsystem_misc_overviewpoints = \"" . $templates->get("questsystem_misc_overviewpoints") . "\";");
      }
      eval("\$questsystem_misc_overview = \"" . $templates->get("questsystem_misc_overview") . "\";");
      output_page($questsystem_misc_overview);
      die();
    } else {
      error("Die Punkteübersicht ist deaktiviert", "Punkteübersicht - Questsystem");
    }
  }
}

$plugins->add_hook("postbit", "questsystem_postbit");
function questsystem_postbit(&$post)
{
  global $mybb, $db;
  //Quest einreichen 
  //Für Post 
  //  echo($post['uid']);
  $userhasquest = $db->simple_select("questsystem_quest_user", "*", "(concat(',',`groups_uids`,',') LIKE '%,{$post['uid']},%' OR uid = '{$post['uid']}') AND qid != '0' AND done = '0' GROUP BY qid");

  if ($db->num_rows($userhasquest) > 0 && $post['uid'] != 0) {
    $pid = $post['pid'];
    $tid = $post['tid'];
    $select_typ = "<select name=\"questid\" size=\"3\" style=\"min-width:160px;\" required>";

    while ($questuser = $db->fetch_array($userhasquest)) {
      $getquestename = $db->fetch_field($db->simple_select("questsystem_quest", "name", "id = {$questuser['qid']}"), "name");
      $select_typ .= "<option value=\"{$questuser['id']}\">{$getquestename}</option>";
    }
    $select_typ .= "</select>";

    $post['questbutton'] = "<button onclick=\"$('#quest{$pid}').modal({ fadeDuration: 250, keepelement: true, zIndex: (typeof modal_zindex !== 'undefined' ? modal_zindex : 9999) }); return false;\" class=\"questbutton\">Quest einreichen</button>";
    $post['modalquest'] = "<div class=\"modal questsystem_submitquest\" id=\"quest{$post['pid']}\" style=\"display: none; padding: 10px; margin: auto; text-align: center;\">
    <form action=\"misc.php?action=questsystem_submitquest\" id=\"questsystem_submitquest\" method=\"post\">
    <input type=\"hidden\" value=\"{$pid}\" name=\"pid\"/>
    <input type=\"hidden\" value=\"{$tid}\" name=\"tid\"/>
    
    {$select_typ}<br />
    <input type=\"submit\" id=\"\" name=\"questsystem_submitquest\" value=\"Quest einreichen\"/>
  </form>
  </div>
  ";

    $post['questbutton'] =  $post['questbutton'] .  $post['modalquest'];
  }
}

$plugins->add_hook("global_start", "questsystem_global");
function questsystem_global()
{
}

$plugins->add_hook("index_start", "questsystem_index");
function questsystem_index()
{
  global $mybb, $db, $markup, $templates, $questsystem_index_mod, $questsystem_index_mod_bit;

  if ($mybb->usergroup['canmodcp'] == 1) {
    $modflag = 0;
    $questsystem_index_mod_bit = "";
    // Meldung für Mods
    //  -> neues Quest eingereicht
    $admincheck =  $db->simple_select("questsystem_quest", "*", "admincheck = 0");
    if ($db->num_rows($admincheck) > 0) {
      $modflag = 1;
      $markup = "";
      // punkte wenn quest eingereicht. 
      while ($quest_in = $db->fetch_array($admincheck)) {
        eval("\$markup = \"" . $templates->get("questsystem_index_mod_bit_quest") . "\";");
        eval("\$questsystem_index_mod_bit .= \"" . $templates->get("questsystem_index_mod_bit") . "\";");
      }
    }

    //  -> User wartet auf Zuteilung
    $userwaits =  $db->simple_select("questsystem_quest_user", "*", "qid = 0");
    //alert an user wenn zugeteilt
    if ($db->num_rows($userwaits) > 0) {
      $modflag = 1;
      eval("\$markup = \"" . $templates->get("questsystem_index_mod_bit_user") . "\";");
      eval("\$questsystem_index_mod_bit .= \"" . $templates->get("questsystem_index_mod_bit") . "\";");
    }
    // -> quest wurde beendet und eingereicht
    $submittedquest =  $db->simple_select("questsystem_quest_user", "*", "done = 2");
    if ($db->num_rows($submittedquest) > 0) {
      $modflag = 1;

      while ($quest_sub = $db->fetch_array($submittedquest)) {
        $userinfo = get_user($quest_sub['uid']);
        $questinfo = $db->fetch_array($db->simple_select("questsystem_type", "*", "id = {$quest_sub['qtid']}"));
        $questdetail = $db->fetch_array($db->simple_select("questsystem_quest", "*", "id = {$quest_sub['qid']}"));
        $username = build_profile_link($userinfo['username'], $quest_sub['uid']);
        eval("\$markup = \"" . $templates->get("questsystem_index_mod_bit_submit") . "\";");
        eval("\$questsystem_index_mod_bit .= \"" . $templates->get("questsystem_index_mod_bit") . "\";");
      }
    }

    if ($mybb->input['action'] == "questaccept" && $mybb->usergroup['canmodcp'] == 1) {

      //Quest done auf 1 
      $update = [
        "done" => 1,
      ];
      //wieviele Punkte soll der User bekommen?
      $points = $db->fetch_field($db->simple_select("questsystem_type", "points_add", "id = {$mybb->input['qtid']} "), "points_add");

      // Ist es ein Gruppenquest?
      $group = $db->fetch_field($db->simple_select("questsystem_quest", "groupquest", "id = {$mybb->input['qid']} "), "groupquest");

      // Inhalt des Felds, welche User das Quest schon gemacht haben.
      $get_uids = $db->fetch_field($db->simple_select("questsystem_quest", "uids", "id = {$mybb->input['qid']} "), "uids");

      // wenn Gruppen quest müssen alle User die Punkte bekommen
      if ($group == 1) {

        // Welche User machen das Gruppenquest
        $users = $db->fetch_field($db->simple_select("questsystem_quest_user", "groups_uids", "id = '" . $mybb->get_input('id') . "' "), "groups_uids");
        // in Array basteln
        $arr_uids = array_filter(explode(",", $users));

        // Array durchgehen
        foreach ($arr_uids as $uid) {
          // an userstring hängen
          $get_uids .= ",{$uid}";
          //done = 1 
          $db->update_query("questsystem_quest_user", $update, "qid = {$mybb->input['qid']} and uid = {$uid}");

          //Punkte verteilen
          $insert = array(
            "uid" => $uid,
            "points" => $points,
            "reason" => "Punkte für erledigtes Quest.",
            "date" => date("Y-m-d"),
            "objectid" => $mybb->get_input('qid', MyBB::INPUT_INT),
          );
          $db->insert_query("questsystem_points", $insert);
          //Alert schicken
          if (class_exists('MybbStuff_MyAlerts_AlertTypeManager')) {
            $alertType = MybbStuff_MyAlerts_AlertTypeManager::getInstance()->getByCode('questsystem_QuestAccept');
            //testen ob es den alertTyp gibt und ob der user einen Alert bekommen möchte
            if ($alertType != NULL && $alertType->getEnabled()) {
              //constructor: first: an welchen user , second: type  and third the objectId 
              $alert = new MybbStuff_MyAlerts_Entity_Alert((int)$uid, $alertType);
              //some extra details
              $alert->setExtraDetails([
                //  'pid' => $questid,
                //  'tid' => $uid
              ]);
              //add the alert
              MybbStuff_MyAlerts_AlertManager::getInstance()->addAlert($alert);
            }
          }
        }
      } else {
        if (class_exists('MybbStuff_MyAlerts_AlertTypeManager')) {
          $alertType = MybbStuff_MyAlerts_AlertTypeManager::getInstance()->getByCode('questsystem_QuestAccept');
          //testen ob es den alertTyp gibt und ob der user einen Alert bekommen möchte
          if ($alertType != NULL && $alertType->getEnabled()) {
            //constructor: first: an welchen user , second: type  and third the objectId 
            $alert = new MybbStuff_MyAlerts_Entity_Alert((int)$mybb->input['uid'], $alertType);
            //some extra details
            $alert->setExtraDetails([
              //  'pid' => $questid,
              //  'tid' => $uid
            ]);
            //add the alert
            MybbStuff_MyAlerts_AlertManager::getInstance()->addAlert($alert);
          }
        }
        // Quest als erledigt markieren
        $db->update_query("questsystem_quest_user", $update, "id = '{$mybb->input['id']}' ");

        // uid hinzufügen (wer hat das Quest schon mal gemacht)
        $get_uids .= ",{$mybb->input['uid']}";

        // Punkte verteilen
        $insert = array(
          "uid" => $mybb->input['uid'],
          "points" => $points,
          "reason" => "Punkte für erledigtes Quest.",
          "date" => date("Y-m-d"),
          "objectid" => $mybb->get_input('qid', MyBB::INPUT_INT),
        );
        $db->insert_query("questsystem_points", $insert);
      }

      //Quest von in Progress auf nicht in Progress stellen
      $update_quest = [
        "in_progress" => 0,
        "uids" => $get_uids,
      ];
      $db->update_query("questsystem_quest", $update_quest, "id = {$mybb->input['qid']} ");
      redirect("index.php");
    }

    if ($mybb->input['action'] == "questdeny" && $mybb->usergroup['canmodcp'] == 1) {
      //Quest done auf 1 
      $update = [
        "done" => 0,
      ];

      // Ist es ein Gruppenquest?
      $group = $db->fetch_field($db->simple_select("questsystem_quest", "groupquest", "id = {$mybb->input['qid']} "), "groupquest");
      // Inhalt des Felds, welche User das Quest schon gemacht haben.
      $get_uids = $db->fetch_field($db->simple_select("questsystem_quest", "uids", "id = {$mybb->input['qid']} "), "uids");

      // wenn Gruppen quest müssen alle User die Punkte bekommen
      if ($group == 1) {
        // Welche User machen das Gruppenquest
        $groupid = $mybb->get_input('id');
        $users = $db->fetch_field($db->simple_select("questsystem_quest_user", "groups_uids", "id = '{$groupid}' "), "groups_uids");
        // in Array basteln
        $arr_uids = array_filter(explode(",", $users));
        // Array durcheghen
        foreach ($arr_uids as $uid) {
          //Alert schicken
          if (class_exists('MybbStuff_MyAlerts_AlertTypeManager')) {
            $alertType = MybbStuff_MyAlerts_AlertTypeManager::getInstance()->getByCode('questsystem_QuestDeny');
            //testen ob es den alertTyp gibt und ob der user einen Alert bekommen möchte
            if ($alertType != NULL && $alertType->getEnabled()) {
              //constructor: first: an welchen user , second: type  and third the objectId 
              $alert = new MybbStuff_MyAlerts_Entity_Alert((int)$uid, $alertType);
              //some extra details
              $alert->setExtraDetails([
                //  'pid' => $questid,
                //  'tid' => $uid
              ]);
              //add the alert
              MybbStuff_MyAlerts_AlertManager::getInstance()->addAlert($alert);
            }
          }
          //done = 0
          $db->update_query("questsystem_quest_user", $update, "qid = {$mybb->input['qid']} and uid = {$uid}");
        }
      } else {
        // alert verschicken
        if (class_exists('MybbStuff_MyAlerts_AlertTypeManager')) {
          $alertType = MybbStuff_MyAlerts_AlertTypeManager::getInstance()->getByCode('questsystem_QuestDeny');
          //testen ob es den alertTyp gibt und ob der user einen Alert bekommen möchte
          if ($alertType != NULL && $alertType->getEnabled()) {
            //constructor: first: an welchen user , second: type  and third the objectId 
            $alert = new MybbStuff_MyAlerts_Entity_Alert((int)$mybb->input['uid'], $alertType);
            //some extra details
            $alert->setExtraDetails([
              //  'pid' => $questid,
              //  'tid' => $uid
            ]);
            //add the alert
            MybbStuff_MyAlerts_AlertManager::getInstance()->addAlert($alert);
          }
        }
        // Quest wieder als unerledigt markieren done = 0
        $db->update_query("questsystem_quest_user", $update, "id = {$mybb->input['id']} ");
        redirect("index.php");
      }
    }

    if ($modflag == 1) {
      eval("\$questsystem_index_mod = \"" . $templates->get("questsystem_index_mod") . "\";");
    }
  }
}

function questsystem_navigation()
{
  global $mybb, $templates, $questsystem_nav, $questsystem_nav_points, $db, $questsystem_add_quest;
  if (
    $mybb->get_input('action') == "questsystem_points" ||
    $mybb->get_input('action') == "questsystem_submit" ||
    $mybb->get_input('action') == "questsystem_done" ||
    $mybb->get_input('action') == "questsystem_progress" ||
    $mybb->get_input('action') == "questsystem"
  ) {
    if ($mybb->settings['questsystem_overview_overall']) {
      eval("\$questsystem_nav_points = \"" . $templates->get("questsystem_nav_points") . "\";");
    }
    $addquest = $db->write_query("SELECt * FROM `" . TABLE_PREFIX . "questsystem_type` WHERE user_add = 1");
    if ($db->num_rows($addquest)) {
      eval("\$questsystem_add_quest = \"" . $templates->get("questsystem_nav_addquest") . "\";");
    }
    eval("\$questsystem_nav = \"" . $templates->get("questsystem_nav") . "\";");
  }
}
/**
 * Testet ob ein User das Quest sehen/benutzen darf
 */
function questsystem_isAllowed($type, $thisuser)
{
  global $db, $mybb;
  $grouparray = array();
  if (is_member($type['groups_allowed'], $thisuser)) {
    return true;
  } else if ($type['group_str'] != "0") {
    $grouparray = explode(",", trim($type['group_str']));
    if (is_numeric($type['group_fid'])) {
      $group = trim($db->fetch_field($db->simple_select("userfields", "fid" . $type['group_fid'], "ufid = '{$thisuser}'"), "fid" . $type['group_fid']));
      return in_array($group, $grouparray);
    } else {
      $fieldid = $db->fetch_field($db->simple_select("application_ucp_fields", "id", "fieldname = '" . $type['group_fid'] . "'"), "id");
      $group = $db->fetch_field($db->simple_select("application_ucp_userfields", "*", "uid = {$thisuser} AND fieldid = '{$fieldid}'"), "value");
      return in_array($group, $grouparray);
    }
  } else {
    return false;
  }
  return false;
}

/****
 * Hilfsfunktion für Mehrfachcharaktere (accountswitcher)
 * Alle angehangenen Charas holen
 * an die Funktion übergeben: Wer ist Online, die dazugehörige accountswitcher ID (ID des Hauptcharas) 
 ****/

function questsystem_get_allchars($thisuser)
{
  global $mybb, $db;

  //von dem übergebenen user, alle Infos bekommen -> brauchen wir um an das Feld as_uid zu kommen
  $user = get_user($thisuser);
  $as_uid = $user['as_uid'];
  //Array initialisieren
  $charas = array();
  if ($as_uid == 0) {
    // as_uid = 0 wenn hauptaccount oder keiner angehangen
    $get_all_users = $db->query("SELECT uid,username FROM " . TABLE_PREFIX . "users WHERE (as_uid = $thisuser) OR (uid = $thisuser) ORDER BY username");
  } else if ($as_uid != 0) {
    //id des users holen wo alle an gehangen sind 
    $get_all_users = $db->query("SELECT uid,username FROM " . TABLE_PREFIX . "users WHERE (as_uid = $as_uid) OR (uid = $thisuser) OR (uid = $as_uid) ORDER BY username");
  }
  //Wir speichern jetzt alle Charas in einem Array
  while ($users = $db->fetch_array($get_all_users)) {
    $uid = $users['uid'];

    $charas[$uid] = $users['username'];
  }
  //und geben es zurück
  return $charas;
}

/**************************** 
 * 
 *  My Alert Integration
 * 
 * *************************** */
if (class_exists('MybbStuff_MyAlerts_AlertTypeManager')) {
  $plugins->add_hook("global_start", "questsystem_alert");
}

function questsystem_alert()
{
  global $mybb, $lang;
  $lang->load('questsystem');
  /**
   * We need our MyAlert Formatter
   * Alert Formater for NewScene
   */
  class MybbStuff_MyAlerts_Formatter_QuestsystemQuestAcceptedFormatter extends MybbStuff_MyAlerts_Formatter_AbstractFormatter
  {
    /**
     * Build the output string for listing page and the popup.
     * @param MybbStuff_MyAlerts_Entity_Alert $alert The alert to format.
     * @return string The formatted alert string.
     */
    public function formatAlert(MybbStuff_MyAlerts_Entity_Alert $alert, array $outputAlert)
    {
      $alertContent = $alert->getExtraDetails();
      return $this->lang->sprintf(
        $this->lang->questsystem_QuestAccepted,
        $outputAlert['name']
      );
    }
    /**
     * Initialize the language, we need the variables $l['myalerts_setting_alertname'] for user cp! 
     * and if need initialize other stuff
     * @return void
     */
    public function init()
    {
      if (!$this->lang->questsystem) {
        $this->lang->load('questsystem');
      }
    }
    /**
     * We want to define where we want to link to. 
     * @param MybbStuff_MyAlerts_Entity_Alert $alert for which alert.
     * @return string return the link.
     */
    public function buildShowLink(MybbStuff_MyAlerts_Entity_Alert $alert)
    {
      $alertContent = $alert->getExtraDetails();
      return $this->mybb->settings['bburl'] . '/misc.php?action=questsystem';
    }
  }
  if (class_exists('MybbStuff_MyAlerts_AlertFormatterManager')) {
    $formatterManager = MybbStuff_MyAlerts_AlertFormatterManager::getInstance();
    if (!$formatterManager) {
      $formatterManager = MybbStuff_MyAlerts_AlertFormatterManager::createInstance($mybb, $lang);
    }
    $formatterManager->registerFormatter(
      new MybbStuff_MyAlerts_Formatter_QuestsystemQuestAcceptedFormatter($mybb, $lang, 'questsystem_QuestAccepted')
    );
  }
  // Info  Zuteilung Quest 
  class MybbStuff_MyAlerts_Formatter_QuestsystemGiveUserFormatter extends MybbStuff_MyAlerts_Formatter_AbstractFormatter
  {
    /**
     * Build the output string tfor listing page and the popup.
     * @param MybbStuff_MyAlerts_Entity_Alert $alert The alert to format.
     * @return string The formatted alert string.
     */
    public function formatAlert(MybbStuff_MyAlerts_Entity_Alert $alert, array $outputAlert)
    {
      $alertContent = $alert->getExtraDetails();
      return $this->lang->sprintf(
        $this->lang->questsystem_giveUser,
        $outputAlert['from_user'],
        $alertContent['tid'],
        $alertContent['pid'],
        $outputAlert['dateline']
      );
    }
    /**
     * Initialize the language, we need the variables $l['myalerts_setting_alertname'] for user cp! 
     * and if need initialize other stuff
     * @return void
     */
    public function init()
    {
      if (!$this->lang->questsystem) {
        $this->lang->load('questsystem');
      }
    }
    /**
     * We want to define where we want to link to. 
     * @param MybbStuff_MyAlerts_Entity_Alert $alert for which alert.
     * @return string return the link.
     */
    public function buildShowLink(MybbStuff_MyAlerts_Entity_Alert $alert)
    {
      $alertContent = $alert->getExtraDetails();
      return $this->mybb->settings['bburl'] . '/misc.php?action=questsystem_progress';
    }
  }
  if (class_exists('MybbStuff_MyAlerts_AlertFormatterManager')) {
    $formatterManager = MybbStuff_MyAlerts_AlertFormatterManager::getInstance();
    if (!$formatterManager) {
      $formatterManager = MybbStuff_MyAlerts_AlertFormatterManager::createInstance($mybb, $lang);
    }
    $formatterManager->registerFormatter(
      new MybbStuff_MyAlerts_Formatter_QuestsystemGiveUserFormatter($mybb, $lang, 'questsystem_giveUser')
    );
  }

  //Info Quest wurde abgelehnt.
  class MybbStuff_MyAlerts_Formatter_QuestsystemQuestDenyFormatter extends MybbStuff_MyAlerts_Formatter_AbstractFormatter
  {
    /**
     * Build the output string for listing page and the popup.
     * @param MybbStuff_MyAlerts_Entity_Alert $alert The alert to format.
     * @return string The formatted alert string.
     */
    public function formatAlert(MybbStuff_MyAlerts_Entity_Alert $alert, array $outputAlert)
    {
      $alertContent = $alert->getExtraDetails();
      return $this->lang->sprintf(
        $this->lang->questsystem_QuestDeny,
        $outputAlert['name']
      );
    }
    /**
     * Initialize the language, we need the variables $l['myalerts_setting_alertname'] for user cp! 
     * and if need initialize other stuff
     * @return void
     */
    public function init()
    {
      if (!$this->lang->questsystem) {
        $this->lang->load('questsystem');
      }
    }
    /**
     * We want to define where we want to link to. 
     * @param MybbStuff_MyAlerts_Entity_Alert $alert for which alert.
     * @return string return the link.
     */
    public function buildShowLink(MybbStuff_MyAlerts_Entity_Alert $alert)
    {
      $alertContent = $alert->getExtraDetails();
      return $this->mybb->settings['bburl'] . '/misc.php?action=questsystem';
    }
  }
  if (class_exists('MybbStuff_MyAlerts_AlertFormatterManager')) {
    $formatterManager = MybbStuff_MyAlerts_AlertFormatterManager::getInstance();
    if (!$formatterManager) {
      $formatterManager = MybbStuff_MyAlerts_AlertFormatterManager::createInstance($mybb, $lang);
    }
    $formatterManager->registerFormatter(
      new MybbStuff_MyAlerts_Formatter_QuestsystemQuestDenyFormatter($mybb, $lang, 'questsystem_QuestDeny')
    );
  }

  //Info Quest wurde abgelehnt.
  class MybbStuff_MyAlerts_Formatter_QuestsystemQuestAcceptFormatter extends MybbStuff_MyAlerts_Formatter_AbstractFormatter
  {
    /**
     * Build the output string for listing page and the popup.
     * @param MybbStuff_MyAlerts_Entity_Alert $alert The alert to format.
     * @return string The formatted alert string.
     */
    public function formatAlert(MybbStuff_MyAlerts_Entity_Alert $alert, array $outputAlert)
    {
      $alertContent = $alert->getExtraDetails();
      return $this->lang->sprintf(
        $this->lang->questsystem_QuestAccept,
        $outputAlert['name']
      );
    }
    /**
     * Initialize the language, we need the variables $l['myalerts_setting_alertname'] for user cp! 
     * and if need initialize other stuff
     * @return void
     */
    public function init()
    {
      if (!$this->lang->questsystem) {
        $this->lang->load('questsystem');
      }
    }
    /**
     * We want to define where we want to link to. 
     * @param MybbStuff_MyAlerts_Entity_Alert $alert for which alert.
     * @return string return the link.
     */
    public function buildShowLink(MybbStuff_MyAlerts_Entity_Alert $alert)
    {
      $alertContent = $alert->getExtraDetails();
      return $this->mybb->settings['bburl'] . '/misc.php?action=questsystem';
    }
  }
  if (class_exists('MybbStuff_MyAlerts_AlertFormatterManager')) {
    $formatterManager = MybbStuff_MyAlerts_AlertFormatterManager::getInstance();
    if (!$formatterManager) {
      $formatterManager = MybbStuff_MyAlerts_AlertFormatterManager::createInstance($mybb, $lang);
    }
    $formatterManager->registerFormatter(
      new MybbStuff_MyAlerts_Formatter_QuestsystemQuestAcceptFormatter($mybb, $lang, 'questsystem_QuestAccept')
    );
  }

  //Info Quest als Partner eingetragen
  class MybbStuff_MyAlerts_Formatter_QuestsystemQuestPartnerFormatter extends MybbStuff_MyAlerts_Formatter_AbstractFormatter
  {
    /**
     * Build the output string for listing page and the popup.
     * @param MybbStuff_MyAlerts_Entity_Alert $alert The alert to format.
     * @return string The formatted alert string.
     */
    public function formatAlert(MybbStuff_MyAlerts_Entity_Alert $alert, array $outputAlert)
    {
      $alertContent = $alert->getExtraDetails();
      return $this->lang->sprintf(
        $this->lang->questsystem_QuestPartner,
        $outputAlert['name']
      );
    }
    /**
     * Initialize the language, we need the variables $l['myalerts_setting_alertname'] for user cp! 
     * and if need initialize other stuff
     * @return void
     */
    public function init()
    {
      if (!$this->lang->questsystem) {
        $this->lang->load('questsystem');
      }
    }
    /**
     * We want to define where we want to link to. 
     * @param MybbStuff_MyAlerts_Entity_Alert $alert for which alert.
     * @return string return the link.
     */
    public function buildShowLink(MybbStuff_MyAlerts_Entity_Alert $alert)
    {
      $alertContent = $alert->getExtraDetails();
      return $this->mybb->settings['bburl'] . '/misc.php?action=questsystem_progress';
    }
  }
  if (class_exists('MybbStuff_MyAlerts_AlertFormatterManager')) {
    $formatterManager = MybbStuff_MyAlerts_AlertFormatterManager::getInstance();
    if (!$formatterManager) {
      $formatterManager = MybbStuff_MyAlerts_AlertFormatterManager::createInstance($mybb, $lang);
    }
    $formatterManager->registerFormatter(
      new MybbStuff_MyAlerts_Formatter_QuestsystemQuestAcceptFormatter($mybb, $lang, 'questsystem_QuestPartner')
    );
  }
}

/**
 * Funktionen für die Installation
 */

function questsystem_add_db($type = "install")
{
  global $db;
  // Erstellen der Tabellen
  // Die Typen und ihre Einstellungen

  if (!$db->table_exists("questsystem_type")) {
    $db->query("CREATE TABLE " . TABLE_PREFIX . "questsystem_type (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `type` varchar(100) NOT NULL,
  `typedescr` varchar(500) NOT NULL DEFAULT '',
  `groups_allowed` varchar(100) NOT NULL,
  `enddays` INT(10) NOT NULL DEFAULT 0,
  `points_minus` int(10) NOT NULL DEFAULT 0,
  `points_add` int(10) NOT NULL DEFAULT 0,
  `admin_assignment` int(1) NOT NULL DEFAULT 0,
  `repeat` int(1) NOT NULL DEFAULT 1,
  `unique` int(1) NOT NULL DEFAULT 0,    
  `delete` int(1) NOT NULL DEFAULT 0,    
  `groupquest` int(1) NOT NULL DEFAULT 1,
  `group_str` varchar(500) NOT NULL DEFAULT 1,
  `group_fid` varchar(150) NOT NULL DEFAULT 1,
  `finish_typ` varchar(50) NOT NULL DEFAULT 'post',
  `user_add` int(1) NOT NULL DEFAULT 0,
  `active` int(1) NOT NULL DEFAULT 1,  
  PRIMARY KEY (`id`)
    ) ENGINE=MyISAM CHARACTER SET utf8 COLLATE utf8_general_ci;");
    //    `groups_questdepend` int(1) DEFAULT 0,
  }

  if (!$db->table_exists("questsystem_quest")) {
    $db->query("CREATE TABLE " . TABLE_PREFIX . "questsystem_quest (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `type` int(10) NOT NULL,
    `uid` int(10) NOT NULL,
    `name` varchar(100) NOT NULL,
    `questdescr` varchar(500) NOT NULL,
    `groupquest` int(1) NOT NULL DEFAULT 0,
    `uids` varchar(500) NOT NULL DEFAULT 0,
    `admincheck` int(1) NOT NULL DEFAULT 0,
    `in_progress` int(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`)
      ) ENGINE=MyISAM CHARACTER SET utf8 COLLATE utf8_general_ci;");
    //    `repeat_users` varchar(500) NOT NULL DEFAULT '', 
  }

  if (!$db->table_exists("questsystem_quest_user")) {
    $db->query("CREATE TABLE " . TABLE_PREFIX . "questsystem_quest_user (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `qid` int(11) NOT NULL DEFAULT 0,
  `qtid` int(11) NOT NULL DEFAULT 0,
  `uid` int(11) NOT NULL DEFAULT 0,
  `done` int(1) NOT NULL DEFAULT 0,
  `tid` int(1) NOT NULL DEFAULT 0,
  `pid` int(1) NOT NULL DEFAULT 0,
  `groups_uids` varchar(150) NOT NULL DEFAULT 0,
  `startdate` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
    ) ENGINE=MyISAM CHARACTER SET utf8 COLLATE utf8_general_ci;");
  }

  if (!$db->table_exists("questsystem_points")) {
    $db->query("CREATE TABLE " . TABLE_PREFIX . "questsystem_points (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `uid` int(11) NOT NULL DEFAULT 0,
    `points` int(11) NOT NULL DEFAULT 0,
    `reason` varchar(150)  DEFAULT '0',
    `date` date NOT NULL,
    `objectid` int(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`)
      ) ENGINE=MyISAM CHARACTER SET utf8 COLLATE utf8_general_ci;");
  }
  if (!$db->field_exists("objectid", "questsystem_points")) {
    $db->add_column("questsystem_points", "objectid", "int(11) NOT NULL DEFAULT 0");
  }
}

function questsystem_add_templates($type = "install")
{
  global $db;
  $templates = array();
  //add templates and stylesheets
  // Add templategroup
  if ($type == "install") {
    $templategrouparray = array(
      'prefix' => 'questsystem',
      'title'  => $db->escape_string('Questsystem'),
      'isdefault' => 1
    );
    $db->insert_query("templategroups", $templategrouparray);
  }

  $templates[] = array(
    "title" => 'questsystem_index_mod',
    "template" => '<div class="reservations_index pm_alert">
    {$questsystem_index_mod_bit}
    </div>',
    "sid" => "-2",
    "version" => "",
    "dateline" => TIME_NOW
  );

  $templates[] = array(
    "title" => 'questsystem_index_mod_bit',
    "template" => '{$markup}',
    "sid" => "-2",
    "version" => "",
    "dateline" => TIME_NOW
  );

  $templates[] = array(
    "title" => 'questsystem_misc_done',
    "template" => '<html>
    <head>
    <title>{$mybb->settings[\\\'bbname\\\']} - Questsystem</title>
    {$headerinclude}
    
    </head>
    <body>
    {$header}
      <table border="0" cellspacing="0" cellpadding="5" class="tborder borderboxstyle questsystem">
        <tr>
    <td class="trow2" colspan="5">
    <h1>Questsystem</h1>
      <div class="questshow-container">
          {$questsystem_nav}
        <div class="questshow__item questshow-main">		
          <div class="questshow-howto">
            <h2>ErledigteQuests</h2>
            Hier hast du eine Übersicht über die Quests, die du erledigt hast.
            <br/>
            <br />
          </div>
            {$questsystem_misc_quests_done}			
          <h2>Abgelaufene Quests</h2>
          Hier hast du eine Übersicht über die Quests,  die abgelaufen sind, ohne dass du sie abgeschlossen hast
                    <br/>
          <br />
        {$questsystem_misc_quests_expired}
        </div>
      </div>
    </td>
      </tr>
      </table>
    <br />
    {$footer}
    </body>
    </html>',
    "sid" => "-2",
    "version" => "",
    "dateline" => TIME_NOW
  );

  $templates[] = array(
    "title" => 'questsystem_misc_main',
    "template" => '<html>
      <head>
      <title>{$mybb->settings[\\\'bbname\\\']} - Questsystem</title>
      {$headerinclude}
      
      </head>
      <body>
      <link rel="stylesheet" href="{$mybb->asset_url}/jscripts/select2/select2.css?ver=1807">
      <script type="text/javascript" src="{$mybb->asset_url}/jscripts/select2/select2.min.js?ver=1806"></script>
      
      {$header}
        <table border="0" cellspacing="0" cellpadding="5" class="tborder borderboxstyle questsystem">
          <tr>
      <td class="trow2" colspan="5">
      <h1>Questsystem</h1>
        <div class="questshow-container">
            {$questsystem_nav}
          <div class="questshow__item questshow-main">
          
                    <div class="questshow-howto">
                      <h2>Questsystem</h2>
                    Willkommen im Questsystem, hier könnt ihr euch Aufgaben ziehen oder zuteilen lassen. Viel Spaß! 
            </div>
              {$questsystem_misc_questtypbit}
          </div>
        </div>
      </td>
	</tr>
        </table>
      <br /> 
      {$footer}
  </body>
  </html>',
    "sid" => "-2",
    "version" => "",
    "dateline" => TIME_NOW
  );

  $templates[] = array(
    "title" => 'questsystem_misc_progress',
    "template" => '<html>
    <head>
    <title>{$mybb->settings[\\\'bbname\\\']} - Questsystem</title>
    {$headerinclude}
    
    </head>
    <body>
    {$header}
      <table border="0" cellspacing="0" cellpadding="5" class="tborder borderboxstyle questsystem">
        <tr>
    <td class="trow2" colspan="5">
    <h1>Questsystem</h1>
      <div class="questshow-container">
          {$questsystem_nav}
        <div class="questshow__item questshow-main">
        
                  <div class="questshow-howto">
                    <h2>Deine aktuellen Quests</h2>
          Hier bekommst du eine Übersicht von den Quests, die du gezogen hast und welche noch nicht erledigt sind
          <br/>
          <br />
          </div>
            {$questsystem_misc_quests_progress}
        </div>
      </div>
    </td>
      </tr>
      </table>
    <br />
    {$footer}
    </body>
    </html>',
    "sid" => "-2",
    "version" => "",
    "dateline" => TIME_NOW
  );
  $templates[] = array(
    "title" => 'questsystem_misc_quests_done',
    "template" => '{$username_tit}
    <div class="questtypbit">
      <div class="questtypbit__item tborder">
        <h3>{$type[\\\'name\\\']}</h3>
        {$waiting}
        <div class="questdescr">{$questdata[\\\'questdescr\\\']}</div>
        <div class="questrules">
          {$expired}
          {$pointsadd}
          {$pointsminus}
          {$group}
          {$success}		
        </div>
      </div>
    </div>',
    "sid" => "-2",
    "version" => "",
    "dateline" => TIME_NOW
  );

  $templates[] = array(
    "title" => 'questsystem_misc_quests_progress',
    "template" => '{$username_tit}
    <div class="questtypbit">
    
      <div class="questtypbit__item">
        <h3>{$type[\\\'name\\\']}: {$questdata[\\\'name\\\']}</h3>
        {$delete_link}
        {$waiting} 
        <div class="questdescr">{$questdata[\\\'questdescr\\\']}</div>
        <div class="questrules">
          {$daysend}
          {$pointsadd}
          {$pointsminus}
          {$group}
          {$success}
          {$submitted}
        </div>
      </div>
    </div>',
    "sid" => "-2",
    "version" => "",
    "dateline" => TIME_NOW
  );

  $templates[] = array(
    "title" => 'questsystem_misc_questtypbit',
    "template" => '<div class="questtypbit">
    <h2>{$type[\\\'name\\\']}</h2>
    <div class="questtypbit__item">
      <div class="questdescr">{$type[\\\'typedescr\\\']}</div>
      <div class="questrules">
          <input type="hidden" value="{$type[\\\'id\\\']}" name="questid"/>
          {$daysend}
          {$pointsadd}
          {$pointsminus}
          {$success}
          {$admin}
          {$takequest}
      
      </div>
    </div>
    </div>',
    "sid" => "-2",
    "version" => "",
    "dateline" => TIME_NOW
  );
  $templates[] = array(
    "title" => 'questsystem_misc_submit',
    "template" => '<html>
    <head>
    <title>{$mybb->settings[\\\'bbname\\\']} - Questsystem</title>
    {$headerinclude}
    
    </head>
    <body>
    {$header}
      <table border="0" cellspacing="0" cellpadding="5" class="tborder borderboxstyle questsystem">
        <tr>
    <td class="trow2" colspan="5">
    <h1>Quest einreichen</h1>
      <div class="questshow-container">
          {$questsystem_nav}
      <div class="questshow__item questshow-main">			
        <div class="questshow-howto">									
        Hier kannst du ein Quest einreichen, damit es von einem Moderator freigeschaltet werden kann. <br/><br />
      </div>
        <form id="submitquestform" method="post" action="misc.php?action=questsystem_submit">
      <div class="submitquestform__item">
      <input type="text" id="quest_name" name="quest_name" placeholder="Name des Quests" value=""/ required>
      </div>
      <div class="submitquestform__item">
      {$select_typ_group}	
      </div>
      <div class="submitquestform__item">
      <textarea id="quest_dscr" name="quest_dscr" rows="4" cols="50" placeholder="Aussagekräftige Beschreibung" required></textarea>
      </div>
      <div class="submitquestform__item submitbutton">
            <input type="submit" id="submit_quest" name="submit_quest" value="Quest einreichen"/>
      </div>
        </form>
          
      </div>
      </div>
    </td>
      </tr>
      </table>
    <br />
    {$footer}
    </body>
    </html>',
    "sid" => "-2",
    "version" => "",
    "dateline" => TIME_NOW
  );

  $templates[] = array(
    "title" => 'questsystem_nav',
    "template" => '<div class="questshow__item questshow-nav">
    <div class="nav__item">
      <a href="misc.php?action=questsystem">Quest Start</a>
    </div>
    <div class="nav__item">
      <a href="misc.php?action=questsystem_progress">Deine aktuellen Quests</a>
    </div>
    <div class="nav__item">
      <a href="misc.php?action=questsystem_done">Deine erledigten Quests</a>
    </div>
      {$questsystem_add_quest}
    {$questsystem_nav_points}
    </div>',
    "sid" => "-2",
    "version" => "",
    "dateline" => TIME_NOW
  );

  $templates[] = array(
    "title" => 'questsystem_nav_addquest',
    "template" => '<div class="nav__item">
        <a href="misc.php?action=questsystem_submit">Quest einreichen</a>
      </div>',
    "sid" => "-2",
    "version" => "",
    "dateline" => TIME_NOW
  );

  $templates[] = array(
    "title" => 'questsystem_member_bit',
    "template" => '<span class="questsystem__points"><strong>{$punkte}</strong> » {$reason} » {$date}</span>
    ',
    "sid" => "-2",
    "version" => "",
    "dateline" => TIME_NOW
  );

  $templates[] = array(
    "title" => 'questsystem_member',
    "template" => '
    <div class="questsystem__profile">
    <h2>{$username}</h2>
      » hat insgesamt {$points_sum} gesammelt
        <div class="questsystem__profilecontainer ">
        {$questsystem_member_bit}
        </div>
      </div>
  ',
    "sid" => "-2",
    "version" => "",
    "dateline" => TIME_NOW
  );


  $templates[] = array(
    "title" => 'questsystem_form_grouprequest',
    "template" => '
        <span class="groupquest"><br/>Möchtest du ein Gruppenquest erledigen? <br/>
          Dann trage hier deinen Partner ein, sonst lasse das Feld leer. 
          <br/>
          <b>Achtung:</b> vor dem Eintragen, abklären ob der User einverstanden ist.<br/>
        
          <input type="text" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
          class="select2-input select2-default" id="complete_{$type[\\\'id\\\']}" tabindex="1" placeholder="" name="partners"><br/><br/>
        </span>
        <script type="text/javascript">
      <!--
      if(use_xmlhttprequest == "1")
      {
          MyBB.select2();
          $("#complete_{$type[\\\'id\\\']}").select2({
              placeholder: "{$lang->search_user}",
              minimumInputLength: 2,
              maximumSelectionSize: "",
              multiple: true,
              ajax: { // instead of writing the function to execute the request we use Select2s convenient helper
                  url: "xmlhttp.php?action=get_users",
                  dataType: "json",
                  data: function (term, page) {
                      return {
                          query: term, // search term
                      };
                  },
                  results: function (data, page) { // parse the results into the format expected by Select2.
                      // since we are using custom formatting functions we do not need to alter remote JSON data
                      return {results: data};
                  }
              },
              initSelection: function(element, callback) {
                  var query = $(element).val();
                  if (query !== "") {
                      var newqueries = [];
                      exp_queries = query.split(",");
                      $.each(exp_queries, function(index, value ){
                          if(value.replace(/\s/g, "") != "")
                          {
                              var newquery = {
                                  id: value.replace(/,\s?/g, ","),
                                  text: value.replace(/,\s?/g, ",")
                              };
                              newqueries.push(newquery);
                          }
                      });
                      callback(newqueries);
                  }
              }
          });
      }
      // -->
      </script> 
  ',
    "sid" => "-2",
    "version" => "",
    "dateline" => TIME_NOW
  );

  $templates[] = array(
    "title" => 'questsystem_form_takequest',
    "template" => '<form id="{$quest_type}" method="post" action="">
          <input type="hidden" name="id" value="{$type[\\\'id\\\']}">
          <input type="hidden" name="type" value="{$quest_type}"\>
          {$formgroup}
          <input type="submit" id="{$quest_type}" name="take_waiting" value="Quest anfordern"/>
          </form>
  ',
    "sid" => "-2",
    "version" => "",
    "dateline" => TIME_NOW
  );

  $templates[] = array(
    "title" => 'questsystem_form_takequest_random',
    "template" => '<form id="{$quest_type}" method="post" action="misc.php?action=questsystem&type={$quest_type}">
        <input type="hidden" value="{$type[\\\'id\\\']}" name="questid"/>
        {$formgroup}
        <input type="submit" id="{$quest_type}" name="take_random" value="Quest ziehen"/>
        </form>
  ',
    "sid" => "-2",
    "version" => "",
    "dateline" => TIME_NOW
  );

  $templates[] = array(
    "title" => 'questsystem_index_mod_bit_quest',
    "template" => '<div class="quest_index__item"><b>Questvorschlag</b><br/>
    <p class="quest_index_descr">{$quest_in[\\\'name\\\']}: 
    {$quest_in[\\\'questdescr\\\']}<br/>
    <a href="admin/index.php?module=rpgstuff-questsystem&action=questsystem_quest_manage">[hier freischalten]</a> 
    <a href="private.php?action=send&uid={$quest_in[\\\'uid\\\']}">[ Nachfragen(PN) ]</a> 
    <a onclick="$(\\\'#editquest{$quest_in[\\\'id\\\']}\\\').modal({ fadeDuration: 250, keepelement: true, zIndex: (typeof modal_zindex !== \\\'undefined\\\' ? modal_zindex : 9999) }); return false;" style="cursor: pointer;">[edit infos]</a>      </span>

    <div class="modal editquest" id="editquest{$quest_in[\\\'id\\\']}" style="display: none; padding: 10px; margin: auto; text-align: center;">
    <form action="" id="formeditscene" method="post" >
    <input type="hidden" value="{$quest_in[\\\'id\\\']}" name="id" id="id"/>
    <center>
    <input type="hidden" name="questtyp" id="questtyp" value="{$quest_in[\\\'type\\\']}" />
    <textarea name="questdescr" id="questdescr" placeholder="Beschreibung" style="height: 80px;"> {$quest_in[\\\'questdescr\\\']}</textarea><br>
          </form>
    <button name="edit_questin" id="editquestin">Submit</button>
  </div>
    </p>

    </div>',
    "sid" => "-2",
    "version" => "",
    "dateline" => TIME_NOW
  );

  $templates[] = array(
    "title" => 'questsystem_index_mod_bit_user',
    "template" => '<div class="quest_index__item">
    <b>Questzuteilung</b><br/>
    Mindestens ein User wartet auf Questzuteilung.<br/>
    <span class="quest_index_descr">
    <a href="admin/index.php?module=rpgstuff-questsystem&action=questsystem_quest_manage">[hier zuteilen]</a> 
    </span>
    </div>',
    "sid" => "-2",
    "version" => "",
    "dateline" => TIME_NOW
  );

  $templates[] = array(
    "title" => 'questsystem_index_mod_bit_submit',
    "template" => '<div class="quest_index__item">
    <span class="quest_index_descr"><b>Quest Einreichung:</b><br/>{$username} hat ein  <a href="showthread.php?tid={$quest_sub[\\\'tid\\\']}&pid={$quest_sub[\\\'pid\\\']}#pid{$quest_sub[\\\'pid\\\']}">Quest eingereicht.</a><br/>
    -> {$questinfo[\\\'finish_typ\\\']} ist relevant.<br/><br/>
    Quest: <i>{$questdetail[\\\'questdescr\\\']} </i><br/>
    <a href="index.php?action=questaccept&id={$quest_sub[\\\'id\\\']}&qtid={$quest_sub[\\\'qtid\\\']}&qid={$quest_sub[\\\'qid\\\']}&uid={$quest_sub[\\\'uid\\\']}">[accept]</a> 
    <a href="index.php?action=questdeny&id={$quest_sub[\\\'id\\\']}&qtid={$quest_sub[\\\'qtid\\\']}&qid={$quest_sub[\\\'qid\\\']}&uid={$quest_sub[\\\'uid\\\']}">[deny]</a> 
    </div>',
    "sid" => "-2",
    "version" => "",
    "dateline" => TIME_NOW
  );

  $templates[] = array(
    "title" => 'questsystem_nav_points',
    "template" => '<div class="nav__item">
        <a href="misc.php?action=questsystem_points">Punkte Übersicht</a>
    </div>',
    "sid" => "-2",
    "version" => "",
    "dateline" => TIME_NOW
  );

  $templates[] = array(
    "title" => 'questsystem_misc_overview',
    "template" => '<html>
<head>
  <title>{$mybb->settings[\\\'bbname\\\']} - Questsystem</title>
  {$headerinclude}

</head>
<body>
  {$header}
  <table border="0" cellspacing="0" cellpadding="5" class="tborder borderboxstyle questsystem">
    <tr>
      <td class="trow2" colspan="5">
        <h1>Questsystem</h1>
        <div class="questshow-container">
          {$questsystem_nav}
          <div class="questshow__item questshow-main">
            <div class="questshow-overview">
              <h2>Übersicht</h2>
              Hier findest du eine Übersicht.
              <div class="questshow-overview__container">
              {$questsystem_misc_overviewquests}
              {$questsystem_misc_overviewpoints}
              </div>
            </div>
          </div>
        </div>
      </td>
    		</tr>
  </table>
  <br />
  {$footer}
</body>
</html>',
    "sid" => "-2",
    "version" => "",
    "dateline" => TIME_NOW
  );

  $templates[] = array(
    "title" => 'questsystem_misc_overviewquests',
    "template" => '
    <h3>Übersicht Quests</h3>
    {$questsystem_misc_overviewquestsbit}
  ',
    "sid" => "-2",
    "version" => "",
    "dateline" => TIME_NOW
  );

  $templates[] = array(
    "title" => 'questsystem_misc_overviewquestsbit',
    "template" => '
    <div class="questsystem-overview__bit tborder">
      <div class="questsystem-overview__user">{$username}</div>
      <div class="questsystem-overview__quests">{$quests}</div>
    </div>
  ',
    "sid" => "-2",
    "version" => "",
    "dateline" => TIME_NOW
  );

  $templates[] = array(
    "title" => 'questsystem_misc_overviewpoints',
    "template" => '
    <h3>Übersicht Punkte</h3>
    {$questsystem_misc_overviewpoints_bit}
  ',
    "sid" => "-2",
    "version" => "",
    "dateline" => TIME_NOW
  );

  $templates[] = array(
    "title" => 'questsystem_misc_overviewpoints_bit',
    "template" => '
    <div class="questsystem-overview__bit tborder">
      <div class="questsystem-overview__user">{$username}<br>
      Gesamtpunkte: {$quests_points_sum}</div>
      <div class="questsystem-overview__quests">{$points_reasons}</div>
    </div>
  ',
    "sid" => "-2",
    "version" => "",
    "dateline" => TIME_NOW
  );

  if ($type == 'update') {
    foreach ($templates as $template) {
      $query = $db->simple_select("templates", "tid, template", "title = '" . $template['title'] . "' AND sid = '-2'");
      $existing_template = $db->fetch_array($query);

      if ($existing_template) {
        if ($existing_template['template'] !== $template['template']) {
          $db->update_query("templates", array(
            'template' => $template['template'],
            'dateline' => TIME_NOW
          ), "tid = '" . $existing_template['tid'] . "'");
        }
      } else {
        $db->insert_query("templates", $template);
      }
    }
  } else {
    foreach ($templates as $template) {
      $check = $db->num_rows($db->simple_select("templates", "title", "title = '" . $template['title'] . "'"));
      if ($check == 0) {
        $db->insert_query("templates", $template);
      }
    }
  }
}

function questsystem_add_settings($type = "install")
{
  global $db;
  if ($type == "install") {  //Einstellungen 
    $setting_group = array(
      'name' => 'questsystem',
      'title' => "Questsystem",
      'description' => "Alle Einstellungen für das Questsystem. ",
      'disporder' => 1,
      'isdefault' => 0
    );
    $gid = $db->insert_query("settinggroups", $setting_group);
  } else {
    $gid = $db->fetch_field($db->write_query("SELECT gid FROM `" . TABLE_PREFIX . "settinggroups` WHERE name like 'questsystem%' LIMIT 1;"), "gid");
  }

  $setting_array = array(
    'questsystem_activ' => array(
      'title' => "Aufgaben einreichen?",
      'description' => "Dürfen Mitglieder Aufgaben einreichen?",
      'optionscode' => 'yesno',
      'value' => '1', // Default
      'disporder' => 1
    ),
    'questsystem_groups' => array(
      'title' => "Gruppen?",
      'description' => "Welche Gruppen dürfen Aufgaben einreichen?",
      'optionscode' => 'text',
      'value' => '4,5,6', // Default
      'disporder' => 2
    ),
    'questsystem_points_generell' => array(
      'title' => 'Punkte Allgemein',
      'description' => 'Sollen für die Quests Punkte vergeben werden?',
      'optionscode' => 'yesno',
      'value' => 'yes', // Default
      'disporder' => 3
    ),
    'questsystem_points' => array(
      'title' => 'Punkte für einreichen eines Quests?',
      'description' => 'Soll es (Haus-)Punkte geben wenn ein Quest eingereicht wird?',
      'optionscode' => 'text',
      'value' => '10', // Default
      'disporder' => 4
    ),
    'questsystem_overview_overall' => array(
      'title' => 'Übersicht Quests?',
      'description' => 'Soll es eine Übersichtsseite geben?',
      'optionscode' => 'yesno',
      'value' => '1', // Default
      'disporder' => 5
    ),
    'questsystem_overview_quest' => array(
      'title' => 'Übersicht Quests - Quest?',
      'description' => 'Soll auf der Übersicht angezeigt werden, wer welches Quest macht?',
      'optionscode' => 'yesno',
      'value' => '1', // Default
      'disporder' => 6
    ),
    'questsystem_overview_points' => array(
      'title' => 'Übersicht Quests - Punkte?',
      'description' => 'Soll auf der Übersichtsseite angezeigt werden, wer wie viele Punkte hat?',
      'optionscode' => 'yesno',
      'value' => '1', // Default
      'disporder' => 7
    ),
  );

  if ($type == 'install') {
    foreach ($setting_array as $name => $setting) {
      $setting['name'] = $name;
      $setting['gid'] = $gid;
      $db->insert_query('settings', $setting);
    }
  }

  if ($type == 'update') {
    foreach ($setting_array as $name => $setting) {
      $setting['name'] = $name;
      $check = $db->write_query("SELECT name FROM `" . TABLE_PREFIX . "settings` WHERE name = '{$name}'");
      $data = $db->write_query("SELECT name FROM `" . TABLE_PREFIX . "settings` WHERE name = '{$name}'");
      $check = $db->num_rows($check);

      $setting['gid'] = $gid;
      if ($check == 0) {
        $db->insert_query('settings', $setting);
      } else {
        //die einstellung gibt es schon, wir testen ob etwas verändert wurde
        while ($setting_old = $db->fetch_array($data)) {
          if (
            $setting_old['title'] != $setting['title'] ||
            $setting_old['description'] != $setting['description'] ||
            $setting_old['optionscode'] != $setting['optionscode'] ||
            $setting_old['value'] != $setting['value'] ||
            $setting_old['disporder'] != $setting['disporder']
          ) {
            $db->update_query('settings', $setting, "name='{$name}'");
          }
        }
      }
    }
  }

  rebuild_settings();
}

function questsystem_stylesheet()
{
  global $db;
  $css = array(
    'name' => 'questsystem.css',
    'tid' => 1,
    'attachedto' => '',
    "stylesheet" =>    '
  .questrules {
    display: flex;
    flex-wrap: wrap;
  }
  
  .questrules span {
    display: inline-block;
    width: 100%;
    padding-left: 10px;}
  
  .questtypbit {
    margin: 10px 0px;
    margin-bottom: 30px;
    padding: 10px;
    padding-top: 0px;
    border: 5px solid var(--tabel-color);
  }
  
  .questshow-container {
    gap: 12px;
    display: grid;
    grid-template-columns: 1fr 5fr;
  }
  
  .nav__item {
    padding: 5px;
    background-color: var(--tabel-color);
    margin: 2px;
    text-align: center;
  }
  
  .questshow__item.questshow-nav {
    padding-top: 5px;
    padding-left: 5px;
  }
  
  .questsystem h1 {
    text-align: center;
  }
  
  #submitquestform {
      display: flex; 
      flex-wrap: wrap;
      width: 100%;
      justify-content: center;
  
  }
  
  .submitquestform__item {
      width:100%;
      text-align: center;
  }
  .submitquestform__item input,
  .submitquestform__item select,
  .submitquestform__item textarea {
      width:50%;
  }
    #submit_quest {
      margin: 10px 0px;
      width:100px;
  }

  .questsystem-overview__bit {
  display: flex;
  gap: 20px;
  padding: 10px;
  width: auto;
  }

  .questsystem-overview__user {
      width: 15%;
  }
  ',
    'cachefile' => $db->escape_string(str_replace('/', '', 'questsystem.css')),
    'lastmodified' => time()
  );

  return $css;
}

function questsystem_stylesheet_update()
{
  $update_array_all = array();
  $update_array = array();

  // $update_array_all[] =
  //   array(
  //     'stylesheet' => "
  //       /* update_string_value - kommentar nicht entfernen */
  //       ",
  //     'update_string' => 'update_string_value'
  //   );
  return $update_array_all;
}

$plugins->add_hook('admin_rpgstuff_update_plugin', "questsystem_admin_update_plugin");
function questsystem_admin_update_plugin(&$table)
{
  global $db, $mybb, $lang;

  $lang->load('rpgstuff_plugin_updates');
  if ($mybb->input['action'] == 'add_update' and $mybb->get_input('plugin') == "questsystem") {
    questsystem_add_db("update");
    questsystem_add_settings("update");
    rebuild_settings();
    questsystem_add_templates("templates");

    //Brauchen wir momentan noch nicht, es gibt noch kein Update :) 

    $update_data_all = questsystem_stylesheet_update();
    foreach ($update_data_all as $update_data) {
      $update_stylesheet = $update_data['stylesheet'];
      $update_string = $update_data['update_string'];
      if (!empty($update_string)) {
        // Ob im Master Style die Überprüfung vorhanden ist
        $masterstylesheet = $db->fetch_field($db->query("SELECT stylesheet FROM " . TABLE_PREFIX . "themestylesheets WHERE tid = 1 AND name = 'lexicon.css'"), "stylesheet");
        $pos = strpos($masterstylesheet, $update_string);
        if ($pos === false) { // nicht vorhanden 
          $theme_query = $db->simple_select('themes', 'tid, name');
          while ($theme = $db->fetch_array($theme_query)) {
            $stylesheet_query = $db->simple_select("themestylesheets", "*", "name='" . $db->escape_string('questsystem.css') . "' AND tid = " . $theme['tid']);
            $stylesheet = $db->fetch_array($stylesheet_query);
            if ($stylesheet) {
              require_once MYBB_ADMIN_DIR . "inc/functions_themes.php";
              $sid = $stylesheet['sid'];

              $updated_stylesheet = array(
                "cachefile" => $db->escape_string($stylesheet['name']),
                "stylesheet" => $db->escape_string($stylesheet['stylesheet'] . "\n\n" . $update_stylesheet),
                "lastmodified" => TIME_NOW
              );

              $db->update_query("themestylesheets", $updated_stylesheet, "sid='" . $sid . "'");

              if (!cache_stylesheet($theme['tid'], $stylesheet['name'], $updated_stylesheet['stylesheet'])) {
                $db->update_query("themestylesheets", array('cachefile' => "css.php?stylesheet=" . $sid), "sid='" . $sid . "'", 1);
              }

              update_theme_stylesheet_list($theme['tid']);
            }
          }
        }
      }
    }
  }
  // Zelle mit dem Namen des Themes
  $table->construct_cell("<b>" . htmlspecialchars_uni("Questsystem") . "</b>", array('width' => '70%'));
  // Überprüfen, ob Update erledigt
  $update_check = questsystem_is_updated();
  if ($update_check) {
    $table->construct_cell($lang->plugins_actual, array('class' => 'align_center'));
  } else {
    $table->construct_cell("<a href=\"index.php?module=rpgstuff-plugin_updates&action=add_update&plugin=questsystem\">" . $lang->plugins_update . "</a>", array('class' => 'align_center'));
  }

  $table->construct_row();
}

// Stylesheet zum Master Style hinzufügen
$plugins->add_hook('admin_rpgstuff_update_stylesheet', "questsystem_admin_update_stylesheet");
function questsystem_admin_update_stylesheet(&$table)
{

  global $db, $mybb, $lang;
  $lang->load('rpgstuff_stylesheet_updates');

  require_once MYBB_ADMIN_DIR . "inc/functions_themes.php";

  // HINZUFÜGEN
  if ($mybb->input['action'] == 'add_master' and $mybb->get_input('plugin') == "questsystem") {

    $css = questsystem_stylesheet();

    $sid = $db->insert_query("themestylesheets", $css);
    $db->update_query("themestylesheets", array("cachefile" => "questsystem.css"), "sid = '" . $sid . "'", 1);

    $tids = $db->simple_select("themes", "tid");
    while ($theme = $db->fetch_array($tids)) {
      update_theme_stylesheet_list($theme['tid']);
    }

    flash_message($lang->stylesheets_flash, "success");
    admin_redirect("index.php?module=rpgstuff-stylesheet_updates");
  }

  // Zelle mit dem Namen des Themes
  $table->construct_cell("<b>" . htmlspecialchars_uni("Questsystem-Manager") . "</b>", array('width' => '70%'));

  // Ob im Master Style vorhanden
  $master_check = $db->fetch_field($db->query("SELECT tid FROM " . TABLE_PREFIX . "themestylesheets 
  WHERE name = 'questsystem.css' 
  AND tid = 1
  "), "tid");

  if (!empty($master_check)) {
    $masterstyle = true;
  } else {
    $masterstyle = false;
  }

  if (!empty($masterstyle)) {
    $table->construct_cell($lang->stylesheets_masterstyle, array('class' => 'align_center'));
  } else {
    $table->construct_cell("<a href=\"index.php?module=rpgstuff-stylesheet_updates&action=add_master&plugin=questsystem\">" . $lang->stylesheets_add . "</a>", array('class' => 'align_center'));
  }
  $table->construct_row();
}

/***
 * checkt ob das Plugin auf der aktuellen Version ist
 */
function questsystem_is_updated()
{
  global $db;
  $check = $db->write_query("SELECT name FROM `" . TABLE_PREFIX . "settings` WHERE name = 'questsystem_overview_overall'");

  if (!$db->num_rows($check)) {
    return false;
  }
  if (!$db->field_exists("objectid", "questsystem_points")) {
    return false;
  }
  return true;
}
