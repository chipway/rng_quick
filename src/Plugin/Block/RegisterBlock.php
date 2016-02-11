<?php

/**
 * @file
 * Contains \Drupal\rng_quick\Plugin\Block\RegisterBlock.
 */

namespace Drupal\rng_quick\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RedirectDestinationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\UrlGeneratorTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Block\BlockBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\rng\EventManagerInterface;
use Drupal\rng_quick\Form\RegisterBlockForm;

/**
 * Provides a 'Quick Registration' block.
 *
 * @Block(
 *   id = "rng_quick_registration",
 *   admin_label = @Translation("Quick Registration"),
 *   category = @Translation("RNG")
 * )
 */
class RegisterBlock extends BlockBase implements ContainerFactoryPluginInterface {

  use UrlGeneratorTrait;
  use RedirectDestinationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The form builder.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface.
   */
  protected $account;

  /**
   * The route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The RNG event manager.
   *
   * @var \Drupal\rng\EventManagerInterface
   */
  protected $eventManager;

  /**
   * The event entity that is being viewed.
   *
   * @var \Drupal\Core\Entity\EntityInterface
   */
  protected $event;

  /**
   * Constructs a new RegisterBlock instance.
   *
   * @param array $configuration
   *   The plugin configuration, i.e. an array with configuration values keyed
   *   by configuration option name. The special key 'context' may be used to
   *   initialize the defined contexts by setting it to an array of context
   *   values keyed by context names.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface
   *   The entity type manager.
   * @param \Drupal\Core\Form\FormBuilderInterface $form_builder
   *   The form builder.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Drupal\rng\EventManagerInterface $event_manager
   *   The RNG event manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, FormBuilderInterface $form_builder, AccountInterface $account, RouteMatchInterface $route_match, EventManagerInterface $event_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->entityTypeManager = $entity_type_manager;
    $this->formBuilder = $form_builder;
    $this->account = $account;
    $this->routeMatch = $route_match;
    $this->eventManager = $event_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('form_builder'),
      $container->get('current_user'),
      $container->get('current_route_match'),
      $container->get('rng.event_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEvent() {
    $route = $this->routeMatch->getRouteObject();
    $route_entity_type = NULL;

    // Determine if current route is for an entity.
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type) {
      // Do not show if on register forms.
      $registration_routes = [
        'rng.event.' . $entity_type->id() . '.register',
        'rng.event.' . $entity_type->id() . '.register.type_list',
      ];
      if (in_array($this->routeMatch->getRouteName(), $registration_routes)) {
        break;
      }

      // Exact link template.
      foreach ($entity_type->getLinkTemplates() as $link_template => $path) {
        if ($route->getPath() === $path) {
          $route_entity_type = $entity_type;
          break 2;
        }
      }

      // Or this route is a sub string of canonical.
      if ($canonical_route = $entity_type->getLinkTemplate('canonical')) {
        if (strlen($route->getPath()) > strlen($canonical_route)) {
          if (substr($route->getPath(), 0, strlen($canonical_route)) == $canonical_route) {
            $route_entity_type = $entity_type;
            break;
          }
        }
      }
    }

    if ($route_entity_type) {
      $event = $this->routeMatch->getParameter($route_entity_type->id());
      // Views does not invoke EntityConverter param upconverter.
      if (!$event instanceof EntityInterface) {
        return $this->entityTypeManager
          ->getStorage($route_entity_type->id())
          ->load($this->event);
      }
      return $event;
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    $event = $this->getEvent();
    if ($this->account->isAuthenticated() && $event && $this->eventManager->isEvent($event)) {
      $event_meta = $this->eventManager->getMeta($event);
      if ($event_meta->identitiesCanRegister('user', [$this->account->id()])) {
        $context = ['event' => $event];
        return $this->entityTypeManager
          ->getAccessControlHandler('registration')
          ->createAccess(NULL, NULL, $context, TRUE);
      }
    }
    return AccessResult::neutral();
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $build = [];
    $event = $this->getEvent();
    $build[] = $this->formBuilder->getForm(RegisterBlockForm::class, $event);
    return $build;
  }

  public function getCacheMaxAge() {
    return 0;
  }

}
