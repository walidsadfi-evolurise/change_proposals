<?php

declare(strict_types=1);

namespace Drupal\change_proposals;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\user\EntityOwnerInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Creates, validates, applies and expires proposals.
 *
 * A proposal is validated twice: when it is made (so the proposer gets
 * immediate feedback) and when it is approved (so a change that became invalid
 * in the meantime is refused rather than half-applied).
 */
class ProposalManager {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $fieldManager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected ConfigFactoryInterface $configFactory,
    protected ModuleHandlerInterface $moduleHandler,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
    protected MailManagerInterface $mailManager,
    protected LanguageManagerInterface $languageManager,
    protected ClientInterface $httpClient,
    protected ProposalDiffer $differ,
    protected AccountSwitcherInterface $accountSwitcher,
  ) {}

  /**
   * Settings as a plain array with defaults.
   */
  public function settings(): array {
    $c = $this->configFactory->get('change_proposals.settings');
    return [
      'entity_types' => (array) ($c->get('entity_types') ?? ['node']),
      'blocked_fields' => (array) ($c->get('blocked_fields') ?? []),
      'expire_days' => max(1, (int) ($c->get('expire_days') ?? 14)),
      'notify_email' => (string) ($c->get('notify_email') ?? ''),
      'notify_on_new' => (bool) ($c->get('notify_on_new') ?? TRUE),
      'webhook_url' => (string) ($c->get('webhook_url') ?? ''),
      'webhook_token' => (string) ($c->get('webhook_token') ?? ''),
    ];
  }

  /**
   * Content entity types that accept proposals.
   *
   * @return array<string, string>
   *   id => label.
   */
  public function allowedEntityTypes(): array {
    $out = [];
    foreach ($this->settings()['entity_types'] as $id) {
      if ($this->entityTypeManager->hasDefinition($id) && $this->entityTypeManager->getDefinition($id) instanceof ContentEntityTypeInterface) {
        $out[$id] = (string) $this->entityTypeManager->getDefinition($id)->getLabel();
      }
    }
    return $out;
  }

  /**
   * Fields that can never be proposed for an entity type: keys + configuration.
   */
  public function blockedFields(string $entityTypeId): array {
    $blocked = $this->settings()['blocked_fields'];
    if ($this->entityTypeManager->hasDefinition($entityTypeId)) {
      $def = $this->entityTypeManager->getDefinition($entityTypeId);
      foreach (['id', 'uuid', 'revision', 'bundle', 'langcode', 'owner', 'uid'] as $key) {
        if ($def->hasKey($key)) {
          $blocked[] = $def->getKey($key);
        }
      }
      if ($def instanceof ContentEntityTypeInterface) {
        foreach ($def->getRevisionMetadataKeys() as $k) {
          $blocked[] = $k;
        }
      }
    }
    return array_values(array_unique(array_filter($blocked)));
  }

  /**
   * Make a proposal.
   *
   * @throws \Drupal\change_proposals\ProposalException
   */
  public function propose(string $entityTypeId, ?int $targetId, ?string $bundle, array $values, AccountInterface $account, string $reason = '', string $source = 'ui', ?string $langcode = NULL): ChangeProposalInterface {
    /** @var \Drupal\change_proposals\ChangeProposalInterface $proposal */
    $proposal = $this->entityTypeManager->getStorage('change_proposal')->create([
      'uid' => (int) $account->id(),
      'target_type' => $entityTypeId,
      'target_id' => $targetId,
      'target_bundle' => (string) $bundle,
      'target_langcode' => $langcode,
      'operation' => $targetId === NULL ? 'create' : 'update',
      'payload' => $values,
      'reason' => $reason,
      'source' => $source,
      'status' => ChangeProposalInterface::PENDING,
    ]);
    try {
      // hook_ENTITY_TYPE_presave() runs prepare(); the insert hook notifies.
      $proposal->save();
    }
    catch (EntityStorageException $e) {
      if ($e->getPrevious() instanceof ProposalException) {
        throw $e->getPrevious();
      }
      throw $e;
    }
    return $proposal;
  }

  /**
   * Check and complete a new proposal before it is stored.
   *
   * Called from hook_change_proposal_presave(), so proposals created through
   * JSON:API or any other channel go through the same checks.
   *
   * @throws \Drupal\change_proposals\ProposalException
   */
  public function prepare(ChangeProposalInterface $proposal): void {
    $account = $proposal->getOwner();
    if (!$account || !$account->hasPermission('create change proposals')) {
      throw new ProposalException('not_allowed', 'This account may not create change proposals.');
    }
    $type = $proposal->getTargetType();
    if (!isset($this->allowedEntityTypes()[$type])) {
      throw new ProposalException('not_allowed', sprintf('Entity type "%s" does not accept proposals.', $type));
    }
    $payload = $proposal->getPayload();
    if (!$payload) {
      throw new ProposalException('invalid', 'The proposal contains no field values.');
    }

    if ($proposal->getOperation() === 'update') {
      $target = $proposal->getTarget();
      if (!$target) {
        throw new ProposalException('not_found', sprintf('No %s with id %s.', $type, (string) $proposal->getTargetId()));
      }
      if (!$target->access('view', $account)) {
        throw new ProposalException('not_allowed', 'This account may not view the target content.');
      }
      $proposal->set('target_bundle', $target->bundle());
      $langcode = $proposal->getTargetLangcode();
      if ($langcode && !$target->hasTranslation($langcode)) {
        throw new ProposalException('not_found', sprintf('No "%s" translation of this content.', $langcode));
      }
      $proposal->set('base_changed', method_exists($target, 'getChangedTime') ? (int) $target->getChangedTime() : NULL);
    }
    else {
      $bundles = $this->bundleInfo->getBundleInfo($type);
      if (!isset($bundles[$proposal->getTargetBundle()])) {
        throw new ProposalException('not_found', sprintf('No "%s" bundle for %s.', $proposal->getTargetBundle(), $type));
      }
    }

    $definitions = $this->fieldManager->getFieldDefinitions($type, $proposal->getTargetBundle());
    $blocked = array_intersect(array_keys($payload), $this->blockedFields($type));
    if ($blocked) {
      throw new ProposalException('blocked_fields', 'Some fields cannot be changed through a proposal.', array_values($blocked));
    }
    $unknown = array_diff(array_keys($payload), array_keys($definitions));
    if ($unknown) {
      throw new ProposalException('invalid', 'Unknown fields for this content type.', array_values($unknown));
    }

    $candidate = $this->buildCandidate($proposal);
    $messages = $this->violations($candidate, array_keys($payload), $account);
    if ($messages) {
      throw new ProposalException('invalid', 'The proposed values do not validate.', $messages);
    }
    $proposal->set('validation', []);

    if (!$proposal->get('summary')->value) {
      $proposal->set('summary', $this->summary($proposal, $candidate));
    }
    if (!$proposal->get('expires')->value) {
      $proposal->set('expires', $this->time->getRequestTime() + $this->settings()['expire_days'] * 86400);
    }
  }

  /**
   * After a proposal is stored: hooks, email, webhook.
   */
  public function afterCreate(ChangeProposalInterface $proposal): void {
    $this->moduleHandler->invokeAll('change_proposals_created', [$proposal]);
    $this->logger->notice('Proposal #@id created by @user: @summary', [
      '@id' => $proposal->id(),
      '@user' => $proposal->getOwner()?->getAccountName(),
      '@summary' => $proposal->label(),
    ]);
    $this->notify($proposal);
    $this->webhook($proposal, 'proposal.created');
  }

  /**
   * The target with the proposed values applied, unsaved.
   *
   * @throws \Drupal\change_proposals\ProposalException
   */
  public function buildCandidate(ChangeProposalInterface $proposal): ContentEntityInterface {
    $type = $proposal->getTargetType();
    $storage = $this->entityTypeManager->getStorage($type);
    if ($proposal->getOperation() === 'update') {
      $target = $proposal->getTarget();
      if (!$target) {
        throw new ProposalException('not_found', 'The target content no longer exists.');
      }
      // Work on a copy: the loaded entity sits in the static cache.
      $candidate = clone $target;
    }
    else {
      $def = $this->entityTypeManager->getDefinition($type);
      $values = [];
      if ($def->hasKey('bundle')) {
        $values[$def->getKey('bundle')] = $proposal->getTargetBundle();
      }
      if ($proposal->getTargetLangcode() && $def->hasKey('langcode')) {
        $values[$def->getKey('langcode')] = $proposal->getTargetLangcode();
      }
      $candidate = $storage->create($values);
      if ($candidate instanceof EntityOwnerInterface) {
        $candidate->setOwnerId((int) $proposal->getOwnerId());
      }
    }
    foreach ($proposal->getPayload() as $field => $value) {
      if ($candidate->hasField($field)) {
        $candidate->set($field, $value);
      }
    }
    return $candidate;
  }

  /**
   * Field-by-field comparison for reviewers.
   *
   * @return list<array{field: string, label: string, current: string, proposed: string, changed: bool}>
   *   One row per proposed field.
   */
  public function diff(ChangeProposalInterface $proposal): array {
    $current = $proposal->getOperation() === 'update' ? $proposal->getTarget() : NULL;
    try {
      $candidate = $this->buildCandidate($proposal);
    }
    catch (ProposalException) {
      $candidate = NULL;
    }
    return $this->differ->diff($proposal->getPayload(), $current, $candidate);
  }

  /**
   * Apply the proposal as a new revision authored by the reviewer.
   *
   * @throws \Drupal\change_proposals\ProposalException
   */
  public function approve(ChangeProposalInterface $proposal, AccountInterface $reviewer, string $note = ''): ContentEntityInterface {
    $this->assertPending($proposal);
    $now = $this->time->getRequestTime();
    try {
      $candidate = $this->buildCandidate($proposal);
    }
    catch (ProposalException $e) {
      $this->decide($proposal, ChangeProposalInterface::FAILED, $reviewer, $note, [
        'error' => $e->errorCode,
        'messages' => [$e->getMessage()],
      ]);
      throw $e;
    }
    $messages = $this->violations($candidate, array_keys($proposal->getPayload()), $reviewer);
    if ($messages) {
      $this->decide($proposal, ChangeProposalInterface::FAILED, $reviewer, $note, [
        'error' => 'invalid',
        'messages' => $messages,
      ]);
      throw new ProposalException('invalid', 'The proposal no longer validates against the current content.', $messages);
    }
    if ($candidate instanceof RevisionableInterface && $candidate->getEntityType()->isRevisionable()) {
      $candidate->setNewRevision(TRUE);
      if ($candidate instanceof RevisionLogInterface) {
        $candidate->setRevisionUserId((int) $reviewer->id());
        $candidate->setRevisionCreationTime($now);
        $candidate->setRevisionLogMessage(sprintf('Change proposal #%d approved%s', $proposal->id(), $note !== '' ? ': ' . $note : ''));
      }
    }
    $candidate->save();
    $result = [
      'entity_type' => $candidate->getEntityTypeId(),
      'entity_id' => $candidate->id(),
      'revision_id' => $candidate instanceof RevisionableInterface ? $candidate->getRevisionId() : NULL,
      'label' => (string) $candidate->label(),
    ];
    if ($candidate->hasLinkTemplate('canonical')) {
      $result['url'] = $candidate->toUrl()->setAbsolute()->toString();
    }
    if ($proposal->getOperation() === 'create') {
      $proposal->set('target_id', $candidate->id());
    }
    $this->decide($proposal, ChangeProposalInterface::APPROVED, $reviewer, $note, $result);
    return $candidate;
  }

  /**
   * Reject with a note.
   *
   * @throws \Drupal\change_proposals\ProposalException
   */
  public function reject(ChangeProposalInterface $proposal, AccountInterface $reviewer, string $note = ''): void {
    $this->assertPending($proposal);
    $this->decide($proposal, ChangeProposalInterface::REJECTED, $reviewer, $note, []);
  }

  /**
   * Mark overdue pending proposals as expired. Returns how many.
   */
  public function expire(): int {
    $storage = $this->entityTypeManager->getStorage('change_proposal');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('status', ChangeProposalInterface::PENDING)
      ->condition('expires', $this->time->getRequestTime(), '<')
      ->range(0, 200)
      ->execute();
    $n = 0;
    foreach ($storage->loadMultiple($ids) as $proposal) {
      /** @var \Drupal\change_proposals\ChangeProposalInterface $proposal */
      $proposal->set('status', ChangeProposalInterface::EXPIRED)->set('decided_at', $this->time->getRequestTime())->save();
      $this->moduleHandler->invokeAll('change_proposals_decided', [$proposal]);
      $this->webhook($proposal, 'proposal.expired');
      $n++;
    }
    return $n;
  }

  /**
   * Pending proposals, newest first.
   *
   * @return \Drupal\change_proposals\ChangeProposalInterface[]
   *   The pending proposals, optionally only those of one owner.
   */
  public function pending(?AccountInterface $owner = NULL, int $limit = 50): array {
    $storage = $this->entityTypeManager->getStorage('change_proposal');
    $q = $storage->getQuery()->accessCheck(FALSE)->condition('status', ChangeProposalInterface::PENDING)->sort('created', 'DESC')->range(0, $limit);
    if ($owner) {
      $q->condition('uid', (int) $owner->id());
    }
    return $storage->loadMultiple($q->execute());
  }

  /**
   * Record a decision.
   */
  protected function decide(ChangeProposalInterface $proposal, string $status, AccountInterface $reviewer, string $note, array $result): void {
    $proposal
      ->set('status', $status)
      ->set('decided_by', (int) $reviewer->id())
      ->set('decided_at', $this->time->getRequestTime())
      ->set('decision_note', $note)
      ->set('result', $result)
      ->save();
    $this->logger->notice('Proposal #@id @status by @user', [
      '@id' => $proposal->id(),
      '@status' => $status,
      '@user' => $reviewer->getAccountName(),
    ]);
    $this->moduleHandler->invokeAll('change_proposals_decided', [$proposal]);
    $this->webhook($proposal, 'proposal.' . $status);
  }

  /**
   * Throw unless the proposal can still be decided.
   */
  protected function assertPending(ChangeProposalInterface $proposal): void {
    if (!$proposal->isPending()) {
      throw new ProposalException('not_pending', sprintf('Proposal is %s.', $proposal->getStatus()));
    }
    $expires = (int) $proposal->get('expires')->value;
    if ($expires && $expires < $this->time->getRequestTime()) {
      $proposal->set('status', ChangeProposalInterface::EXPIRED)->set('decided_at', $this->time->getRequestTime())->save();
      throw new ProposalException('expired', 'Proposal has expired.');
    }
  }

  /**
   * Validation messages relevant to the proposed fields.
   *
   * Entity-level violations are always included. Validation runs as the given
   * account, because constraints such as the text formats a user may use
   * depend on who is acting, not on who happens to be the current user (a
   * Drush command, a queue worker or an MCP session acting for someone else).
   *
   * @return string[]
   *   Human-readable messages, prefixed with the field label.
   */
  protected function violations(ContentEntityInterface $candidate, array $fields, AccountInterface $as): array {
    $this->accountSwitcher->switchTo($as);
    try {
      $violations = $candidate->validate();
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
    $messages = [];
    foreach ($violations as $violation) {
      $path = (string) $violation->getPropertyPath();
      $root = explode('.', $path)[0];
      if ($path !== '' && !in_array($root, $fields, TRUE) && !$candidate->isNew()) {
        continue;
      }
      $messages[] = ($path !== '' ? $path . ': ' : '') . strip_tags((string) $violation->getMessage());
    }
    return $messages;
  }

  /**
   * One line a reviewer can scan.
   */
  protected function summary(ChangeProposalInterface $proposal, ContentEntityInterface $candidate): string {
    $bundles = $this->bundleInfo->getBundleInfo($proposal->getTargetType());
    $bundleLabel = (string) ($bundles[$proposal->getTargetBundle()]['label'] ?? $proposal->getTargetBundle());
    $fields = implode(', ', array_keys($proposal->getPayload()));
    if ($proposal->getOperation() === 'create') {
      $text = sprintf('Create %s "%s"', $bundleLabel, (string) $candidate->label());
    }
    else {
      $target = $proposal->getTarget();
      $text = sprintf('Update %s #%d "%s": %s', $bundleLabel, $proposal->getTargetId(), (string) $target?->label(), $fields);
    }
    return Unicode::truncate($text, 255, TRUE, TRUE);
  }

  /**
   * Email reviewers about a new proposal.
   */
  protected function notify(ChangeProposalInterface $proposal): void {
    $s = $this->settings();
    if (!$s['notify_on_new']) {
      return;
    }
    $to = $s['notify_email'] !== '' ? $s['notify_email'] : (string) $this->configFactory->get('system.site')->get('mail');
    if ($to === '') {
      return;
    }
    $site = (string) $this->configFactory->get('system.site')->get('name');
    $params = [
      'subject' => sprintf('[%s] Change proposal #%d: %s', $site, $proposal->id(), $proposal->label()),
      'body' => implode("\n", array_filter([
        sprintf('Proposed by: %s (%s)', $proposal->getOwner()?->getDisplayName(), $proposal->get('source')->value),
        $proposal->get('reason')->value ? 'Reason: ' . $proposal->get('reason')->value : NULL,
        '',
        'Review: ' . $proposal->toUrl()->setAbsolute()->toString(),
      ])),
    ];
    try {
      $this->mailManager->mail('change_proposals', 'new_proposal', $to, $this->languageManager->getDefaultLanguage()->getId(), $params);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not send proposal email: @m', ['@m' => $e->getMessage()]);
    }
  }

  /**
   * POSTs the proposal to the configured webhook.
   *
   * The body carries a Slack-compatible "text" plus the full payload.
   */
  protected function webhook(ChangeProposalInterface $proposal, string $event): void {
    $s = $this->settings();
    if ($s['webhook_url'] === '') {
      return;
    }
    $body = [
      'event' => $event,
      'text' => sprintf('[%s] %s — %s', (string) $this->configFactory->get('system.site')->get('name'), $event, $proposal->label()),
      'proposal' => $proposal->toPublicArray(),
    ];
    $this->moduleHandler->alter('change_proposals_webhook', $body, $proposal);
    $headers = ['Content-Type' => 'application/json'];
    if ($s['webhook_token'] !== '') {
      $headers['Authorization'] = 'Bearer ' . $s['webhook_token'];
    }
    try {
      $this->httpClient->request('POST', $s['webhook_url'], ['json' => $body, 'headers' => $headers, 'timeout' => 5]);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Webhook failed for proposal #@id: @m', [
        '@id' => $proposal->id(),
        '@m' => $e->getMessage(),
      ]);
    }
  }

}
