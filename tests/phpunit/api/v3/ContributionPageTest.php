<?php
/*
 +--------------------------------------------------------------------+
 | CiviCRM version 4.5                                                |
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC (c) 2004-2014                                |
 +--------------------------------------------------------------------+
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License and the CiviCRM Licensing Exception along                  |
 | with this program; if not, contact CiviCRM LLC                     |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +--------------------------------------------------------------------+
*/

require_once 'CiviTest/CiviUnitTestCase.php';


/**
 *  Test APIv3 civicrm_contribute_recur* functions
 *
 *  @package CiviCRM_APIv3
 *  @subpackage API_Contribution
 */

class api_v3_ContributionPageTest extends CiviUnitTestCase {
  protected $_apiversion = 3;
  protected $testAmount = 34567;
  protected $params;
  protected $id = 0;
  protected $contactIds = array();
  protected $_entity = 'contribution_page';
  protected $contribution_result = null;
  protected $_priceSetParams = array();

  /**
   * @var array
   *  - contribution_page
   *  - price_set
   *  - price_field
   *  - price_field_value
   */
  protected $_ids = array();


  public $DBResetRequired = TRUE;
  public function setUp() {
    parent::setUp();
    $this->contactIds[] = $this->individualCreate();
    $this->params = array(
      'title' => "Test Contribution Page",
      'financial_type_id' => 1,
      'currency' => 'NZD',
      'goal_amount' => $this->testAmount,
      'is_pay_later' => 1,
      'is_monetary' => TRUE,
    );

    $this->_priceSetParams = array(
      'is_quick_config' => 1,
      'extends' => 'CiviContribute',
      'financial_type_id' => 'Donation',
      'title' => 'my Page'
    );
  }

  function tearDown() {
    foreach ($this->contactIds as $id) {
      $this->callAPISuccess('contact', 'delete', array('id' => $id));
    }
    $this->quickCleanUpFinancialEntities();
  }

  public function testCreateContributionPage() {
    $result = $this->callAPIAndDocument($this->_entity, 'create', $this->params, __FUNCTION__, __FILE__);
    $this->assertEquals(1, $result['count']);
    $this->assertNotNull($result['values'][$result['id']]['id']);
    $this->getAndCheck($this->params, $result['id'], $this->_entity);
  }

  public function testGetBasicContributionPage() {
    $createResult = $this->callAPISuccess($this->_entity, 'create', $this->params);
    $this->id = $createResult['id'];
    $getParams = array(
      'currency' => 'NZD',
      'financial_type_id' => 1,
    );
    $getResult = $this->callAPIAndDocument($this->_entity, 'get', $getParams, __FUNCTION__, __FILE__);
    $this->assertEquals(1, $getResult['count']);
  }

  public function testGetContributionPageByAmount() {
    $createResult = $this->callAPISuccess($this->_entity, 'create', $this->params);
    $this->id = $createResult['id'];
    $getParams = array(
      'amount' => ''. $this->testAmount, // 3456
      'currency' => 'NZD',
      'financial_type_id' => 1,
    );
    $getResult = $this->callAPIAndDocument($this->_entity, 'get', $getParams, __FUNCTION__, __FILE__);
    $this->assertEquals(1, $getResult['count']);
  }

  public function testDeleteContributionPage() {
    $createResult = $this->callAPISuccess($this->_entity, 'create', $this->params);
    $deleteParams = array('id' => $createResult['id']);
    $this->callAPIAndDocument($this->_entity, 'delete', $deleteParams, __FUNCTION__, __FILE__);
    $checkDeleted = $this->callAPISuccess($this->_entity, 'get', array());
    $this->assertEquals(0, $checkDeleted['count']);
  }

  public function testGetFieldsContributionPage() {
    $result = $this->callAPISuccess($this->_entity, 'getfields', array('action' => 'create'));
    $this->assertEquals(12, $result['values']['start_date']['type']);
  }


