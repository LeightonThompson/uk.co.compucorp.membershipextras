<?php

use CRM_MembershipExtras_Test_Fabricator_PaymentPlanOrder as PaymentPlanOrderFabricator;
use CRM_MembershipExtras_Test_Fabricator_Contact as ContactFabricator;
use CRM_MembershipExtras_PaymentProcessor_OfflineRecurringContribution as OfflineRecurringContributionPaymentProcessor;
use CRM_MembershipExtras_Test_Fabricator_MembershipType as MembershipTypeFabricator;
use CRM_MembershipExtras_Test_Fabricator_PriceField as PriceFieldFabricator;
use CRM_MembershipExtras_Test_Fabricator_PriceFieldValue as PriceFieldValueFabricator;

/**
 * Class CRM_MembershipExtras_Hook_PostProcess_UpdateSubscriptionTest
 *
 * @group headless
 */
class CRM_MembershipExtras_Hook_PostProcess_UpdateSubscriptionTest extends BaseHeadlessTest {

  private $contact;
  private $membershipType;
  private $recurringContributionParams = [];
  private $lineItemsParams = [];
  private $contributionParams = [];
  private $memberDuesFinancialType = [];
  private $eftPaymentInstrumentID = 0;
  private $contributionPendingStatusValue = 0;
  private $defaultMembershipsPriceSet = [];

  /**
   * The form used to update recurring contributions.
   *
   * @var CRM_Contribute_Form_UpdateSubscription
   */
  private $updateSubscriptionForm;

  public function setUp() {
    $this->setTestParameterValues();
    $this->createRequiredTestEntities();
    $this->setUpDefaultPaymentPlanParameters();
    $this->setUpUpdateSubscriptionForm();
  }

  /**
   * Loads parameters required for the tests.
   *
   * @throws \CiviCRM_API3_Exception
   */
  private function setTestParameterValues() {
    $this->contributionPendingStatusValue = $this->getPendingContributionStatusValue();
    $this->memberDuesFinancialType = $this->getMembershipDuesFinancialType();
    $this->eftPaymentInstrumentID = $this->getEFTPaymentInstrumentID();
    $this->defaultMembershipsPriceSet = $this->getDefaultPriceSet();
  }

  /**
   * Obtains value for the 'Pending' contribution status option value.
   *
   * @return array
   * @throws \CiviCRM_API3_Exception
   */
  private function getPendingContributionStatusValue() {
    return civicrm_api3('OptionValue', 'getvalue', [
      'return' => 'value',
      'option_group_id' => 'contribution_status',
      'name' => 'Pending',
    ]);
  }

  /**
   * Obtains default price set for memberships.
   *
   * @return array
   * @throws \CiviCRM_API3_Exception
   */
  private function getDefaultPriceSet() {
    $result = civicrm_api3('PriceSet', 'get', [
      'sequential' => 1,
      'name' => 'default_membership_type_amount',
      'options' => ['limit' => 1],
    ]);

    if ($result['count'] > 0) {
      return array_shift($result['values']);
    }

    return [];
  }

  /**
   * Fabricates entities required for tests.
   *
   * @throws \CiviCRM_API3_Exception
   */
  private function createRequiredTestEntities() {
    $this->contact = ContactFabricator::fabricate();

    $this->membershipType = MembershipTypeFabricator::fabricate(
      [
        'name' => 'Test Rolling Membership',
        'period_type' => 'rolling',
        'minimum_fee' => 120,
      ],
      TRUE
    );
    $priceField = PriceFieldFabricator::fabricate([
      'name' => 'default_price_set',
      'label' => 'Member Dues',
      'price_set_id' => $this->defaultMembershipsPriceSet['id'],
    ]);
    PriceFieldValueFabricator::fabricate([
      'label' => $this->membershipType->name,
      'amount' => $this->membershipType->minimum_fee,
      'price_field_id' => $priceField['id'],
      'membership_type_id' => $this->membershipType->id,
      'financial_type_id' => $this->memberDuesFinancialType['id'],
    ]);
  }

