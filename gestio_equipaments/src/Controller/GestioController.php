<?php

namespace Drupal\gestio_equipaments\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

class GestioController extends ControllerBase {

  /**
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * {@inheritdoc}
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database')
    );
  }

  /**
   * Mostra la pàgina de llistat d'equipaments.
   */
  public function listPage(Request $request) {
    $build = [];

    // Botó per afegir un nou equipament.
    $build['add_link'] = [
      '#type' => 'link',
      '#title' => $this->t('Afegir Equipament'),
      '#url' => Url::fromRoute('gestio_equipaments.add'),
      '#attributes' => [
        'class' => ['button', 'button--action', 'button--primary'],
      ],
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    // Formulari de cerca simple
    $build['search_form'] = $this->formBuilder()->getForm('\Drupal\gestio_equipaments\Form\SearchForm');

    // Preparar la taula.
    $header = [
      'codi_equipament' => $this->t('Codi'),
      'nom_equipament' => $this->t('Nom Equipament'),
      'municipi' => $this->t('Municipi'),
      'comarca' => $this->t('Comarca'), // NOU CAMP
      'espai_principal' => $this->t('Espai Principal'),
      'operations' => $this->t('Operacions'),
    ];

    // Construir la consulta a la base de dades.
    $query = $this->database->select('nou_formulari_equipaments', 'e')
      ->fields('e', ['codi_equipament', 'nom_equipament', 'municipi', 'comarca', 'espai_principal']); // NOU CAMP

    // Aplicar filtre de cerca si existeix.
    $search_term = $request->query->get('search');
    if ($search_term) {
      $orGroup = $query->orConditionGroup()
          ->condition('codi_equipament', '%' . $query->escapeLike($search_term) . '%', 'LIKE')
          ->condition('nom_equipament', '%' . $query->escapeLike($search_term) . '%', 'LIKE')
          ->condition('municipi', '%' . $query->escapeLike($search_term) . '%', 'LIKE')
          ->condition('comarca', '%' . $query->escapeLike($search_term) . '%', 'LIKE') // NOU CAMP
          ->condition('espai_principal', '%' . $query->escapeLike($search_term) . '%', 'LIKE');
      $query->condition($orGroup);
    }
    
    // Paginació
    $pager = $query->extend('Drupal\Core\Database\Query\PagerSelectExtender')->limit(50);
    $results = $pager->execute()->fetchAll();

    $rows = [];
    foreach ($results as $row) {
      $operations = [
        'data' => [
          '#type' => 'operations',
          '#links' => [
            'edit' => [
              'title' => $this->t('Editar'),
              'url' => Url::fromRoute('gestio_equipaments.edit', ['codi_equipament' => $row->codi_equipament]),
            ],
            'delete' => [
              'title' => $this->t('Eliminar'),
              'url' => Url::fromRoute('gestio_equipaments.delete', ['codi_equipament' => $row->codi_equipament]),
            ],
          ],
        ],
      ];

      $rows[] = [
        'codi_equipament' => $row->codi_equipament,
        'nom_equipament' => $row->nom_equipament,
        'municipi' => $row->municipi,
        'comarca' => $row->comarca, // NOU CAMP
        'espai_principal' => $row->espai_principal,
        'operations' => $operations,
      ];
    }
    
    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t("No s'han trobat equipaments amb els criteris seleccionats."),
    ];

    $build['pager'] = ['#type' => 'pager'];

    return $build;
  }
}