<?php
/**
 * Class for CiviRules Group Contact Action Form
 *
 * @author Jaap Jansma (CiviCooP) <jaap.jansma@civicoop.org>
 * @license AGPL-3.0
 */

class CRM_CivirulesActions_Activity_Form_Activity extends CRM_CivirulesActions_Form_Form {

  protected $use_old_contact_ref_fields = false;

  public function preProcess()
  {
    $version = CRM_Core_BAO_Domain::version();
    if (version_compare($version, '4.5', '<')) {
      $this->use_old_contact_ref_fields = true;
    }

    parent::preProcess(); // TODO: Change the autogenerated stub
  }


  /**
   * Overridden parent method to build the form
   *
   * @access public
   */
  public function buildQuickForm() {
    $this->add('hidden', 'rule_action_id');
    $this->add('select', 'activity_type_id', ts('Activity type'), array('' => ts('-- please select --')) + CRM_Core_OptionGroup::values('activity_type'), true);
    $this->add('select', 'status_id', ts('Status'), array('' => ts('-- please select --')) + CRM_Core_OptionGroup::values('activity_status'), true);
    $this->add('text', 'subject', ts('Subject'));

    $this->assign('use_old_contact_ref_fields', $this->use_old_contact_ref_fields);
    if ($this->use_old_contact_ref_fields) {
      $data = unserialize($this->ruleAction->action_params);
      $assignees = array();
      if (!empty($data['assignee_contact_id'])) {
        if (is_array($data['assignee_contact_id'])) {
          $assignees = $data['assignee_contact_id'];
        } else {
          $assignees[] = $data['assignee_contact_id'];
        }
      }
      $this->assign('selectedContacts', implode(",", $assignees));
      CRM_Contact_Form_NewContact::buildQuickForm($this);
    } else {
      $attributes = array(
        'multiple' => TRUE,
        'create' => TRUE,
        'api' => array('params' => array('is_deceased' => 0))
      );
      $this->addEntityRef('assignee_contact_id', ts('Assigned to'), $attributes, false);
    }

    $delayList = array('' => ts(' - Do not set an activity date - ')) + CRM_Civirules_Delay_Factory::getOptionList();
    $this->add('select', 'activity_date_time', ts('Set activity date'), $delayList);
    foreach(CRM_Civirules_Delay_Factory::getAllDelayClasses() as $delay_class) {
      $delay_class->addElements($this, 'activity_date_time', $this->rule);
    }
    $this->assign('delayClasses', CRM_Civirules_Delay_Factory::getAllDelayClasses());

    $this->addButtons(array(
      array('type' => 'next', 'name' => ts('Save'), 'isDefault' => TRUE,),
      array('type' => 'cancel', 'name' => ts('Cancel'))));
  }

  /**
   * Overridden parent method to set default values
   *
   * @return array $defaultValues
   * @access public
   */
  public function setDefaultValues() {
    $defaultValues = parent::setDefaultValues();
    $data = unserialize($this->ruleAction->action_params);
    if (!empty($data['activity_type_id'])) {
      $defaultValues['activity_type_id'] = $data['activity_type_id'];
    }
    if (!empty($data['status_id'])) {
      $defaultValues['status_id'] = $data['status_id'];
    }
    if (!empty($data['subject'])) {
      $defaultValues['subject'] = $data['subject'];
    }
    if (!empty($data['assignee_contact_id'])) {
      $defaultValues['assignee_contact_id'] = $data['assignee_contact_id'];
      if ($this->use_old_contact_ref_fields) {
        $assignee_contact_id = reset($data['assignee_contact_id']);
        if (!empty($assignee_contact_id)) {
          $defaultValues['contact[1]'] = civicrm_api3('Contact', 'getvalue', array(
            'id' => $assignee_contact_id,
            'return' => 'display_name'
          ));
          $defaultValues['contact_select_id[1]'] = $assignee_contact_id;
        }
      }
    }

    foreach(CRM_Civirules_Delay_Factory::getAllDelayClasses() as $delay_class) {
      $delay_class->setDefaultValues($defaultValues, 'activity_date_time', $this->rule);
    }
    $activityDateClass = unserialize($data['activity_date_time']);
    if ($activityDateClass) {
      $defaultValues['activity_date_time'] = get_class($activityDateClass);
      foreach($activityDateClass->getValues('activity_date_time', $this->rule) as $key => $val) {
        $defaultValues[$key] = $val;
      }
    }

    return $defaultValues;
  }

  /**
   * Function to add validation action rules (overrides parent function)
   *
   * @access public
   */
  public function addRules() {
    parent::addRules();
    $this->addFormRule(array(
      'CRM_CivirulesActions_Activity_Form_Activity',
      'validateActivityDateTime'
    ));
  }

  /**
   * Function to validate value of the delay
   *
   * @param array $fields
   * @return array|bool
   * @access public
   * @static
   */
  static function validateActivityDateTime($fields) {
    $errors = array();
    if (!empty($fields['activity_date_time'])) {
      $ruleActionId = CRM_Utils_Request::retrieve('rule_action_id', 'Integer');
      $ruleAction = new CRM_Civirules_BAO_RuleAction();
      $ruleAction->id = $ruleActionId;
      $ruleAction->find(true);
      $rule = new CRM_Civirules_BAO_Rule();
      $rule->id = $ruleAction->rule_id;
      $rule->find(true);

      $activityDateClass = CRM_Civirules_Delay_Factory::getDelayClassByName($fields['activity_date_time']);
      $activityDateClass->validate($fields, $errors, 'activity_date_time', $rule);
    }

    if (count($errors)) {
      return $errors;
    }

    return TRUE;
  }

  /**
   * Overridden parent method to process form data after submitting
   *
   * @access public
   */
  public function postProcess() {
    $data['activity_type_id'] = $this->_submitValues['activity_type_id'];
    $data['status_id'] = $this->_submitValues['status_id'];
    $data['subject'] = $this->_submitValues['subject'];
    $data['assignee_contact_id'] = false;

    if ($this->use_old_contact_ref_fields) {
      $values = $this->controller->exportValues();
      if (!empty($values['contact_select_id']) && count($values['contact_select_id']) > 0) {
        $data['assignee_contact_id'] = $values['contact_select_id'];
      }
    } else {
      $data["assignee_contact_id"] = explode(',', $this->_submitValues["assignee_contact_id"]);
    }

    $data['activity_date_time'] = 'null';
    if (!empty($this->_submitValues['activity_date_time'])) {
      $scheduledDateClass = CRM_Civirules_Delay_Factory::getDelayClassByName($this->_submitValues['activity_date_time']);
      $scheduledDateClass->setValues($this->_submitValues, 'activity_date_time', $this->rule);
      $data['activity_date_time'] = serialize($scheduledDateClass);
    }

    $this->ruleAction->action_params = serialize($data);
    $this->ruleAction->save();
    parent::postProcess();
  }

}
