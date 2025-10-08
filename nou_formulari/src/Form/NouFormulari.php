<?php

namespace Drupal\nou_formulari\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Mail\MailFormatHelper;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Render\Markup;

/**
 * Classe principal per al Formulari d'Autoavaluació municipal.
 * Desa tant les preguntes (Sí/No) com els subítems (checkbox + date)
 * en una sola taula 'nou_formulari_dades_formulari'.
 */
class NouFormulari extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
	return 'nou_formulari';
  }

  /**
   * Carrega les dades dels equips des de la taula 'nou_formulari_equipaments' de la BD.
   *
   * @return array
   *   Llista d'equipaments amb les claus:
   *   - field_codi_equipament
   *   - field_comarca
   *   - field_municipi
   *   - field_nom_equipament
   *   - field_espai_principal
   *   - municipi_normalitzat
   */
  private function carregaDades() {
	// (Les línies amb $files només per assegurar que la variable existeix, tot i que no s'utilitza.)
	$files = [];
	$files = [];
	$files = []; // Dummy.
	$files = [];
	$files = [];
	$files = [];
	$files = []; // Assegurem-nos que la variable estigui definida.

	$query = \Drupal::database()->select('nou_formulari_equipaments', 'e');
	$query->fields('e', [
	  'codi_equipament',
	  'comarca',
	  'municipi',
	  'nom_equipament',
	  'espai_principal',
	]);
	$resultat = $query->execute();
	$rows = [];
	foreach ($resultat as $fila) {
	  $municipi = trim($fila->municipi);
	  $municipi_normalitzat = $this->normalitzaText($municipi);
	  $rows[] = [
		'field_codi_equipament' => trim($fila->codi_equipament),
		'field_comarca' => $fila->comarca,
		'field_municipi' => $municipi,
		'field_nom_equipament' => $fila->nom_equipament,
		'field_espai_principal' => $fila->espai_principal,
		'municipi_normalitzat' => $municipi_normalitzat,
	  ];
	}
	return $rows;
  }

  /**
   * Carrega de la BD la fila de dades per a un equipament concret, si existeix.
   *
   * @param string $codi_equipament
   *   El codi de l'equipament.
   *
   * @return object|null
   *   Un objecte amb totes les columnes o NULL si no existeix.
   */
  private function carregaDadesFormulariEquipament($codi_equipament) {
	// No aplicuem normalització perquè el valor s'ha desat tal com s'ha llegit de la BD.
	$query = \Drupal::database()->select('nou_formulari_dades_formulari', 't');
	$query->fields('t');
	$query->condition('codi_equipament', $codi_equipament);
	$resultat = $query->execute()->fetchAssoc();
	return !empty($resultat) ? (object) $resultat : NULL;
  }

  /**
   * Desa o actualitza a la base de dades totes les dades del formulari.
   *
   * @param array $dades_formulari
   *   L'array associatiu amb totes les dades del formulari.
   *
   * @return int|null
   *   L'ID del registre inserit o actualitzat, o NULL en cas d'error.
   */
private function guardaDadesFormulari($dades_formulari) {
	   // Escriu al log PHP per depuració.
	   error_log("Iniciant guardaDadesFormulari. Dades a desar: " . print_r($dades_formulari, TRUE));
   
	   \Drupal::logger('nou_formulari')->notice('Dades del formulari a desar: <pre>@dades</pre>', [
		 '@dades' => print_r($dades_formulari, TRUE),
	   ]);
   
	   try {
		 // Comprovem si ja existeix una entrada per aquest codi d'equipament.
		 $existing = \Drupal::database()->select('nou_formulari_dades_formulari', 't')
		   ->fields('t', ['id'])
		   ->condition('codi_equipament', $dades_formulari['codi_equipament'])
		   ->execute()
		   ->fetchField();
   
		 $id_registre_principal = NULL;
   
		 if ($existing) {
		   // Actualitzem la fila existent amb les noves dades.
		   \Drupal::database()->update('nou_formulari_dades_formulari')
			 ->fields($dades_formulari)
			 ->condition('id', $existing)
			 ->execute();
		   
		   $id_registre_principal = $existing;
   
		   \Drupal::logger('nou_formulari')->notice('Actualitzat registre existent amb ID: @id', [
			 '@id' => $id_registre_principal,
		   ]);
		   error_log("Actualitzat registre existent amb ID: " . $id_registre_principal);
		 }
		 else {
		   // Inserim una nova fila a la taula.
		   $inserted_id = \Drupal::database()->insert('nou_formulari_dades_formulari')
			 ->fields($dades_formulari)
			 ->execute();
		   
		   $id_registre_principal = $inserted_id;
   
		   \Drupal::logger('nou_formulari')->notice('Inserit nou registre amb ID: @id', [
			 '@id' => $id_registre_principal,
		   ]);
		   error_log("Inserit nou registre amb ID: " . $id_registre_principal);
		 }
   
		 // === INICI: NOVA LÒGICA PER GUARDAR A L'HISTORIAL ===
		 if ($id_registre_principal) {
		   // Preparem les dades per a la taula d'historial.
		   $dades_historial = $dades_formulari;
		   // Afegim el 'id' del registre principal que acabem d'inserir o actualitzar.
		   $dades_historial['id'] = $id_registre_principal;
   
		   // Inserim una nova fila a la taula d'historial. Mai actualitzem l'historial.
		   \Drupal::database()->insert('nou_formulari_dades_formulari_historial')
			   ->fields($dades_historial)
			   ->execute();
		   
		   \Drupal::logger('nou_formulari')->notice('Registrat canvi a l\'historial per al registre amb ID principal: @id', [
			   '@id' => $id_registre_principal,
		   ]);
		   error_log("Registrat canvi a l'historial per a l'ID principal: " . $id_registre_principal);
		 }
		 // === FI: NOVA LÒGICA PER GUARDAR A L'HISTORIAL ===
   
		 return $id_registre_principal;
   
	   }
	   catch (\Exception $e) {
		 \Drupal::messenger()->addError(
		   $this->t("No s'ha pogut desar les dades del formulari a la base de dades. Error: @missatge", [
			 '@missatge' => $e->getMessage(),
		   ])
		 );
		 \Drupal::logger('nou_formulari')->error('Error guardant formulari: @error', [
		   '@error' => $e->getMessage(),
		 ]);
		 error_log("Error guardant formulari: " . $e->getMessage());
		 return NULL;
	   }
	 }
	 
  /**
   * Obté les dades d'un equipament a partir d'una llista carregada.
   *
   * @param array $rows
   *   Llista d'equipaments.
   * @param string $codi_equipament
   *   El codi de l'equipament.
   *
   * @return array
   *   Les dades de l'equipament o un array buit si no es troba.
   */
  private function obtenirDadesEquipament($rows, $codi_equipament) {
	foreach ($rows as $row) {
	  if ((string) trim($row['field_codi_equipament']) === (string) trim($codi_equipament)) {
		return $row;
	  }
	}
	return [];
  }

  /**
   * Llista de municipis segons les comarques seleccionades i els municipis de l'usuari.
   *
   * @param array $rows
   *   Llista d'equipaments.
   * @param array $comarques
   *   Llista de comarques seleccionades.
   * @param array $user_municipis_normalitzats
   *   Municipis de l'usuari normalitzats.
   *
   * @return array
   *   Llista de municipis.
   */
  private function llistaMunicipis($rows, $comarques, $user_municipis_normalitzats) {
	$municipis = [];
	foreach ($rows as $row) {
	  if (in_array($row['field_comarca'], $comarques) && !empty($row['field_municipi'])) {
		$row_municipi_normalitzat = $row['municipi_normalitzat'];
		if (in_array($row_municipi_normalitzat, $user_municipis_normalitzats)) {
		  $municipi = $row['field_municipi'];
		  $municipis[$municipi] = $municipi;
		}
	  }
	}
	asort($municipis);
	return $municipis;
  }

  /**
   * Llista d'espais principals segons els municipis seleccionats.
   *
   * @param array $rows
   *   Llista d'equipaments.
   * @param array $municipis
   *   Municipis seleccionats.
   *
   * @return array
   *   Llista d'espais principals.
   */
  private function llistaEspaisPrincipals($rows, $municipis_seleccionats, $codis_equipaments_disponibles) {
	  $espais = [];
	  // Assegurem que $municipis_seleccionats sigui un array per evitar errors amb in_array.
	  if (!is_array($municipis_seleccionats)) {
		  $municipis_seleccionats = [];
	  }
  
	  foreach ($rows as $row) {
		// Comprovem si la fila pertany a un municipi seleccionat
		// i si l'equipament d'aquesta fila està entre els disponibles.
		if (in_array($row['field_municipi'], $municipis_seleccionats) &&
			!empty($row['field_espai_principal']) &&
			in_array($row['field_codi_equipament'], $codis_equipaments_disponibles)) {
		  $espai = $row['field_espai_principal'];
		  $espais[$espai] = $espai; // Afegim l'espai si encara no hi és
		}
	  }
	  asort($espais);
  
	  // L'opció 'Mostrar tots' s'afegeix sempre, independentment de si hi ha altres espais.
	  // Si $espais està buit, només hi haurà 'Mostrar tots'.
	  // Si l'usuari selecciona 'Mostrar tots', la lògica de llistaEquipaments
	  // ja filtra correctament i mostrarà un selector d'equipaments buit si no n'hi ha cap disponible.
	  $espais['mostrar_tots'] = $this->t('Mostrar tots');
	  return $espais;
	}

  /**
   * Llista d'equipaments segons els municipis i espai principal seleccionats.
   *
   * @param array $rows
   *   Llista d'equipaments.
   * @param array $municipis_seleccionats
   *   Municipis seleccionats.
   * @param string $espai_principal
   *   L'espai principal seleccionat o 'mostrar_tots'.
   *
   * @return array
   *   Array amb 'available' (opcions per al selector) i 'total'.
   */
  private function llistaEquipaments($rows, $municipis_seleccionats, $espai_principal) {
	$equipaments = [];
	$total_equipaments_list = [];
	$municipis_netejats = array_map([$this, 'normalitzaText'], $municipis_seleccionats);
	$espai_principal_normalitzat = $this->normalitzaText($espai_principal);
	foreach ($rows as $row) {
	  $municipi_normalitzat = $this->normalitzaText($row['field_municipi']);
	  if (in_array($municipi_normalitzat, $municipis_netejats)) {
		if ($espai_principal == 'mostrar_tots' || $this->normalitzaText($row['field_espai_principal']) == $espai_principal_normalitzat) {
		  $codi_equipament = $row['field_codi_equipament'];
		  $nom_equipament = $row['field_nom_equipament'];
		  $total_equipaments_list[$codi_equipament] = $nom_equipament;
		  $equipaments[$codi_equipament] = $nom_equipament;
		}
	  }
	}
	asort($equipaments);
	asort($total_equipaments_list);
	return [
	  'available' => $equipaments,
	  'total' => $total_equipaments_list,
	];
  }

  /**
   * Retorna els grups de preguntes i els seus subítems.
   */
