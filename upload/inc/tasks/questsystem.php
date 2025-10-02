<?php

/**
 * Questsystem for MyBB 1.8
 * Automatische Bereinigung der abgelaufenen Quests
 *
 */
// error_reporting(-1);
// ini_set('display_errors', true);


/***
 * all the magic 
 * 
 */
function task_questsystem($task)
{
  global $db, $mybb, $lang;

  $get_types = $db->simple_select("questsystem_type", "*", "active = 1 and enddays != 0");
  while ($type = $db->fetch_array($get_types)) {
    $get_quests = $db->simple_select("questsystem_quest_user", "*", "qtid = {$type['id']} and done = 0 AND and DATE_ADD(startdate, INTERVAL {$type['enddays']} DAY) < CURDATE()");
    while ($entry = $db->fetch_array($get_quests)) {
      $insert = array(
        "uid" => $entry['uid'],
        "points" => "-" . $type['points_minus'],
        "reason" => "Punktabzug für nicht erledigtes Quest.",
        "date" => date("Y-m-d"),
      );
      $db->insert_query("questsystem_points", $insert);

      $update = array(
        "in_progress" => 0,
      );
      $db->update_query("questsystem_quest", $update, "id='{$entry['qid']}'");

      $db->delete_query("questsystem_quest_user", "id='{$entry['id']}'");
      add_task_log($task, "Questsystem bereinigt. uid: {$entry['uid']} questid: {$entry['qid']}");
    }
  }
}

// MyBB SQL Error - [20] array ( 'error_no' => 1146, 'error' => 'Table \'d0391dab.mybb_questsystem_questtype\' doesn\'t exist', 'query' => 'SELECT * FROM mybb_questsystem_questtype WHERE active = 1 and enddays != 0', )
