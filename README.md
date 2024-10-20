# Questsystem
Questsystem für RPGs.

- Questtypen anlegen (Beschränkung für einzelne Gruppen, auch über Profilfeld/Steckbrieffeld)
  - Einstellung ob Quests ablaufen
  - Punkte für erledigte Quests (wenn gewünscht)
  - Abzug wenn Quest nicht abgeschlossen wird (wenn gewünscht)
  - Questzuteilung über Admin oder zufällig
  - Gleichzeitiges bearbeiten? Darf das Quest nur von einem User gleichzeitig bearbeitet werden?
  - Gruppenquest? Dürfen Quests als Gruppenquest eingereicht werden?
  - Questeinreichung? Dürfen User Quests einreichen?
  - Abschluss als Post oder gesamte Szene
- Quests erstellen
- Quests einreichen (Gruppenquests oder Einzelquests)
- Optional: Punkte für Quests sammeln
- Quests erledigen und einreichen

**Alle wichtigen Infos findest du im Wiki**   
[Zum Wiki]([https://github.com/little-evil-genius/rpgstuff_modul](https://github.com/katjalennartz/questsystem/wiki))  


## good to know
### Links
Verwaltung ACP: admin/index.php?module=config-questsystem  
Übersicht: misc.php?action=questsystem

### Variablen  
- Template: index" hinter {$header}: {$questsystem_index_mod}
- Template: postbit vor {$post['button_edit']}: {$post['questbutton']}
- Template: member_profile hinter {$awaybit}: {$questsystem_member} 

### Templates
- questsystem_index_mod
- questsystem_index_mod_bit
- questsystem_misc_done
- questsystem_misc_main
- questsystem_misc_progress
- questsystem_misc_quests_done
- questsystem_misc_quests_progress
- questsystem_misc_questtypbit
- questsystem_misc_submit
- questsystem_nav
- questsystem_member_bit
- questsystem_member
- questsystem_form_grouprequest
- questsystem_form_takequest
- questsystem_form_takequest_random
- questsystem_index_mod_bit_quest
- questsystem_index_mod_bit_user
- questsystem_index_mod_bit_submit

