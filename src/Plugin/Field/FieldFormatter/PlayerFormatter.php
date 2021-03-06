<?php

namespace Drupal\media_mpx\Plugin\Field\FieldFormatter;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\media_mpx\DataObjectFactoryCreator;
use Drupal\media_mpx\MpxLogger;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Uri;
use Lullabot\Mpx\DataService\ObjectListQuery;
use Lullabot\Mpx\DataService\Player\Player;
use Lullabot\Mpx\DataService\Sort;
use Lullabot\Mpx\Player\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Field formatter for an mpx player.
 *
 * @todo This needs to only attach to mpx media types.
 *
 * @FieldFormatter(
 *   id="media_mpx_video",
 *   label = @Translation("mpx Video player"),
 *   field_types={
 *     "string"
 *   }
 * )
 */
class PlayerFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private $entityTypeManager;

  /**
   * The creator used to load player factories.
   *
   * @var \Drupal\media_mpx\DataObjectFactoryCreator
   */
  private $dataObjectFactoryCreator;

  /**
   * The logger for mpx errors.
   *
   * @var \Drupal\media_mpx\MpxLogger
   */
  private $mpxLogger;

  /**
   * The system messenger for error reporting.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs a PlayerFormatter object.
   *
   * @param string $plugin_id
   *   The plugin_id for the formatter.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The definition of the field to which the formatter is associated.
   * @param array $settings
   *   The formatter settings.
   * @param string $label
   *   The formatter label display setting.
   * @param string $view_mode
   *   The view mode.
   * @param array $third_party_settings
   *   Any third party settings.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\media_mpx\DataObjectFactoryCreator $data_object_factory_creator
   *   The creator of mpx data factories.
   * @param \Drupal\media_mpx\MpxLogger $mpx_logger
   *   The logger for mpx errors.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The system messenger for error reporting.
   */
  public function __construct(string $plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, string $label, string $view_mode, array $third_party_settings, EntityTypeManagerInterface $entity_type_manager, DataObjectFactoryCreator $data_object_factory_creator, MpxLogger $mpx_logger, MessengerInterface $messenger) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);
    $this->entityTypeManager = $entity_type_manager;
    $this->dataObjectFactoryCreator = $data_object_factory_creator;
    $this->mpxLogger = $mpx_logger;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('entity_type.manager'),
      $container->get('media_mpx.data_object_factory_creator'),
      $container->get('media_mpx.exception_logger'),
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $element = [];

    /** @var \Drupal\media\Entity\Media $entity */
    $entity = $items->getEntity();
    /** @var \Drupal\media_mpx\Plugin\media\Source\Media $source_plugin */
    $source_plugin = $entity->getSource();

    // @todo Cache this.
    $factory = $this->dataObjectFactoryCreator->forObjectType($source_plugin->getAccount()->getUserEntity(), 'Player Data Service', 'Player', '1.6');

    try {
      $player = $factory->load(new Uri($this->getSetting('player')))->wait();
    }
    catch (TransferException $e) {
      // If we can't load a player, we can't render any elements.
      $this->mpxLogger->logException($e);
      return $element;
    }
    $this->renderIframes($items, $player, $element);

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = parent::settingsForm($form, $form_state);

    // This method is called multiple times during a single request, so we have
    // a basic static cache as the results are slow to fetch.
    static $options = [];

    if (empty($options)) {
      try {
        $options = $this->fetchPlayerOptions();
      }
      catch (TransferException $e) {
        $this->mpxLogger->logException($e);
        $this->messenger->addError($this->t('An unexpected error occurred. The full error has been logged. %error',
          [
            '%error' => $e->getMessage(),
          ])
        );

        return [];
      }
    }

    $elements['player'] = [
      '#type' => 'select',
      '#title' => $this->t('mpx Player'),
      '#description' => $this->t('Select the mpx player to use for playing videos.'),
      '#options' => $options,
    ];

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    // @todo Somehow cache the player title so we show that instead of the ID.
    $summary[] = $this->t('mpx Player: @title', ['@title' => $this->getSetting('player')]);
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'player' => '',
    ];
  }

  /**
   * Build the array of available players.
   *
   * @return array
   *   The array of player options.
   */
  private function fetchPlayerOptions(): array {
    $options = [];
    $bundle = $this->fieldDefinition->getTargetBundle();
    /** @var \Drupal\media\Entity\MediaType $type */
    $type = $this->entityTypeManager->getStorage('media_type')->load($bundle);
    /** @var \Drupal\media_mpx\Plugin\media\Source\Media $source_plugin */
    $source_plugin = $type->getSource();

    $factory = $this->dataObjectFactoryCreator->forObjectType($source_plugin->getAccount()
      ->getUserEntity(), 'Player Data Service', 'Player', '1.6');
    $query = new ObjectListQuery();
    $sort = new Sort();
    $sort->addSort('title');
    $query->setSort($sort);

    /** @var \Lullabot\Mpx\DataService\Player\Player[] $results */
    $results = $factory->select($query, $source_plugin->getAccount());

    foreach ($results as $player) {
      if (!$player->getDisabled()) {
        $options[(string) $player->getId()] = $player->getTitle();
      }
    }
    return $options;
  }

  /**
   * Render the player iframes for this element.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The items to render.
   * @param \Lullabot\Mpx\DataService\Player\Player $player
   *   The player to render the items with.
   * @param array &$element
   *   The render array.
   */
  private function renderIframes(FieldItemListInterface $items, Player $player, array &$element) {
    /** @var \Drupal\media\Entity\Media $entity */
    $entity = $items->getEntity();
    /** @var \Drupal\media_mpx\Plugin\media\Source\Media $source_plugin */
    $source_plugin = $entity->getSource();
    foreach ($items as $delta => $item) {
      try {
        /** @var \Lullabot\Mpx\DataService\Media\Media $mpx_media */
        $mpx_media = $source_plugin->getMpxObject($entity);
      }
      catch (TransferException $e) {
        // If this media item is missing, continue on to the next element.
        $this->mpxLogger->logException($e);
        continue;
      }
      $url = new Url($source_plugin->getAccount(), $player, $mpx_media);

      // @todo What cache contexts or tags do we set?
      $element[$delta] = [
        '#type' => 'media_mpx_iframe',
        '#url' => (string) $url,
      ];
    }
  }

}
