<?php

// Namespace correcte (basat en la carpeta)
namespace Drupal\tauler\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Url; 

class TaulerController extends ControllerBase {

  protected $database;
  protected $currentUser;
  const MAX_INITIAL_MUNICIPALITIES_DISPLAY = 3;

  public function __construct(Connection $database, AccountInterface $current_user) {
	$this->database    = $database;
	$this->currentUser = $current_user;
  }

  public static function create(ContainerInterface $container) {
	return new static(
	  $container->get('database'),
	  $container->get('current_user')
	);
  }

public function showDashboard() {
	  $stats                         = [];
	  $chart_data                    = [];
	  $has_data                      = FALSE;
	  $total_user_municipalities_from_profile = 0; // Recompte dels municipis del perfil
	  $displayable_user_municipalities_names = []; // Llista de noms de municipi correctes, obtinguts dels equipaments
	  
	  $uid     = $this->currentUser->id();
	  $account = User::load($uid);
	  $is_admin = $this->currentUser->hasRole('administrator');
	  
	  $user_municipalities_normalized_for_db = []; // Per a la lògica de BD interna
	  $temp_municipis_from_profile           = []; // Noms del perfil d'usuari
	  
	  if (!$is_admin) {
		  if ($account && $account->hasField('field_municipi_user') && !$account->get('field_municipi_user')->isEmpty()) {
			foreach ($account->get('field_municipi_user')->getValue() as $valor) {
			  $m_profile_original = str_replace('_', ' ', $this->safeTrim($valor['value'] ?? ''));
			  if ($m_profile_original !== '') {
				$temp_municipis_from_profile[] = $m_profile_original;
				$user_municipalities_normalized_for_db[] = $this->normalitzaText($m_profile_original);
			  }
			}
			$temp_municipis_from_profile = array_unique($temp_municipis_from_profile);
			$total_user_municipalities_from_profile  = count($temp_municipis_from_profile);
			$user_municipalities_normalized_for_db = array_unique($user_municipalities_normalized_for_db);
		  
		  } else {
			$build = [
			  '#markup'   => $this->t('No tens cap municipi assignat al teu perfil. No es poden mostrar estadístiques.'),
			  '#attached' => $this->getBasicAttached(),
			];
			$build['#cache']['contexts'][] = 'user'; 
			return $build;
		  }
		  
		  if (empty($user_municipalities_normalized_for_db)) {
			$build = [
			  '#markup'   => $this->t('No s\'han pogut determinar els teus municipis assignats (normalitzats) o la llista està buida.'),
			  '#attached' => $this->getBasicAttached(),
			];
			$build['#cache']['contexts'][] = 'user'; 
			return $build;
		  }
	  }
	  
	  $equipaments_user_codis = [];
	  $equipaments_info_user = [];
	  $correct_municipality_names_from_equipments = []; 
	  
	  try {
		$query_equip = $this->database->select('nou_formulari_equipaments', 'e')
		  ->fields('e', ['codi_equipament', 'nom_equipament', 'municipi', 'espai_principal']);
		foreach ($query_equip->execute()->fetchAll() as $row) {
		  $codi_equip_actual = $this->safeTrim($row->codi_equipament);
		  $municipi_original_equipament = $this->safeTrim($row->municipi ?? 'Desconegut'); 
		  $info_equip = [
			  'nom' => $this->safeTrim($row->nom_equipament ?? 'Desconegut'),
			  'municipi' => $municipi_original_equipament,
			  'espai_principal' => $this->safeTrim($row->espai_principal ?? 'Desconegut'),
		  ];
  
		  if ($is_admin) {
			  $equipaments_user_codis[$codi_equip_actual] = TRUE;
			  $equipaments_info_user[$codi_equip_actual] = $info_equip;
			  if ($municipi_original_equipament !== 'Desconegut' && $municipi_original_equipament !== '') {
				  $correct_municipality_names_from_equipments[$municipi_original_equipament] = TRUE;
			  }
		  } else {
			  $mun_norm_equipament = $this->normalitzaText($municipi_original_equipament);
			  if (in_array($mun_norm_equipament, $user_municipalities_normalized_for_db, TRUE)) {
				  $equipaments_user_codis[$codi_equip_actual] = TRUE;
				  $equipaments_info_user[$codi_equip_actual] = $info_equip;
				  if ($municipi_original_equipament !== 'Desconegut' && $municipi_original_equipament !== '') {
					  $correct_municipality_names_from_equipments[$municipi_original_equipament] = TRUE;
				  }
			  }
		  }
		}
		$displayable_user_municipalities_names = array_keys($correct_municipality_names_from_equipments);
		sort($displayable_user_municipalities_names, SORT_LOCALE_STRING);
	  
	  }
	  catch (\Exception $e) { $this->getLogger('tauler')->error('Error carregant equipaments usuari: @msg', ['@msg' => $e->getMessage()]); }
	  
	  $total_user_municipalities_for_stats = count($displayable_user_municipalities_names);
	  
	  try {
		$stats['total_equipaments_global'] = (int) $this->database->select('nou_formulari_equipaments', 'e')->countQuery()->execute()->fetchField();
		$stats['equipaments_autoavaluats_global'] = (int) $this->database->select('nou_formulari_dades_formulari', 't')->distinct()->fields('t', ['codi_equipament'])->countQuery()->execute()->fetchField();
	  } catch (\Exception $e) { $this->getLogger('tauler')->error('Error obtenint totals globals: @msg', ['@msg' => $e->getMessage()]); }
	  $stats['total_equipaments_user'] = count($equipaments_user_codis);
	  
	  $all_submissions_for_user_equipments = []; 
	  if (!empty($equipaments_user_codis)) {
		$query_form_data = $this->database->select('nou_formulari_dades_formulari', 't');
		$query_form_data->fields('t'); 
		$query_form_data->condition('codi_equipament', array_keys($equipaments_user_codis), 'IN');
		$all_submissions_for_user_equipments = $query_form_data->execute()->fetchAll();
	  }
	  
	  $question_columns = $this->getQuestionColumns();
	  $results_db = []; 
	  foreach ($all_submissions_for_user_equipments as $submission_candidate) {
		  $row_array_candidate = (array) $submission_candidate;
		  $has_valid_answer = FALSE;
		  foreach ($question_columns as $col_name) {
			  $valor_pregunta = $row_array_candidate[$col_name] ?? '';
			  if (in_array(strtolower($this->safeTrim($valor_pregunta)), ['sí', 'no'])) {
				  $has_valid_answer = TRUE;
				  break; 
			  }
		  }
		  if ($has_valid_answer) { $results_db[] = $submission_candidate; }
	  }
	  
	  $accions_millora_data = [];
	  $form_questions_definitions = $this->getGroupsDefinition();
	  
	  if (!empty($results_db)) {
		$has_data = TRUE;
		$stats['total_submissions'] = count($results_db); 
		$stats['percent_equip_autoavaluats'] = ($stats['total_equipaments_user'] > 0) ? round(($stats['total_submissions'] / $stats['total_equipaments_user']) * 100) : 0;
	  
		$total_questions  = count($question_columns);
		$pilars_si_no_pma = ['Participació' => ['si' => 0, 'no' => 0, 'pma' => 0],'Accessibilitat' => ['si' => 0, 'no' => 0, 'pma' => 0],'Igualtat' => ['si' => 0, 'no' => 0, 'pma' => 0],'Sostenibilitat' => ['si' => 0, 'no' => 0, 'pma' => 0],];
		$global_si = 0; $global_no = 0;
		$question_no_counts = array_fill_keys($question_columns, 0);
		$question_si_counts = array_fill_keys($question_columns, 0);
		$equipaments_all_yes = [];
		$equipaments_implementing_plan = [];
		$municipalities_with_active_plan = [];
  
		foreach ($results_db as $row_data) {
		  $row_array = (array) $row_data; $current_row_si = 0; $has_subitem_row = FALSE; $has_date_row = FALSE;
		  $codi_equipament_actual = $this->safeTrim($row_array['codi_equipament'] ?? '');
		  $nom_equipament_actual = $equipaments_info_user[$codi_equipament_actual]['nom'] ?? 'N/D';
		  $municipi_actual_equip = $equipaments_info_user[$codi_equipament_actual]['municipi'] ?? 'N/D';
		  $espai_principal_actual = $equipaments_info_user[$codi_equipament_actual]['espai_principal'] ?? 'N/D';
		  $url_formulari = Url::fromRoute('nou_formulari.form', [], ['query' => ['equipament_seleccionat' => $codi_equipament_actual]])->toString();
  
		  foreach ($question_columns as $col) {
			$pilar_name = $this->getPilarFromColumn($col); if (!$pilar_name) continue;
			$valor = $row_array[$col] ?? '';
			if ($this->isRespostaSi($valor)) { 
			  $pilars_si_no_pma[$pilar_name]['si']++; $global_si++; $current_row_si++;
			  $question_si_counts[$col]++;
			} else { 
			  $global_no++; $question_no_counts[$col]++;
			  $pma_per_pregunta = FALSE;
			  foreach ($form_questions_definitions as $group_data_def) {
				  foreach($group_data_def['questions'] as $q_key_def => $q_info_def) {
					  $current_question_col_name_check = $group_data_def['prefix_col'] . '_' . $q_key_def; if ($current_question_col_name_check !== $col) continue;
					  if (!empty($q_info_def['subitems'])) { foreach (array_keys($q_info_def['subitems']) as $sub_key_def) { $chk_field_name = $current_question_col_name_check . '_subitem_' . $sub_key_def; if (isset($row_array[$chk_field_name]) && $row_array[$chk_field_name] == 1) { $pma_per_pregunta = TRUE; break 3; }}}
					  $custom_plan_field_name = $current_question_col_name_check . '_custom_plan'; if (isset($row_array[$custom_plan_field_name]) && !empty(trim($row_array[$custom_plan_field_name]))) { $pma_per_pregunta = TRUE; break 2; }
				  }
			  }
			  if ($pma_per_pregunta) { $pilars_si_no_pma[$pilar_name]['pma']++; } else { $pilars_si_no_pma[$pilar_name]['no']++; }
			}
		  }
  
		  if ($current_row_si === $total_questions) { $equipaments_all_yes[$codi_equipament_actual] = TRUE; }
		  
		  foreach ($form_questions_definitions as $group_data) {
			  $pilar_actual_def_text = (string) $group_data['title']; $prefix_col_actual_def = $group_data['prefix_col'];
			  foreach ($group_data['questions'] as $question_key => $question_info) {
				  $pregunta_col_base = $prefix_col_actual_def . '_' . $question_key;
				  if (!empty($question_info['subitems'])) {
					  foreach ($question_info['subitems'] as $sub_key => $sub_label_obj) {
						  $sub_label_text = (string) $sub_label_obj; $chk_field = $pregunta_col_base . '_subitem_' . $sub_key; $date_field = $chk_field . '_date';
						  if (isset($row_array[$chk_field]) && $row_array[$chk_field] == 1) {
							  $has_subitem_row = TRUE;
							  $any_compromis = $this->safeTrim($row_array[$date_field] ?? '');
							  if(!empty($any_compromis)) $has_date_row = TRUE;
							  $accions_millora_data[] = [
								  'codi_equipament' => $codi_equipament_actual,
								  'nom_equipament' => $nom_equipament_actual,
								  'municipi' => $municipi_actual_equip,
								  'espai_principal' => $espai_principal_actual,
								  'pilar' => $pilar_actual_def_text,
								  'pregunta_key' => $question_key,
								  'accio_id_curt' => $prefix_col_actual_def . '_' . $sub_key,
								  'accio_text' => $sub_label_text,
								  'any_compromis' => $any_compromis,
								  'url_formulari' => $url_formulari,
							  ];
						  }
					  }
				  }
				  $custom_plan_field = $pregunta_col_base . '_custom_plan'; $custom_date_field = $custom_plan_field . '_date';
				  if (isset($row_array[$custom_plan_field]) && trim($row_array[$custom_plan_field]) !== '') {
					  $has_subitem_row = TRUE;
					  $any_compromis_custom = $this->safeTrim($row_array[$custom_date_field] ?? '');
					  if(!empty($any_compromis_custom)) $has_date_row = TRUE;
					  $accions_millora_data[] = [
						  'codi_equipament' => $codi_equipament_actual,
						  'nom_equipament' => $nom_equipament_actual,
						  'municipi' => $municipi_actual_equip,
						  'espai_principal' => $espai_principal_actual,
						  'pilar' => $pilar_actual_def_text,
						  'pregunta_key' => $question_key,
						  'accio_id_curt' => $prefix_col_actual_def . '_' . $question_key . '_custom',
						  'accio_text' => $this->safeTrim($row_array[$custom_plan_field]),
						  'any_compromis' => $any_compromis_custom,
						  'url_formulari' => $url_formulari,
					  ];
				  }
			  }
		  }
		  if ($has_subitem_row) { $equipaments_implementing_plan[$codi_equipament_actual] = TRUE; }
		  if ($has_date_row && $municipi_actual_equip !== 'N/D') { $municipalities_with_active_plan[$municipi_actual_equip] = TRUE; }
		} 
	  
		$stats['equipaments_all_yes'] = count($equipaments_all_yes); $stats['equipaments_implementing_plan']= count($equipaments_implementing_plan);
		$stats['municipalities_with_active_plan_count'] = count($municipalities_with_active_plan);
		$stats['percent_municipis_pla_actiu'] = ($total_user_municipalities_for_stats > 0) ? round(($stats['municipalities_with_active_plan_count'] / $total_user_municipalities_for_stats) * 100) : 0;
	  
		$pilars_si_no_pma_percent_data = [
		  'labels' => array_keys($pilars_si_no_pma),
		  'dataSiPercent' => [], 'dataPmaPercent' => [], 'dataNoPercent' => [],
		];
		foreach ($pilars_si_no_pma as $pilar_nom => $dades_pilar) {
		  $total_pilar_respostes = $dades_pilar['si'] + $dades_pilar['pma'] + $dades_pilar['no'];
		  $pilars_si_no_pma_percent_data['dataSiPercent'][] = ($total_pilar_respostes > 0) ? round(($dades_pilar['si'] / $total_pilar_respostes) * 100, 1) : 0;
		  $pilars_si_no_pma_percent_data['dataPmaPercent'][] = ($total_pilar_respostes > 0) ? round(($dades_pilar['pma'] / $total_pilar_respostes) * 100, 1) : 0;
		  $pilars_si_no_pma_percent_data['dataNoPercent'][] = ($total_pilar_respostes > 0) ? round(($dades_pilar['no'] / $total_pilar_respostes) * 100, 1) : 0;
		}
		$chart_data['pilarsSiNoPmaPercent'] = $pilars_si_no_pma_percent_data;
		
		arsort($question_si_counts);
		$top_si_questions_db_keys = array_slice(array_filter($question_si_counts, fn($c) => $c > 0), 0, 10, TRUE);
		$labels_mes_si_curtes = []; $labels_mes_si_completes = [];
		foreach(array_keys($top_si_questions_db_keys) as $db_key_q_si) {
			[$pilar_part, $pregunta_part] = explode('_', $db_key_q_si, 2);
			$label_q_si_completa = (string) ($form_questions_definitions[$pilar_part]['questions'][$pregunta_part]['label'] ?? $db_key_q_si);
			$labels_mes_si_completes[] = $label_q_si_completa;
			$labels_mes_si_curtes[] = mb_strlen($label_q_si_completa) > 40 ? mb_substr($label_q_si_completa, 0, 37) . '...' : $label_q_si_completa;
		}
		$chart_data['mesSi'] = ['labels' => $labels_mes_si_curtes, 'fullLabels' => $labels_mes_si_completes, 'data' => array_values($top_si_questions_db_keys)];
	  
		arsort($question_no_counts);
		$top_no_questions_db_keys = array_slice(array_filter($question_no_counts, fn($c) => $c > 0), 0, 10, TRUE);
		$labels_mes_no_curtes = []; $labels_mes_no_completes = [];
		foreach(array_keys($top_no_questions_db_keys) as $db_key_q_no) {
			  [$pilar_part, $pregunta_part] = explode('_', $db_key_q_no, 2);
			  $label_q_no_completa = (string) ($form_questions_definitions[$pilar_part]['questions'][$pregunta_part]['label'] ?? $db_key_q_no);
			  $labels_mes_no_completes[] = $label_q_no_completa;
			  $labels_mes_no_curtes[] = mb_strlen($label_q_no_completa) > 40 ? mb_substr($label_q_no_completa, 0, 37) . '...' : $label_q_no_completa;
		}
		$chart_data['mesNo'] = ['labels' => $labels_mes_no_curtes, 'fullLabels' => $labels_mes_no_completes, 'data' => array_values($top_no_questions_db_keys)];
	  
		$total_evaluated = $stats['total_submissions'] ?? 0;
		$count_2030 = $stats['equipaments_all_yes'] ?? 0;
		$count_with_plan = $stats['equipaments_implementing_plan'] ?? 0;
		$count_incomplete = max(0, $total_evaluated - $count_2030 - $count_with_plan);
		$chart_data['estatEquipaments'] = [
		  'labels' => [$this->t('Equipaments 2030 (tots Sí)'), $this->t('Amb plans de millora actius'), [$this->t('Equipaments pendent d’autoavaluar'), $this->t('o d’activar plans de millora')]],
		  'data' => [$count_2030, $count_with_plan, $count_incomplete],
		  'dataPercent' => [($total_evaluated > 0) ? round(($count_2030 / $total_evaluated) * 100, 1) : 0, ($total_evaluated > 0) ? round(($count_with_plan / $total_evaluated) * 100, 1) : 0, ($total_evaluated > 0) ? round(($count_incomplete / $total_evaluated) * 100, 1) : 0],
		];
	  }
	  
	  $opcions_filtres_taula = [];
	  if (!empty($accions_millora_data)) {
		  $opcions_filtres_taula['municipis'] = array_values(array_unique(array_column($accions_millora_data, 'municipi'))); sort($opcions_filtres_taula['municipis']);
		  $opcions_filtres_taula['espais_principals'] = array_values(array_unique(array_column($accions_millora_data, 'espai_principal'))); sort($opcions_filtres_taula['espais_principals']);
		  $opcions_filtres_taula['equipaments'] = array_values(array_unique(array_column($accions_millora_data, 'nom_equipament'))); sort($opcions_filtres_taula['equipaments']);
		  $opcions_filtres_taula['pilars'] = array_values(array_unique(array_column($accions_millora_data, 'pilar'))); sort($opcions_filtres_taula['pilars']);
		  $opcions_filtres_taula['preguntes'] = array_values(array_unique(array_column($accions_millora_data, 'pregunta_key'))); sort($opcions_filtres_taula['preguntes']);
	  }
	  
	  // === BLOC DE CODI NOU: LÒGICA PER A LA TAULA D'HISTORIAL ===
	  $history_data = [];
	  $opcions_filtres_historial = ['usuaris' => [], 'equipaments' => [], 'municipis' => []];
	  try {
		  $query_history = $this->database->select('nou_formulari_dades_formulari_historial', 'h');
		  $query_history->fields('h');
		  if (!$is_admin && !empty($equipaments_user_codis)) {
			  $query_history->condition('h.codi_equipament', array_keys($equipaments_user_codis), 'IN');
		  }
		  $query_history->orderBy('h.codi_equipament', 'ASC');
		  $query_history->orderBy('h.hora_submissio', 'ASC');
		  $all_history_results = $query_history->execute()->fetchAll();
		  
		  $processed_history = [];
		  $last_record_per_equipament = [];
		  foreach ($all_history_results as $current_record) {
			  $codi_equip = $current_record->codi_equipament;
			  $previous_record = $last_record_per_equipament[$codi_equip] ?? NULL;
			  $comparison = $this->compareHistoryEntries($current_record, $previous_record);
			  $url_hist = Url::fromRoute('nou_formulari.form', [], ['query' => ['equipament_seleccionat' => $codi_equip]])->toString();
			  $processed_history[] = [
				  'username' => $this->safeTrim($current_record->username),
				  'nom_equipament' => $this->safeTrim($current_record->nom_equipament),
				  'codi_equipament' => $codi_equip,
				  'municipi' => $this->safeTrim($current_record->municipi),
				  'hora_submissio' => $this->safeTrim($current_record->hora_submissio),
				  'url_formulari' => $url_hist,
				  'summary' => $comparison['summary'],
				  'details' => $comparison['details'],
			  ];
			  $last_record_per_equipament[$codi_equip] = $current_record;
		  }
		  $history_data = array_reverse($processed_history);
		  if (!empty($history_data)) {
			  $opcions_filtres_historial['usuaris'] = array_values(array_unique(array_column($history_data, 'username'))); sort($opcions_filtres_historial['usuaris']);
			  $opcions_filtres_historial['equipaments'] = array_values(array_unique(array_column($history_data, 'nom_equipament'))); sort($opcions_filtres_historial['equipaments']);
			  $opcions_filtres_historial['municipis'] = array_values(array_unique(array_column($history_data, 'municipi'))); sort($opcions_filtres_historial['municipis']);
		  }
	  } catch (\Exception $e) { $this->getLogger('tauler')->error('Error carregant historial: @msg', ['@msg' => $e->getMessage()]); }
	  // === FI BLOC DE CODI NOU ===
  
	  $build = [
		'#type'     => 'inline_template',
		'#template' => $this->getInlineTemplate(),
		'#context'  => [
		  'stats'                              => $stats,
		  'is_admin'                           => $is_admin,
		  'has_data'                           => $has_data,
		  'total_user_municipalities_display'  => count($displayable_user_municipalities_names),
		  'all_user_municipalities_json'       => Json::encode($displayable_user_municipalities_names),
		  'max_initial_municipalities_display' => self::MAX_INITIAL_MUNICIPALITIES_DISPLAY,
		  'chart_data_json'                    => ($has_data && !empty($chart_data)) ? Json::encode($chart_data) : '{}',
		  'accions_millora_json'               => Json::encode($accions_millora_data),
		  'opcions_filtres_json'               => Json::encode($opcions_filtres_taula),
		  'has_accions_data'                   => !empty($accions_millora_data),
		  'history_data_json'                  => Json::encode($history_data),
		  'opcions_filtres_historial_json'     => Json::encode($opcions_filtres_historial),
		  'has_history_data'                   => !empty($history_data),
		  'total_user_municipalities_for_active_plan_stats' => $total_user_municipalities_from_profile,
		],
		'#attached' => $this->getBasicAttached(),
	  ];
	  $build['#cache']['contexts'][] = 'user';
	  $build['#cache']['contexts'][] = 'user.roles';
	  return $build;
	}
				
