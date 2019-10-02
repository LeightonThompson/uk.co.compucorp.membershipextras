<?php
use CRM_MembershipExtras_ExtensionUtil as E;
use CRM_MembershipExtras_Service_MoneyUtilities as MoneyUtilities;

class CRM_MembershipExtras_Page_EditContributionRecurLineItems extends CRM_Core_Page {

  /**
   * ID of the recurring contribution being viewed.
   *
   * @var int
   */
  private $contribRecur;

  /**
   * List of available financial types in the system.
   *
   * @var array
   */
  private $financialTypes = array();

  /**
   * Contains the list of all membership types
   *
   * @var array
   */
  private $allMembershipTypes = [];

  /**
   * Contains the list of all current line items
   * membership types
   *
   * @var array
   */
  private $currentLineItemMembershipTypes = [];

  /**
   * Contains the list of all next line items
   * membership types
   *
   * @var array
   */
  private $nextLineItemMembershipTypes = [];

  /**
   * @inheritdoc
   */
  public function __construct($title = NULL, $mode = NULL) {
    parent::__construct($title, $mode);

    $this->contribRecur = $this->getRecurringContribution();
    $this->financialTypes = $this->getFinancialTypes();
    $this->setAllMembershipTypes();
  }

  /**
   * Loads recurring contribution identified by ID set in request.
   *
   * @return array
   */
  private function getRecurringContribution() {
    $recurringContributionID = CRM_Utils_Request::retrieveValue('crid', 'Positive', 0);

    return civicrm_api3('ContributionRecur', 'getsingle', [
      'id' => $recurringContributionID,
    ]);
  }

  /**
   * Loads available financial types.
   *
   * @return array
   */
  private function getFinancialTypes() {
    $financialTypes = array();

    $result = civicrm_api3('FinancialType', 'get', [
      'options' => ['limit' => 0],
    ]);

    if ($result['count'] > 0) {
      foreach ($result['values'] as $financialType) {
        $financialTypes[] = array_merge($financialType, array(
          'tax_rate' => $this->getTaxRateForFinancialType($financialType['id']),
        ));
      }
    }

    return $financialTypes;
  }

  private function setAllMembershipTypes() {
    $result = civicrm_api3('MembershipType', 'get', [
      'options' => ['limit' => 0],
    ]);

    if ($result['count'] > 0) {
      $this->allMembershipTypes = $result['values'];
    }
  }

  private function getCurrentTabMembershipTypes() {
    $allowedTypes = $this->allMembershipTypes;
    foreach ($allowedTypes as $key => $type) {
      $lineItemIndex = array_search($type['member_of_contact_id'], array_column($this->currentLineItemMembershipTypes, 'org_id'));
      if ($lineItemIndex !== FALSE) {
        unset($allowedTypes[$key]);
      }
    }

    return $allowedTypes;
  }

  private function getNextTabMembershipTypes() {
    $allowedTypes = $this->allMembershipTypes;
    foreach ($allowedTypes as $key => $type) {
      $lineItemIndex = array_search($type['member_of_contact_id'], array_column($this->currentLineItemMembershipTypes, 'org_id'));
      if ($lineItemIndex !== FALSE) {
        $lineItemType = $this->currentLineItemMembershipTypes[$lineItemIndex];
        if ($lineItemType['is_autorenew']) {
          unset($allowedTypes[$key]);
          continue;
        }
      }

      $lineItemIndex = array_search($type['member_of_contact_id'], array_column($this->nextLineItemMembershipTypes, 'org_id'));
      if ($lineItemIndex !== FALSE) {
        unset($allowedTypes[$key]);
        continue;
      }
    }

    return $allowedTypes;
  }

  /**
   * Obtains membership type data for the given membership ID.
   *
   * @param $membershipID
   *
   * @return array
   */
  private function getMembershipTypeFromMembershipID($membershipID) {
    try {
      $result = civicrm_api3('Membership', 'getsingle', [
        'id' => $membershipID,
        'api.MembershipType.getsingle' => [
          'id' => '$value.membership_type_id',
        ],
      ]);

      return $result['api.MembershipType.getsingle'];
    } catch (Exception $e) {
      return [];
    }
  }