protected function getGroups() {
   // OBTENIM EL SUBDIRECTORI ON ESTÀ INSTAL·LAT DRUPAL (ex: /diba)
   $base_path = \Drupal::request()->getBasePath();
   
   // Obtenim la ruta del nostre mòdul (ex: modules/custom/nou_formulari)
   $module_path = \Drupal::service('extension.list.module')->getPath('nou_formulari');
   
   // CONSTRUÏM LA RUTA COMPLETA I CORRECTA UNINT LES DUES PARTS
   $img_participatiu = $base_path . '/' . $module_path . '/images/participatiu.png';
   $img_accesible = $base_path . '/' . $module_path . '/images/accesible.png';
   $img_igualtat = $base_path . '/' . $module_path . '/images/igualtat.png';
   $img_sostenible = $base_path . '/' . $module_path . '/images/sostenible.png';
   
	  return [
		'participacio' => [
		  'title' => $this->t('<img src="@img_src" style="width: 84px; height: 84px; display: inline-block; vertical-align: middle;" alt="Icona Participatiu">
		  <h2 style="color:#ec6c67; font-size:34px; font-weight:500; display: inline-block; vertical-align: middle;margin-left:20px;">
			<strong>P</strong>articipatiu
		  </h2>', ['@img_src' => $img_participatiu]),
		  'questions' => [
			'P1' => [
			  'label' => $this->t("L’equipament disposa d’espais de comunicació i relació amb la ciutadania i entitats"),
			  'help_text_question' => $this->t("Equipament que fomenta la ciutadania activa i la participació en la seva gestió i governança per tal d’impulsar un sentit democràtic de comunitat i de responsabilitat cívica."),
			  'subitems' => [
				'P1_1' => $this->t("Disposar d’espais virtuals com bústies de suggeriments, comunitats virtuals o xarxes socials per a comunicar-vos i relacionar-vos amb la ciutadania"),
				'P1_2' => $this->t("Facilitar l’ús de sales per al desenvolupament de projectes associatius, grupals o individuals"),
				'P1_3' => $this->t("Fer petites enquestes de satisfacció d’usuaris o qualsevol altre tipus de mecanismes que doni veu als ciutadans"),
			  ],
			],
			'P2' => [
			  'label' => $this->t("La ciutadania forma part d’algun òrgan decisori o de consulta de l’equipament"),
			  'help_text_question' => $this->t("Forma part d’una xarxa ciutadana de benestar que escolta les persones que en fan ús i les seves demandes i es relaciona activament amb les entitats i altres equipaments del seu entorn."),
			  'subitems' => [
				'P2_1' => $this->t("Disposar d’espais de participació i consulta permanent com un consell, una taula, un fòrum o un comitè on es debaten i concreten propostes per millorar la gestió i ordenació dels usos i activitats de l’equipament"),
				'P2_2' => $this->t("La ciutadania i/o entitats participen en la presa de decisions ja sigui en l’aprovació d’un pla, un programa o un projecte d’actuació"),
				'P2_3' => $this->t("Realitzar accions per conèixer els interessos i/o necessitats de la ciutadania i/o entitats"),
			  ],
			],
			'P3' => [
			  'label' => $this->t("L’equipament participa d’estratègies conjuntes amb altres equipaments i serveis locals"),
			  'help_text_question' => $this->t("La participació és un dret de la ciutadania, que fa referència a la seva capacitat d’incidir d’alguna manera en els afers públics. La participació ciutadana alhora és indicador de salut democràtica, de ciutadania activa i compromesa amb els afers públics que afecten tota la societat."),
			  'subitems' => [
				'P3_1' => $this->t("Disposar d’una estructura estable, com un fòrum, un consell, una comissió on es treballa en xarxa amb els serveis que ofereix el territori en el teu àmbit"),
				'P3_2' => $this->t("Comptar amb programes d’intervenció transversals amb altres serveis del territori vinculats (joventut, esports, treball, salut, mobilitat, cultura, educació, habitatge, entitats, etc.)"),
				'P3_3' => $this->t("Participar en algun òrgan de governança de l’ens local"),
			  ],
			],
		  ],
		],
		'accessibilitat' => [
		  'title' => $this->t('<img src="@img_src" style="width: 94px; height: 94px; display: inline-block; vertical-align: middle;" alt="Icona Accessibilitat">
		  <h2 style="color:#ec6c67; font-size:34px; font-weight:500; display: inline-block; vertical-align: middle;margin-left:20px;">
			<strong>A</strong>ccessibilitat
		  </h2>', ['@img_src' => $img_accesible]),
		  'questions' => [
			'A1' => [
			  'label' => $this->t("L’equipament incorpora criteris i accions per garantir la inclusió i l’accés per igual de la ciutadania als recursos"),
			  'help_text_question' => $this->t("Equipament que garanteix l’accés universal i equitatiu a tota la ciutadania, sense cap mena de barrera ni de discriminació per motius de discapacitat, econòmics o per raons de gènere, d’edat, de nacionalitat, de color, de llengua, d’orientació sexual, de religió, etc."),
			  'subitems' => [
				'A1_1' => $this->t("Disposar de diferents elements per facilitar la inclusió: en l’estil de comunicació, el disseny dels espais, o les accions per garantir que arribem a tots els públics"),
				'A1_2' => $this->t("Fomentar una comunicació comprensible pels diferents col·lectius d’usuaris, utilitzant per exemple criteris de lectura fàcil, sistema Braille, lletra ampliada, sistemes alternatius i augmentatius de comunicació bé incorporant l’ús de diferents llengües"),
				'A1_3' => $this->t("Tenir espais i/o serveis per donar resposta als interessos i/o necessitats de la diversitat de públics, o bé el fet de realitzar accions periòdiques de crida d’usuaris per arribar a les persones que no se senten interpel·lades pels serveis oferts"),
			  ],
			],
			'A2' => [
			  'label' => $this->t("A l’equipament es programen accions accessibles a tots els col·lectius"),
			  'help_text_question' => $this->t("És inclusiu i equitatiu perquè ha corregit els desequilibris i la infrarepresentació de certs col·lectius no usuaris i promou un ús ciutadà plural i representatiu del poble / barri / ciutat, sense deixar ningú de banda ni enrere."),
			  'subitems' => [
				'A2_1' => $this->t("Realitzar accions i activitats que inclouen als diferents col·lectius de la població a la qual s’adreça l’equipament"),
				'A2_2' => $this->t("Promoure activitats intergeneracionals entre infants i gent gran"),
				'A2_3' => $this->t("Disposar de programes d’activitats adreçats a diferents col·lectius, fent incidència en aquells que requereixen especial atenció"),
			  ],
			],
			'A3' => [
			  'label' => $this->t("A l’equipament s’implementen mesures de supressió de barreres arquitectòniques i d’accessibilitat universal"),
			  'help_text_question' => $this->t("L’accessibilitat és sens dubte una de les característiques indispensables per garantir la inclusió social."),
			  'subitems' => [
				'A3_1' => $this->t("Disposar d’identificació i senyalització per garantir que externament l’edifici sigui visible i identificable i que està clarament senyalitzat als indicadors de carrers, als documents i pàgines web municipals i al propi edifici, tenint en compte els diferents sistemes de comunicació (visual, auditiu, tàctil…)"),
				'A3_2' => $this->t("Garantir que existeix algun itinerari accessible per arribar a peu a l’equipament, o que es pugui arribar en transport públic"),
				'A3_3' => $this->t("Tenir aparcaments de bicicletes, patinets i/o altres mitjans de transport sostenible"),
			  ],
			],
			'A4' => [
			  'label' => $this->t("Es posa a disposició de col·lectius vulnerables línies d’ajut econòmic per l’ús de l’equipament"),
			  'help_text_question' => $this->t("El marc normatiu (llei accessibilitat) estableix el compliment de diferents mesures que en el cas dels equipaments tenen a veure amb l’accessibilitat a l’edificació, l’accessibilitat al servei i l’accessibilitat a la comunicació vinculats als conceptes de disseny universal, supressió de barreres arquitectòniques i l’accessibilitat universal cognitiva i econòmica."),
			  'subitems' => [
				'A4_1' => $this->t("Programar activitats gratuïtes periòdiques o puntuals obertes a tothom"),
				'A4_2' => $this->t("Dissenyar diferents sistemes de beques, ajuts o tarifació social de forma que es garanteix l’oportunitat d’accés universal"),
				'A4_3' => $this->t("Fer una comunicació eficient per assegurar-nos que la informació sobre els ajuts econòmics arriba als col·lectius que ho necessitin"),
			  ],
			],
		  ],
		],
		'igualtat' => [
		  'title' => $this->t('<img src="@img_src" style="width: 90px; height: 90px; display: inline-block; vertical-align: middle;" alt="Icona Igualtat">
		  <h2 style="color:#ec6c67; font-size:34px; font-weight:500; display: inline-block; vertical-align: middle;margin-left:20px;">
			<strong>I</strong>gualtat
		  </h2>', ['@img_src' => $img_igualtat]),
		  'questions' => [
			'I1' => [
			  'label' => $this->t("Es garanteix l’accés de qualitat i equitatiu amb criteris interseccionals a l’equipament"),
			  'help_text_question' => $this->t("Equipament que garanteix i promou la igualtat i dignitat de totes les persones, que és un espai lliure de masclisme i de tota mena d’abusos, discriminacions i violències, i que fomenta la trobada i la bona convivència intergeneracional i intercultural entre veïns i veïnes."),
			  'subitems' => [
				'I1_1' => $this->t("Oferir activitats aptes per a persones de qualsevol gènere o edat"),
				'I1_2' => $this->t("Dissenyar un programa adaptat a les necessitats culturals i/o de caràcter religiós com incorporar menús halal o considerar els períodes religiosos com el Ramadà"),
				'I1_3' => $this->t("Comptar amb espais de participació amb la representació de persones, entitats o referents de diferents eixos de desigualtat"),
			  ],
			],
			'I2' => [
			  'label' => $this->t("L’equipament disposa d’un protocol d’actuació davant de les violències"),
			  'help_text_question' => $this->t("Un equipament educador en una igualtat ciutadana plena des de la diversitat i els valors cívics i democràtics."),
			  'subitems' => [
				'I2_1' => $this->t("Fer accions de comunicació i formació dels protocols d’actuació davant les violències per tal que tot l’equip els conegui"),
				'I2_2' => $this->t("Promoure la protecció de les dones i del col·lectiu LGBTIQ+"),
				'I2_3' => $this->t("Tenir referents per a la prevenció en violències de qualsevol tipus"),
			  ],
			],
			'I3' => [
			  'label' => $this->t("El personal de l’equipament està capacitat per oferir una atenció igualitària"),
			  'help_text_question' => $this->t("Els equipaments públics són llocs de trobada i socialització per a totes les persones on poder establir relacions i dur a terme activitats."),
			  'subitems' => [
				'I3_1' => $this->t("L’equip del centre rep formació per l’atenció amb tracte amable i pel foment a la diversitat"),
				'I3_2' => $this->t("Assegurar la contractació de personal acceptant la no-discriminació, no sexisme ni racisme"),
				'I3_3' => $this->t("Utilitzar un llenguatge inclusiu i adequat perquè arribi al màxim de persones i evitar estereotips i expressions o imatges sexistes, racistes, homòfobes"),
			  ],
			],
			'I4' => [
			  'label' => $this->t("L’equipament compta amb espais adequats a les necessitats de tots els col·lectius"),
			  'help_text_question' => $this->t("Per aquest motiu, els equipaments municipals han de ser espais inclusius, i el seu disseny, implantació i gestió no han de discriminar ningú i han de situar la vida al centre."),
			  'subitems' => [
				'I4_1' => $this->t("Disposar de lavabos inclusius amb una senyalització no binària que poden ser utilitzats per qualsevol persona, independentment de la sua identitat o expressió de gènere"),
				'I4_2' => $this->t("Disposar de vestidors o espais destinats a canviar-se de roba, que atenguin a la diversitat (home, dona, no binari, familiars, etc.)"),
				'I4_3' => $this->t("Disposar d’espais de lactància"),
			  ],
			],
		  ],
		],
		'sostenibilitat' => [
		  'title' => $this->t('<img src="@img_src" style="width: 104px; height: 104px; display: inline-block; vertical-align: middle;" alt="Icona Sostenibilitat">
		  <h2 style="color:#ec6c67; font-size:34px; font-weight:500; display: inline-block; vertical-align: middle;margin-left:20px;">
			<strong>S</strong>ostenibilitat
		  </h2>', ['@img_src' => $img_sostenible]),
		  'questions' => [
			'S1' => [
			  'label' => $this->t("A l’equipament es realitzen accions potenciadores de consciència ecològica i de promoció d’hàbits i valors en matèria de sostenibilitat ambiental"),
			  'help_text_question' => $this->t("Impulsa la consciència ecològica a partir de acció educadora i la promoció d’hàbits i valors en matèria de sostenibilitat."),
			  'subitems' => [
				'S1_1' => $this->t("L’equipament disposa de panells, rètols electrònics o pantalles que informen sobre les condicions d’humitat i temperatura dels espais interiors i de la producció d’energia d’origen renovable generada pel propi equipament"),
				'S1_2' => $this->t("Es promou la informació i la consciència ambiental i energètica de persones externes implicades d’una manera o altra amb l’equipament: usuaris, proveïdors, empreses subcontractades, etc."),
				'S1_3' => $this->t("Proporciona formació ambiental i energètica al personal de l’equipament per aconseguir la seva implicació cap a la reducció de consum energètic i de materials"),
			  ],
			],
			'S2' => [
			  'label' => $this->t("A l’equipament s’apliquen accions de reciclatge i reutilització dels seus materials i dels residus"),
			  'help_text_question' => $this->t("Equipament sostenible i ambientalment responsable, compromès amb la lluita contra el canvi climàtic i amb l’eficiència energètica i l’economia circular."),
			  'subitems' => [
				'S2_1' => $this->t("L’equipament incentiva la reparació i reutilització dels materials utilitzats, a fi de reduir els residus generats"),
				'S2_2' => $this->t("Disposar de punts de recollida selectiva de residus reciclables en una situació visible i accessible per als veïns"),
				'S2_3' => $this->t("L’equipament reutilitza material usat d’altres espais o equipaments municipals, donant-los-hi una segona vida"),
			  ],
			],
			'S3' => [
			  'label' => $this->t("A l’equipament es promouen actuacions per millorar l’eficiència energètica i reduir els consums"),
			  'help_text_question' => $this->t("Garantir la sostenibilitat necessita urgentment d’uns equipaments ambientalment responsables, compromesos amb l’eficiència energètica i l’economia circular."),
			  'subitems' => [
				'S3_1' => $this->t("Disposar de l’etiqueta energètica en un lloc visible i/o pot ser consultada telemàticament pels usuaris i la resta de la comunitat"),
				'S3_2' => $this->t("Implementar mesures concretes d’estalvi energètic i de reducció de consum propi com, per exemple: substitució de l’enllumenat existent per un de nou amb tecnologia LED, la instal·lació de captadors o reductors de cabal a les aixetes"),
				'S3_3' => $this->t("Tenir les dades del consum energètic periòdic (diari, mensual, anual) i fer el seguiment de les mateixes"),
			  ],
			],
			'S4' => [
			  'label' => $this->t("A l’equipament es duen a terme accions de renaturalització dels espais, de pacificació de l’entorn i de transformació del seu context urbà o natural"),
			  'help_text_question' => $this->t("En aquest sentit, els equipaments no només han de fer una gestió responsable dels recursos necessaris per al seu funcionament, sinó que també han d’exercir un rol de sensibilització a la població en matèria ambiental."),
			  'subitems' => [
				'S4_1' => $this->t("L’equipament ha (re)naturalitzat en els darrers anys els espais a l’aire lliure (com són patis, terrasses, piscines, etc.) o l’entorn urbà proper a l’equipament"),
				'S4_2' => $this->t("L’entorn de l’equipament ha estat pacificat de trànsit mitjançant carrers residencials o de prioritat invertida, zones de vianants, camins escolars, carrils bici, o altres"),
				'S4_3' => $this->t("Disposar d’espais que disposin de condicions de confort tèrmic en episodis de temperatures extremes i que puguin ser considerats refugis climàtics"),
			  ],
			],
		  ],
		],
	  ];
	}	   
  /**
   * Desa la selecció de municipis i espai en un magatzem temporal
   * i retorna un token curt que la identifica.
   */
  private function desaSeleccioToken(array $municipis, $espai) {
	$store = \Drupal::keyValueExpirable('nou_formulari_sel');
	$token = substr(hash('sha1', serialize($municipis) . $espai . microtime()), 0, 10);
	$store->setWithExpire($token, [
	  'municipis' => $municipis,
	  'espai'     => $espai,
	], 86400); // 24 h
	return $token;
  }
  
  /**
   * Recupera la selecció (municipis i espai) a partir del token “sel”.
   */
  private function recuperaSeleccioToken($token) {
	$store = \Drupal::keyValueExpirable('nou_formulari_sel');
	$dades = $store->get($token);
	return $dades ?: ['municipis' => [], 'espai' => ''];
  }
  
  
  
  
  
  
  
  
  
  
  
  
  /**
   * Funció per treure accents, apòstrofs i convertir a minúscules.
   */
  private function normalitzaText($cadena_input) {
	// Assegurem que treballem amb string
	$cadena = (string) $cadena_input;
  
	if ($cadena === '') {
	  return '';
	}
  
	// Convertir a minúscules (millor fer-ho aviat)
	$n = mb_strtolower($cadena, 'UTF-8');
  
	// Normalització Unicode per treure accents (si la classe Normalizer existeix)
	if (class_exists('\Normalizer')) {
	  $n = \Normalizer::normalize($n, \Normalizer::FORM_D);
	  $n = preg_replace('/\p{Mn}/u', '', $n);
	}
	// Per si Normalizer no està disponible, intent alternatiu (menys perfecte)
	else {
		$originals = 'ÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÐÑÒÓÔÕÖØÙÚÛÜÝÞßàáâãäåæçèéêëìíîïðñòóôõöøùúûýýþÿŔŕ';
		$modificats = 'AAAAAAACEEEEIIIIDNOOOOOOUUUUYbsaaaaaaaceeeeiiiidnoooooouuuuyybyRr';
		$n = strtr($n, $originals, $modificats);
	}
  
	// Substituir espais, apòstrofs i guions per guions baixos
	$n = str_replace([' ', "'", '-'], '_', $n);
  
	// Eliminar qualsevol caràcter que no sigui lletra, número o guió baix
	$n = preg_replace('/[^a-z0-9_]/u', '', $n);
  
	// Eliminar guions baixos múltiples o al principi/final
	$n = preg_replace('/__+/', '_', $n); // Opcional: reemplaçar múltiples _ per un de sol
	$n = trim($n, '_');
  
	return $n;
  }

  /**
   * {@inheritdoc}
   */
  /**
	  * {@inheritdoc}
	  */
public function buildForm(array $form, FormStateInterface $form_state) {
					  
		  /* ------------------------------------------------------------------
		   * 0) Control d’accés
		   * ----------------------------------------------------------------- */
		  $current_user = \Drupal::currentUser();
		  $uid          = $current_user->id();
		
		  if ($current_user->isAnonymous()) {
			$form['message'] = [
			  '#type'   => 'markup',
			  '#markup' => $this->t('Necessites estar loguejat per poder enviar el Formulari.
				Per fer el login clica <a href="/ca/user/login">aquí</a>.'),
			];
			return $form;
		  }
		
		  $account = \Drupal\user\Entity\User::load($uid);
		  if (
			!$account ||
			!$account->hasField('field_municipi_user') ||
			$account->get('field_municipi_user')->isEmpty()
		  ) {
			$form['message'] = [
			  '#type'   => 'markup',
			  '#markup' => $this->t('No ets un usuari autoritzat.'),
			];
			return $form;
		  }
	  
		  /* ------------------------------------------------------------------
		   * NOU: Títol i descripció principals del formulari
		   * ----------------------------------------------------------------- */
		  $form['main_title'] = [
			  '#type'   => 'markup',
			  '#markup' => '<div class="custom-title">' . $this->t('Formulari d’autoavaluació municipal') . '</div>',
		  ];
		
		  $form['main_description_text'] = [
			  '#type'   => 'markup',
			  '#markup' => '<div class="custom-description">' . $this->t('El formulari és un instrument de suport al procés d’autoavaluació. Ajuda a identificar quina és la situació actual de l’equipament avaluat respecte els quatre pilars, quines mesures ja té consolidades i en quins aspectes encara hi ha marge de millora. Responeu “sí” o “no” a les 15 preguntes que trobareu a continuació.') . '</div>',
		  ];
		
		  /* ------------------------------------------------------------------
		   * 1) Municipis de l’usuari
		   * ----------------------------------------------------------------- */
		  $municipis_user              = [];
		  $municipis_user_normalitzats = [];
		  foreach ($account->get('field_municipi_user')->getValue() as $valor) {
			$m = str_replace('_', ' ', trim($valor['value']));
			if ($m !== '') {
			  $municipis_user[]              = $m;
			  $municipis_user_normalitzats[] = $this->normalitzaText($m);
			}
		  }
		
		  /* ------------------------------------------------------------------
		   * 2) Valors de la query string per recordar seleccions
		   * ----------------------------------------------------------------- */
		  $request   = \Drupal::request();
		  $token_qs  = $request->query->get('sel');
		  $equip_qs  = $request->query->get('equipament_seleccionat');
		
		  if ($token_qs) {
			$recup        = $this->recuperaSeleccioToken($token_qs);
			$municipis_qs = !empty($recup['municipis'])
			  ? implode('|', $recup['municipis'])
			  : '';
			$espai_qs     = $recup['espai'] ?? '';
		  }
		  else {
			$municipis_qs = $request->query->get('municipis');
			$espai_qs     = $request->query->get('espai_principal');
		  }
		
		  /* ------------------------------------------------------------------
		   * 3) Carreguem dades i valors inicials
		   * ----------------------------------------------------------------- */
		  $dades                  = $this->carregaDades();
		  $equipament_seleccionat = (!$form_state->isSubmitted() && $equip_qs)
			? $equip_qs
			: NULL;
		  $row_data = $equipament_seleccionat
			? $this->carregaDadesFormulariEquipament($equipament_seleccionat)
			: NULL;
		
		  /* ------------------------------------------------------------------
		   * 4) Capçalera i botó per admins
		   * ----------------------------------------------------------------- */
		 $legend_items = [
			  ['icon' => '   🟢   ', 'text' => $this->t('Enviat i Completat (Tot Sí)')],
			  ['icon' => '   |   🟡   ', 'text' => $this->t('Enviat (amb algun "No")')],
			  ['icon' => '   |   🔴   ', 'text' => $this->t('Pendent d\'enviar   |')],
			  ['icon' => '   ⚠   ', 'text' => $this->t('Enviat (respostes pendents)'), 'color_class' => 'icon-legend-warning-custom-color'], // Nova clau 'color_class'
			];
		 
			$legend_html = '<div class="icon-legend-nouformulari" style="margin-top: 20px; padding: 10px; background-color: #f9f9f9; border-radius: 4px; display: flex; flex-wrap: wrap; gap: 15px; font-size: 0.9em; align-items: center;">';
			foreach ($legend_items as $item) {
			  $icon_text = $item['icon'];
			  $text_label = $item['text'];
			  
			  $icon_span_class = ''; // Classe per a l'span de la icona
			  $icon_base_style = 'font-size: 1.2em; margin-right: 5px;'; // Estil base per a totes les icones
		 
			  if (isset($item['color_class'])) {
				$icon_span_class = ' class="' . $item['color_class'] . '"'; // Assignem la classe si està definida
			  }
		 
			  $legend_html .= '<span style="display: flex; align-items: center;">' .
								'<span' . $icon_span_class . ' style="' . $icon_base_style . '">' . $icon_text . '</span>' .
								'<span class="legend-text">' . $text_label . '</span>' .
							  '</span>';
			}
			$legend_html .= '</div>';
		  
		  
			$form['description'] = [
			  '#type'   => 'markup',
			  '#markup' => '<div class="custom-description">' . // S'ha mantingut .custom-description, es pot revisar si cal un altre nom
				$this->t('Ajuda a identificar la situació actual de l’equipament respecte als quatre pilars. Responeu “sí” o “no” a les 15 preguntes. <br> <br> <span class="icona-info">ⓘ&nbsp;&nbsp;</span> <em>En aquelles preguntes que respongueu “NO” se us obrirà un desplegable en el que podreu triar quina o quines accions de millora us comprometeu a realitzar per tal d’assolir la resposta afirmativa a aquella qüestió, i en quin horitzó . El conjunt de totes les accions de millora que establiu formarà el vostre “Pla de Millora” particularitzat.</em><br><br>') .
				$legend_html . 
				'</div>',
			];
			if ($current_user->hasRole('administrator')) {
			$csv_url = \Drupal\Core\Url::fromRoute('csv_convert.page');
			$form['csv_button'] = [
			  '#type'       => 'link',
			  '#title'      => $this->t('Descarregar CSV/Full de càlcul'),
			  '#url'        => $csv_url,
			  '#attributes' => ['class' => ['button', 'button--primary']],
			];
		  }
		
		  /* ------------------------------------------------------------------
		   * 5) Comarques de l’usuari i contenidor principal
		   * ----------------------------------------------------------------- */
		  $comarques_user = [];
		  foreach ($dades as $fila) {
			if (
			  in_array($fila['municipi_normalitzat'], $municipis_user_normalitzats) &&
			  $fila['field_comarca']
			) {
			  $comarques_user[$fila['field_comarca']] = $fila['field_comarca'];
			}
		  }
		  $comarques_options       = array_unique($comarques_user);
		  $comarques_seleccionades = array_keys($comarques_options);
		
		  $form['fields_wrapper'] = [
			'#type'       => 'container',
			'#tree'       => TRUE,
			'#prefix'     => '<div id="fields-wrapper">',
			'#suffix'     => '</div>',
			'#attributes' => ['class' => ['formulari-en-columnes']],
		  ];
		  $form['fields_wrapper']['equipament_seleccionat_hidden'] = [
			'#type'          => 'hidden',
			'#default_value' => $equipament_seleccionat,
		  ];
		  $form['fields_wrapper']['comarca'] = [
			'#type'    => 'select',
			'#title'   => $this->t('Comarca'),
			'#options' => $comarques_options,
			'#default_value' => $comarques_seleccionades,
			'#multiple'      => TRUE,
			'#disabled'      => TRUE,
		  ];
		
		  /* ------------------------------------------------------------------
		   * 6) MUNICIPI – record de selecció
		   * ----------------------------------------------------------------- */
		  $municipis_options = $this->llistaMunicipis(
			$dades,
			$comarques_seleccionades,
			$municipis_user_normalitzats
		  );
		
		  $municipis_sel = $form_state->getValue(['fields_wrapper', 'municipi']);
		  if (!$municipis_sel) {
			$municipis_sel = $municipis_qs
			  ? array_values(
				  array_intersect(explode('|', $municipis_qs), array_keys($municipis_options))
				)
			  : array_keys($municipis_options);
			$form_state->setValue(['fields_wrapper', 'municipi'], $municipis_sel);
		  }
		
		  $form['fields_wrapper']['municipi'] = [
			'#type'    => 'select',
			'#title'   => $this->t('Municipi'),
			'#prefix'  => '<div id="municipi-wrapper">',
			'#suffix'  => '</div>',
			'#options' => $municipis_options,
			'#default_value' => $municipis_sel,
			'#multiple'      => TRUE,
			'#ajax' => [
			  'callback' => '::actualitzaEspaisPrincipals',
			  'wrapper'  => 'fields-wrapper', 
			  'event'    => 'change',
			  'progress' => [
				'type'    => 'throbber',
				'message' => $this->t('Carregant espais principals...'),
			  ],
			],
		  ];
		
		  /* ------------------------------------------------------------------
		   * 7) ESPAI PRINCIPAL – record de selecció
		   * ----------------------------------------------------------------- */
			$current_municipis_sel_array = is_array($municipis_sel) ? $municipis_sel : [];
			$tots_equipaments_dels_municipis_actuals = [];
			foreach ($dades as $fila) {
			  if (in_array($fila['field_municipi'], $current_municipis_sel_array)) {
				$tots_equipaments_dels_municipis_actuals[$fila['field_codi_equipament']] = $fila['field_nom_equipament'];
			  }
			}
		  
			$equipaments_processats_per_filtre_espais = $this->carregaEquipamentsTotesPreguntesSi($tots_equipaments_dels_municipis_actuals);
		  
			$codis_equipaments_disponibles_en_municipis = array_keys(
			  array_diff_key($tots_equipaments_dels_municipis_actuals, $equipaments_processats_per_filtre_espais)
			);
		  
			$espais_opts = $this->llistaEspaisPrincipals($dades, $current_municipis_sel_array, $codis_equipaments_disponibles_en_municipis);
		  
			$espai_principal_sel = $form_state->getValue(['fields_wrapper', 'espai_principal']);
		  
			if ($espai_principal_sel === NULL) { 
			  if ($espai_qs && isset($espais_opts[$espai_qs])) { 
				$espai_principal_sel = $espai_qs;
			  }
			  else {
				$espai_principal_sel = NULL;
			  }
			}
			elseif (!isset($espais_opts[$espai_principal_sel])) {
			  $espai_principal_sel = NULL;
			  $form_state->setValue(['fields_wrapper', 'espai_principal'], NULL);
			}
		  
			if ($equipament_seleccionat) {
			  $espai_principal_sel = NULL;
			  $form_state->setValue(['fields_wrapper', 'espai_principal'], NULL);
			}
		  
			$form['fields_wrapper']['espai_principal'] = [
			  '#type'          => 'select',
			  '#title'         => $this->t('Espai Principal'),
			  '#prefix'        => '<div id="espai_principal-wrapper">',
			  '#suffix'        => '</div>',
			  '#options'       => $espais_opts,
			  '#empty_option'  => $this->t('- Selecciona un Espai Principal -'),
			  '#default_value' => $espai_principal_sel,
			  '#disabled'      => FALSE, 
			  '#ajax' => [
				'callback' => '::actualitzaEquipaments',
				'wrapper'  => 'fields-wrapper', 
				'event'    => 'change',
				'progress' => [
				  'type'    => 'throbber',
				  'message' => $this->t('Carregant equipaments...'),
				],
			  ],
			];
		
		  /* ------------------------------------------------------------------
		   * 8) SELECTOR D’EQUIPAMENTS
		   * ----------------------------------------------------------------- */
		  $form['fields_wrapper']['equipament_wrapper'] = [
			'#type'   => 'container',
			'#prefix' => '<div id="equipament-wrapper">',
			'#suffix' => '</div>',
		  ];
		  $form['fields_wrapper']['equipament_wrapper']['equipament'] = [
			'#type'         => 'select',
			'#title'        => $this->t('Equipament'),
			'#options'      => [],
			'#empty_option' => $this->t('- Selecciona un Equipament -'),
			'#ajax' => [
			  'callback' => '::actualitzaFormulari',
			  'wrapper'  => 'form-wrapper', 
			  'event'    => 'change',
			  'progress' => [
				'type'    => 'throbber',
				'message' => $this->t('Carregant dades...'),
			  ],
			],
		  ];
		  $form['fields_wrapper']['equipament_wrapper']['dades_equipament'] = [
			'#type'   => 'container',
			'#prefix' => '<div id="dades-equipament-wrapper">',
			'#suffix' => '</div>',
		  ];
		
		  /* ------------------------------------------------------------------
		   * 8.0) FILTRAT D’EQUIPAMENTS ENVIATS
		   * ----------------------------------------------------------------- */
		  $equip_opts = [];
		  if ($espai_principal_sel) {
			$equip_opts_all = $this->llistaEquipaments(
			  $dades,
			  $municipis_sel,
			  $espai_principal_sel
			)['available'];
			$equipaments_ja_processats_per_selector = $this->carregaEquipamentsTotesPreguntesSi($equip_opts_all);
			$equip_opts          = array_diff_key($equip_opts_all, $equipaments_ja_processats_per_selector);
		
			$form['fields_wrapper']['equipament_wrapper']['equipament']['#options'] = $equip_opts;
		  }
		
		  $equip_sel =
			$equipament_seleccionat
			  ? NULL
			  : $form_state->getValue(['fields_wrapper', 'equipament_wrapper', 'equipament']);
		
		  if ($equip_sel && !isset($equip_opts[$equip_sel]) && $espai_principal_sel) {
			$equip_sel = NULL;
			$form_state->setValue(
			  ['fields_wrapper', 'equipament_wrapper', 'equipament'],
			  NULL
			);
		  }
		  $form['fields_wrapper']['equipament_wrapper']['equipament']['#default_value'] =
			$equip_sel;
		
		  /* ------------------------------------------------------------------
		   * 8.1) INFO EQUIPAMENT
		   * ----------------------------------------------------------------- */
		  $equipament_per_info = $equipament_seleccionat;
		  if ($equipament_per_info) {
			$dades_equ = $this->obtenirDadesEquipament($dades, $equipament_per_info);
			if ($dades_equ) {
			  $nom_net  = \Drupal\Component\Utility\Xss::filter(
				$dades_equ['field_nom_equipament'],
				[]
			  );
			  $codi_net = \Drupal\Component\Utility\Xss::filter(
				$dades_equ['field_codi_equipament'],
				[]
			  );
		
			  $form['fields_wrapper']['equipament_wrapper']['dades_equipament'] = [
				'#type' => 'container',
				'#prefix' => '<div id="dades-equipament-wrapper">',
				'#suffix' => '</div>',
				'#attributes' => ['class' => ['amb-dades-equipament']],
			  ];
		
			  $form['fields_wrapper']['equipament_wrapper']['dades_equipament']['info'] = [
				'#type'     => 'inline_template',
				'#template' => '
				  <div><strong class="equipament-color">{{ label_code }}:</strong> <span class="codi-equipament-color">{{ code }}</span></div>
				  <div><strong class="equipament-color">{{ label_name }}:</strong> <span class="equipament-color">{{ name }}</span></div>',
				'#context'  => [
				  'label_code' => $this->t('Codi Equipament'),
				  'code'       => $codi_net,
				  'label_name' => $this->t('Nom Equipament'),
				  'name'       => $nom_net,
				],
			  ];
			}
		  }
		
		  /* ------------------------------------------------------------------
		   * 9) COMPTADORS (existent i nou)
		   * ----------------------------------------------------------------- */
		  $form['fields_wrapper']['comptadors_globals_container'] = [
			  '#type' => 'container',
			  '#attributes' => ['class' => ['comptadors-globals-container']],
		  ];
		
		  $equipaments_totals_del_filtre = $this->llistaEquipaments($dades, $municipis_sel, 'mostrar_tots')['total'];
		  $equipaments_amb_alguna_resposta_significativa = $this->carregaEquipamentsTotesPreguntesSi($equipaments_totals_del_filtre);
		
		  $estats_detallats_equipaments_processats = [];
		  if (!empty($equipaments_amb_alguna_resposta_significativa)) {
			  $estats_detallats_equipaments_processats = $this->classificaEstatDetallatEquipaments(
				  array_keys($equipaments_amb_alguna_resposta_significativa),
				  $equipaments_totals_del_filtre 
			  );
			  uasort($estats_detallats_equipaments_processats, function ($a, $b) {
				  return strcmp($b['hora_submissio'], $a['hora_submissio']);
			  });
		  }
		
		  $items_counter_primer_desplegable = [];
		  $comptador_processats_primer_desplegable = 0;
	  
		  foreach ($estats_detallats_equipaments_processats as $codi => $estat_info) {
			  $comptador_processats_primer_desplegable++;
			  $nom_net = \Drupal\Component\Utility\Xss::filter($estat_info['nom'], []);
	  
			  $params_url = ['equipament_seleccionat' => $codi];
			  $token_actual_qs = $request->query->get('sel');
			  if ($token_actual_qs) {
				  $params_url['sel'] = $token_actual_qs;
			  }
			  $edit_url = Url::fromRoute('<current>', [], ['query' => $params_url])->toString();
			  $edit_link_html = ' <a class="edit-link" href="' . $edit_url . '" title="' . $this->t('Editar') . '">✎</a>';
	  
			  if ($estat_info['estat'] === 'TOT_SI') {
				  $items_counter_primer_desplegable[] = [
					  '#markup' => '<div class="equipament-enviat">' .
								   '<span class="tick">🟢</span> ' . $nom_net . $edit_link_html .
								   '</div>',
				  ];
			  }
			  else { 
				  $items_counter_primer_desplegable[] = [
					  '#markup' => '<div class="equipament-taronja">' .
								   '<span class="warning-sign">🟡</span> ' . $nom_net . $edit_link_html .
								   '</div>',
				  ];
			  }
		  }
	  
		  $equipaments_pendents_globals = array_diff_key(
			  $equipaments_totals_del_filtre,
			  $estats_detallats_equipaments_processats 
		  );
		  asort($equipaments_pendents_globals); 
	  
		  foreach ($equipaments_pendents_globals as $codi => $nom_equipament_actual) {
			  $nom_net = \Drupal\Component\Utility\Xss::filter($nom_equipament_actual, []);
			  $items_counter_primer_desplegable[] = [
				  '#markup' => '<div class="equipament-pendent">' .
							   '<span class="cross">🔴</span> ' . $nom_net .
							   '</div>',
			  ];
		  }
		  
		  $form['fields_wrapper']['comptadors_globals_container']['comptador_enviats_wrapper'] = [
				'#type' => 'container',
				'#attributes' => ['class' => ['comptador-individual-wrapper']],
			];
			$form['fields_wrapper']['comptadors_globals_container']['comptador_enviats_wrapper']['equipaments_counter'] = [
				'#type'  => 'details',
				'#title' => $this->t('@enviats / @total equipaments processats', [
					'@enviats' => $comptador_processats_primer_desplegable,
					'@total'   => count($equipaments_totals_del_filtre),
				]),
				'#open' => FALSE,
				'#attributes' => ['class' => ['equipaments-counter']], 
				'items_container' => [ 
				  '#type' => 'container',
				  '#attributes' => ['class' => ['equipaments-list-primer', 'direct-markup-list']], 
				],
			];
			if (!empty($items_counter_primer_desplegable)) {
			  foreach ($items_counter_primer_desplegable as $key_item => $item_render_array) {
				  $form['fields_wrapper']['comptadors_globals_container']['comptador_enviats_wrapper']['equipaments_counter']['items_container']['item_' . $key_item] = $item_render_array;
			  }
			}
		
		  $equipaments_incomplets_segon_desplegable = $this->carregaEquipamentsAmbRespostesPendents($equipaments_amb_alguna_resposta_significativa);
		  
		  $items_counter_incompletes = [];
		  asort($equipaments_incomplets_segon_desplegable);
		  foreach ($equipaments_incomplets_segon_desplegable as $codi => $nom) {
			  $params_url = ['equipament_seleccionat' => $codi];
			  $token_actual_qs = $request->query->get('sel');
			  if ($token_actual_qs) {
				  $params_url['sel'] = $token_actual_qs;
			  }
			  $edit_url = Url::fromRoute('<current>', [], ['query' => $params_url])->toString();
			  $items_counter_incompletes[] = [
				  '#markup' =>
				  '<div class="equipament-incomplet">' 
				  . '<span class="warning-sign">⚠</span> ' 
				  . \Drupal\Component\Utility\Xss::filter($nom, [])
				  . ' <a class="edit-link" href="' . $edit_url . '" title="' . $this->t('Completar') . '">'
				  . '✎</a>'
				  . '</div>',
			  ];
		  }
		
		  $form['fields_wrapper']['comptadors_globals_container']['comptador_incomplets_wrapper'] = [
				'#type' => 'container',
				'#attributes' => ['class' => ['comptador-individual-wrapper']],
			];
			$form['fields_wrapper']['comptadors_globals_container']['comptador_incomplets_wrapper']['equipaments_incomplets_counter'] = [
				'#type'  => 'details',
				'#title' => $this->t('@count equipaments enviats amb respostes pendents', [
					'@count' => count($equipaments_incomplets_segon_desplegable),
				]),
				'#open' => FALSE, 
				'#attributes' => ['class' => ['equipaments-counter', 'equipaments-incomplets-counter']], 
				'items_container' => [ 
				  '#type' => 'container',
				  '#attributes' => ['class' => ['equipaments-list-incomplets', 'direct-markup-list']], 
				],
			];
			if (!empty($items_counter_incompletes)) {
			  foreach ($items_counter_incompletes as $key_item_inc => $item_render_array_inc) {
				  $form['fields_wrapper']['comptadors_globals_container']['comptador_incomplets_wrapper']['equipaments_incomplets_counter']['items_container']['item_' . $key_item_inc] = $item_render_array_inc;
			  }
			}
		
		  /* ------------------------------------------------------------------
		   * 10) Grup de preguntes
		   * ----------------------------------------------------------------- */
		  $groups = $this->getGroups();
		  $options_anys_compromis = array_combine(range(2025, 2030), range(2025, 2030));
	  
		  foreach ($groups as $gk => $gdata) {
			$form[$gk] = [
			  '#type'  => 'fieldset',
			  '#title' => $gdata['title'],
			  '#tree'  => TRUE,
			];
	  
			// JA NO NECESSITEM help_text_for_question_group aquí
	  
			foreach ($gdata['questions'] as $qk => $qinfo) {
			  $fname = $gk . '_' . $qk;
			  $df    = isset($row_data->{$fname}) ? $row_data->{$fname} : NULL;
		
			  // === INICI MODIFICACIÓ PER A ICONA D'AJUDA PER PREGUNTA INDIVIDUAL ===
			  $qinfo_label_string = (string) $qinfo['label']; 
			  $help_icon_html = '';
	  
			  // Agafem el text d'ajuda directament de la informació de la pregunta
			  $help_text_for_this_question = $qinfo['help_text_question'] ?? ''; 
	  
			  if (!empty($help_text_for_this_question)) {
				  $help_text_string_for_tooltip = (string) $help_text_for_this_question; 
				  
				  $help_icon_html = '<span class="nou-formulari-help-tooltip-wrapper">' .
									  '<span class="nou-formulari-help-icon">?</span>' .
									  '<span class="nou-formulari-help-tooltiptext">' . $help_text_string_for_tooltip . '</span>' .
									'</span>';
			  }
			  
			  $final_title_markup = Markup::create($qinfo_label_string . $help_icon_html);
			  // === FI MODIFICACIÓ ===
	  
			  $form[$gk][$fname] = [
				'#type'    => 'radios',
				'#title'   => $final_title_markup, // Títol amb la icona d'ajuda
				'#options' => ['Sí' => $this->t('Sí'), 'No' => $this->t('No')],
			  ];
		
			  if (in_array($df, ['Sí', 'No'], TRUE)) {
				$form[$gk][$fname]['#default_value'] = $df;
			  }
		
			  if (!empty($qinfo['subitems'])) {
				$swrap = $fname . '_subitems_wrapper';
		
				$total_subitems_possibles = count($qinfo['subitems']) + 1;
				$marcats_inicialment = 0;
		
				foreach ($qinfo['subitems'] as $sk_calc => $slab_calc) {
					$chk_calc_name = $fname . '_subitem_' . $sk_calc;
					$dat_calc_name = $chk_calc_name . '_date';
					$es_marcat_std = (isset($row_data->{$dat_calc_name}) && $row_data->{$dat_calc_name} !== '') ? 1 
										: (isset($row_data->{$chk_calc_name}) ? $row_data->{$chk_calc_name} : 0);
					if ($es_marcat_std) {
						$marcats_inicialment++;
					}
				}
		
				$custom_plan_db_field = $fname . '_custom_plan';
				$def_plan_custom_calc = isset($row_data->{$custom_plan_db_field}) ? $row_data->{$custom_plan_db_field} : '';
				if ($def_plan_custom_calc !== '') {
					$marcats_inicialment++;
				}
		
				$titol_base_details_text = $this->t('Accions de millora');
				$comptador_text = '';
				if ($marcats_inicialment > 0) {
					$comptador_text = $marcats_inicialment . '/' . $total_subitems_possibles;
				}
				
				$form[$gk][$swrap] = [
				  '#type'  => 'details',
				  '#title' => [ 
					'#markup' => '<span class="details-title-base">' . $titol_base_details_text . '</span>' .
								 '<span class="details-title-counter">' . $comptador_text . '</span>',
				  ],
				  '#open'  => FALSE,
				  '#states'=> [
					'visible' => [
					  ':input[name="' . $gk . '[' . $fname . ']"]' => ['value' => 'No'],
					],
				  ],
				  '#attributes' => [ 
					  'class' => ['accions-millora-details'], 
					  'data-total-subitems' => $total_subitems_possibles,
					  'data-title-base-text' => $titol_base_details_text, 
				  ],
				];
	  
				foreach ($qinfo['subitems'] as $sk => $slab) {
				  $chk = $fname . '_subitem_' . $sk;
				  $dat = $chk . '_date';
				  $def_chk_subitem = (isset($row_data->{$dat}) && $row_data->{$dat} !== '') ? 1 : (isset($row_data->{$chk}) ? $row_data->{$chk} : 0);
				  $def_dat_subitem_bd = isset($row_data->{$dat}) ? $row_data->{$dat} : '';
	  
				  $default_any_select = '2025';
				  if (!empty($def_dat_subitem_bd) && isset($options_anys_compromis[(string)$def_dat_subitem_bd])) {
					  $default_any_select = (string)$def_dat_subitem_bd;
				  }
	  
				  $form[$gk][$swrap][$chk] = [
					'#type'          => 'checkbox',
					'#title'         => $slab,
					'#default_value' => $def_chk_subitem,
					'#attributes'    => ['class' => ['subitem-action-checkbox']],
				  ];
		
				  $form[$gk][$swrap][$dat] = [
					'#type'          => 'select',
					'#title'         => $this->t('Any de compromís'),
					'#options'       => $options_anys_compromis,
					'#default_value' => $default_any_select,
					'#attributes'    => [
					  'class' => ['any-compromis-select'],
					  'title' => $this->t('Selecciona l\'any de compromís'),
					],
					'#states'        => [
					  'visible' => [
						':input[name="' . $gk . '[' . $swrap . '][' . $chk . ']"]' => [
						  'checked' => TRUE,
						],
					  ],
					],
				  ];
				}
		
				$custom_chk  = $fname . '_custom_chk';
				$custom_plan = $fname . '_custom_plan';
				$custom_date = $custom_plan . '_date';
		
				$def_plan_custom = isset($row_data->{$custom_plan}) ? $row_data->{$custom_plan} : '';
				$def_date_custom_bd = isset($row_data->{$custom_date}) ? $row_data->{$custom_date} : '';
				$def_chk_val_custom  = $def_plan_custom !== '' ? 1 : 0;
		
				$default_custom_any_select = '2025';
				if (!empty($def_date_custom_bd) && isset($options_anys_compromis[(string)$def_date_custom_bd])) {
					$default_custom_any_select = (string)$def_date_custom_bd;
				}
	  
				$form[$gk][$swrap][$custom_chk] = [
				  '#type'          => 'checkbox',
				  '#title'         => $this->t('Altres'),
				  '#default_value' => $def_chk_val_custom,
				  '#attributes'    => [
					  'class' => [ 
						  'personalitzada-chk',
						  'subitem-action-checkbox', 
						  'custom-action-checkbox',  
					  ],
				  ],
				];
		
				$form[$gk][$swrap][$custom_plan] = [
				  '#type'          => 'textarea',
				  '#title'         => $this->t('Descripció de la millora'),
				  '#default_value' => $def_plan_custom,
				  '#rows'          => 3,
				  '#states'        => [
					'visible' => [
					  ':input[name="' . $gk . '[' . $swrap . '][' . $custom_chk . ']"]' => [
						'checked' => TRUE,
					  ],
					],
				  ],
				];
		
				$form[$gk][$swrap][$custom_date] = [
				  '#type'          => 'select',
				  '#title'         => $this->t('Any de compromís'),
				  '#options'       => $options_anys_compromis,
				  '#default_value' => $default_custom_any_select,
				  '#attributes'    => [
					'class' => ['any-compromis-select'],
					'title' => $this->t('Selecciona l\'any de compromís'),
				  ],
				  '#states'        => [
					'visible' => [
					  ':input[name="' . $gk . '[' . $swrap . '][' . $custom_chk . ']"]' => [
						'checked' => TRUE,
					  ],
					],
				  ],
				];
			  }
			}
		  }
		
		  $form['#attached']['html_head'][] = [
			[
			  '#tag'   => 'style',
			  '#value' => $this->getCustomCss() . $this->getAdditionalCustomCssForCounter(),
			],
			'inline_css_nou_formulari',
		  ];
		  
		  $form['#attached']['library'][] = 'nou_formulari/nou_formulari_js'; 
		  $form['#attached']['library'][] = 'core/drupal.once'; 
	  
		  $form['#prefix'] = '<div id="form-wrapper">';
		  $form['#suffix'] = '</div>';
		  $form['submit'] = [
			'#type'  => 'submit',
			'#value' => $this->t('Desar'),
		  ];
		
		  return $form;
	  }							

        /**
		 * Funció auxiliar per afegir CSS específic per al comptador.
		 * Això manté el getCustomCss() original més net.
		 */
		private function getAdditionalCustomCssForCounter() {
		  return '
			.details-title-base {
			  /* Estils per al text base "Accions de millora" si cal */
			}
			.details-title-counter {
			  margin-left: 8px; /* Espai entre el text i el comptador */
			  font-weight: bold; /* Comptador en negreta */
			  /* Pots afegir més estils aquí, com un color diferent si vols */
			  /* color: #555; */
			}
		  ';
		}
							/* Funció per obtenir el CSS personalitzat.
   */
private function getCustomCss() {
	  return '
		/* CSS personalitzat */
		.nou-formulari {
		  display: flex;
		  flex-direction: column;
		  margin: 0 auto;
		}
		.fieldgroup {
		  margin: 0 !important;
		  padding: 20px !important;
		  display: flex;
		  justify-content: space-between;
		  align-items: flex-end;
		  border-bottom: solid 2px #fff !important;
		}
		legend .fieldset-legend {
		  margin-top: 20px !important;
		  width: 80%;
		  max-width: 80%;
		}
		#edit-sostenibilitat, #edit-accessibilitat {
		  background-color: #f9d2d0; 
		  border: none;
		  border-radius: 0;
		  margin-bottom: 20px;
		  padding: 20px;
		}
		#edit-igualtat, #edit-participacio {
		  background-color: #fae1e0; 
		  border: none;
		  border-radius: 0;
		  margin-bottom: 20px;
		  padding: 20px;
		}
		#edit-participacio-participacio-p1, #edit-participacio-participacio-p2, #edit-participacio-participacio-p3,
		#edit-sostenibilitat-sostenibilitat-s1, #edit-sostenibilitat-sostenibilitat-s2, #edit-sostenibilitat-sostenibilitat-s3, #edit-sostenibilitat-sostenibilitat-s4,
		#edit-igualtat-igualtat-i1, #edit-igualtat-igualtat-i2, #edit-igualtat-igualtat-i3, #edit-igualtat-igualtat-i4,
		#edit-accessibilitat-accessibilitat-a1, #edit-accessibilitat-accessibilitat-a2, #edit-accessibilitat-accessibilitat-a3, #edit-accessibilitat-accessibilitat-a4 {
		  display: flex;
		  gap: 10px;
		}
		.formulari-en-columnes { /* Aquest és #fields-wrapper */
		  display: flex;
		  flex-wrap: wrap; /* Permet que els elements passin a la següent línia si no caben */
		  gap: 20px; /* Espai entre selectors de comarca, municipi, etc. */
		}
		#fields-wrapper > div[id$="-wrapper"]:not(#equipament_wrapper):not(#dades-equipament-wrapper) {
		   width: calc(25% - 15px); /* (100% / 4 selectors) - part del gap */
		   min-width: 200px; /* Evita que siguin massa petits */
		}
		 #fields-wrapper #equipament_wrapper { /* El selector dequipament */
		   width: calc(25% - 15px);
		   min-width: 200px;
		}
   
   
		/* --- ESTATS PER ALS COMPTADORS --- */
		.comptadors-globals-container {
		  display: flex;
		  flex-wrap: wrap; 
		  gap: 20px; 
		  width: 100%; 
		  margin-bottom: 20px; 
		}
		.comptador-individual-wrapper {
		  flex: 1; 
		  min-width: 300px; 
		}
		
		.form-control {
		  border: solid 1px #ec6c67 !important;
		  border-radius: 3px !important;
		}
		label.option {
		  background-color: #fff;
		  border-radius: 8px;
		  padding: 10px 24px;
		  display: inline;
		  font-weight: normal;
		}
		input[type="radio"]:checked + label {
		  cursor: pointer !important;
		  background-color: #ec6c67 !important;
		  color: #fff !important;
		}
		input[type="radio"]:hover + label {
		  cursor: pointer !important;
		  background-color: #ec6c67 !important;
		  color: #fff !important;
		}
		.form-radio {
		  display: none !important;
		}
		form input[type="submit"] {
		  background-color: rgba(194, 15, 47, 0.90);
		  color: white;
		  padding: 20px 40px;
		  border: none;
		  border-radius: 8px;
		  cursor: pointer;
		  font-size: 1.2em;
		  margin-left: auto; 
		  display: block; 
		  text-align: center;
		  width: 100%; 
		}
		form input[type="submit"]:hover {
		  background-color: black;
		}
		.equipament-color, .codi-equipament-color {
		  color: #c20f2f;
		}
		.custom-title {
		  color: #c20f2f;
		  font-size: 2.5em;
		  font-weight: bold;
		}
		.custom-description {
		  color: black;
		  font-size: 1.2em;
		  margin-top: 10px;
		  margin-bottom: 20px;
		}
		select option {
		  color: black;
		}
		select option[disabled] {
		  color: gray;
		  opacity: 0.6;
		}
		.equipaments-counter summary {
		  font-size: 1.2em;
		  cursor: pointer;
		  list-style: none; 
		}
		.equipaments-counter summary::-webkit-details-marker {
		  display: none; 
		}
		.equipaments-counter summary:focus {
		   outline: none; 
		}
		.equipaments-counter summary::before { 
		  content: " ▼"; 
		  font-size: 0.8em;
		  margin-right: 5px;
		  display: inline-block;
		}
		.equipaments-counter[open] summary::before {
		  content: " ▲"; 
		}
   
		/* Estils per als estats dels equipaments al desplegable */
		.equipament-enviat { /* Verd */
		  color: green;
		  display: flex;
		  align-items: center;
		  justify-content: space-between; 
		  padding: 2px 0; 
		}
		.equipament-pendent { /* Vermell */
		  color: red;
		  display: flex;
		  align-items: center;
		  justify-content: space-between;
		  padding: 2px 0;
		}
		.equipament-taronja { /* Taronja - NOU */
		  color: #ffaf00; /* Color taronja sol·licitat */
		  display: flex;
		  align-items: center;
		  justify-content: space-between;
		  padding: 2px 0;
		}
   
		.equipament-enviat .tick, 
		.equipament-pendent .cross,
		.equipament-taronja .warning-sign { /* Icones */
		  margin-right: 6px;
		}
		.equipament-taronja .warning-sign { /* Assegurem color icona taronja */
		  color: #ffaf00;
		}
   
		.equipament-enviat:hover, 
		.equipament-pendent:hover {
		  background-color: #f1f1f1;
		}
		.equipament-taronja:hover {
		  background-color: #fff8e1; /* Taronja pàl·lid per hover */
		}
		
		/* Estils per al desplegable d equipaments incomplets (segon desplegable) */
		.equipament-incomplet { /* Aquest ja té un color taronjós, però el mantenim per si és diferent */
		  color: #b75600; 
		  display: flex;
		  align-items: center;
		  justify-content: space-between;
		  padding: 2px 0;
		}
		.equipament-incomplet .warning-sign {
		  margin-right: 6px;
		}
		.equipament-incomplet:hover {
		  background-color: #fff3e0; 
		}
		
		.form-type-checkbox label,
		.form-type-radios legend, /* Aplicar també a la llegenda dels radios */
		.form-type-radios label { /* I als labels individuals dels radios si es vol */
		  white-space: normal !important; 
		  vertical-align: middle !important;
		  overflow: visible !important;
		}
		label.option { 
		  line-height: 30px !important;
		}
		.form-check-input[type="checkbox"] {
		  border: 1.5px solid black;
		}
		.fieldgroup { 
		  border-top: solid 2px #fff !important;
		  border-bottom: none !important;
		  margin-top: 20px;
		  padding-top: 20px;
		}
		
		.amb-dades-equipament { 
		  padding: 15px;
		  border: solid 1px #ec6c67;
		  border-radius: 3px;
		  margin-top: 10px;
		  background-color: #fff8f7; 
		}
		
		.edit-link { 
			margin-left: 8px;
			text-decoration: none;
			color: #007bff; 
		  }
		  .edit-link:hover {
			text-decoration: underline;
			color: #0056b3;
		  }
		  .equipaments-list-primer ul, .equipaments-list-incomplets ul {
			  list-style-type: none;
			  padding-left: 0;
		  }
		  
		  .icona-info {
			color: #c20f2f;
		  }
		  
		  .icon-legend-nouformulari .icon-legend-warning-custom-color {
			 color: #b75600; 
		   }
		  
		   /* Estils per a la icona d\'ajuda i el tooltip */
		   .nou-formulari-help-tooltip-wrapper {
			   position: relative;
			   display: inline-block;
			   margin-left: 8px; 
			   vertical-align: middle; 
			   cursor: help;
		   }
		   .nou-formulari-help-icon {
			   font-weight: bold;
			   border: 1px solid #767676;
			   border-radius: 50%;
			   padding: 2px 6.5px; 
			   font-size: 0.9em; 
			   background-color: #f0f0f0;
			   color: #333;
			   line-height: 1.2; 
			   position: relative;
			   top: -3px;
		   }
		   .nou-formulari-help-tooltiptext {
			   visibility: hidden;
			   width: 550px; 
			   background-color: #333;
			   color: #fff;
			   text-align: left;
			   border-radius: 4px;
			   padding: 10px;
			   position: absolute;
			   z-index: 1000; 
			   bottom: 135%; 
			   left: 50%;
			   transform: translateX(-50%);
			   opacity: 0;
			   transition: opacity 0.3s ease-in-out, visibility 0s linear 0.3s; 
			   font-weight: normal;
			   font-size: 15px; /* Pots ajustar la mida de la font aquí */
			   line-height: 1.5;
			   box-shadow: 0 2px 5px rgba(0,0,0,0.2);
			   white-space: normal; 
		   }
		   .nou-formulari-help-tooltiptext::after { 
			   content: "";
			   position: absolute;
			   top: 100%; 
			   left: 50%;
			   transform: translateX(-50%);
			   border-width: 6px;
			   border-style: solid;
			   border-color: #333 transparent transparent transparent; 
		   }
		   .nou-formulari-help-tooltip-wrapper:hover .nou-formulari-help-tooltiptext {
			   visibility: visible;
			   opacity: 1;
			   transition-delay: 0s; 
		   }
		   .form-type-radios > legend {
			   display: inline; 
		   }
	  ';
	}	 
	 
	 /**
		* Classifica l'estat detallat dels equipaments basant-se en les seves respostes.
		*
		* Per a un conjunt de codis d'equipaments que se sap que tenen almenys una
		* resposta vàlida a la base de dades.
		*
		* @param array $codis_equipaments_a_classificar
		*   Array de codis d'equipament a classificar.
		* @param array $noms_equipaments_totals
		*   Array associatiu [codi_equipament => nom_equipament] amb tots els equipaments
		*   del filtre actual, per poder recuperar el nom.
		*
		* @return array
		*   Array associatiu [codi_equipament => ['nom' => nom, 'estat' => estat_string]],
		*   on estat_string pot ser:
		*   - 'TOT_SI': Totes les preguntes principals són "Sí".
		*   - 'ALGUN_NO': Alguna pregunta és "No" (i cap és "No respost").
		*   - 'ALGUN_NO_RESPOST': Alguna pregunta és "No respost".
		*   - '' (string buit): En cas d'error o estat no determinable (no hauria de passar).
		*/