	/**
	   * Compara dos registres de l'historial (nou i vell) i retorna els canvis.
	   */
private function compareHistoryEntries($new_record, $old_record) {
		   $is_creation = ($old_record === NULL);
		   $details = [];
		   $new_array = (array) $new_record;
		   $pretty_field_names = $this->getPrettyFieldNames();
	   
		   if ($is_creation) {
			   // === LÒGICA PER A LA CREACIÓ INICIAL ===
			   // Recorrem tots els camps definits i els afegim a la llista de detalls.
			   foreach ($pretty_field_names as $field_key => $pretty_name) {
				   $new_value = $this->safeTrim($new_array[$field_key] ?? '');
				   $details[] = [
					   'field' => $pretty_name,
					   'from'  => $this->t('-'), // Indiquem que abans no existia
					   'to'    => empty($new_value) ? $this->t('buit') : $new_value,
				   ];
			   }
		   } else {
			   // === LÒGICA PER A LES ACTUALITZACIONS (la que ja teníem) ===
			   $old_array = (array) $old_record;
			   foreach ($pretty_field_names as $field_key => $pretty_name) {
				   $old_value = $this->safeTrim($old_array[$field_key] ?? '');
				   $new_value = $this->safeTrim($new_array[$field_key] ?? '');
	   
				   if ($old_value !== $new_value) {
					   $details[] = [
						   'field' => $pretty_name,
						   'from'  => empty($old_value) ? $this->t('buit') : $old_value,
						   'to'    => empty($new_value) ? $this->t('buit') : $new_value,
					   ];
				   }
			   }
		   }
	   
		   $count = count($details);
		   $summary = '';
	   
		   if ($is_creation) {
			   // El recompte sempre serà el total de camps definits.
			   $summary = $this->t('Registre inicial creat (@count camps)', ['@count' => $count]);
		   } elseif ($count === 0) {
			   $summary = $this->t('Re-desat sense canvis');
		   } else {
			   $summary = $this->formatPlural($count, '1 camp canviat', '@count camps canviats');
		   }
	   
		   return ['summary' => $summary, 'details' => $details];
		 }
		 		 	