  private function getMembershipTypeFromPriceFieldValue($priceFieldValueId) {
    $priceFieldMembershipType = civicrm_api3('PriceFieldValue', 'get', [
      'sequential' => 1,
      'id' => $priceFieldValueId,
      'options' => ['sort' => 'id desc'],
    ]);

    if (!empty($priceFieldMembershipType['values'][0]['membership_type_id'])) {
      $lineItemMembershipTypeId = $priceFieldMembershipType['values'][0]['membership_type_id'];
      return civicrm_api3('MembershipType', 'get', [
        'sequential' => 1,
        'id' => $lineItemMembershipTypeId,
      ])['values'][0];
    }

    return NULL;
  }

  /**
   * @inheritdoc
   */
  public function run() {
    CRM_Utils_System::setTitle(E::ts('Manage Installments'));

    $this->assign('currentDate', date('Y-m-d'));
    $this->assign('recurringContribution', $this->contribRecur);
    $this->assign('recurringContributionID', $this->contribRecur['id']);

    $this->assign('periodStartDate', CRM_Utils_Array::value('start_date', $this->contribRecur));
    $this->assign('periodEndDate', CRM_Utils_Array::value('end_date', $this->contribRecur));

    $currentPeriodLineItems = $this->getCurrentPeriodLineItems();
    $this->setCurrentLineItemMembershipTypes($currentPeriodLineItems);
    $this->setNextLineItemMembershipTypes($currentPeriodLineItems);

    $this->assign('largestMembershipEndDate', $this->getLargestMembershipEndDate($currentPeriodLineItems));

    $this->assign('currentPeriodMembershipTypes', $this->getCurrentTabMembershipTypes());
    $this->assign('nextPeriodMembershipTypes', $this->getNextTabMembershipTypes());

    $this->assign('lineItems', $currentPeriodLineItems);

    $nextPeriodLineItems = $this->getNextPeriodLineItems();
    $this->assign('showNextPeriodTab', $this->showNextPeriodTab());
    $this->assign('nextPeriodStartDate', $this->calculateNextPeriodStartDate());
    $this->assign('financialTypes', $this->financialTypes);
    $this->assign('currencySymbol', $this->getCurrencySymbol());
    $this->assign('nextPeriodLineItems', $nextPeriodLineItems);

    parent::run();
  }

  /**
   * Obtains list of line items for the current period.
   *
   * @return array
   */
  private function getCurrentPeriodLineItems() {
    $conditions = [
      'is_removed' => 0,
      'start_date' => ['IS NOT NULL' => 1],
    ];

    $installments = CRM_Utils_Array::value('installments', $this->contribRecur, 0);
    if ($installments <= 1) {
      $conditions['end_date'] = ['IS NULL' => 1];
    }

    return $this->getLineItems($conditions);
  }

  private function setCurrentLineItemMembershipTypes($currentPeriodLineItems) {
    foreach ($currentPeriodLineItems as $lineItem) {
      if ($lineItem['entity_table'] != 'civicrm_membership') {
        continue;
      }

      $typeDetails = [];

      $typeDetails['is_autorenew'] = $lineItem['auto_renew'];

      $lineItemMembershipType = $this->getMembershipTypeFromMembershipID($lineItem['entity_id']);
      $typeDetails['name'] = $lineItemMembershipType['name'];
      $typeDetails['org_id'] = $lineItemMembershipType['member_of_contact_id'];

      $this->currentLineItemMembershipTypes[] = $typeDetails;
    }
  }

  private function setNextLineItemMembershipTypes($currentPeriodLineItems) {
    foreach ($currentPeriodLineItems as $lineItem) {
      if ($lineItem['entity_table'] != 'civicrm_contribution_recur') {
        continue;
      }

      $typeDetails = [];

      $lineItemMembershipType = $this->getMembershipTypeFromPriceFieldValue($lineItem['price_field_value_id']);
      $typeDetails['name'] = $lineItemMembershipType['name'];
      $typeDetails['org_id'] = $lineItemMembershipType['member_of_contact_id'];

      $this->nextLineItemMembershipTypes[] = $typeDetails;
    }
  }

  /**
   * Obtains list of line items for the next period.
   *
   * @return array
   */
  private function getNextPeriodLineItems() {
    $conditions = [
      'auto_renew' => TRUE,
      'is_removed' => 0,
    ];

    $installments = CRM_Utils_Array::value('installments', $this->contribRecur, 0);
    if ($installments <= 1) {
      $conditions['end_date'] = ['IS NULL' => 1];
    }

    return $this->getLineItems($conditions);
  }

  /**
   * @return string
   */
  private function getCurrencySymbol() {
    $config = CRM_Core_Config::singleton();
    return CRM_Core_DAO::getFieldValue('CRM_Financial_DAO_Currency', $config->defaultCurrency, 'symbol', 'name');
  }

