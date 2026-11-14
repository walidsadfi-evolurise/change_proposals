<?php

declare(strict_types=1);

namespace Drupal\change_proposals;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Reviewers see everything; proposers see their own.
 */
class ChangeProposalAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    /** @var \Drupal\change_proposals\ChangeProposalInterface $entity */
    switch ($operation) {
      case 'view':
        $own = AccessResult::allowedIfHasPermission($account, 'view own change proposals')
          ->andIf(AccessResult::allowedIf((int) $entity->getOwnerId() === (int) $account->id())->addCacheableDependency($entity))
          ->cachePerUser();
        return AccessResult::allowedIfHasPermission($account, 'review change proposals')->orIf($own);

      case 'review':
        return AccessResult::allowedIfHasPermission($account, 'review change proposals')
          ->andIf(AccessResult::allowedIf($entity->isPending())->addCacheableDependency($entity));

      case 'update':
      case 'delete':
        return AccessResult::allowedIfHasPermission($account, 'administer change proposals');
    }
    return AccessResult::neutral();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermission($account, 'create change proposals');
  }

}