private function classificaEstatDetallatEquipaments(array $codis_equipaments_a_classificar, array $noms_equipaments_totals) {
			if (empty($codis_equipaments_a_classificar)) {
			  return [];
			}
		
			$connection = \Drupal::database();
			$query = $connection->select('nou_formulari_dades_formulari', 't');
			$camps_preguntes_principals = [
			  'participacio_P1', 'participacio_P2', 'participacio_P3',
			  'accessibilitat_A1', 'accessibilitat_A2', 'accessibilitat_A3', 'accessibilitat_A4',
			  'igualtat_I1', 'igualtat_I2', 'igualtat_I3', 'igualtat_I4',
			  'sostenibilitat_S1', 'sostenibilitat_S2', 'sostenibilitat_S3', 'sostenibilitat_S4',
			];
			// Afegim 'hora_submissio' als camps a recuperar
			$query->fields('t', array_merge(['codi_equipament', 'hora_submissio'], $camps_preguntes_principals));
			$query->condition('codi_equipament', $codis_equipaments_a_classificar, 'IN');
			$resultats_db = $query->execute()->fetchAllAssoc('codi_equipament');
		
			$estats_classificats = [];
		
			foreach ($codis_equipaments_a_classificar as $codi) {
			  $nom_equipament = $noms_equipaments_totals[$codi] ?? $this->t('Nom desconegut');
		
			  if (!isset($resultats_db[$codi])) {
				continue;
			  }
		
			  $registre = $resultats_db[$codi];
			  $te_no_respost = FALSE;
			  $te_no = FALSE;
			  
			  foreach ($camps_preguntes_principals as $camp) {
				$valor_camp = $registre->$camp ?? NULL;
				if ($valor_camp === 'No respost') {
				  $te_no_respost = TRUE;
				  break; 
				}
				if ($valor_camp === 'No') {
				  $te_no = TRUE;
				}
			  }
		
			  $estat_final = '';
			  if ($te_no_respost) {
				$estat_final = 'ALGUN_NO_RESPOST';
			  }
			  elseif ($te_no) { 
				$estat_final = 'ALGUN_NO';
			  }
			  else {
				$tots_son_si = TRUE;
				foreach ($camps_preguntes_principals as $camp) {
				  if (($registre->$camp ?? NULL) !== 'Sí') {
					$tots_son_si = FALSE;
					break;
				  }
				}
				if ($tots_son_si) {
				  $estat_final = 'TOT_SI';
				}
				else {
				  $estat_final = 'ALGUN_NO_RESPOST'; 
				}
			  }
		
			  $estats_classificats[$codi] = [
				'nom' => $nom_equipament,
				'estat' => $estat_final,
				'hora_submissio' => $registre->hora_submissio ?? '0000-00-00 00:00:00', // Guardem la data de submissió
			  ];
			}
			return $estats_classificats;
		  }	 
  /**
   * Retorna un array amb els equips que tenen TOTES les preguntes a "Sí".
   */