  /**
   * Returns largest end date for memberships associated to recurring
   * contribution.
   *
   * @param $lineItems
   *
   * @return string
   */
  private function getLargestMembershipEndDate($lineItems) {
    $latestDate = null;

    foreach ($lineItems as $line) {
      if ($line['entity_table'] != 'civicrm_membership') {
        continue;
      }

      $membership = $this->getMembership($line['entity_id']);
      $membershipDate = new DateTime($membership['end_date']);

      if (!isset($latestDate)) {
        $latestDate = $membershipDate;
      } elseif ($latestDate < $membershipDate) {
        $latestDate = $membershipDate;
      }
    }

    return isset($latestDate) ? $latestDate->format('Y-m-d') : '';
  }

  /**
   * Gets the memberships identified by givenn ID.
   *
   * @param $membershipID
   *
   * @return array
   */
  private function getMembership($membershipID) {
    if (empty($membershipID)) {
      return [];
    }
    $membership = civicrm_api3('Membership', 'getsingle', [
      'sequential' => 1,
      'id' => $membershipID,
    ]);
    return $membership;
  }

  /**
   * Checks if auto-renew is enabled for recurring contribution.
   *
   * @return boolean
   */
  private function showNextPeriodTab() {
    return CRM_Utils_String::strtobool(CRM_Utils_Array::value('auto_renew', $this->contribRecur));
  }

  /**
   * Calculates next period's start date
   *
   * @return string
   */
  private function calculateNextPeriodStartDate() {
    $numberOfInstallments = 1;
    if (!empty($this->contribRecur['installments'])) {
      $numberOfInstallments = $this->contribRecur['installments'];
    }
    $intervalLength = CRM_Utils_Array::value('frequency_interval', $this->contribRecur, 0) * $numberOfInstallments;

    switch (CRM_Utils_Array::value('frequency_unit', $this->contribRecur)) {
      case 'month':
        $interval = 'P' . $intervalLength . 'M';
        break;
      case 'day':
        $interval = 'P' . $intervalLength .'D';
        break;
      case 'year':
        $interval = 'P' . $intervalLength .'Y';
        break;
    }

    $nextPeriodStartDate = new DateTime(CRM_Utils_Array::value('start_date', $this->contribRecur));
    $nextPeriodStartDate->add(new DateInterval($interval));

    return $nextPeriodStartDate->format('Y-m-d');
  }

  /**
   * Obtains list of line items for the current recurring contribution.
   *
   * @param array $conditions
   *
   * @return array
   */
  private function getLineItems($conditions = []) {
    $lineItems = array();

    $options = array_merge($conditions, [
      'sequential' => 1,
      'contribution_recur_id' => $this->contribRecur['id'],
      'api.LineItem.getsingle' => [
        'id' => '$value.line_item_id',
        'entity_table' => ['IS NOT NULL' => 1],
        'entity_id' => ['IS NOT NULL' => 1]
      ],
    ]);

    $result = civicrm_api3('ContributionRecurLineItem', 'get', $options);

    if ($result['count'] > 0) {
      foreach ($result['values'] as $lineItemData) {
        $lineDetails = $lineItemData['api.LineItem.getsingle'];
        $lineDetails['tax_rate'] = $this->getTaxRateForFinancialType($lineDetails['financial_type_id']);
        $lineDetails['financial_type'] = $this->getFinancialTypeName($lineDetails['financial_type_id']);

        unset($lineDetails['id']);
        unset($lineItemData['api.LineItem.getsingle']);
        $lineItems[] = array_merge($lineItemData, $lineDetails);
      }
    }

    return $lineItems;
  }

  /**
   * Returns tax rate used for given financial type ID.
   *
   * @param $financialTypeID
   *
   * @return double
   */
  private function getTaxRateForFinancialType($financialTypeID) {
    $taxRates = CRM_Core_PseudoConstant::getTaxRates();
    $rate = MoneyUtilities::roundToPrecision(
      CRM_Utils_Array::value($financialTypeID, $taxRates, 0),
      2
    );

    return $rate;
  }

  /**
   * Returns the financial type's name identified by the given ID.
   *
   * @param int $id
   *
   * @return string
   */
  private function getFinancialTypeName($id) {
    foreach ($this->financialTypes as $financialType) {
      if ($financialType['id'] === $id) {
        return $financialType['name'];
      }
    }

    return '';
  }

}