  private function setUpDefaultPaymentPlanParameters() {
    $this->recurringContributionParams = [
      'sequential' => 1,
      'contact_id' => $this->contact['id'],
      'amount' => 0,
      'frequency_unit' => 'month',
      'frequency_interval' => 1,
      'installments' => 12,
      'contribution_status_id' => 'Pending',
      'is_test' => 0,
      'auto_renew' => 1,
      'cycle_day' => 1,
      'payment_processor_id' => $this->getPayLaterProcessorID()['id'],
      'financial_type_id' => $this->memberDuesFinancialType['id'],
      'payment_instrument_id' => $this->eftPaymentInstrumentID,
      'start_date' => date('Y-m-d'),
    ];

    $this->contributionParams = [
      'contact_id' => $this->contact['id'],
      'fee_amount' => 0,
      'net_amount' => 120,
      'total_amount' => 120,
      'receive_date' => date('Y-m-d'),
      'payment_instrument_id' => $this->eftPaymentInstrumentID,
      'financial_type_id' => $this->memberDuesFinancialType['id'],
      'contribution_status_id' => $this->contributionPendingStatusValue,
      'is_pay_later' => TRUE,
      'skipLineItem' => 1,
      'skipCleanMoney' => TRUE,
    ];

    $priceFieldValue = $this->getDefaultPriceFieldValueID($this->membershipType->id);
    $this->lineItemsParams[] = [
      'entity_table' => 'civicrm_membership',
      'price_field_id' => $priceFieldValue['price_field_id'],
      'label' => 'Membership subscription',
      'qty' => 1,
      'unit_price' => 120,
      'line_total' => 120,
      'price_field_value_id' => $priceFieldValue['id'],
      'financial_type_id' => $this->getMembershipDuesFinancialType()['id'],
      'non_deductible_amount' => 0,
    ];
  }

  /**
   * Sets up the update recurring contribution form.
   */
  private function setUpUpdateSubscriptionForm() {
    $controller = new CRM_Core_Controller();
    $this->updateSubscriptionForm = new CRM_Contribute_Form_UpdateSubscription();
    $this->updateSubscriptionForm->controller = $controller;
  }

  /**
   * Obtains default payment processor used for offline recurring contributions.
   *
   * @return array
   * @throws \CiviCRM_API3_Exception
   */
  private function getPayLaterProcessorID() {
    $result = civicrm_api3('PaymentProcessor', 'get', [
      'sequential' => 1,
      'name' => OfflineRecurringContributionPaymentProcessor::NAME,
      'is_test' => '0',
      'options' => ['limit' => 1],
    ]);

    if ($result['count'] > 0) {
      return array_shift($result['values']);
    }

    return [];
  }

  /**
   * Obtains 'Membership Dues' financial type.
   *
   * @return array
   * @throws \CiviCRM_API3_Exception
   */
  private function getMembershipDuesFinancialType() {
    $result = civicrm_api3('FinancialType', 'get', [
      'sequential' => 1,
      'name' => 'Member Dues',
      'options' => ['limit' => 1],
    ]);

    if ($result['count'] > 0) {
      return array_shift($result['values']);
    }

    return [];
  }

  /**
   * Obtains value for EFT payment instrument option value.
   *
   * @return array
   * @throws \CiviCRM_API3_Exception
   */
  private function getEFTPaymentInstrumentID() {
    return civicrm_api3('OptionValue', 'getvalue', [
      'return' => 'value',
      'option_group_id' => 'payment_instrument',
      'label' => 'EFT',
    ]);
  }

  /**
   * Gets the default price field value for the given membership ID.
   *
   * @param int $membershipTypeID
   *
   * @return array
   * @throws \CiviCRM_API3_Exception
   */
  private function getDefaultPriceFieldValueID($membershipTypeID) {
    $result = civicrm_api3('PriceFieldValue', 'get', [
      'sequential' => 1,
      'price_field_id.price_set_id.name' => 'default_membership_type_amount',
    ]);

    if ($result['count'] > 0) {
      return array_shift($result['values']);
    }

    return [];
  }

  public function testUpdatingCycleDayUpdatesReceiveDatesOfContributionsInFuture() {
    $startDate = date('Y-m-01', strtotime('-6 months'));
    $this->recurringContributionParams['start_date'] = $startDate;
    $this->contributionParams['receive_date'] = $startDate;

    $paymentPlan = PaymentPlanOrderFabricator::fabricate(
      $this->recurringContributionParams,
      $this->lineItemsParams,
      $this->contributionParams
    );

    $installmentsBeforeUpdating = $this->getPaymentPlanInstallments($paymentPlan['id']);
    $this->assertEquals(12, count($installmentsBeforeUpdating));

    $newCycleDay = 15;
    $this->simulateUpdateCycleDayWithForm($paymentPlan, $newCycleDay);

    $updateHook = new CRM_MembershipExtras_Hook_PostProcess_UpdateSubscription($this->updateSubscriptionForm);
    $updateHook->postProcess();

    $i = 1;
    foreach ($installmentsBeforeUpdating as $installment) {
      $this->assertInstallmentReceiveDateIsOK($installment, $newCycleDay, $i);
      $i++;
    }
  }

  /**
   * Updates the payment plan to the given cycle day, setting up the form.
   *
   * @param $paymentPlan
   * @param $newCycleDay
   *
   * @throws \CiviCRM_API3_Exception
   */
  private function simulateUpdateCycleDayWithForm($paymentPlan, $newCycleDay) {
    $this->updateSubscriptionForm->set('crid', $paymentPlan['id']);
    $this->updateSubscriptionForm->buildForm();
    $this->updateSubscriptionForm->set('update_installments', 1);
    $this->updateSubscriptionForm->set('auto_renew', 1);
    $this->updateSubscriptionForm->set('old_cycle_day', 1);
    $this->updateSubscriptionForm->set('old_payment_instrument_id', $this->eftPaymentInstrumentID);
    $this->updateSubscriptionForm->setVar('_submitValues', [
      'old_cycle_day' => 1,
      'cycle_day' => $newCycleDay,
      'auto_renew' => 1,
      'payment_instrument_id' => $this->eftPaymentInstrumentID,
    ]);

    $this->updateRecurringContributionCycleDay($paymentPlan['id'], $newCycleDay);
  }