	  /**
	   * Genera un array de noms de camp "llegibles" per a la vista de detalls.
	   */
	  private function getPrettyFieldNames() {
		$pretty_names = [];
		$definitions = $this->getGroupsDefinition();
	
		foreach ($definitions as $group_data) {
			foreach ($group_data['questions'] as $q_key => $q_info) {
				$main_question_key = $group_data['prefix_col'] . '_' . $q_key;
				$pretty_names[$main_question_key] = (string) $q_info['label'];
	
				if (!empty($q_info['subitems'])) {
					foreach ($q_info['subitems'] as $sub_key => $sub_label) {
						$checkbox_key = $main_question_key . '_subitem_' . $sub_key;
						$date_key = $checkbox_key . '_date';
						$pretty_names[$checkbox_key] = $this->t('Acció: "@sublabel"', ['@sublabel' => $sub_label]);
						$pretty_names[$date_key] = $this->t('Any compromís per a "@sublabel"', ['@sublabel' => $sub_label]);
					}
				}
				$custom_plan_key = $main_question_key . '_custom_plan';
				$custom_date_key = $custom_plan_key . '_date';
				$pretty_names[$custom_plan_key] = $this->t('Acció personalitzada per a "@question"', ['@question' => $q_info['label']]);
				$pretty_names[$custom_date_key] = $this->t('Any compromís acció personalitzada per a "@question"', ['@question' => $q_info['label']]);
			}
		}
		return $pretty_names;
	  }
	  		  		  	
