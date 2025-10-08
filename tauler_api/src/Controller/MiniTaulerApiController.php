<?php

namespace Drupal\tauler_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\tauler\Controller\TaulerController; // Necessari per al constructor

class MiniTaulerApiController extends ControllerBase implements ContainerInjectionInterface {

  protected $originalTaulerController; 
  protected $currentUser;
  protected $database;

  public function __construct(TaulerController $originalTaulerController, AccountInterface $currentUser, Connection $database) {
    $this->originalTaulerController = $originalTaulerController;
    $this->currentUser = $currentUser;
    $this->database = $database;
  }

  public static function create(ContainerInterface $container) {
    $originalTaulerController = TaulerController::create($container);
    return new static(
      $originalTaulerController,
      $container->get('current_user'),
      $container->get('database')
    );
  }

// Funció sencera per reemplaçar a MiniTaulerApiController.php
// Funció sencera per reemplaçar a MiniTaulerApiController.php
public function getApiData() {
    $api_output_data = [
      'percent_equipaments_autoavaluats' => 0,
      'equipaments_autoavaluats_count' => 0,
      'total_equipaments_count' => 0,
      'equipaments_amb_pla_millora' => 0,
      'grafic_respostes_pilars_percent' => null,
      'grafic_participacio_municipal' => null,
      'tipologia_espais_autoavaluats' => [],
      'has_data' => false,
    ];

    try {
      $question_columns_helper = $this->getQuestionColumnsInternal();
      $form_questions_definitions_original = $this->getGroupsDefinitionInternal();

      // --- Càlcul de % Equipaments Autoavaluats (GLOBAL) ---
      $total_equipaments_global_query = $this->database->select('nou_formulari_equipaments', 'e');
      $total_equipaments_global_query->addExpression('COUNT(DISTINCT e.codi_equipament)');
      $total_equipaments_global = (int) $total_equipaments_global_query->execute()->fetchField();
      $api_output_data['total_equipaments_count'] = $total_equipaments_global;

      $query_autoavaluats_global = $this->database->select('nou_formulari_dades_formulari', 'nfdf');
      $query_autoavaluats_global->addExpression('COUNT(DISTINCT nfdf.codi_equipament)');
      $or_group_valid_global = $query_autoavaluats_global->orConditionGroup();
      foreach ($question_columns_helper as $col_name) {
        $or_group_valid_global->condition($col_name, 'Sí', '=');
        $or_group_valid_global->condition($col_name, 'No', '=');
      }
      $query_autoavaluats_global->condition($or_group_valid_global);
      $total_equipaments_autoavaluats_valids_global = (int) $query_autoavaluats_global->execute()->fetchField();
      $api_output_data['equipaments_autoavaluats_count'] = $total_equipaments_autoavaluats_valids_global;

      if ($total_equipaments_global > 0) {
        $api_output_data['percent_equipaments_autoavaluats'] = round(($total_equipaments_autoavaluats_valids_global / $total_equipaments_global) * 100);
      } else {
        $api_output_data['percent_equipaments_autoavaluats'] = 0;
      }


      // --- Càlcul d'Equipaments amb Pla Millora (GLOBAL) ---
      $query_pla_millora_global = $this->database->select('nou_formulari_dades_formulari', 'nfdf_pm');
      $query_pla_millora_global->fields('nfdf_pm');
      $all_submissions_global_pm = $query_pla_millora_global->execute()->fetchAll();
      $equipaments_amb_pla_codis_globals = [];
      foreach ($all_submissions_global_pm as $submission_pm) {
        $row_array_pm = (array) $submission_pm;
        $codi_equipament_actual_pm = trim($row_array_pm['codi_equipament'] ?? '');
        if (empty($codi_equipament_actual_pm)) continue;
        $has_subitem_row_pm = false;
        foreach ($form_questions_definitions_original as $group_key_pm => $group_data_pm) {
          $prefix_col_pm = $group_data_pm['prefix_col'];
          foreach ($group_data_pm['questions'] as $question_key_pm => $question_info_pm) {
            $pregunta_col_base_pm = $prefix_col_pm . '_' . $question_key_pm;
            if (!empty($question_info_pm['subitems'])) {
              foreach (array_keys($question_info_pm['subitems']) as $sub_key_pm) {
                $chk_field_pm = $pregunta_col_base_pm . '_subitem_' . $sub_key_pm;
                if (isset($row_array_pm[$chk_field_pm]) && $row_array_pm[$chk_field_pm] == 1) {
                  $has_subitem_row_pm = true; break 3;
                }
              }
            }
            $custom_plan_field_pm = $pregunta_col_base_pm . '_custom_plan';
            if (isset($row_array_pm[$custom_plan_field_pm]) && trim($row_array_pm[$custom_plan_field_pm]) !== '') {
              $has_subitem_row_pm = true; break 2;
            }
          }
        }
        if ($has_subitem_row_pm) {
          $equipaments_amb_pla_codis_globals[$codi_equipament_actual_pm] = true;
        }
      }
      $api_output_data['equipaments_amb_pla_millora'] = count($equipaments_amb_pla_codis_globals);

      // --- Càlcul Gràfic Respostes Principals per Pilar (%) (GLOBAL) ---
      $pilars_si_no_pma_global = [ 'Participació' => ['si' => 0, 'no' => 0, 'pma' => 0], 'Accessibilitat' => ['si' => 0, 'no' => 0, 'pma' => 0], 'Igualtat' => ['si' => 0, 'no' => 0, 'pma' => 0], 'Sostenibilitat' => ['si' => 0, 'no' => 0, 'pma' => 0], ];
      $query_submissions_valid_pilars_global = $this->database->select('nou_formulari_dades_formulari', 'nfdf_pilars');
      $query_submissions_valid_pilars_global->fields('nfdf_pilars');
      $or_group_pilars_global = $query_submissions_valid_pilars_global->orConditionGroup();
      foreach ($question_columns_helper as $col_name_pilars) {
        $or_group_pilars_global->condition($col_name_pilars, 'Sí', '=');
        $or_group_pilars_global->condition($col_name_pilars, 'No', '=');
      }
      $query_submissions_valid_pilars_global->condition($or_group_pilars_global);
      $submissions_valid_pilars_global = $query_submissions_valid_pilars_global->execute()->fetchAll();
      foreach ($submissions_valid_pilars_global as $submission_pilars) {
        $row_array_pilars = (array) $submission_pilars;
        foreach ($question_columns_helper as $col_pilars) {
          $pilar_name_global = $this->getPilarFromColumnInternal($col_pilars);
          if (!$pilar_name_global || !isset($pilars_si_no_pma_global[$pilar_name_global])) continue;
          $valor_pilars = $row_array_pilars[$col_pilars] ?? '';
          if (mb_strtolower(trim($valor_pilars), 'UTF-8') === 'sí') {
            $pilars_si_no_pma_global[$pilar_name_global]['si']++;
          } elseif (mb_strtolower(trim($valor_pilars), 'UTF-8') === 'no') {
            $pma_per_pregunta_global = false;
            foreach ($form_questions_definitions_original as $group_key_def_g => $group_data_def_g) {
              foreach($group_data_def_g['questions'] as $q_key_def_g => $q_info_def_g) {
                $current_q_col_check_g = $group_data_def_g['prefix_col'] . '_' . $q_key_def_g;
                if ($current_q_col_check_g !== $col_pilars) continue;
                if (!empty($q_info_def_g['subitems'])) {
                  foreach (array_keys($q_info_def_g['subitems']) as $sub_key_def_g) {
                    $chk_field_g = $current_q_col_check_g . '_subitem_' . $sub_key_def_g;
                    if (isset($row_array_pilars[$chk_field_g]) && $row_array_pilars[$chk_field_g] == 1) { $pma_per_pregunta_global = true; break 3; }
                  }
                }
                $custom_plan_g = $current_q_col_check_g . '_custom_plan';
                if (isset($row_array_pilars[$custom_plan_g]) && !empty(trim($row_array_pilars[$custom_plan_g]))) { $pma_per_pregunta_global = true; break 2; }
              }
            }
            if ($pma_per_pregunta_global) { $pilars_si_no_pma_global[$pilar_name_global]['pma']++; } else { $pilars_si_no_pma_global[$pilar_name_global]['no']++; }
          }
        }
      }
      $pilars_percent_data_global = [ 'labels' => array_keys($pilars_si_no_pma_global), 'dataSiPercent' => [], 'dataPmaPercent' => [], 'dataNoPercent' => [], ];
      foreach ($pilars_si_no_pma_global as $dades_pilar_g) {
        $total_respostes_pilar_g = $dades_pilar_g['si'] + $dades_pilar_g['pma'] + $dades_pilar_g['no'];
        if ($total_respostes_pilar_g > 0) {
          $pilars_percent_data_global['dataSiPercent'][] = round(($dades_pilar_g['si'] / $total_respostes_pilar_g) * 100, 1);
          $pilars_percent_data_global['dataPmaPercent'][] = round(($dades_pilar_g['pma'] / $total_respostes_pilar_g) * 100, 1);
          $pilars_percent_data_global['dataNoPercent'][] = round(($dades_pilar_g['no'] / $total_respostes_pilar_g) * 100, 1);
        } else {
          $pilars_percent_data_global['dataSiPercent'][] = 0;
          $pilars_percent_data_global['dataPmaPercent'][] = 0;
          $pilars_percent_data_global['dataNoPercent'][] = 0;
        }
      }
      $api_output_data['grafic_respostes_pilars_percent'] = $pilars_percent_data_global;

      // --- Càlcul Gràfic Participació Municipal (%) (GLOBAL) ---
      $total_municipis_sistema_query = $this->database->select('nou_formulari_equipaments', 'e_mun');
      $total_municipis_sistema_query->addExpression('COUNT(DISTINCT e_mun.municipi)');
      $total_municipis_registrats_al_sistema = (int) $total_municipis_sistema_query->execute()->fetchField();
      $query_municipis_autoavaluats_global = $this->database->select('nou_formulari_dades_formulari', 'nfdf_mun_g');
      $query_municipis_autoavaluats_global->addExpression('COUNT(DISTINCT nfdf_mun_g.municipi)');
      $or_group_mun_valid_g = $query_municipis_autoavaluats_global->orConditionGroup();
      foreach ($question_columns_helper as $col_name_mun_g) {
        $or_group_mun_valid_g->condition($col_name_mun_g, 'Sí', '=');
        $or_group_mun_valid_g->condition($col_name_mun_g, 'No', '=');
      }
      $query_municipis_autoavaluats_global->condition($or_group_mun_valid_g);
      $total_municipis_amb_avaluacio_valida_global = (int) $query_municipis_autoavaluats_global->execute()->fetchField();

      if ($total_municipis_registrats_al_sistema > 0) {
        $percent_amb_avaluacio_global = round(($total_municipis_amb_avaluacio_valida_global / $total_municipis_registrats_al_sistema) * 100, 1);
        $percent_sense_avaluacio_global = 100 - $percent_amb_avaluacio_global;
        if (($percent_amb_avaluacio_global + $percent_sense_avaluacio_global) > 100 && $percent_sense_avaluacio_global > 0) { $percent_sense_avaluacio_global = max(0, 100 - $percent_amb_avaluacio_global); }
        $percent_sense_avaluacio_global = round($percent_sense_avaluacio_global, 1);
        $api_output_data['grafic_participacio_municipal'] = [ 'labels' => [$this->t('Municipis amb autoavaluació'), $this->t('Municipis sense autoavaluació')], 'data_percent' => [$percent_amb_avaluacio_global, $percent_sense_avaluacio_global], 'data_counts' => [ 'amb_avaluacio' => $total_municipis_amb_avaluacio_valida_global, 'sense_avaluacio' => $total_municipis_registrats_al_sistema - $total_municipis_amb_avaluacio_valida_global, 'total' => $total_municipis_registrats_al_sistema ] ];
      } else {
         $api_output_data['grafic_participacio_municipal'] = [ 'labels' => [$this->t('Municipis amb autoavaluació'), $this->t('Municipis sense autoavaluació')], 'data_percent' => [0, 0], 'data_counts' => ['amb_avaluacio' => 0, 'sense_avaluacio' => 0, 'total' => 0] ];
      }

      // ===== CÀLCUL FINAL I COMPLET: Tipologia d'espais autoavaluats =====
      
      $resultats_tipologies = [];
      
      $query_all_types = $this->database->select('nou_formulari_equipaments', 'e_all');
      $query_all_types->distinct()->fields('e_all', ['espai_principal']);
      $query_all_types->condition('espai_principal', '', '<>');
      $all_types_list = $query_all_types->execute()->fetchCol();

      if (!empty($all_types_list)) {
        $query_totals_per_tipologia = $this->database->select('nou_formulari_equipaments', 'e_totals');
        $query_totals_per_tipologia->fields('e_totals', ['espai_principal']);
        $query_totals_per_tipologia->addExpression('COUNT(e_totals.codi_equipament)', 'total_count');
        $query_totals_per_tipologia->condition('e_totals.espai_principal', '', '<>');
        $query_totals_per_tipologia->groupBy('e_totals.espai_principal');
        $totals_per_tipologia_map = $query_totals_per_tipologia->execute()->fetchAllKeyed(0, 1);

        $avaluats_per_tipologia_map = [];
        if ($total_equipaments_autoavaluats_valids_global > 0) {
            $query_avaluats_per_tipologia = $this->database->select('nou_formulari_dades_formulari', 'nfdf_tipo');
            $query_avaluats_per_tipologia->fields('nfdf_tipo', ['espai_principal']);
            $query_avaluats_per_tipologia->addExpression('COUNT(DISTINCT nfdf_tipo.codi_equipament)', 'avaluats_count');
            $or_group_avaluats = $query_avaluats_per_tipologia->orConditionGroup();
            foreach ($question_columns_helper as $col) {
                $or_group_avaluats->condition($col, ['Sí', 'No'], 'IN');
            }
            $query_avaluats_per_tipologia->condition($or_group_avaluats);
            $query_avaluats_per_tipologia->condition('espai_principal', '', '<>');
            $query_avaluats_per_tipologia->groupBy('espai_principal');
            $avaluats_per_tipologia_map = $query_avaluats_per_tipologia->execute()->fetchAllKeyed(0, 1);
        }
        
        $items_processats = [];
        foreach ($all_types_list as $tipologia) {
          $recompte_avaluats = (int) ($avaluats_per_tipologia_map[$tipologia] ?? 0);
          $percentatge = ($total_equipaments_autoavaluats_valids_global > 0)
              ? round(($recompte_avaluats / $total_equipaments_autoavaluats_valids_global) * 100, 1)
              : 0.0;

          $items_processats[] = [
              'nom' => $tipologia,
              'percentatge' => $percentatge,
              'avaluats' => $recompte_avaluats,
              'total_categoria' => (int) ($totals_per_tipologia_map[$tipologia] ?? 0),
          ];
        }
        
        // ===== NOU BLOC D'AJUST PER GARANTIR SUMA 100% =====
        $suma_total_percentatges = array_sum(array_column($items_processats, 'percentatge'));
        $diferencia = 100.0 - $suma_total_percentatges;

        if ($diferencia != 0 && !empty($items_processats)) {
            // Ordenem per trobar l'element amb el percentatge més alt
            usort($items_processats, function($a, $b) {
                return $b['percentatge'] <=> $a['percentatge'];
            });
            // Apliquem la diferència al primer element (el més gran)
            $items_processats[0]['percentatge'] += $diferencia;
            // Arrodonim de nou per si l'ajust ha creat més decimals
            $items_processats[0]['percentatge'] = round($items_processats[0]['percentatge'], 1);
        }
        
        // Ordenem la llista final de més a menys percentatge per a la visualització
        usort($items_processats, function($a, $b) {
            if ($a['percentatge'] == $b['percentatge']) {
                return $b['avaluats'] <=> $a['avaluats'];
            }
            return $b['percentatge'] <=> $a['percentatge'];
        });

        $resultats_tipologies = $items_processats;
      }

      $api_output_data['tipologia_espais_autoavaluats'] = $resultats_tipologies;
      
      $api_output_data['has_data'] = true;

    } catch (\Exception $e) {
      \Drupal::logger('tauler_api')->error('Error calculant dades globals per API: @message | Fitxer: @file | Línia: @line', [ '@message' => $e->getMessage(), '@file' => $e->getFile(), '@line' => $e->getLine(), ]);
      $api_output_data['error'] = $this->t('S\'ha produït un error en calcular les estadístiques globals.');
      $api_output_data['has_data'] = false;
      $response = new CacheableJsonResponse($api_output_data, 500);
      return $response;
    }
    
    $response = new CacheableJsonResponse($api_output_data);
    $response->getCacheableMetadata()->setCacheMaxAge(0);
    $response->getCacheableMetadata()->addCacheContexts(['url.path']);

    return $response;
  }
  
  
    private function getQuestionColumnsInternal(): array {
    return [
        'participacio_P1', 'participacio_P2', 'participacio_P3',
        'accessibilitat_A1', 'accessibilitat_A2', 'accessibilitat_A3', 'accessibilitat_A4',
        'igualtat_I1', 'igualtat_I2', 'igualtat_I3', 'igualtat_I4',
        'sostenibilitat_S1', 'sostenibilitat_S2', 'sostenibilitat_S3', 'sostenibilitat_S4',
    ];
  }

  private function getGroupsDefinitionInternal() {
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

  private function getPilarFromColumnInternal(string $col): ?string {
    if(str_starts_with($col,'participacio_'))return 'Participació';
    if(str_starts_with($col,'accessibilitat_'))return 'Accessibilitat';
    if(str_starts_with($col,'igualtat_'))return 'Igualtat';
    if(str_starts_with($col,'sostenibilitat_'))return 'Sostenibilitat';
    return NULL;
  }
}