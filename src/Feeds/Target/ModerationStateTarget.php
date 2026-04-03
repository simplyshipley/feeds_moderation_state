<?php

/**
 * @file
 * Feeds target plugin that writes a moderation state value directly to an
 * entity.
 */

declare(strict_types=1);

namespace Drupal\feeds_moderation_state\Feeds\Target;

use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\feeds\FeedInterface;
use Drupal\feeds\FeedTypeInterface;
use Drupal\feeds\Plugin\Type\Target\TargetBase;
use Drupal\feeds\TargetDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Feeds target that writes a moderation state value directly to an entity.
 *
 * Map any source field that already contains a valid workflow state ID (e.g.
 * 'published', 'archived', 'draft') to this target. The value is passed
 * directly to $entity->set('moderation_state', $value) before Feeds saves
 * the entity.
 *
 * If the feed type has the feeds_moderation_state 'bypass_transitions' setting
 * enabled, $entity->setSyncing(TRUE) is called before the state is set so that
 * Drupal's workflow transition validation is skipped.
 *
 * This target is automatically injected by feeds_moderation_state when the
 * module is enabled on a feed type. You do not need to add it manually.
 *
 * @FeedsTarget(
 *   id = "feeds_moderation_state_value"
 * )
 */
class ModerationStateTarget extends TargetBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a ModerationStateTarget plugin.
   *
   * @param array $configuration
   *   Plugin configuration. Must contain 'feed_type' and 'target_definition'.
   * @param string $plugin_id
   *   Plugin ID.
   * @param array $plugin_definition
   *   Plugin definition.
   * @param \Drupal\content_moderation\ModerationInformationInterface $moderationInformation
   *   The content moderation information service.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    protected readonly ModerationInformationInterface $moderationInformation,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('content_moderation.moderation_information'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function targets(array &$targets, FeedTypeInterface $feed_type, array $definition): void {
    $targets['feeds_moderation_state_value'] = TargetDefinition::create()
      ->setPluginId($definition['id'])
      ->setLabel((string) t('Moderation state (feeds_moderation_state)'))
      ->addProperty('value', (string) t('State ID'));
  }

  /**
   * {@inheritdoc}
   *
   * Writes the mapped value directly to the entity's moderation_state field.
   * Silently skips non-content entities and entities not under a Content
   * Moderation workflow.
   */
  public function setTarget(FeedInterface $feed, EntityInterface $entity, $target, array $values): void {
    if (empty($values['value'])) {
      return;
    }

    if (!$entity instanceof ContentEntityInterface) {
      return;
    }

    // New entities are always created at the workflow default state. Only
    // existing entities being updated should have their state overridden.
    if ($entity->isNew()) {
      return;
    }

    if (!$this->moderationInformation->isModeratedEntity($entity)) {
      return;
    }

    $settings = $feed->getType()->getThirdPartySettings('feeds_moderation_state');
    if (!empty($settings['bypass_transitions'])) {
      $entity->setSyncing(TRUE);
    }

    $entity->set('moderation_state', (string) $values['value']);
  }

  /**
   * {@inheritdoc}
   */
  public function isMutable(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty(FeedInterface $feed, EntityInterface $entity, $target): bool {
    if (!$entity instanceof ContentEntityInterface) {
      return TRUE;
    }

    if (!$entity->hasField('moderation_state')) {
      return TRUE;
    }

    return $entity->get('moderation_state')->isEmpty();
  }

}
