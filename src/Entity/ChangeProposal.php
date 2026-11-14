<?php

declare(strict_types=1);

namespace Drupal\change_proposals\Entity;

use Drupal\change_proposals\ChangeProposalAccessControlHandler;
use Drupal\change_proposals\ChangeProposalInterface;
use Drupal\change_proposals\ChangeProposalListBuilder;
use Drupal\change_proposals\Form\ChangeProposalDeleteForm;
use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\EntityOwnerTrait;
use Drupal\views\EntityViewsData;

/**
 * A proposed change to a content entity.
 */
#[ContentEntityType(
  id: 'change_proposal',
  label: new TranslatableMarkup('Change proposal'),
  label_collection: new TranslatableMarkup('Change proposals'),
  label_singular: new TranslatableMarkup('change proposal'),
  label_plural: new TranslatableMarkup('change proposals'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'summary',
    'owner' => 'uid',
    'uid' => 'uid',
  ],
  handlers: [
    'list_builder' => ChangeProposalListBuilder::class,
    'access' => ChangeProposalAccessControlHandler::class,
    'views_data' => EntityViewsData::class,
    'form' => [
      'delete' => ChangeProposalDeleteForm::class,
    ],
  ],
  links: [
    'canonical' => '/admin/content/change-proposals/{change_proposal}',
    'collection' => '/admin/content/change-proposals',
    'delete-form' => '/admin/content/change-proposals/{change_proposal}/delete',
  ],
  admin_permission: 'administer change proposals',
  base_table: 'change_proposal',
  internal: FALSE,
)]
class ChangeProposal extends ContentEntityBase implements ChangeProposalInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);
    $fields['uid']->setLabel(new TranslatableMarkup('Proposed by'));

    $fields['target_type'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Target entity type'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 32);
    $fields['target_bundle'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Target bundle'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 128);
    $fields['target_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Target entity ID'))
      ->setDescription(new TranslatableMarkup('Empty for a creation proposal.'))
      ->setSetting('unsigned', TRUE);
    $fields['target_langcode'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Target language'))
      ->setSetting('max_length', 12);
    $fields['operation'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Operation'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', ['update' => 'Update', 'create' => 'Create'])
      ->setDefaultValue('update');
    $fields['payload'] = BaseFieldDefinition::create('map')
      ->setLabel(new TranslatableMarkup('Proposed field values'))
      ->setDescription(new TranslatableMarkup('field_name => value, as the entity API accepts them.'));
    $fields['summary'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Summary'))
      ->setSetting('max_length', 255);
    $fields['reason'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Reason given by the proposer'));
    $fields['source'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Source'))
      ->setDescription(new TranslatableMarkup('ui, jsonapi, mcp, drush, or an integration name.'))
      ->setSetting('max_length', 64)
      ->setDefaultValue('ui');
    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'expired' => 'Expired',
        'failed' => 'Failed',
      ])
      ->setDefaultValue('pending');
    $fields['base_changed'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Target last changed when proposed'))
      ->setDescription(new TranslatableMarkup('Lets reviewers see that the content moved on since the proposal.'));
    $fields['validation'] = BaseFieldDefinition::create('map')
      ->setLabel(new TranslatableMarkup('Validation messages at proposal time'));
    $fields['decided_by'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Decided by'))
      ->setSetting('target_type', 'user');
    $fields['decided_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Decided at'));
    $fields['decision_note'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Reviewer note'));
    $fields['result'] = BaseFieldDefinition::create('map')
      ->setLabel(new TranslatableMarkup('Result of applying the proposal'));
    $fields['expires'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Expires'));
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Proposed on'));
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus(): string {
    return (string) $this->get('status')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function isPending(): bool {
    return $this->getStatus() === 'pending';
  }

  /**
   * {@inheritdoc}
   */
  public function getOperation(): string {
    return (string) $this->get('operation')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetType(): string {
    return (string) $this->get('target_type')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetBundle(): string {
    return (string) $this->get('target_bundle')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetId(): ?int {
    $v = $this->get('target_id')->value;
    return $v === NULL || $v === '' ? NULL : (int) $v;
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetLangcode(): ?string {
    $v = $this->get('target_langcode')->value;
    return $v === NULL || $v === '' ? NULL : (string) $v;
  }

  /**
   * {@inheritdoc}
   */
  public function getPayload(): array {
    $v = $this->get('payload')->getValue();
    return isset($v[0]) && is_array($v[0]) ? $v[0] : [];
  }

  /**
   * {@inheritdoc}
   */
  public function getResult(): array {
    $v = $this->get('result')->getValue();
    return isset($v[0]) && is_array($v[0]) ? $v[0] : [];
  }

  /**
   * {@inheritdoc}
   */
  public function getValidation(): array {
    $v = $this->get('validation')->getValue();
    return isset($v[0]) && is_array($v[0]) ? $v[0] : [];
  }

  /**
   * {@inheritdoc}
   */
  public function getTarget(): ?ContentEntityInterface {
    $id = $this->getTargetId();
    if ($id === NULL) {
      return NULL;
    }
    $entity = $this->entityTypeManager()->getStorage($this->getTargetType())->load($id);
    if (!$entity instanceof ContentEntityInterface) {
      return NULL;
    }
    $lang = $this->getTargetLangcode();
    if ($lang && $entity->hasTranslation($lang)) {
      $entity = $entity->getTranslation($lang);
    }
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function toPublicArray(): array {
    $result = $this->getResult();
    $out = [
      'id' => (int) $this->id(),
      'uuid' => $this->uuid(),
      'status' => $this->getStatus(),
      'operation' => $this->getOperation(),
      'target_type' => $this->getTargetType(),
      'target_bundle' => $this->getTargetBundle(),
      'target_id' => $this->getTargetId(),
      'target_langcode' => $this->getTargetLangcode(),
      'summary' => (string) $this->get('summary')->value,
      'reason' => (string) $this->get('reason')->value,
      'source' => (string) $this->get('source')->value,
      'payload' => $this->getPayload(),
      'validation' => $this->getValidation(),
      'created' => date(DATE_ATOM, (int) $this->get('created')->value),
      'expires' => $this->get('expires')->value ? date(DATE_ATOM, (int) $this->get('expires')->value) : NULL,
      'decided_at' => $this->get('decided_at')->value ? date(DATE_ATOM, (int) $this->get('decided_at')->value) : NULL,
      'decision_note' => (string) $this->get('decision_note')->value,
      'result' => $result ?: NULL,
      'review_url' => $this->toUrl()->setAbsolute()->toString(),
    ];
    return $out;
  }

}
