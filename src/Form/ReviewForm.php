<?php

declare(strict_types=1);

namespace Drupal\change_proposals\Form;

use Drupal\change_proposals\ChangeProposalInterface;
use Drupal\change_proposals\ProposalException;
use Drupal\change_proposals\ProposalManager;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The review page: metadata, field-by-field diff, approve / reject.
 */
final class ReviewForm extends FormBase {

  public function __construct(
    private readonly ProposalManager $manager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('change_proposals.manager'), $container->get('date.formatter'), $container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'change_proposals_review';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ChangeProposalInterface $change_proposal = NULL): array {
    if (!$change_proposal) {
      return $form;
    }
    $form_state->set('proposal_id', $change_proposal->id());
    $target = $change_proposal->getTarget();
    $canReview = $change_proposal->access('review');

    $meta = [
      $this->t('Status') => ucfirst($change_proposal->getStatus()),
      $this->t('Operation') => ucfirst($change_proposal->getOperation()),
      $this->t('Target') => $target && $target->hasLinkTemplate('canonical')
        ? Link::fromTextAndUrl(sprintf('%s (#%s)', $target->label(), $target->id()), $target->toUrl())->toString()
        : $this->t('New @bundle', ['@bundle' => $change_proposal->getTargetBundle()]),
      $this->t('Proposed by') => sprintf('%s · %s', $change_proposal->getOwner()?->getDisplayName(), $change_proposal->get('source')->value),
      $this->t('Proposed on') => $this->dateFormatter->format((int) $change_proposal->get('created')->value, 'medium'),
      $this->t('Expires') => $change_proposal->get('expires')->value ? $this->dateFormatter->format((int) $change_proposal->get('expires')->value, 'medium') : '—',
    ];
    if ($change_proposal->get('reason')->value) {
      $meta[$this->t('Reason')] = $change_proposal->get('reason')->value;
    }
    if ($change_proposal->getTargetLangcode()) {
      $meta[$this->t('Translation')] = $change_proposal->getTargetLangcode();
    }
    $base = (int) $change_proposal->get('base_changed')->value;
    if ($target && $base && method_exists($target, 'getChangedTime') && (int) $target->getChangedTime() > $base) {
      $meta[$this->t('Warning')] = $this->t('The content was edited after this proposal was made (@when). The current values below are the live ones.', [
        '@when' => $this->dateFormatter->format((int) $target->getChangedTime(), 'medium'),
      ]);
    }
    if (!$change_proposal->isPending()) {
      $meta[$this->t('Decided')] = sprintf(
        '%s · %s%s',
        $change_proposal->get('decided_by')->entity?->getDisplayName() ?? $this->t('system'),
        $change_proposal->get('decided_at')->value ? $this->dateFormatter->format((int) $change_proposal->get('decided_at')->value, 'medium') : '',
        $change_proposal->get('decision_note')->value ? ' · ' . $change_proposal->get('decision_note')->value : '',
      );
      $result = $change_proposal->getResult();
      if (!empty($result['messages'])) {
        $meta[$this->t('Failure')] = implode(' / ', (array) $result['messages']);
      }
      elseif (!empty($result['revision_id'])) {
        $meta[$this->t('Applied as')] = $this->t('revision @rev', ['@rev' => $result['revision_id']]);
      }
    }
    $rows = [];
    foreach ($meta as $k => $v) {
      $rows[] = [['data' => $k, 'header' => TRUE], $v];
    }
    $form['meta'] = ['#type' => 'table', '#rows' => $rows, '#attributes' => ['class' => ['change-proposal-meta']]];

    $diff = [];
    foreach ($this->manager->diff($change_proposal) as $row) {
      $diff[] = [
        'data' => [
          ['data' => $row['label'], 'header' => TRUE],
          ['data' => ['#type' => 'inline_template', '#template' => '<pre style="white-space:pre-wrap;margin:0">{{ v }}</pre>', '#context' => ['v' => $row['current']]]],
          ['data' => ['#type' => 'inline_template', '#template' => '<pre style="white-space:pre-wrap;margin:0">{{ v }}</pre>', '#context' => ['v' => $row['proposed']]]],
        ],
        'class' => $row['changed'] ? ['color-warning'] : [],
      ];
    }
    $form['diff'] = [
      '#type' => 'table',
      '#caption' => $this->t('Proposed changes'),
      '#header' => [$this->t('Field'), $this->t('Current'), $this->t('Proposed')],
      '#rows' => $diff,
      '#empty' => $this->t('The target no longer exists.'),
    ];

    if ($canReview) {
      $form['note'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Note to the proposer'),
        '#rows' => 2,
        '#description' => $this->t('Stored with the decision and returned to the integration or agent that proposed the change.'),
      ];
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['approve'] = [
        '#type' => 'submit',
        '#value' => $this->t('Approve and apply'),
        '#button_type' => 'primary',
        '#submit' => ['::approve'],
      ];
      $form['actions']['reject'] = [
        '#type' => 'submit',
        '#value' => $this->t('Reject'),
        '#submit' => ['::reject'],
      ];
    }
    return $form;
  }

  /**
   * Approve.
   */
  public function approve(array &$form, FormStateInterface $form_state): void {
    $proposal = $this->load($form_state);
    try {
      $entity = $this->manager->approve($proposal, $this->currentUser(), (string) $form_state->getValue('note'));
      $this->messenger()->addStatus($this->t('Proposal #@id applied to %label.', ['@id' => $proposal->id(), '%label' => $entity->label()]));
    }
    catch (ProposalException $e) {
      $this->messenger()->addError($this->t('Could not apply proposal #@id: @m @d', ['@id' => $proposal->id(), '@m' => $e->getMessage(), '@d' => implode(' / ', $e->messages)]));
    }
    $form_state->setRedirect('entity.change_proposal.collection');
  }

  /**
   * Reject.
   */
  public function reject(array &$form, FormStateInterface $form_state): void {
    $proposal = $this->load($form_state);
    try {
      $this->manager->reject($proposal, $this->currentUser(), (string) $form_state->getValue('note'));
      $this->messenger()->addStatus($this->t('Proposal #@id rejected.', ['@id' => $proposal->id()]));
    }
    catch (ProposalException $e) {
      $this->messenger()->addError($e->getMessage());
    }
    $form_state->setRedirect('entity.change_proposal.collection');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  /**
   * Reload the proposal for the submit handlers.
   */
  private function load(FormStateInterface $form_state): ChangeProposalInterface {
    /** @var \Drupal\change_proposals\ChangeProposalInterface $p */
    $p = $this->entityTypeManager->getStorage('change_proposal')->load($form_state->get('proposal_id'));
    return $p;
  }

}