private function carregaEquipamentsTotesPreguntesSi(array $llista_equipaments) {
	   $connection = \Drupal::database();
   
	   $query = $connection->select('nou_formulari_dades_formulari', 't');
	   $query->fields('t', [
		 'codi_equipament',
		 'nom_equipament',
		 'participacio_P1', 'participacio_P2', 'participacio_P3',
		 'accessibilitat_A1', 'accessibilitat_A2', 'accessibilitat_A3', 'accessibilitat_A4',
		 'igualtat_I1', 'igualtat_I2', 'igualtat_I3', 'igualtat_I4',
		 'sostenibilitat_S1', 'sostenibilitat_S2', 'sostenibilitat_S3', 'sostenibilitat_S4',
	   ]);
	   $resultats  = $query->execute()->fetchAll();
	   $camps_clau = [
		 'participacio_P1', 'participacio_P2', 'participacio_P3',
		 'accessibilitat_A1', 'accessibilitat_A2', 'accessibilitat_A3', 'accessibilitat_A4',
		 'igualtat_I1', 'igualtat_I2', 'igualtat_I3', 'igualtat_I4',
		 'sostenibilitat_S1', 'sostenibilitat_S2', 'sostenibilitat_S3', 'sostenibilitat_S4',
	   ];
	   $enviats = [];
   
	   foreach ($resultats as $registre) {
		 $hi_ha_resposta = FALSE;
		 foreach ($camps_clau as $camp) {
		   if (
			 isset($registre->$camp) &&
			 $registre->$camp !== '' &&
			 $registre->$camp !== 'No respost'
		   ) {
			 $hi_ha_resposta = TRUE;
			 break;
		   }
		 }
   
		 if (
		   $hi_ha_resposta &&
		   array_key_exists($registre->codi_equipament, $llista_equipaments)
		 ) {
		   $enviats[$registre->codi_equipament] = $registre->nom_equipament;
		 }
	   }
   
	   return $enviats;
	 }