  /**
   * Test form submission with basic price set
   */
  public function testSubmit() {
    $this->setUpContributionPage();
    $priceFieldID = reset($this->_ids['price_field']);
    $priceFieldValueID = reset($this->_ids['price_field_value']);
    $submitParams = array(
      'price_' . $priceFieldID => $priceFieldValueID,
      'id' => (int) $this->_ids['contribution_page'],
      'amount' => 10
    );

    $this->callAPISuccess('contribution_page', 'submit', $submitParams);
    $this->callAPISuccess('contribution', 'getsingle', array('contribution_page_id' => $this->_ids['contribution_page']));
  }
  /**
   * Test submit with a membership block in place
   */
  public function testSubmitMembershipBlockNotSeparatePayment() {
    $this->setUpMembershipContributionPage();
    $submitParams = array(
      'price_' . $this->_ids['price_field'] => reset($this->_ids['price_field_value']),
      'id' => (int) $this->_ids['contribution_page'],
      'amount' => 10,
      'billing_first_name' => 'Billy',
      'billing_middle_name' => 'Goat',
      'billing_last_name' => 'Gruff',
      'selectMembership' => $this->_ids['membership_type'],

    );

    $this->callAPIAndDocument('contribution_page', 'submit', $submitParams, __FUNCTION__, __FILE__, 'submit contribution page', NULL, 'Submit');
    $contribution = $this->callAPISuccess('contribution', 'getsingle', array('contribution_page_id' => $this->_ids['contribution_page']));
    $this->callAPISuccess('membership_payment', 'getsingle', array('contribution_id' => $contribution['id']));
  }

  /**
   * Test submit with a membership block in place
   */
  public function testSubmitMembershipBlockIsSeparatePayment() {
    $this->setUpMembershipContributionPage(TRUE);
    $submitParams = array(
      'price_' . $this->_ids['price_field'] => reset($this->_ids['price_field_value']),
      'id' => (int) $this->_ids['contribution_page'],
      'amount' => 10,
      'billing_first_name' => 'Billy',
      'billing_middle_name' => 'Goat',
      'billing_last_name' => 'Gruff',
      'selectMembership' => $this->_ids['membership_type'],
    );

    $this->callAPIAndDocument('contribution_page', 'submit', $submitParams, __FUNCTION__, __FILE__, 'submit contribution page', NULL, 'Submit');
    $contributions = $this->callAPISuccess('contribution', 'get', array('contribution_page_id' => $this->_ids['contribution_page']));
    $this->assertCount(2, $contributions['values']);
    $membershipPayment = $this->callAPISuccess('membership_payment', 'getsingle', array());
    $this->assertTrue(in_array($membershipPayment['contribution_id'], array_keys($contributions['values'])));
    $membership = $this->callAPISuccessGetSingle('membership', array('id' => $membershipPayment['membership_id']));
    $this->assertEquals($membership['contact_id'], $contributions['values'][$membershipPayment['contribution_id']]['contact_id']);
  }

  /**
   * set up membership contribution page
   * @param bool $isSeparatePayment
   */
  function setUpMembershipContributionPage($isSeparatePayment = FALSE) {
    $this->setUpMembershipBlockPriceSet();
    $this->params['payment_processor_id'] = $this->_ids['payment_processor'] = $this->paymentProcessorCreate(array('payment_processor_type_id' => 'Dummy',));
    $this->setUpContributionPage();

    $this->callAPISuccess('membership_block', 'create', array(
      'entity_id' => $this->_ids['contribution_page'],
      'entity_table' => 'civicrm_contribution_page',
      'is_required' => TRUE,
      'is_active' => TRUE,
      'is_separate_payment' => $isSeparatePayment,
      'membership_type_default' => $this->_ids['membership_type'],
    ));
  }

