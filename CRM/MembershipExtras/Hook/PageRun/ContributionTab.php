<?php

class CRM_MembershipExtras_Hook_PageRun_ContributionTab implements CRM_MembershipExtras_Hook_PageRun_PageRunInterface {

  private $page;

  /**
   * @param CRM_Core_Page $page
   */
  public function handle($page) {
    $this->page = $page;
    $this->improveFrequencyColumnWordingForPaymentSchemeRecurringContributions();
  }

  /**
   * The values under the frequency column in the
   * recurring contribution tab are not relevant for
   * recurring contributions that are linked to payment
   * schemes, so we alter the wording to indicate that
   * it uses a payment scheme.
   *
   * @return void
   */
  private function improveFrequencyColumnWordingForPaymentSchemeRecurringContributions() {
    $rowTypes = ['activeRecurRows', 'inactiveRecurRows'];
    foreach ($rowTypes as $rowType) {
      $tplVarName = $rowType . 'PaymentSchemeField';

      $recurRows = $this->page->get_template_vars($rowType);
      $recurIds = [];
      foreach ($recurRows as $recurRow) {
        $recurIds[] = $recurRow['id'];
      }

      if (empty($recurIds)) {
        $this->page->assign($tplVarName, '[]');
        continue;
      }

      $recurRowsPaymentSchemeField = $this->getRecurringContributionsPaymentSchemeFieldInSameInputOrder($recurIds);
      $this->page->assign($tplVarName, json_encode($recurRowsPaymentSchemeField));
    }
  }

  private function getRecurringContributionsPaymentSchemeFieldInSameInputOrder($recurIds) {
    $paymentSchemeValues = \Civi\Api4\ContributionRecur::get()
      ->addSelect('payment_plan_extra_attributes.payment_scheme_id')
      ->addWhere('id', 'IN', $recurIds)
      ->execute()
      ->indexBy('id')
      ->getArrayCopy();

    $resultInSameInputOrder = [];
    foreach ($recurIds as $recurId) {
      $resultInSameInputOrder[] = $paymentSchemeValues[$recurId]['payment_plan_extra_attributes.payment_scheme_id'];
    }

    return $resultInSameInputOrder;
  }

}