  protected function getGroupsDefinition() {
	return [
	  'participacio' => ['title' => $this->t('Participació'), 'prefix_col' => 'participacio', 'questions' => [
		  'P1' => ['label' => $this->t("L’equipament disposa d’espais de comunicació i relació amb la ciutadania i entitats"), 'subitems' => ['P1_1' => $this->t("Disposar d’espais virtuals com bústies de suggeriments, comunitats virtuals o xarxes socials per a comunicar-vos i relacionar-vos amb la ciutadania"),'P1_2' => $this->t("Facilitar l’ús de sales per al desenvolupament de projectes associatius, grupals o individuals"),'P1_3' => $this->t("Fer petites enquestes de satisfacció d’usuaris o qualsevol altre tipus de mecanismes que doni veu als ciutadans"),]],
		  'P2' => ['label' => $this->t("La ciutadania forma part d’algun òrgan decisori o de consulta de l’equipament"), 'subitems' => ['P2_1' => $this->t("Disposar d’espais de participació i consulta permanent com un consell, una taula, un fòrum o un comitè on es debaten i concreten propostes per millorar la gestió i ordenació dels usos i activitats de l’equipament"),'P2_2' => $this->t("La ciutadania i/o entitats participen en la presa de decisions ja sigui en l’aprovació d’un pla, un programa o un projecte d’actuació"),'P2_3' => $this->t("Realitzar accions per conèixer els interessos i/o necessitats de la ciutadania i/o entitats"),]],
		  'P3' => ['label' => $this->t("L’equipament participa d’estratègies conjuntes amb altres equipaments i serveis locals"), 'subitems' => ['P3_1' => $this->t("Disposar d’una estructura estable, com un fòrum, un consell, una comissió on es treballa en xarxa amb els serveis que ofereix el territori en el teu àmbit"),'P3_2' => $this->t("Comptar amb programes d’intervenció transversals amb altres serveis del territori vinculats (joventut, esports, treball, salut, mobilitat, cultura, educació, habitatge, entitats, etc.)"),'P3_3' => $this->t("Participar en algun òrgan de governança de l’ens local"),]],
		],
	  ],
	  'accessibilitat' => ['title' => $this->t('Accessibilitat'), 'prefix_col' => 'accessibilitat', 'questions' => [
		  'A1' => ['label' => $this->t("L’equipament incorpora criteris i accions per garantir la inclusió i l’accés per igual de la ciutadania als recursos"), 'subitems' => ['A1_1' => $this->t("Disposar de diferents elements per facilitar la inclusió: en l’estil de comunicació, el disseny dels espais, o les accions per garantir que arribem a tots els públics"),'A1_2' => $this->t("Fomentar una comunicació comprensible pels diferents col·lectius d’usuaris, utilitzant per exemple criteris de lectura fàcil, sistema Braille, lletra ampliada, sistemes alternatius i augmentatius de comunicació bé incorporant l’ús de diferents llengües"),'A1_3' => $this->t("Tenir espais i/o serveis per donar resposta als interessos i/o necessitats de la diversitat de públics, o bé el fet de realitzar accions periòdiques de crida d’usuaris per arribar a les persones que no se senten interpel·lades pels serveis oferts"),]],
		  'A2' => ['label' => $this->t("A l’equipament es programen accions accessibles a tots els col·lectius"), 'subitems' => ['A2_1' => $this->t("Realitzar accions i activitats que inclouen als diferents col·lectius de la població a la qual s’adreça l’equipament"),'A2_2' => $this->t("Promoure activitats intergeneracionals entre infants i gent gran"),'A2_3' => $this->t("Disposar de programes d’activitats adreçats a diferents col·lectius, fent incidència en aquells que requereixen especial atenció"),]],
		  'A3' => ['label' => $this->t("A l’equipament s’implementen mesures de supressió de barreres arquitectòniques i d’accessibilitat universal"), 'subitems' => ['A3_1' => $this->t("Disposar d’identificació i senyalització per garantir que externament l’edifici sigui visible i identificable i que està clarament senyalitzat als indicadors de carrers, als documents i pàgines web municipals i al propi edifici, tenint en compte els diferents sistemes de comunicació (visual, auditiu, tàctil…)"),'A3_2' => $this->t("Garantir que existeix algun itinerari accessible per arribar a peu a l’equipament, o que es pugui arribar en transport públic"),'A3_3' => $this->t("Tenir aparcaments de bicicletes, patinets i/o altres mitjans de transport sostenible"),]],
		  'A4' => ['label' => $this->t("Es posa a disposició de col·lectius vulnerables línies d’ajut econòmic per l’ús de l’equipament"), 'subitems' => ['A4_1' => $this->t("Programar activitats gratuïtes periòdiques o puntuals obertes a tothom"),'A4_2' => $this->t("Dissenyar diferents sistemes de beques, ajuts o tarifació social de forma que es garanteix l’oportunitat d’accés universal"),'A4_3' => $this->t("Fer una comunicació eficient per assegurar-nos que la informació sobre els ajuts econòmics arriba als col·lectius que ho necessitin"),]],
		],
	  ],
	  'igualtat' => ['title' => $this->t('Igualtat'), 'prefix_col' => 'igualtat', 'questions' => [
		  'I1' => ['label' => $this->t("Es garanteix l’accés de qualitat i equitatiu amb criteris interseccionals a l’equipament"), 'subitems' => ['I1_1' => $this->t("Oferir activitats aptes per a persones de qualsevol gènere o edat"),'I1_2' => $this->t("Dissenyar un programa adaptat a les necessitats culturals i/o de caràcter religiós com incorporar menús halal o considerar els períodes religiosos com el Ramadà"),'I1_3' => $this->t("Comptar amb espais de participació amb la representació de persones, entitats o referents de diferents eixos de desigualtat"),]],
		  'I2' => ['label' => $this->t("L’equipament disposa d’un protocol d’actuació davant de les violències"), 'subitems' => ['I2_1' => $this->t("Fer accions de comunicació i formació dels protocols d’actuació davant les violències per tal que tot l’equip els conegui"),'I2_2' => $this->t("Promoure la protecció de les dones i del col·lectiu LGBTIQ+"),'I2_3' => $this->t("Tenir referents per a la prevenció en violències de qualsevol tipus"),]],
		  'I3' => ['label' => $this->t("El personal de l’equipament està capacitat per oferir una atenció igualitària"), 'subitems' => ['I3_1' => $this->t("L’equip del centre rep formació per l’atenció amb tracte amable i pel foment a la diversitat"),'I3_2' => $this->t("Assegurar la contractació de personal acceptant la no-discriminació, no sexisme ni racisme"),'I3_3' => $this->t("Utilitzar un llenguatge inclusiu i adequat perquè arribi al màxim de persones i evitar estereotips i expressions o imatges sexistes, racistes, homòfobes"),]],
		  'I4' => ['label' => $this->t("L’equipament compta amb espais adequats a les necessitats de tots els col·lectius"), 'subitems' => ['I4_1' => $this->t("Disposar de lavabos inclusius amb una senyalització no binària que poden ser utilitzats per qualsevol persona, independentment de la seva identitat o expressió de gènere"),'I4_2' => $this->t("Disposar de vestidors o espais destinats a canviar-se de roba, que atenguin a la diversitat (home, dona, no binari, familiars, etc.)"),'I4_3' => $this->t("Disposar d’espais de lactància"),]],
		],
	  ],
	  'sostenibilitat' => ['title' => $this->t('Sostenibilitat'), 'prefix_col' => 'sostenibilitat', 'questions' => [
		  'S1' => ['label' => $this->t("A l’equipament es realitzen accions potenciadores de consciència ecològica i de promoció d’hàbits i valors en matèria de sostenibilitat ambiental"), 'subitems' => ['S1_1' => $this->t("L’equipament disposa de panells, rètols electrònics o pantalles que informen sobre les condicions d’humitat i temperatura dels espais interiors i de la producció d’energia d’origen renovable generada pel propi equipament"),'S1_2' => $this->t("Es promou la informació i la consciència ambiental i energètica de persones externes implicades d’una manera o altra amb l’equipament: usuaris, proveïdors, empreses subcontractades, etc."),'S1_3' => $this->t("Proporciona formació ambiental i energètica al personal de l’equipament per aconseguir la sua implicació cap a la reducció de consum energètic i de materials"),]],
		  'S2' => ['label' => $this->t("A l’equipament s’apliquen accions de reciclatge i reutilització dels seus materials i dels residus"), 'subitems' => ['S2_1' => $this->t("L’equipament incentiva la reparació i reutilització dels materials utilitzats, a fi de reduir els residus generats"),'S2_2' => $this->t("Disposar de punts de recollida selectiva de residus reciclables en una situació visible i accessible per als veïns"),'S2_3' => $this->t("L’equipament reutilitza material usat d’altres espais o equipaments municipals, donant-los-hi una segona vida"),]],
		  'S3' => ['label' => $this->t("A l’equipament es promouen actuacions per millorar l’eficiència energètica i reduir els consums"), 'subitems' => ['S3_1' => $this->t("Disposar de l’etiqueta energètica en un lloc visible i/o pot ser consultada telemàticament pels usuaris i la resta de la comunitat"),'S3_2' => $this->t("Implementar mesures concretes d’estalvi energètic i de reducció de consum propi com, per exemple: substitució de l’enllumenat existent per un de nou amb tecnologia LED, la instal·lació de captadors o reductors de cabal a les aixetes"),'S3_3' => $this->t("Tenir les dades del consum energètic periòdic (diari, mensual, anual) i fer el seguiment de les mateixes"),]],
		  'S4' => ['label' => $this->t("A l’equipament es duen a terme accions de renaturalització dels espais, de pacificació de l’entorn i de transformació del seu context urbà o natural"), 'subitems' => ['S4_1' => $this->t("L’equipament ha (re)naturalitzat en els darrers anys els espais a l’aire lliure (com són patis, terrasses, piscines, etc.) o l’entorn urbà proper a l’equipament"),'S4_2' => $this->t("L’entorn de l’equipament ha estat pacificat de trànsit mitjançant carrers residencials o de prioritat invertida, zones de vianants, camins escolars, carrils bici, o altres"),'S4_3' => $this->t("Disposar d’espais que disposin de condicions de confort tèrmic en episodis de temperatures extremes i que puguin ser considerats refugis climàtics"),]],
		],
	  ],
	];
  }