  /**
   * The default data set does not include a complete default membership price set - not quite sure why
   * This function ensures it exists & populates $this->_ids with it's data
   */
  function setUpMembershipBlockPriceSet() {
    $this->_ids['price_set'][] = $this->callAPISuccess('price_set', 'getvalue', array('name' => 'default_membership_type_amount', 'return' => 'id'));
    if (empty($this->_ids['membership_type'])) {
      $this->_ids['membership_type'] = $this->membershipTypeCreate(array('minimum_fee' => 1));
    }
    try {
      $this->_ids['price_field'] = $this->callAPISuccessGetValue('price_field', array(
          'return' => 'id',
          'name' => 'membership_amount',
          'price_set_id' => reset($this->_ids['price_set']),
          'options' => array('limit' => 1))
      );
      $this->_ids['price_field_value'] =  array($this->callAPISuccessGetValue('price_field_value', array('weight' => 1, 'return' => 'id', 'name' => 'membership_amount', 'price_field_id' => $this->_ids['price_field'])));
      $this->callAPISuccess('price_field_value', 'create', array('id' => $this->_ids['price_field_value'][0],  'membership_type_id' => $this->_ids['membership_type'],));
    }
    catch (Exception $e) {
      //default price set likely not set up correctly :-(
      $priceField = $this->callAPISuccess('price_field', 'create', array(
        'price_set_id' => reset($this->_ids['price_set']),
        'name' => 'membership_amount',
        'label' => 'Membership Amount',
        'html_type' => 'Radio',
        'sequential' => 1,
        'api.price_field_value.create' => array(
          'name' => 'membership_amount',
          'label' => 'Membership Amount',
          'amount' => 1,
          'financial_type_id' => 1,
          'format.only_id' => TRUE,
          'membership_type_id' => $this->_ids['membership_type']
        )
      ));
      $this->_ids['price_field'] = $priceField['id'];
      $this->_ids['price_field_value'] = array($priceField['values'][0]['api.price_field_value.create']);
    }
  }
  /**
   * help function to set up contribution page with some defaults
   */
  function setUpContributionPage() {
    $contributionPageResult = $this->callAPISuccess($this->_entity, 'create', $this->params);
    if (empty($this->_ids['price_set'])) {
      $priceSet = $this->callAPISuccess('price_set', 'create', $this->_priceSetParams);
      $this->_ids['price_set'][] = $priceSet['id'];
    }
    $priceSetID = reset($this->_ids['price_set']);
    CRM_Price_BAO_PriceSet::addTo('civicrm_contribution_page', $contributionPageResult['id'], $priceSetID );

    if (empty($this->_ids['price_field'])) {
      $priceField = $this->callAPISuccess('price_field', 'create', array(
        'price_set_id' => $priceSetID,
        'label' => 'Goat Breed',
        'html_type' => 'Radio',
      ));
      $this->_ids['price_field'] = array($priceField['id']);
    }
    if (empty($this->_ids['price_field_value'])) {
      $this->callAPISuccess('price_field_value', 'create', array(
          'price_set_id' => $priceSetID,
          'price_field_id' => $priceField['id'],
          'label' => 'Long Haired Goat',
          'amount' => 20,
        )
      );
      $priceFieldValue = $this->callAPISuccess('price_field_value', 'create', array(
          'price_set_id' => $priceSetID,
          'price_field_id' => $priceField['id'],
          'label' => 'Shoe-eating Goat',
          'amount' => 10,
        )
      );
      $this->_ids['price_field_value'] = array($priceFieldValue['id']);
    }
    $this->_ids['contribution_page'] = $contributionPageResult['id'];
  }

  public static function setUpBeforeClass() {
      // put stuff here that should happen before all tests in this unit
  }

  public static function tearDownAfterClass(){
    $tablesToTruncate = array(
      'civicrm_contact',
      'civicrm_financial_type',
      'civicrm_contribution',
      'civicrm_contribution_page',
    );
    $unitTest = new CiviUnitTestCase();
    $unitTest->quickCleanup($tablesToTruncate);
  }
}