/**
   * Carrega els equipaments que ja han estat "enviats" (tenen alguna resposta Sí/No)
   * però que encara tenen alguna pregunta principal amb "No respost".
   *
   * @param array $equipaments_enviats_amb_nom
   *   Un array d'equipaments ja considerats "enviats", en format [codi_equipament => nom_equipament].
   *   Normalment, el resultat de carregaEquipamentsTotesPreguntesSi().
   *
   * @return array
   *   Un array d'equipaments amb respostes pendents, en format [codi_equipament => nom_equipament].
   */
  private function carregaEquipamentsAmbRespostesPendents(array $equipaments_enviats_amb_nom) {
	if (empty($equipaments_enviats_amb_nom)) {
	  return [];
	}

	$connection = \Drupal::database();
	$query = $connection->select('nou_formulari_dades_formulari', 't');

	// Camps de les preguntes principals a verificar per "No respost"
	// (mateixos camps que a carregaEquipamentsTotesPreguntesSi)
	$camps_preguntes_principals = [
	  'participacio_P1', 'participacio_P2', 'participacio_P3',
	  'accessibilitat_A1', 'accessibilitat_A2', 'accessibilitat_A3', 'accessibilitat_A4',
	  'igualtat_I1', 'igualtat_I2', 'igualtat_I3', 'igualtat_I4',
	  'sostenibilitat_S1', 'sostenibilitat_S2', 'sostenibilitat_S3', 'sostenibilitat_S4',
	];

	$query->fields('t', array_merge(['codi_equipament'], $camps_preguntes_principals));
	// Filtrem només pels equipaments que ja estan "enviats"
	$query->condition('codi_equipament', array_keys($equipaments_enviats_amb_nom), 'IN');

	$resultats = $query->execute()->fetchAllAssoc('codi_equipament');
	$incomplets = [];

	foreach ($resultats as $codi_equipament => $registre) {
	  $te_resposta_pendent = FALSE;
	  foreach ($camps_preguntes_principals as $camp) {
		if (isset($registre->$camp) && $registre->$camp === 'No respost') {
		  $te_resposta_pendent = TRUE;
		  break;
		}
	  }

	  if ($te_resposta_pendent) {
		// El nom el recuperem de l'array original per consistència
		$incomplets[$codi_equipament] = $equipaments_enviats_amb_nom[$codi_equipament];
	  }
	}
	return $incomplets;
  }


	   /**
   * Submit parcial per quan es prem el botó "Carregar".
   */
  public function carregarEquipamentSubmit(array &$form, FormStateInterface $form_state) {
	$form_state->setRebuild(TRUE);
  }

  /**
   * AJAX callback per refrescar el formulari sencer.
   */
  public function ajaxRefrescaForm(array &$form, FormStateInterface $form_state) {
	return $form;
  }

  /**
   * AJAX callback per actualitzar espais principals i el comptador.
   */