  public function testOnlyPEndingContributionsChangeReceiveDateOnCycleDayUpdate() {
    $startDate = date('Y-m-01', strtotime('+1 month'));
    $this->recurringContributionParams['start_date'] = $startDate;
    $this->contributionParams['receive_date'] = $startDate;

    $paymentPlan = PaymentPlanOrderFabricator::fabricate(
      $this->recurringContributionParams,
      $this->lineItemsParams,
      $this->contributionParams
    );
    $installmentsBeforeUpdating = $this->getPaymentPlanInstallments($paymentPlan['id']);
    $this->changeContributionStatusToCompleted($installmentsBeforeUpdating[0]['id']);
    $this->changeContributionStatusToCompleted($installmentsBeforeUpdating[1]['id']);
    $this->changeContributionStatusToCompleted($installmentsBeforeUpdating[2]['id']);

    $newCycleDay = 15;
    $this->simulateUpdateCycleDayWithForm($paymentPlan, $newCycleDay);

    $updateHook = new CRM_MembershipExtras_Hook_PostProcess_UpdateSubscription($this->updateSubscriptionForm);
    $updateHook->postProcess();

    $i = 1;
    foreach ($installmentsBeforeUpdating as $installment) {
      $this->assertInstallmentReceiveDateIsOK($installment, $newCycleDay, $i);
      $i++;
    }
  }

  /**
   * Completes the given installment.
   *
   * @param $installmentID
   *
   * @throws \CiviCRM_API3_Exception
   */
  private function changeContributionStatusToCompleted($installmentID) {
    civicrm_api3('Contribution', 'create', [
      'sequential' => 1,
      'id' => $installmentID,
      'contribution_status_id' => 'Completed',
      'options' => ['limit' => 0],
    ]);
  }

  /**
   * Updates cycle day for recurring contribution.
   *
   * @param $recurringContributionID
   * @param $cycleDay
   *
   * @throws \CiviCRM_API3_Exception
   */
  private function updateRecurringContributionCycleDay($recurringContributionID, $cycleDay) {
    civicrm_api3('ContributionRecur', 'create', [
      'sequential' => 1,
      'id' => $recurringContributionID,
      'cycle_day' => $cycleDay,
    ]);
  }

  /**
   * Checks the date for the installment follows expected business logic.
   *
   * @param array $installmentBeforeUpdate
   * @param int $newCycleDay
   * @param int $nth
   *
   * @throws \CiviCRM_API3_Exception
   */
  private function assertInstallmentReceiveDateIsOK($installmentBeforeUpdate, $newCycleDay, $nth) {
    $installmentAfterUpdate = civicrm_api3('Contribution', 'getsingle', [
      'id' => $installmentBeforeUpdate['id'],
    ]);

    $now = new DateTime(date('Y-m-d 00:00:00'));
    $originalReceiveDate = new DateTime($installmentBeforeUpdate['receive_date']);
    $newReceiveDate = new DateTime($installmentAfterUpdate['receive_date']);

    if ($originalReceiveDate >= $now && $installmentAfterUpdate['contribution_status_id'] === $this->contributionPendingStatusValue) {
      $this->assertEquals(
        $originalReceiveDate->format('Y-m-') . $newCycleDay,
        $newReceiveDate->format('Y-m-d'),
        "Installment $nth did not get updated! Original date: {$originalReceiveDate->format('Y-m-d')} / Current Date: {$newReceiveDate->format('Y-m-d')}"
      );
    }
    else {
      $this->assertEquals(
        $installmentBeforeUpdate['receive_date'],
        $installmentAfterUpdate['receive_date'],
        "Installment $nth changed receive_date and it should not have! Original receive date: {$originalReceiveDate->format('Y-m-d')} / Current Date: {$newReceiveDate->format('Y-m-d')}"
      );
    }
  }

  /**
   * Obtains installments for the recurring contribution.
   *
   * @param int $recurringContributionID
   *
   * @return array|mixed
   * @throws \CiviCRM_API3_Exception
   */
  private function getPaymentPlanInstallments($recurringContributionID) {
    $result = civicrm_api3('Contribution', 'get', [
      'sequential' => 1,
      'contribution_recur_id' => $recurringContributionID,
      'options' => ['limit' => 0, 'sort' => 'id ASC'],
    ]);

    if ($result['count'] > 0) {
      return $result['values'];
    }

    return [];
  }

}