  private function safeTrim($value): string { return trim((string) $value); }
  private function isRespostaSi($valor): bool { return mb_strtolower($this->safeTrim($valor), 'UTF-8') === 'sí'; }

private function getInlineTemplate(): string {
			$css = $this->getTaulerCss();
			$js_code  = $this->getTaulerJs();
		
			return <<<TWIG
		<style>
		{$css}
		</style>
		<div class="dashboard-container">
		  <h1 class="dashboard-title">{{ 'Tauler d’estadístiques del municipi'|t }}</h1>
		  {% if total_user_municipalities_display > 0 %}
			<div class="user-municipalities-display">
			  <p>{{ 'Mostrant estadístiques per als municipis:'|t }}
				<span id="dynamic-municipalities-list-content"></span>
				{% if total_user_municipalities_display > max_initial_municipalities_display %}
				  <button type="button" id="toggle-municipalities-visibility-btn" class="btn-link-style"
						  data-text-more-pattern="{{ 'Veure més ({count})'|t }}"
						  data-total-municipalities="{{ total_user_municipalities_display }}"
						  data-text-less="{{ 'contraure'|t }}"></button>
				{% endif %}
			  </p>
			</div>
		  {% elseif total_user_municipalities_for_active_plan_stats == 0 %}
		  {% endif %}
		  {% if has_data %} 
		  <div class="top-stats-grid">
			<div class="stat-card top-stat">
			  <h3>{{ 'Percentatge d’equipaments esportius autoavaluats <br>segons els criteris d’Equipaments 2030'|t }}</h3>
			  <div class="stat-value">{{ stats.percent_equip_autoavaluats|default('0') }}%</div>
			  <div class="stat-description">{{ '@auto de @total equipaments'|t({'@auto': stats.total_submissions|default('0'),'@total': stats.total_equipaments_user|default('0')}) }}</div>
			</div>
			<div class="stat-card top-stat">
			  <h3>{{ "Nombre d’equipaments esportius on s’estan<br> implementant plans de millora per assolir els criteris <br>d'Equipaments 2030"|t }}</h3>
			  <div class="stat-value">{{ stats.equipaments_implementing_plan|default('0') }}</div>
			  <div class="stat-description">{{ '  '|t }}</div>
			</div>
		  </div>
		  <div class="stats-grid">
		  </div>
		  <div class="charts-grid">
			<div class="chart-container"><canvas id="chartPilarsSiNoPmaPercent"></canvas></div>
			<div class="chart-container"><canvas id="chartEstatEquipaments"></canvas></div>
			<div class="chart-container"><canvas id="chartMesSi"></canvas></div>
			<div class="chart-container"><canvas id="chartMesNo"></canvas></div>
		  </div>
		  {% else %} 
		  <div class="no-data-message">
			{% if total_user_municipalities_display > 0 and not has_data %} 
			  <p>{{ 'No s\'han trobat formularis enviats per als municipis dels teus equipaments assignats.'|t }}</p>
			  <p>{{ 'Si creus que és un error, contacta amb l\'administrador.'|t }}</p>
			   {% if not has_accions_data %}<p>{{ 'Tampoc s\'han trobat accions de millora registrades.'|t }}</p>{% endif %}
			{% elseif total_user_municipalities_for_active_plan_stats > 0 and not has_data %} 
			   <p>{{ 'No s\'han trobat dades d\'autoavaluació per als teus municipis.'|t }}</p>
			{% endif %} 
		  </div>
		  {% endif %} 
		  <hr class="section-divider">
		  <h2 class="section-title">{{ 'Taula de Plans de millora actius'|t }}</h2>
		  {% if has_accions_data %}
			<div class="actions-table-controls">
			  <div class="actions-search-and-reset-control">
				<div class="actions-search-control"><label for="actions-search-input">{{ 'Cercar acció:'|t }}</label><input type="text" id="actions-search-input" placeholder="{{ 'Escriu per cercar...'|t }}"></div>
				<div class="actions-reset-control"><button type="button" id="reset-filters-button" title="{{ 'Restableix tots els filtres de la taula'|t }}">{{ 'Restablir filtres'|t }}</button></div>
			  </div>
			  <div class="actions-filters-grid">
				<div><label for="filter-municipi">{{ 'Municipi:'|t }}</label><select id="filter-municipi"><option value="">{{ 'Tots'|t }}</option></select></div>
				<div><label for="filter-espai">{{ 'Espai Principal:'|t }}</label><select id="filter-espai"><option value="">{{ 'Tots'|t }}</option></select></div>
				<div><label for="filter-equipament">{{ 'Equipament:'|t }}</label><select id="filter-equipament"><option value="">{{ 'Tots'|t }}</option></select></div>
				<div><label for="filter-pilar">{{ 'Pilar:'|t }}</label><select id="filter-pilar"><option value="">{{ 'Tots'|t }}</option></select></div>
				<div><label for="filter-pregunta">{{ 'Pregunta (clau):'|t }}</label><select id="filter-pregunta"><option value="">{{ 'Totes'|t }}</option></select></div>
			  </div>
			</div>
			<div class="table-container">
			  <table id="accions-millora-table" class="dashboard-table">
				<thead><tr><th data-sort-by="codi_equipament">{{ 'Codi Equip.'|t }}<span class="sort-arrow"></span></th><th data-sort-by="nom_equipament">{{ 'Nom Equipament'|t }}<span class="sort-arrow"></span></th><th data-sort-by="municipi">{{ 'Municipi'|t }}<span class="sort-arrow"></span></th><th data-sort-by="accio_id_curt">{{ 'Acció (ID)'|t }}<span class="sort-arrow"></span></th><th data-sort-by="any_compromis">{{ 'Any Compromís'|t }}<span class="sort-arrow"></span></th></tr></thead>
				<tbody></tbody>
			  </table>
			</div>
			<div id="pagination-controls-container-actions" class="pagination-container"><div id="pagination-controls-actions" class="pagination-controls"></div></div>
			<p id="actions-table-no-results" class="no-data-message" style="display:none;">{{ 'No s\'han trobat accions de millora que coincideixin amb els criteris de cerca/filtre.'|t }}</p>
		  {% else %} 
			<div class="no-data-message">
			  {% if total_user_municipalities_display > 0 and not has_accions_data %}<p>{{ 'No s\'han trobat accions de millora registrades per als equipaments dels teus municipis.'|t }}</p>{% endif %}
			</div>
		  {% endif %}
		  
		  <hr class="section-divider">
		  <h2 class="section-title">{{ 'Historial d\'Evolució dels Formularis'|t }}</h2>
		  {% if has_history_data %}
			<div class="history-table-controls actions-table-controls">
			  <div class="actions-search-and-reset-control">
				<div class="actions-search-control"><label for="history-search-input">{{ 'Cercar a l\'historial:'|t }}</label><input type="text" id="history-search-input" placeholder="{{ 'Cerca per equipament, municipi, usuari...'|t }}"></div>
				<div class="actions-reset-control"><button type="button" id="history-reset-filters-button" title="{{ 'Restableix tots els filtres de l\'historial'|t }}">{{ 'Restablir filtres'|t }}</button></div>
			  </div>
			  <div class="actions-filters-grid history-filters-grid">
				{% if is_admin %}
				  <div><label for="history-filter-user">{{ 'Usuari:'|t }}</label><select id="history-filter-user"><option value="">{{ 'Tots'|t }}</option></select></div>
				{% endif %}
				<div><label for="history-filter-municipi">{{ 'Municipi:'|t }}</label><select id="history-filter-municipi"><option value="">{{ 'Tots'|t }}</option></select></div>
				<div><label for="history-filter-equipament">{{ 'Equipament:'|t }}</label><select id="history-filter-equipament"><option value="">{{ 'Tots'|t }}</option></select></div>
				<div class="date-filter"><label for="history-filter-date-from">{{ 'Des de:'|t }}</label><input type="date" id="history-filter-date-from"></div>
				<div class="date-filter"><label for="history-filter-date-to">{{ 'Fins a:'|t }}</label><input type="date" id="history-filter-date-to"></div>
			  </div>
			</div>
			<div class="table-container">
			  <table id="history-table" class="dashboard-table">
				<thead>
				  <tr>
					<th data-sort-by="hora_submissio">{{ 'Data Canvi'|t }}<span class="sort-arrow"></span></th>
					<th data-sort-by="codi_equipament">{{ 'Codi Equip.'|t }}<span class="sort-arrow"></span></th>
					{% if is_admin %}
					  <th data-sort-by="username">{{ 'Usuari'|t }}<span class="sort-arrow"></span></th>
					{% endif %}
					<th data-sort-by="nom_equipament">{{ 'Equipament'|t }}<span class="sort-arrow"></span></th>
					<th data-sort-by="municipi">{{ 'Municipi'|t }}<span class="sort-arrow"></span></th>
					<th data-sort-by="summary">{{ 'Canvis Realitzats'|t }}<span class="sort-arrow"></span></th>
				  </tr>
				</thead>
				<tbody></tbody>
			  </table>
			</div>
			<div id="pagination-controls-container-history" class="pagination-container"><div id="pagination-controls-history" class="pagination-controls"></div></div>
			<p id="history-table-no-results" class="no-data-message" style="display:none;">{{ 'No s\'han trobat registres a l\'historial que coincideixin amb la teva cerca.'|t }}</p>
		  {% else %}
			<div class="no-data-message">
			  <p>{{ 'Encara no hi ha cap historial de canvis per mostrar.'|t }}</p>
			</div>
		  {% endif %}
		</div> 
		<script>
		const globalChartData = {{ chart_data_json|raw }};
		const globalAccionsMilloraData = {{ accions_millora_json|raw }};
		const globalOpcionsInicialsFiltres = {{ opcions_filtres_json|raw }};
		const globalAllUserMunicipalitiesNames = {{ all_user_municipalities_json|raw }};
		const MAX_INITIAL_MUNICIPALITIES_JS = {{ max_initial_municipalities_display }};
		const globalHistoryData = {{ history_data_json|raw }};
		const globalOpcionsFiltresHistorial = {{ opcions_filtres_historial_json|raw }};
		const globalIsAdmin = {{ is_admin ? 'true' : 'false' }};
		{$js_code}
		</script>
TWIG;
  }
      			  			
private function getTaulerCss(): string {
	  return <<<CSS
	  .dashboard-container { padding: 20px; font-family: sans-serif; max-width: 1600px; margin: 0 auto; }
	  .dashboard-title { color: #7f2c37; font-size: 2em; margin-bottom: 20px; border-bottom: 2px solid #f0f0f0; padding-bottom: 10px; }
	  .user-municipalities-display p { font-size: 1em; color: #333; margin-bottom: 20px; line-height: 1.6; }
	  #dynamic-municipalities-list-content strong { font-weight: 600; }
	  #toggle-municipalities-visibility-btn { background: none; border: none; color: #d44942; text-decoration: underline; cursor: pointer; padding: 0 2px; margin-left: 5px; font-size: inherit; font-family: inherit; vertical-align: baseline; }
	  #toggle-municipalities-visibility-btn:hover { color: #7f2c37; text-decoration: none; }
	  .top-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 29px; margin-bottom: 30px; }
	  .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 0px; }
	  .stat-card { background-color: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 15px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); text-align: center; display: flex; flex-direction: column; justify-content: space-between; min-height: 120px; }
	  .stat-card h3 { margin-top: 0; margin-bottom: 8px; color: #495057; font-size: 1.1em; font-weight: bold; }
	  .stat-card .stat-value { font-size: 2em; font-weight: bold; color: #d44942; margin-bottom: 5px; }
	  .stat-card .stat-description { font-size: 0.9em; color: #6c757d; line-height: 1.3; }
	  .stat-card.top-stat { background-color: #FAE1DF; border-color: #dee2e6; padding: 20px; }
	  .stat-card.top-stat h3 { color: #771129; font-size: 1.05em; font-weight: 600; }
	  .stat-card.top-stat .stat-value { color: #e2727c; font-size: 2.3em; font-weight: 700; }
	  .stat-card.top-stat .stat-description { color: #1E1E1E; font-size: 0.9em; }
	  .charts-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(450px, 1fr)); gap: 30px; margin-bottom: 30px; }
	  .chart-container { background-color: #fff; border: 1px solid #dee2e6; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); min-height: 400px; display: flex; flex-direction: column; align-items: center; justify-content: flex-start; }
	  .chart-container canvas { max-width: 100%; max-height: 380px; height: auto !important; }
	  .chart-row-container { grid-column: 1 / -1; display: grid; grid-template-columns: 1fr 1fr; gap: 30px; }
	  @media (max-width: 950px) { .chart-row-container { grid-template-columns: 1fr; } }
	  .no-data-message { text-align: center; font-size: 1.2em; color: #6c757d; padding: 20px; margin-top: 20px; border: 1px dashed #dee2e6; border-radius: 8px; background-color: #f8f9fa; }
	  .stat-description.no-data-msg { margin-top: 20px; font-style: italic; }
	  .section-divider { border: 0; height: 1px; background: #ddd; margin: 40px 0; }
	  .section-title { color: #7f2c37; font-size: 1.8em; margin-bottom: 20px; }
	  .actions-table-controls { margin-bottom: 20px; padding: 15px; background-color: #f9f9f9; border-radius: 8px; border: 1px solid #eee;}
	  .actions-search-and-reset-control { display: flex; align-items: flex-end; gap: 15px; margin-bottom: 15px; }
	  .actions-search-control { flex-grow: 1; }
	  .actions-search-control label { font-weight: bold; margin-right: 10px; display: block; margin-bottom: 5px;}
	  .actions-search-control input[type="text"] { padding: 8px 10px; border-radius: 4px; border: 1px solid #ccc; width: 100%; box-sizing: border-box; }
	  #reset-filters-button, #history-reset-filters-button { padding: 8px 15px; background-color: #6c757d; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 0.9em; height: 38px; line-height: 1.4; }
	  #reset-filters-button:hover, #history-reset-filters-button:hover { background-color: #5a6268; }
	  .actions-filters-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; }
	  .actions-filters-grid div { display: flex; flex-direction: column; }
	  .actions-filters-grid label { font-weight: bold; margin-bottom: 5px; font-size: 0.9em; }
	  .actions-filters-grid select, .actions-filters-grid input[type="date"] { padding: 8px; border-radius: 4px; border: 1px solid #ccc; background-color: white; height: 38px; box-sizing: border-box; }
	  .history-filters-grid { grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); }
	  .table-container { overflow-x: auto; }
	  .dashboard-table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 0.9em; }
	  .dashboard-table th, .dashboard-table td { border: 1px solid #ddd; padding: 10px; text-align: left; vertical-align: top; }
	  .dashboard-table th { background-color: #f2f2f2; color: #333; font-weight: bold; cursor: pointer; position: relative; }
	  .dashboard-table th .sort-arrow { display: inline-block; width: 0; height: 0; border-left: 5px solid transparent; border-right: 5px solid transparent; margin-left: 5px; opacity: 0.4; position: absolute; right: 8px; top: 50%; transform: translateY(-50%); }
	  .dashboard-table th.sort-asc .sort-arrow { border-bottom: 5px solid #333; opacity: 1; }
	  .dashboard-table th.sort-desc .sort-arrow { border-top: 5px solid #333; opacity: 1; }
	  #accions-millora-table tbody tr:nth-child(even) { background-color: #f9f9f9; }
	  #history-table tbody tr.data-row:nth-child(even) { background-color: #f9f9f9; }
	  .dashboard-table tbody tr:hover { background-color: #f1f1f1; }
	  .dashboard-table tbody tr.clickable-row { cursor: pointer; } 
	  .pagination-container { display: flex; justify-content: center; margin-top: 20px; padding: 10px 0; }
	  .pagination-controls button, .pagination-controls span { margin: 0 3px; padding: 6px 10px; border: 1px solid #ddd; background-color: #f8f8f8; color: #d44942; cursor: pointer; border-radius: 4px; user-select: none; font-size: 0.9em; }
	  .pagination-controls button:hover:not(:disabled) { background-color: #eee; }
	  .pagination-controls button:disabled { color: #aaa; cursor: not-allowed; background-color: #fdfdfd; }
	  .pagination-controls span.current-page { font-weight: bold; background-color: #d44942; color: white; border-color: #d44942; }
	  .pagination-controls span.dots { border: none; background-color: transparent; color: #333; cursor: default; padding: 6px 4px; }
	  .info-icon { cursor: help; margin-left: 5px; color: #d44942; font-weight: bold; display: inline-block; }
	  .info-icon:hover { color: #7f2c37; }
	  .custom-tooltip { position: fixed; background-color: #333; color: #fff; padding: 8px 12px; border-radius: 4px; font-size: 0.85em; z-index: 1070; display: none; max-width: 300px; word-wrap: break-word; box-shadow: 0 2px 5px rgba(0,0,0,0.2); pointer-events: none; }
	  .history-summary-cell { display: flex; justify-content: space-between; align-items: center; gap: 10px; }
	  .details-toggle-button { background-color: #f0f0f0; border: 1px solid #ccc; color: #333; padding: 4px 8px; font-size: 0.8em; border-radius: 4px; cursor: pointer; white-space: nowrap; }
	  .details-toggle-button:hover { background-color: #e0e0e0; }
	  .history-details-row { background-color: #fdfdfd !important; }
	  .history-details-row td { padding: 15px 20px 15px 40px; border-bottom: 2px solid #ddd; }
	  .history-details-list { list-style-type: none; padding-left: 0; margin: 0; font-size: 0.9em; }
	  .history-details-list li { padding: 5px 0; border-bottom: 1px solid #eee; }
	  .history-details-list li:last-child { border-bottom: none; }
	  .history-details-list strong { color: #7f2c37; }
	  .change-from { text-decoration: line-through; color: #999; }
	  .change-to { color: #007bff; font-weight: bold; }
CSS;
  }
        					  	  	  
private function getTaulerJs(): string {
	  return <<<JS
	(function () {
	'use strict';
	// --- DADES GLOBALS ---
	const chartData = (typeof globalChartData !== 'undefined') ? globalChartData : {};
	const accionsMilloraData = Array.isArray(globalAccionsMilloraData) ? globalAccionsMilloraData : [];
	const opcionsInicialsFiltres = (typeof globalOpcionsInicialsFiltres !== 'undefined') ? globalOpcionsInicialsFiltres : {};
	const historyData = Array.isArray(globalHistoryData) ? globalHistoryData : [];
	const opcionsFiltresHistorial = (typeof globalOpcionsFiltresHistorial !== 'undefined') ? globalOpcionsFiltresHistorial : {};
	const allUserMunicipalitiesNamesList = Array.isArray(globalAllUserMunicipalitiesNames) ? globalAllUserMunicipalitiesNames : [];
	const maxInitialMunicipalitiesToShow = (typeof MAX_INITIAL_MUNICIPALITIES_JS !== 'undefined') ? MAX_INITIAL_MUNICIPALITIES_JS : 3;
	const isAdmin = (typeof globalIsAdmin !== 'undefined') ? globalIsAdmin : false;
	
	// --- PALETA DE COLORS ---
	const colors = {
	  primary: '#d44942', primary_light: '#dd746c', secondary: '#7f2c37',
	  title_color: '#c20f2f', light_grey: '#e9ecef', dark_grey: '#343a40'
	};
	
	// --- GESTIÓ TOOLTIP ---
	let tooltipElement;
	function createTooltipElement() {
	  if (!document.getElementById('tauler-custom-tooltip')) {
		tooltipElement = document.createElement('div');
		tooltipElement.id = 'tauler-custom-tooltip';
		tooltipElement.className = 'custom-tooltip';
		document.body.appendChild(tooltipElement);
	  } else {
		tooltipElement = document.getElementById('tauler-custom-tooltip');
	  }
	}
	function showTooltip(event, text) {
	  if (!tooltipElement || !text) return;
	  tooltipElement.innerHTML = text;
	  tooltipElement.style.display = 'block';
	  positionTooltip(event);
	}
	function hideTooltip() { if (tooltipElement) tooltipElement.style.display = 'none'; }
	function positionTooltip(event) {
	  if (!tooltipElement) return;
	  let x = event.clientX + 15; let y = event.clientY + 15;
	  const tooltipRect = tooltipElement.getBoundingClientRect();
	  const viewportWidth = window.innerWidth; const viewportHeight = window.innerHeight;
	  if (x + tooltipRect.width > viewportWidth) x = event.clientX - tooltipRect.width - 15;
	  if (y + tooltipRect.height > viewportHeight) y = event.clientY - tooltipRect.height - 15;
	  tooltipElement.style.left = x + 'px'; tooltipElement.style.top = y + 'px';
	}
	
	// --- LÒGICA GENERAL DE LA PÀGINA (MUNICIPIS I GRÀFICS) ---
	function initializeDashboard() {
		const municipalitiesListSpan = document.getElementById('dynamic-municipalities-list-content');
		const toggleMunicipalitiesBtn = document.getElementById('toggle-municipalities-visibility-btn');
		let municipalitiesListIsExpanded = false;
	
		function updateMunicipalitiesListDisplay() {
		  if (!municipalitiesListSpan || !allUserMunicipalitiesNamesList || allUserMunicipalitiesNamesList.length === 0) {
			if(toggleMunicipalitiesBtn) toggleMunicipalitiesBtn.style.display = 'none';
			return;
		  }
		  const toDisplay = municipalitiesListIsExpanded ? allUserMunicipalitiesNamesList : allUserMunicipalitiesNamesList.slice(0, maxInitialMunicipalitiesToShow);
		  municipalitiesListSpan.innerHTML = toDisplay.map(m => `<strong>\${m}</strong>`).join(', ') + (!municipalitiesListIsExpanded && allUserMunicipalitiesNamesList.length > maxInitialMunicipalitiesToShow ? '...' : '');
		
		  if (toggleMunicipalitiesBtn) {
			if (allUserMunicipalitiesNamesList.length <= maxInitialMunicipalitiesToShow) {
			  toggleMunicipalitiesBtn.style.display = 'none';
			} else {
			  toggleMunicipalitiesBtn.style.display = 'inline';
			  if (municipalitiesListIsExpanded) {
				toggleMunicipalitiesBtn.textContent = toggleMunicipalitiesBtn.dataset.textLess || 'contraure';
			  } else {
				toggleMunicipalitiesBtn.textContent = (toggleMunicipalitiesBtn.dataset.textMorePattern || 'Veure més ({count})').replace('{count}', allUserMunicipalitiesNamesList.length);
			  }
			}
		  }
		}
		if (toggleMunicipalitiesBtn) {
		  toggleMunicipalitiesBtn.addEventListener('click', () => {
			municipalitiesListIsExpanded = !municipalitiesListIsExpanded;
			updateMunicipalitiesListDisplay();
		  });
		}
		updateMunicipalitiesListDisplay();
	
		if (typeof Chart === 'undefined') { console.error('Chart.js no està carregat.'); return; }
		Chart.defaults.plugins.title.color = colors.title_color;
		Chart.defaults.plugins.title.font = { size: 16, weight: 'bold' };
	
		const tooltipCallbacks = {
			padding: 10,
			callbacks: {
				title: (tooltipItems) => {
					const item = tooltipItems[0];
					if(!item) return '';
					const fullLabels = item.chart.config.options.plugins.tooltip.fullLabels || chartData[item.chart.canvas.id.replace('chart','').toLowerCase()]?.labels || [];
					const fullLabel = fullLabels[item.dataIndex] || item.label || '';
					const MAX_CHARS = 50;
					if (fullLabel.length > MAX_CHARS) {
						const words = fullLabel.split(' '); let lines = []; let currentLine = '';
						words.forEach(word => {
							if ((currentLine + word).length > MAX_CHARS) { lines.push(currentLine.trim()); currentLine = word + ' '; } else { currentLine += word + ' '; }
						});
						lines.push(currentLine.trim());
						return lines;
					}
					return fullLabel;
				}
			}
		};
	
		if (chartData.mesSi?.labels?.length) { new Chart('chartMesSi', { type:'bar', data:{ labels: chartData.mesSi.labels, datasets:[{ label:'"Sí"', data: chartData.mesSi.data, backgroundColor: colors.primary }] }, options:{ indexAxis:'y', responsive:true, maintainAspectRatio:false, scales:{ x:{ beginAtZero:true, title:{display:true,text:'Quantitat "Sí"'} } }, plugins:{ legend:{display:false}, title:{display:true,text:'Preguntes amb més respostes SÍ'}, tooltip: {...tooltipCallbacks, fullLabels: chartData.mesSi.fullLabels} } } }); }
		if (chartData.mesNo?.labels?.length) { new Chart('chartMesNo', { type:'bar', data:{ labels: chartData.mesNo.labels, datasets:[{ label:'"No"', data: chartData.mesNo.data, backgroundColor: colors.secondary }] }, options:{ indexAxis:'y', responsive:true, maintainAspectRatio:false, scales:{ x:{ beginAtZero:true, title:{display:true,text:'Quantitat "No"'} } }, plugins:{ legend:{display:false}, title:{display:true,text:'Preguntes amb més respostes NO'}, tooltip: {...tooltipCallbacks, fullLabels: chartData.mesNo.fullLabels} } } }); }
		if (chartData.pilarsSiNoPmaPercent?.labels?.length) { new Chart('chartPilarsSiNoPmaPercent', { type: 'bar', data: { labels: chartData.pilarsSiNoPmaPercent.labels, datasets: [ { label: 'Sí (%)', data: chartData.pilarsSiNoPmaPercent.dataSiPercent, backgroundColor: colors.primary }, { label: 'No (amb pla) (%)', data: chartData.pilarsSiNoPmaPercent.dataPmaPercent, backgroundColor: colors.primary_light }, { label: 'No (sense pla) (%)', data: chartData.pilarsSiNoPmaPercent.dataNoPercent, backgroundColor: colors.secondary } ] }, options: { responsive: true, maintainAspectRatio: false, scales: { x: { stacked: true, title: { display: true, text: 'Pilars' } }, y: { stacked: true, beginAtZero: true, max: 100, title: { display: true, text: 'Percentatge Respostes (%)' }, ticks: { callback: (v) => v + "%" } } }, plugins: { legend: { position: 'top' }, title: { display: true, text: 'Respostes Principals per Pilar (%)' }, tooltip: { callbacks: { label: (c) => `\${c.dataset.label || ''}: \${c.parsed.y.toFixed(1)}%` } } } } }); }
		if (chartData.estatEquipaments?.data?.some(d => d > 0)) { new Chart('chartEstatEquipaments', { type: 'doughnut', data: { labels: chartData.estatEquipaments.labels, datasets: [{ label: 'Nre. Equipaments', data: chartData.estatEquipaments.data, backgroundColor: [ colors.primary, colors.primary_light, colors.secondary ], hoverOffset: 4 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' }, title: { display: true, text: 'Estat dels Equipaments' }, tooltip: { callbacks: { label: function(c) { const label = Array.isArray(c.label) ? c.label.join(' ') : c.label; return `\${label || ''}: \${c.parsed || 0} (\${(chartData.estatEquipaments.dataPercent[c.dataIndex] || 0).toFixed(1)}%)`; } } } } } }); }
	}
	
	// --- LÒGICA COMPARTIDA PER A LES TAULES ---
	function setupTable(config) {
		if (!Array.isArray(config.data) || config.data.length === 0) {
			if(config.controlsContainer) config.controlsContainer.style.display = 'none';
			if(config.table && config.table.parentElement) config.table.parentElement.style.display = 'none';
			if(config.paginationContainer) config.paginationContainer.style.display = 'none';
			return;
		}
	
		const FILES_PER_PAGINA = 50;
		let currentPage = 1;
		let currentSort = { column: config.defaultSortColumn, direction: 'desc' };
		let filteredData = [...config.data];
	
		function normalizeText(text) { return typeof text === 'string' ? text.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase() : ''; }
	
		function populateSelect(selectEl, options, selectedValue = '') {
			if (!selectEl) return;
			const currentValue = selectEl.value;
			selectEl.innerHTML = '<option value="">Tots</option>';
			if (Array.isArray(options)) {
				options.forEach(optText => {
					if (optText === null || typeof optText === 'undefined') return;
					const opt = document.createElement('option');
					opt.value = String(optText); opt.textContent = String(optText);
					selectEl.appendChild(opt);
				});
			}
			selectEl.value = (options.includes(selectedValue)) ? selectedValue : (options.includes(currentValue) ? currentValue : '');
		}
  
		/* -----------------------------------------------------------------
		 * Mapa explícit de correspondències select → claus de dades
		 * -----------------------------------------------------------------*/
		const selectKeyMap = {
			municipi:           ['municipi', 'municipis'],
			espai_principal:    ['espai_principal', 'espais_principals'],
			nom_equipament:     ['nom_equipament', 'equipaments'],
			pilar:              ['pilar', 'pilars'],
			pregunta_key:       ['pregunta_key', 'preguntes'],
			username:           ['username', 'usuaris'] // per a la taula d’historial
		};
	
		Object.entries(config.filterSelects).forEach(([selectKey, selectEl]) => {
			if (!selectEl) return;
			let opcions = [];
			(selectKeyMap[selectKey] || [selectKey]).some(clau => {
				if (Array.isArray(config.initialFilterOptions[clau])) {
					opcions = config.initialFilterOptions[clau];
					return true;
				}
				return false;
			});
			populateSelect(selectEl, opcions);
		});
		/* ------ FI de la correcció ------ */
	
		function renderTable() {
			if (!config.tableBody) return;
			config.tableBody.innerHTML = '';
			const paginatedItems = filteredData.slice((currentPage - 1) * FILES_PER_PAGINA, currentPage * FILES_PER_PAGINA);
			if (paginatedItems.length === 0) {
				if (config.noResultsEl) config.noResultsEl.style.display = 'block';
				renderPagination();
				return;
			}
			if (config.noResultsEl) config.noResultsEl.style.display = 'none';
			paginatedItems.forEach((item, index) => config.renderRow(item, index));
			renderPagination();
		}
	
		function renderPagination() {
			if (!config.paginationControls) return;
			config.paginationControls.innerHTML = '';
			const totalPages = Math.ceil(filteredData.length / FILES_PER_PAGINA);
			if (totalPages <= 1) return;
		
			const createBtn = (pageNum, text, isDisabled = false, isCurrent = false, isDots = false) => {
				const el = document.createElement(isCurrent || isDots ? 'span' : 'button');
				el.textContent = text || pageNum;
				if (isCurrent) el.className = 'current-page';
				else if (isDots) el.className = 'dots';
				else { el.disabled = isDisabled; if (!isDisabled) el.addEventListener('click', () => { currentPage = pageNum; renderTable(); }); }
				return el;
			};
		
			config.paginationControls.appendChild(createBtn(currentPage - 1, 'Anterior', currentPage === 1));
			if (totalPages <= 7) {
				for (let i = 1; i <= totalPages; i++) { config.paginationControls.appendChild(createBtn(i, i, false, i === currentPage)); }
			} else {
				config.paginationControls.appendChild(createBtn(1, '1', false, currentPage === 1));
				if (currentPage > 3) config.paginationControls.appendChild(createBtn(0, '...', false, false, true));
				for (let i = Math.max(2, currentPage - 1); i <= Math.min(totalPages - 1, currentPage + 1); i++) { config.paginationControls.appendChild(createBtn(i, i, false, i === currentPage)); }
				if (currentPage < totalPages - 2) config.paginationControls.appendChild(createBtn(0, '...', false, false, true));
				config.paginationControls.appendChild(createBtn(totalPages, totalPages, false, currentPage === totalPages));
			}
			config.paginationControls.appendChild(createBtn(currentPage + 1, 'Següent', currentPage === totalPages));
		}
	
		function applyFiltersAndSearch(resetPage = true) {
			if (resetPage) currentPage = 1;
			const searchVal = config.searchInput ? normalizeText(config.searchInput.value) : '';
			const filterValues = Object.fromEntries(Object.entries(config.filterSelects).filter(([key, el]) => el).map(([key, el]) => [key, el.value]));
			
			filteredData = config.data.filter(item => {
				const searchMatch = !searchVal || config.searchFields.some(field => normalizeText(item[field]).includes(searchVal));
				const filtersMatch = Object.entries(filterValues).every(([key, val]) => !val || String(item[key]) === String(val));
				
				if (config.dateFromInput && config.dateToInput) {
					const dateFrom = config.dateFromInput.value;
					const dateTo = config.dateToInput.value;
					const itemDate = item.hora_submissio?.split(' ')[0] || '';
					if (dateFrom && itemDate < dateFrom) return false;
					if (dateTo && itemDate > dateTo) return false;
				}
	
				return searchMatch && filtersMatch;
			});
	
			sortData();
			renderTable();
		}
	
		function sortData() {
			if (!currentSort.column) return;
			filteredData.sort((a, b) => {
				const valA = a[currentSort.column]; const valB = b[currentSort.column];
				let comparison = String(valA).localeCompare(String(valB), undefined, { numeric: true, sensitivity: 'base' });
				return currentSort.direction === 'desc' ? comparison * -1 : comparison;
			});
		}
	
		if (config.searchInput) config.searchInput.addEventListener('input', () => applyFiltersAndSearch());
		Object.values(config.filterSelects).filter(el => el).forEach(sel => sel.addEventListener('change', () => applyFiltersAndSearch()));
		if (config.dateFromInput) config.dateFromInput.addEventListener('change', () => applyFiltersAndSearch());
		if (config.dateToInput) config.dateToInput.addEventListener('change', () => applyFiltersAndSearch());
		
		if (config.resetButton) config.resetButton.addEventListener('click', () => {
			if (config.searchInput) config.searchInput.value = '';
			Object.values(config.filterSelects).filter(el => el).forEach(sel => sel.value = '');
			if (config.dateFromInput) config.dateFromInput.value = '';
			if (config.dateToInput) config.dateToInput.value = '';
			applyFiltersAndSearch();
		});
	
		sortData();
		renderTable();
	}
	
	document.addEventListener('DOMContentLoaded', function() {
		createTooltipElement();
		initializeDashboard();
	
		setupTable({
			data: accionsMilloraData,
			defaultSortColumn: 'any_compromis',
			table: document.getElementById('accions-millora-table'),
			tableBody: document.querySelector('#accions-millora-table tbody'),
			tableHeaders: document.querySelectorAll('#accions-millora-table th[data-sort-by]'),
			searchInput: document.getElementById('actions-search-input'),
			filterSelects: {
				municipi: document.getElementById('filter-municipi'),
				espai_principal: document.getElementById('filter-espai'),
				nom_equipament: document.getElementById('filter-equipament'),
				pilar: document.getElementById('filter-pilar'),
				pregunta_key: document.getElementById('filter-pregunta'),
			},
			initialFilterOptions: opcionsInicialsFiltres,
			resetButton: document.getElementById('reset-filters-button'),
			noResultsEl: document.getElementById('actions-table-no-results'),
			paginationControls: document.getElementById('pagination-controls-actions'),
			controlsContainer: document.querySelector('.actions-table-controls'),
			paginationContainer: document.getElementById('pagination-controls-container-actions'),
			searchFields: ['codi_equipament', 'nom_equipament', 'municipi', 'accio_text', 'accio_id_curt', 'any_compromis'],
			renderRow: (item, index) => {
				const r = document.querySelector('#accions-millora-table tbody').insertRow();
				if (index % 2 === 1) { r.style.backgroundColor = '#f9f9f9'; }
				r.insertCell().textContent = item.codi_equipament || '-';
				r.insertCell().textContent = item.nom_equipament || '-';
				r.insertCell().textContent = item.municipi || '-';
				const acCell = r.insertCell();
				acCell.appendChild(document.createTextNode(item.accio_id_curt || '-'));
				if (item.accio_text) {
					const infoIcon = document.createElement('span');
					infoIcon.className = 'info-icon'; infoIcon.innerHTML = 'ⓘ';
					infoIcon.addEventListener('mouseover', (e) => showTooltip(e, item.accio_text));
					infoIcon.addEventListener('mousemove', (e) => positionTooltip(e));
					infoIcon.addEventListener('mouseout', hideTooltip);
					acCell.appendChild(infoIcon);
				}
				r.insertCell().textContent = item.any_compromis || '-';
				if (item.url_formulari) {
					r.classList.add('clickable-row');
					r.addEventListener('click', () => window.open(item.url_formulari, '_blank'));
				}
			}
		});
	
		setupTable({
			data: historyData,
			defaultSortColumn: 'hora_submissio',
			table: document.getElementById('history-table'),
			tableBody: document.querySelector('#history-table tbody'),
			tableHeaders: document.querySelectorAll('#history-table th[data-sort-by]'),
			searchInput: document.getElementById('history-search-input'),
			filterSelects: {
				username: document.getElementById('history-filter-user'),
				municipi: document.getElementById('history-filter-municipi'),
				nom_equipament: document.getElementById('history-filter-equipament'),
			},
			dateFromInput: document.getElementById('history-filter-date-from'),
			dateToInput: document.getElementById('history-filter-date-to'),
			initialFilterOptions: {
				username: opcionsFiltresHistorial.usuaris,
				municipi: opcionsFiltresHistorial.municipis,
				nom_equipament: opcionsFiltresHistorial.equipaments
			},
			resetButton: document.getElementById('history-reset-filters-button'),
			noResultsEl: document.getElementById('history-table-no-results'),
			paginationControls: document.getElementById('pagination-controls-history'),
			controlsContainer: document.querySelector('.history-table-controls'),
			paginationContainer: document.getElementById('pagination-controls-container-history'),
			searchFields: ['hora_submissio', 'codi_equipament', 'username', 'nom_equipament', 'municipi', 'summary'],
			renderRow: (item, index) => {
				const tableBody = document.querySelector('#history-table tbody');
				const r = tableBody.insertRow();
				if (index % 2 === 1) { r.style.backgroundColor = '#f9f9f9'; }
	
				if (item.url_formulari) {
					r.classList.add('clickable-row');
					r.addEventListener('click', (e) => {
						if (e.target.tagName.toLowerCase() !== 'button') {
						   window.open(item.url_formulari, '_blank');
						}
					});
				}
				r.insertCell().textContent = new Date(item.hora_submissio.replace(' ', 'T')).toLocaleString('ca-ES') || '-';
				r.insertCell().textContent = item.codi_equipament || '-';
				if(isAdmin) r.insertCell().textContent = item.username || '-';
				r.insertCell().textContent = item.nom_equipament || '-';
				r.insertCell().textContent = item.municipi || '-';
				
				const summaryCell = r.insertCell();
				summaryCell.classList.add('history-summary-cell');
				
				const summarySpan = document.createElement('span');
				summarySpan.textContent = item.summary;
				summaryCell.appendChild(summarySpan);
				
				if (item.details && item.details.length > 0) {
					const detailsButton = document.createElement('button');
					detailsButton.textContent = 'Veure Detalls';
					detailsButton.classList.add('details-toggle-button');
					summaryCell.appendChild(detailsButton);
	
					const detailsRow = tableBody.insertRow();
					detailsRow.classList.add('history-details-row');
					detailsRow.style.display = 'none';
					if (index % 2 === 1) { detailsRow.style.backgroundColor = '#f9f9f9'; }
	
					const detailsCell = detailsRow.insertCell();
					const colSpan = isAdmin ? 6 : 5;
					detailsCell.setAttribute('colspan', colSpan);
	
					let detailsHtml = '<ul class="history-details-list">';
					item.details.forEach(change => {
						detailsHtml += `<li><strong>\${change.field}:</strong> <span class="change-from">\${change.from}</span> → <span class="change-to">\${change.to}</span></li>`;
					});
					detailsHtml += '</ul>';
					detailsCell.innerHTML = detailsHtml;
	
					detailsButton.addEventListener('click', () => {
						const isVisible = detailsRow.style.display !== 'none';
						detailsRow.style.display = isVisible ? 'none' : '';
						detailsButton.textContent = isVisible ? 'Veure Detalls' : 'Amagar Detalls';
					});
				}
			}
		});
	});
	})();
JS;
  }
  
              														
  private function getBasicAttached(): array {
	return ['library' => ['core/jquery', 'core/drupal'],'html_head' => [[['#tag'=>'script','#attributes' => ['src'=>'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js','crossorigin'=>'anonymous',]],'chartjs-cdn']],];
  }
  private function getQuestionColumns(): array { return ['participacio_P1','participacio_P2','participacio_P3','accessibilitat_A1','accessibilitat_A2','accessibilitat_A3','accessibilitat_A4','igualtat_I1','igualtat_I2','igualtat_I3','igualtat_I4','sostenibilitat_S1','sostenibilitat_S2','sostenibilitat_S3','sostenibilitat_S4',]; }
  private function getPilarFromColumn(string $col): ?string { if(str_starts_with($col,'participacio_'))return 'Participació'; if(str_starts_with($col,'accessibilitat_'))return 'Accessibilitat'; if(str_starts_with($col,'igualtat_'))return 'Igualtat'; if(str_starts_with($col,'sostenibilitat_'))return 'Sostenibilitat'; return NULL; }
  private function normalitzaText(string $cadena): string { if($cadena==='')return'';$n=mb_strtolower($cadena,'UTF-8');if(class_exists('\Normalizer')){$n=\Normalizer::normalize($n,\Normalizer::FORM_D);$n=preg_replace('/\p{Mn}/u','',$n);}$n=str_replace([' ',"'",'-'],'_',$n);$n=preg_replace('/[^a-z0-9_]/','',$n);return trim($n,'_'); }
}