public function actualitzaEspaisPrincipals(array &$form, FormStateInterface $form_state) {
   
	 /* ---------- Neteja de valors ---------- */
	 $form_state->setValue(['fields_wrapper', 'espai_principal'], NULL);
	 $form_state->setValue(['fields_wrapper', 'equipament_wrapper', 'equipament'], NULL);
	 $form_state->setValue(['fields_wrapper', 'equipament_seleccionat_hidden'], NULL);
   
	 $entrada = &$form_state->getUserInput();
	 unset($entrada['fields_wrapper']['equipament_wrapper']['equipament']);
	 unset($entrada['fields_wrapper']['equipament_seleccionat_hidden']);
   
	 /* ---------- Redirecció neta (sense equipament_seleccionat) ---------- */
	 // Recalcul·lem token amb els nous municipis i espai buit.
	 $municipis_sel = $form_state->getValue(['fields_wrapper', 'municipi']) ?? [];
	 $token         = $this->desaSeleccioToken($municipis_sel, '');
   
	 $url = \Drupal\Core\Url::fromRoute('<current>', [], ['query' => ['sel' => $token]])->toString();
   
	 $response = new \Drupal\Core\Ajax\AjaxResponse();
	 $response->addCommand(new \Drupal\Core\Ajax\RedirectCommand($url));
	 return $response;
   }   
			   /**
   * AJAX callback per actualitzar equips.
   */
