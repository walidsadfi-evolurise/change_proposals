<?php

declare(strict_types=1);

namespace Drupal\change_proposals;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Lists proposals at /admin/content/change-proposals.
 */
class ChangeProposalListBuilder extends EntityListBuilder {

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected AccountInterface $currentUser;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    $instance = new static($entity_type, $container->get('entity_type.manager')->getStorage($entity_type->id()));
    $instance->dateFormatter = $container->get('date.formatter');
    $instance->currentUser = $container->get('current_user');
    $instance->requestStack = $container->get('request_stack');
    return $instance;
  }

  public function __construct(EntityTypeInterface $entity_type, EntityStorageInterface $storage) {
    parent::__construct($entity_type, $storage);
    $this->limit = 50;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntityIds() {
    $status = $this->requestStack->getCurrentRequest()?->query->get('status', 'pending') ?? 'pending';
    $query = $this->getStorage()->getQuery()->accessCheck(TRUE)->sort('created', 'DESC');
    if ($status !== 'all') {
      $query->condition('status', $status);
    }
    if (!$this->currentUser->hasPermission('review change proposals')) {
      $query->condition('uid', (int) $this->currentUser->id());
    }
    return $query->pager($this->limit)->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $build = parent::render();
    $current = $this->requestStack->getCurrentRequest()?->query->get('status', 'pending') ?? 'pending';
    $links = [];
    foreach (['pending', 'approved', 'rejected', 'failed', 'expired', 'all'] as $status) {
      $links[$status] = [
        'title' => ucfirst($status),
        'url' => Url::fromRoute('entity.change_proposal.collection', [], ['query' => ['status' => $status]]),
        'attributes' => $status === $current ? ['class' => ['is-active']] : [],
      ];
    }
    $build['filters'] = [
      '#theme' => 'links',
      '#links' => $links,
      '#attributes' => ['class' => ['inline', 'change-proposals-filters']],
      '#weight' => -10,
    ];
    $build['table']['#empty'] = $this->t('No @status proposals.', ['@status' => $current === 'all' ? '' : $current]);
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    return [
      'id' => $this->t('ID'),
      'summary' => $this->t('Proposal'),
      'target' => $this->t('Target'),
      'status' => $this->t('Status'),
      'owner' => $this->t('Proposed by'),
      'source' => $this->t('Source'),
      'created' => $this->t('Proposed on'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\change_proposals\ChangeProposalInterface $entity */
    $target = $entity->getTarget();
    $row['id'] = $entity->id();
    $row['summary'] = Link::fromTextAndUrl((string) $entity->label(), $entity->toUrl());
    $row['target'] = $target && $target->hasLinkTemplate('canonical')
      ? Link::fromTextAndUrl((string) $target->label(), $target->toUrl())
      : ($entity->getOperation() === 'create' ? $this->t('New @bundle', ['@bundle' => $entity->getTargetBundle()]) : $this->t('(deleted)'));
    $row['status'] = ucfirst($entity->getStatus());
    $row['owner'] = $entity->getOwner()?->getDisplayName();
    $row['source'] = $entity->get('source')->value;
    $row['created'] = $this->dateFormatter->format((int) $entity->get('created')->value, 'short');
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultOperations(EntityInterface $entity) {
    $operations = parent::getDefaultOperations($entity);
    /** @var \Drupal\change_proposals\ChangeProposalInterface $entity */
    if ($entity->access('view')) {
      $operations['review'] = [
        'title' => $entity->isPending() ? $this->t('Review') : $this->t('View'),
        'weight' => -10,
        'url' => $entity->toUrl(),
      ];
    }
    return $operations;
  }

}