public function actualitzaEquipaments(array &$form, FormStateInterface $form_state) {
   
	 /* ---------- Neteja valors que no volem conservar ---------- */
	 $form_state->setValue(['fields_wrapper', 'equipament_wrapper', 'equipament'], NULL);
	 $form_state->setValue(['fields_wrapper', 'equipament_seleccionat_hidden'], NULL);
   
	 $entrada = &$form_state->getUserInput();
	 unset($entrada['fields_wrapper']['equipament_wrapper']['equipament']);
	 unset($entrada['fields_wrapper']['equipament_seleccionat_hidden']);
   
	 /* ---------- Preparem dades per al token ---------- */
	 $municipis_sel = $form_state->getValue(['fields_wrapper', 'municipi']) ?? [];
	 $espai_sel     = $form_state->getValue(['fields_wrapper', 'espai_principal']) ?? '';
   
	 // Si per qualsevol causa Drupal encara no ha agafat el valor nou,
	 // intentem llegir-lo directament del request.
	 if ($espai_sel === '' && isset($entrada['fields_wrapper']['espai_principal'])) {
	   $espai_sel = $entrada['fields_wrapper']['espai_principal'];
	 }
   
	 /* ---------- Guardem selecció i fem token ---------- */
	 $token = $this->desaSeleccioToken($municipis_sel, $espai_sel);
   
	 /* ---------- Redirigim amb token + espai_principal ---------- */
	 $url = \Drupal\Core\Url::fromRoute(
			  '<current>',
			  [],
			  ['query' => [
				'sel'              => $token,
				'espai_principal'  => $espai_sel,
			  ]]
			)->toString();
   
	 $response = new \Drupal\Core\Ajax\AjaxResponse();
	 $response->addCommand(new \Drupal\Core\Ajax\RedirectCommand($url));
	 return $response;
   }
   
				  /**
   * {@inheritdoc}
   *
   * Eliminem la comprovació que impedia tornar a obrir un equipament que ja havia estat enviat.
   */
public function validateForm(array &$form, FormStateInterface $form_state) {
	   
		 /* ---------- Validació equipament seleccionat ---------- */
		 $triggering_element = $form_state->getTriggeringElement();
		 // Comprovem si l'element que dispara l'acció és un botó i si el seu valor és 'Carregar'.
		 // Si no és el botó 'Carregar', o si no és un botó, procedim amb la validació de l'equipament.
		 $is_carregar_button = isset($triggering_element['#type']) && $triggering_element['#type'] === 'submit' &&
							   isset($triggering_element['#value']) && $triggering_element['#value'] === $this->t('Carregar');
	  
		 if (!$is_carregar_button) {
	   
		   $equipament = $form_state->getValue(['fields_wrapper', 'equipament_wrapper', 'equipament']);
	   
		   if (empty($equipament)) {
			 $equipament = \Drupal::request()->query->get('equipament_seleccionat');
		   }
		   if (empty($equipament)) {
			 // Només establim l'error si no s'està intentant carregar un nou equipament amb AJAX
			 // (ja que en aquest cas el selector d'equipament podria estar buit momentàniament)
			 // Aquesta comprovació potser no és necessària si 'Carregar' no arriba aquí o té la seva pròpia lògica.
			 // Però per assegurar, només posem l'error si el trigger no és part d'un procés AJAX que canvia l'equipament.
			 $current_route = \Drupal::routeMatch()->getRouteName();
			 $ajax_routes_equipament = ['nou_formulari.delete_equipament']; // Afegir altres rutes AJAX si cal
	  
			 // La validació principal és que si no hi ha equipament i s'intenta enviar (no carregar), és un error.
			 // El botó submit principal no té un #name específic per defecte, però sí un #value 'Desar'.
			 $is_submit_desar = isset($triggering_element['#value']) && $triggering_element['#value'] === $this->t('Desar');
			 if ($is_submit_desar) {
				 // CORRECCIÓ: S'ha corregit el nom del camp per a l'error. Faltava un claudàtor.
				 $form_state->setErrorByName('fields_wrapper[equipament_wrapper][equipament]', $this->t('Has de seleccionar un equipament.'));
				 return; // Retornem aviat si no hi ha equipament en l'enviament final.
			 }
		   }
		 }
	   
		 /* ---------- Validació dels camps Any de compromís ---------- */
		 $groups = $this->getGroups();
		 $valid_years_range = range(2025, 2030);
	  
		 foreach ($groups as $group_key => $group_data) {
		   $group_values = $form_state->getValue($group_key, []); // Ex: $form_state->getValue('participacio')
		   foreach ($group_data['questions'] as $question_key => $question_info) {
	   
			 if (!empty($question_info['subitems'])) {
			   $sub_wrapper_name = $group_key . '_' . $question_key . '_subitems_wrapper';
			   // Accedim als valors del wrapper de subítems dins del grup.
			   // Ex: $group_values['participacio_P1_subitems_wrapper']
			   $sub_wrapper_values = $group_values[$sub_wrapper_name] ?? [];
	  
			   if (!empty($sub_wrapper_values)) { // Comprovem si hi ha valors dins del wrapper
				 foreach ($question_info['subitems'] as $sub_key => $sub_label) {
				   $date_field_key_in_wrapper = $group_key . '_' . $question_key . '_subitem_' . $sub_key . '_date';
				   $year_val   = $sub_wrapper_values[$date_field_key_in_wrapper] ?? ''; // Valor de l'any
	   
				   // Construïm el nom complet del camp per setErrorByName
				   $full_date_field_name = $group_key . '[' . $sub_wrapper_name . '][' . $date_field_key_in_wrapper . ']';
	  
				   if ($year_val !== '') { // Un select sempre tindrà valor si no té #empty_option
					 if (!is_numeric($year_val) || !in_array((int)$year_val, $valid_years_range, TRUE)) {
					   $form_state->setErrorByName(
						 $full_date_field_name,
						 $this->t('L\'any de compromís per "@subitem" ha de ser un any vàlid entre 2025 i 2030.', ['@subitem' => $sub_label])
					   );
					 }
				   }
				 }
	   
				 /* --- Validació any acció personalitzada --- */
				 $custom_date_field_key_in_wrapper = $group_key . '_' . $question_key . '_custom_plan_date';
				 $custom_year = $sub_wrapper_values[$custom_date_field_key_in_wrapper] ?? '';
				 
				 $full_custom_date_field_name = $group_key . '[' . $sub_wrapper_name . '][' . $custom_date_field_key_in_wrapper . ']';
	  
				 if ($custom_year !== '') {
				   if (!is_numeric($custom_year) || !in_array((int)$custom_year, $valid_years_range, TRUE)) {
					 $form_state->setErrorByName(
					   $full_custom_date_field_name,
					   $this->t('L\'any de compromís per l\'acció personalitzada ha de ser un any vàlid entre 2025 i 2030.')
					 );
				   }
				 }
			   }
			 }
		   }
		 }
	   }
   
   /**
	  * {@inheritdoc}
	  */
   public function submitForm(array &$form, FormStateInterface $form_state) {
	  
		 /* ------------------------------------------------------------------
		  * 1) Dades bàsiques
		  * ----------------------------------------------------------------- */
		 $equipament = $form_state->getValue(['fields_wrapper', 'equipament_wrapper', 'equipament']);
		 if (empty($equipament)) {
		   $equipament = \Drupal::request()->query->get('equipament_seleccionat');
		 }
		 
		 // === INICI DE LA MODIFICACIÓ ===
		 // Afegim una comprovació de seguretat aquí. Si després de tot no tenim un
		 // codi d'equipament, mostrem un error i aturem l'execució per evitar errors.
		 if (empty($equipament)) {
		   \Drupal::messenger()->addError($this->t("No s'ha seleccionat cap equipament. No s'ha desat cap dada."));
		   // Aturem l'enviament del formulari.
		   return;
		 }
		 // === FINAL DE LA MODIFICACIÓ ===
	  
		 $current_user = \Drupal::currentUser();
		 $username     = $current_user->getAccountName();
	  
		 $dades            = $this->carregaDades();
		 $dades_equipament = $this->obtenirDadesEquipament($dades, $equipament);
	  
		 $comarca         = !empty($dades_equipament['field_comarca'])         ? $dades_equipament['field_comarca']         : 'No disponible';
		 $municipi        = !empty($dades_equipament['field_municipi'])        ? $dades_equipament['field_municipi']        : 'No disponible';
		 $espai_principal = !empty($dades_equipament['field_espai_principal']) ? $dades_equipament['field_espai_principal'] : 'No disponible';
		 $nom_equipament  = !empty($dades_equipament['field_nom_equipament'])  ? $dades_equipament['field_nom_equipament']  : 'No seleccionat';
		 $codi_equipament = !empty($dades_equipament['field_codi_equipament']) ? $dades_equipament['field_codi_equipament'] : 'No disponible';
	  
		 $hora_submissio = date('Y-m-d H:i:s');
	  
		 /* ------------------------------------------------------------------
		  * 2) Inicialitzem les dades a desar
		  * ----------------------------------------------------------------- */
		 $groups = $this->getGroups();
		 $dades_formulari = [
		   'username'        => $username,
		   'comarca'         => $comarca,
		   'municipi'        => $municipi,
		   'espai_principal' => $espai_principal,
		   'nom_equipament'  => $nom_equipament,
		   'codi_equipament' => $codi_equipament,
		   'hora_submissio'  => $hora_submissio,
		 ];
	  
		 /* ------------------------------------------------------------------
		  * 3) Preguntes principals (Sí / No) – amb “No respost”
		  * ----------------------------------------------------------------- */
		 foreach ($groups as $group_key => $group_data) {
		   $group_values = $form_state->getValue($group_key, []);
	  
		   foreach ($group_data['questions'] as $question_key => $question_info) {
			 $field_name = $group_key . '_' . $question_key;
	  
			 if (isset($group_values[$field_name]) &&
				 ($group_values[$field_name] === 'Sí' || $group_values[$field_name] === 'No')) {
			   $dades_formulari[$field_name] = $group_values[$field_name];
			 }
			 else {
			   $dades_formulari[$field_name] = 'No respost';
			 }
		   }
		 }
	  
		 /* ------------------------------------------------------------------
		  * 4) Subítems i acció personalitzada
		  * ----------------------------------------------------------------- */
		 foreach ($groups as $group_key => $group_data) {
		   $group_values = $form_state->getValue($group_key, []); // valors del grup (ex: 'participacio')
	  
		   foreach ($group_data['questions'] as $question_key => $question_info) {
	  
			 /* Si la pregunta no té subítems, saltem. */
			 if (empty($question_info['subitems'])) {
			   continue;
			 }
	  
			 /* Nom de la pregunta principal i valor («Sí» / «No» / «No respost») */
			 $pregunta_principal_key = $group_key . '_' . $question_key;
			 // Agafem el valor ja processat a la secció 3 (que inclou "No respost")
			 $valor_principal    = $dades_formulari[$pregunta_principal_key] ?? 'No respost';
	  
			 /* ----------------------------------------------
			  * CAS 1 → El principal és «Sí»
			  *         ➜ posem tots els subítems (checkbox, data, text personalitzat) a blanc/0.
			  * -------------------------------------------- */
			 if ($valor_principal === 'Sí') {
	  
			   /* Subítems estàndard */
			   foreach ($question_info['subitems'] as $sub_key => $sub_label) {
				 $checkbox_name = $group_key . '_' . $question_key . '_subitem_' . $sub_key;
				 $date_name     = $checkbox_name . '_date';
	  
				 $dades_formulari[$checkbox_name] = 0;   // desmarcat
				 $dades_formulari[$date_name]     = '';  // data buida
			   }
	  
			   /* Acció de millora personalitzada */
			   $custom_plan_name = $group_key . '_' . $question_key . '_custom_plan';
			   $custom_date_name = $custom_plan_name . '_date'; // Nom correcte del camp de data per al pla personalitzat
	  
			   $dades_formulari[$custom_plan_name] = ''; // text del pla personalitzat buit
			   $dades_formulari[$custom_date_name] = ''; // data del pla personalitzat buida
	  
			   /* No cal continuar processant subítems per aquesta pregunta principal, ja estan resetejats */
			   continue;
			 }
	  
			 /* ----------------------------------------------
			  * CAS 2 → El principal és «No» o «No respost»
			  *         Aquí gestionem que si un checkbox de subítem (estàndard o personalitzat)
			  *         no està marcat, la seva data i/o text associat es buidi.
			  * -------------------------------------------- */
			 $sub_wrapper_name = $group_key . '_' . $question_key . '_subitems_wrapper';
			 // Obtenim els valors dins del 'details' (wrapper dels subítems).
			 // Si el wrapper no existeix o no té valors (ex: el 'details' no s'ha obert), serà un array buit.
			 $sub_wrapper_values = $group_values[$sub_wrapper_name] ?? [];
	  
			 /* --- Subítems estàndard --- */
			 foreach ($question_info['subitems'] as $sub_key => $sub_label) {
			   $checkbox_name = $group_key . '_' . $question_key . '_subitem_' . $sub_key;
			   $date_name     = $checkbox_name . '_date';
	  
			   $is_checked = !empty($sub_wrapper_values[$checkbox_name]) ? 1 : 0;
			   $date_val   = $sub_wrapper_values[$date_name] ?? '';
	  
			   $dades_formulari[$checkbox_name] = $is_checked;
			   // Si el checkbox NO està marcat, l'any de compromís s'ha de buidar.
			   $dades_formulari[$date_name]     = $is_checked ? $date_val : '';
			 }
	  
			 /* --- Acció personalitzada --- */
			 // El checkbox que controla si l'acció personalitzada s'ha d'omplir
			 $custom_chk_name  = $group_key . '_' . $question_key . '_custom_chk';
			 // Els camps de dades de l'acció personalitzada
			 $custom_plan_name = $group_key . '_' . $question_key . '_custom_plan';
			 $custom_date_name = $custom_plan_name . '_date'; // Nom correcte del camp de data per al pla personalitzat
	  
			 // Comprovem si el checkbox de l'acció personalitzada ("Acció de millora personalitzada") està marcat
			 $is_custom_action_checked = !empty($sub_wrapper_values[$custom_chk_name]) ? 1 : 0;
	  
			 $custom_plan_val = $sub_wrapper_values[$custom_plan_name] ?? '';
			 $custom_date_val = $sub_wrapper_values[$custom_date_name] ?? '';
	  
			 // Guardem el text del pla i la data només si el checkbox d'acció personalitzada està marcat.
			 // Altrament, els buidem.
			 $dades_formulari[$custom_plan_name] = $is_custom_action_checked ? $custom_plan_val : '';
			 $dades_formulari[$custom_date_name] = $is_custom_action_checked ? $custom_date_val : '';
		   }
		 }
	  
		 /* ------------------------------------------------------------------
		  * 5) Persistim les dades i mostrem missatge
		  * ----------------------------------------------------------------- */
		 $this->guardaDadesFormulari($dades_formulari);
	  
		 /* Debug al navegador (es manté). */
		 $form['debug_output'] = [
		   '#type'   => 'markup',
		   '#markup' => '<script>console.log("Dades del formulari enviades:", ' . json_encode($dades_formulari) . ');</script>',
		 ];
	  
		 \Drupal::messenger()->addMessage(
		   $this->t("El formulari s'ha desat correctament. Gràcies, @username.", ['@username' => $username])
		 );
	  
		 $form_state->setRedirect('<current>');
	   }
			  /**
   * Processa la crida AJAX per eliminar un equipament de la BD.
   */
  public function deleteEquipamentAjax(Request $request) {
	$codi = $request->request->get('codi_equipament');
	if (empty($codi)) {
	  return new JsonResponse([
		'success' => false,
		'message' => $this->t("No s'ha rebut cap codi_equipament."),
	  ]);
	}
	try {
	  $this->esborraEquipamentEnviat($codi);
	}
	catch (\Exception $e) {
	  return new JsonResponse([
		'success' => false,
		'message' => $this->t("No s'ha pogut eliminar l'equipament amb codi %codi. Error: @error", [
		  '%codi' => $codi,
		  '@error' => $e->getMessage(),
		]),
	  ]);
	}
	return new JsonResponse([
	  'success' => true,
	  'message' => $this->t("S'ha eliminat l'equipament amb codi %codi.", ['%codi' => $codi]),
	]);
  }

  /**
   * Comprova si totes les preguntes principals tenen la resposta "Sí".
   */
  private function totesPreguntesSi(array $respostes) {
	foreach ($respostes as $resposta) {
	  if ($resposta !== 'Sí') {
		return false;
	  }
	}
	return true;
  }

  /**
   * AJAX callback per actualitzar el formulari amb les dades de l'equipament seleccionat.
   */
public function actualitzaFormulari(array &$form, FormStateInterface $form_state) {
	 // Obtenim el codi de l'equipament seleccionat.
	 $equipament = $form_state->getValue(['fields_wrapper', 'equipament_wrapper', 'equipament']);
	 \Drupal::logger('nou_formulari')->notice('actualitzaFormulari() - equipament seleccionat: @equipament', ['@equipament' => $equipament]);
   
	 // Si s'ha seleccionat un equipament, forcem una recàrrega completa de la pàgina.
	 if (!empty($equipament)) {
	   $peticio = \Drupal::request();
	   $token   = $peticio->query->get('sel'); // Reutilitzem token existent si hi és.
   
	   $query = ['equipament_seleccionat' => $equipament];
	   if ($token) {
		 $query['sel'] = $token;
	   }
   
	   $url = \Drupal\Core\Url::fromRoute('<current>', [], ['query' => $query])->toString();
   
	   $response = new \Drupal\Core\Ajax\AjaxResponse();
	   $response->addCommand(new \Drupal\Core\Ajax\RedirectCommand($url));
	   return $response;
	 }
   
	 // Si no s'ha seleccionat cap equipament, retornem el formulari sense canvis.
	 return $form;
   }
}
